<?php
// Character profiles (ex_profiles and ex_npc_profiles from migration 005). Used by the turn
// pipeline, speak.php and the dashboard's Profiles page.
// This file only defines functions and constants, so requesting it over HTTP prints nothing.
//
// An NPC uses its assigned profile, else the default profile (if one is chosen), else none.
// With no profile, or an empty override, the private config applies unchanged. Profiles never
// hold URLs or keys: a model override keeps the configured provider's URL and key, and the only
// modes a profile may switch to (mock, dwemerllm) need neither, so a stored value can never
// send a key to another provider.

const PROFILE_LLM_MODES = ['mock' => 'Mock (no model, predictable replies)', 'dwemerllm' => 'DwemerDistro LLM Studio (no key)'];
const PROFILE_MAX_PROMPT = 2000;
const PROFILE_NAME_PATTERN = '/^[A-Za-z0-9_.:\/@+-]+$/D';

// The profile for one NPC, null when none applies, or false (error logged) when the tables
// could not be read. The prompt is returned as stored; the caller places it in the prompt.
function profile_for_npc(\PgSql\Connection $db, string $npcId): array|null|false
{
    $result = @pg_query_params($db,
        'SELECT p.id, p.name, p.system_prompt, p.llm_mode, p.llm_model, p.tts_voice, a.npc_id IS NOT NULL AS assigned
         FROM ex_profiles p LEFT JOIN ex_npc_profiles a ON a.profile_id = p.id AND a.npc_id = $1
         WHERE a.npc_id IS NOT NULL OR p.is_default
         ORDER BY a.npc_id IS NOT NULL DESC LIMIT 1',
        [$npcId]);
    if ($result === false) {
        app_log('profile lookup failed: ' . pg_last_error($db));
        return false;
    }
    $row = pg_fetch_assoc($result);
    if ($row === false) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['assigned'] = $row['assigned'] === 't';
    return $row;
}

// The llm settings for one NPC. A model override keeps the configured mode, URL and key. A mode
// override (mock or dwemerllm only) starts from the config's limits but drops its base_url,
// api_key and model, which belong to the configured provider.
function profile_llm(array $llm, ?array $profile): array
{
    if ($profile === null) {
        return $llm;
    }
    $mode = $profile['llm_mode'] ?? null;
    if (is_string($mode) && isset(PROFILE_LLM_MODES[$mode]) && $mode !== ($llm['mode'] ?? 'mock')) {
        $llm = ['mode' => $mode] + array_diff_key($llm, array_flip(['mode', 'base_url', 'api_key', 'model']));
    }
    $model = $profile['llm_model'] ?? null;
    if (is_string($model) && $model !== '') {
        $llm['model'] = $model;
    }
    return $llm;
}

// The tts settings for one NPC: only the voice can be overridden.
function profile_tts(array $tts, ?array $profile): array
{
    $voice = $profile['tts_voice'] ?? null;
    if (is_string($voice) && $voice !== '') {
        $tts['voice'] = $voice;
    }
    return $tts;
}

// What the event log keeps about the profile a turn used: never the prompt text itself.
function profile_trace(?array $profile): ?array
{
    if ($profile === null) {
        return null;
    }
    return ['id' => $profile['id'], 'name' => $profile['name'], 'assigned' => $profile['assigned'],
        'llm_mode' => $profile['llm_mode'], 'llm_model' => $profile['llm_model'], 'tts_voice' => $profile['tts_voice'],
        'prompt_chars' => mb_strlen((string)$profile['system_prompt'])];
}

// Checks a profile form. Returns [values, '', ''] or [null, field id, message].
// The prompt keeps line breaks; other control characters become spaces.
function profile_validate(array $input): array
{
    $text = fn(string $key) => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    if (!mb_check_encoding($text('name') . $text('system_prompt'), 'UTF-8')) {
        return [null, 'profile-prompt', 'The name and prompt must be valid text.'];
    }
    $name = clean_line($text('name'));
    $prompt = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', str_replace("\r\n", "\n", $text('system_prompt'))) ?? '');
    $mode = $text('llm_mode');
    $model = $text('llm_model');
    $voice = $text('tts_voice');
    if ($name === '' || mb_strlen($name) > 64) {
        return [null, 'profile-name', 'Name must be 1-64 characters.'];
    }
    if (mb_strlen($prompt) > PROFILE_MAX_PROMPT) {
        return [null, 'profile-prompt', 'System prompt must be at most ' . PROFILE_MAX_PROMPT . ' characters.'];
    }
    if ($mode !== '' && !isset(PROFILE_LLM_MODES[$mode])) {
        return [null, 'profile-mode', 'Choose a reply mode from the list.'];
    }
    if ($model !== '' && (strlen($model) > 200 || !preg_match(PROFILE_NAME_PATTERN, $model))) {
        return [null, 'profile-model', 'Model must be at most 200 characters of letters, digits and _ . : / @ + -'];
    }
    if ($mode === 'dwemerllm' && $model === '') {
        return [null, 'profile-model', 'DwemerDistro LLM Studio needs a model; the configured model belongs to another mode.'];
    }
    if ($voice !== '' && (strlen($voice) > 64 || !preg_match(PROFILE_NAME_PATTERN, $voice))) {
        return [null, 'profile-voice', 'Voice must be at most 64 characters of letters, digits and _ . : / @ + -'];
    }
    return [['name' => $name, 'system_prompt' => $prompt, 'llm_mode' => $mode === '' ? null : $mode,
        'llm_model' => $model === '' ? null : $model, 'tts_voice' => $voice === '' ? null : $voice,
        'is_default' => ($input['is_default'] ?? '') === '1'], '', ''];
}
