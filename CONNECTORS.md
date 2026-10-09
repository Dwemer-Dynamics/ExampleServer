# Connectors

Copyable settings for `config/config.php`. Each block replaces the matching key. Keys are
blank on purpose: paste your own, and never commit `config/config.php`. Changes apply on
the next request; nothing is checked or downloaded when settings are saved or viewed.

Every LLM mode (`llm.mode`) except `mock` needs an explicit `model`. Voice and decision
providers use the keys shown in their own blocks below. If a provider fails, the game gets
a clear error (`llm_unavailable`, `tts_unavailable`, `stt_unavailable`) and the reason is in
the Apache error log.

## LLM (`llm.mode`)

| mode | Endpoint | Key |
|---|---|---|
| `mock` (default) | none | none |
| `openai` | `base_url` + `/chat/completions`, default `https://api.openai.com/v1` | usually |
| `openrouter` | `https://openrouter.ai/api/v1/chat/completions` | required |
| `local` | your `base_url` + `/chat/completions` | optional |
| `dwemerllm` | DwemerDistro LLM Studio, `http://127.0.0.1:1234/v1/chat/completions` | never sent |

```php
'llm' => ['mode' => 'openrouter', 'model' => 'provider/model-name', 'api_key' => '',
          'timeout_seconds' => 30, 'max_tokens' => 200],
```

```php
'llm' => ['mode' => 'dwemerllm', 'model' => 'id-of-the-model-loaded-in-llm-studio',
          'timeout_seconds' => 60, 'max_tokens' => 200],
```

```php
'llm' => ['mode' => 'local', 'base_url' => 'http://127.0.0.1:5000/v1', 'model' => 'your-model',
          'api_key' => '', 'timeout_seconds' => 60, 'max_tokens' => 200],
```

```php
'llm' => ['mode' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'your-model',
          'api_key' => '', 'timeout_seconds' => 30, 'max_tokens' => 200],
```

Older configs with `'mode' => 'openai'` and their own `base_url` work unchanged. For
`dwemerllm`, start LLM Studio from the DwemerDistro launcher and load a model first; the
server does not list or check models for you. Bio, knowledge facts and history are sent the
same way in every mode.

## Voice

Text always comes first. Start with PocketTTS for text-to-speech (TTS) and Parakeet for
speech-to-text (STT), the recommended DwemerDistro starting choices; the `openai` TTS and
`fasterwhisper` STT blocks are supported alternatives. Only these providers are wired in:

```php
'tts' => ['enabled' => true, 'provider' => 'pockettts',
          'url' => 'http://127.0.0.1:8086/v1/audio/speech', 'model' => 'pocket-tts', 'voice' => 'alba',
          'timeout_seconds' => 30],
```

```php
'tts' => ['enabled' => true, 'provider' => 'openai',
          'url' => 'https://your-host/v1/audio/speech', 'model' => 'your-tts-model', 'voice' => 'your-voice',
          'api_key' => '', 'timeout_seconds' => 30],
```

```php
'stt' => ['enabled' => true, 'provider' => 'parakeet',
          'url' => 'http://127.0.0.1:8022/v1/audio/transcriptions', 'model' => 'whisper-1',
          'timeout_seconds' => 60],
```

```php
'stt' => ['enabled' => true, 'provider' => 'fasterwhisper',
          'url' => 'http://127.0.0.1:9876/api/v0/transcribe', 'form_field' => 'audio_file',
          'timeout_seconds' => 60],
```

The `openai` TTS provider asks for `response_format: wav`. Any reply that is not a WAV
(`RIFF` ... `WAVE`) is refused. `parakeet` uploads the WAV as multipart field `file` with
`model` (`whisper-1`); `fasterwhisper` uploads it as `form_field` (`audio_file`). Both must
answer JSON `{"text": "..."}`. STT text must be 1-1000 characters of UTF-8, otherwise the
player is asked to type. Configs without `provider` keep using PocketTTS and faster-whisper.

Voice is opt-in: the sample config ships with PocketTTS and Parakeet selected but
`enabled => false`. Check in the DwemerDistro launcher that the service is available
(install it there if missing) and start it, then enable it here or
on the dashboard Voice page. Changing the STT provider there swaps an unchanged default URL
and drops the other provider's field (`model` or `form_field`); other saved settings stay.

Try it from the client (the WAV is saved, not played):

```text
example_mod.exe --say "hello"              with "voice_enabled": true in config.json
example_mod.exe --listen C:\path\speech.wav
```

## NPC selection (`decision`, optional)

`decision.php` only picks which offered NPC should answer. It never changes `turn.php`,
history or actions. Off by default, it then always returns the baseline NPC.
Skip it when the game already has a valid explicit or crosshair target: do not send the
transcript, just send the turn to that NPC.

```php
'decision' => ['enabled' => true, 'provider' => 'mock'],
```

```php
'decision' => ['enabled' => true, 'provider' => 'jev', 'api_key' => '',
               'url' => 'https://openrouter.ai/api/alpha/decisions', 'model' => 'typesafe/jev-1.13'],
```

`mock` picks the first offered NPC whose name appears in the line. `jev` sends one `choice`
question with one criterion per offered NPC plus `abstain`, waits at most 1.5 seconds, reads
at most 16 KB and does not follow redirects. It accepts only an offered id with confidence
0.5 or more; anything else returns the baseline with a reason. The key is sent only to
`url`. Try it with `example_mod.exe --decide "Scout, is the north trail safe?"`. That
command only prints the choice.
Each call writes one row to Logs > Connector calls (request id, provider, status, reason,
time taken; never the transcript) after the reply was sent.

## Profiles (per NPC, optional)

Profiles live in the database, not the config: Configuration > World & Behavior > Profiles.
A profile adds a system prompt and may override the LLM `model`, the reply mode (only `mock` or
`dwemerllm`, which need no URL or key) and the TTS `voice`. Empty fields inherit the blocks on
this page. A model override keeps the configured provider, URL and key; a mode override drops
them, so a stored profile can never send a key somewhere you did not configure. The voice is
used when the game names the NPC in `speak.php` (`npc_id`).

## Memory and vector search (optional)

Both are off by default (Configuration > AI & Voice > Memory settings).

```php
'memory' => ['enabled' => true, 'auto_notes' => true, 'auto_extract' => true],
```

`enabled` gives each turn this session's saved summary and newest diary entries.
`auto_notes` and `auto_extract` call the NPC's own LLM mode after the reply was sent: in `mock`
mode they write labelled mock notes and propose a fact only for player lines like "remember
that the bridge is closed". Proposed facts wait on Roleplay > Memory until approved.

```php
// Mock vectors: hashed words, deterministic, NOT semantic. No service or key.
'embeddings' => ['enabled' => true, 'provider' => 'mock'],
```

```php
// Any OpenAI-compatible POST /v1/embeddings endpoint. Set the key on API keys.
'embeddings' => ['enabled' => true, 'provider' => 'openai', 'api_key' => '',
                 'url' => 'https://api.openai.com/v1/embeddings', 'model' => 'text-embedding-3-small',
                 'dims' => 0, 'timeout_seconds' => 5],
```

The URL is the full endpoint and is called without redirects, with the timeout and a 4 MB reply
cap, and never retried. Vectors belong to the configured endpoint, full model name and vector
size; changing any of these requires Reindex and excludes the old vectors from retrieval.
Rotating the API key keeps the vectors. Every vector must be a list of 1-4096 finite numbers (exactly `dims` of
them when `dims` is not 0) with non-zero length; it is stored at unit length as JSON in the
database (no extension needed). After enabling or changing the model, press **Reindex** on
Memory settings (64 facts per press). A turn embeds the player's line once and compares it with
at most 200 stored vectors (this NPC's and global facts, newest first); keyword search fills
the remaining places and is used alone when the call fails. Editing a fact clears its vector.
Local OpenAI-compatible servers that serve `/v1/embeddings` (for example LM Studio with an
embedding model loaded) are configured the same way with their own URL and model; this has
not been verified with a real service.
