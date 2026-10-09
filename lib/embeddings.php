<?php
// Optional embedding vectors for shared facts (ex_knowledge.embedding, migration 006).
// Off by default (embeddings.enabled). Used by the turn pipeline and the Memory settings page.
// This file only defines functions and constants, so requesting it over HTTP prints nothing.
//
// - provider 'openai': any OpenAI-compatible POST /v1/embeddings at the configured URL, with
//   the key from the API keys page. No redirects, a time limit, a size cap and no retry.
// - provider 'mock': deterministic hashed word counts. It needs no service and is NOT
//   semantic: it only finds facts that share words, which is enough to test the pipeline.
// - Every vector is checked (a list of 1-4096 finite numbers, the configured size, non-zero
//   length) and stored at unit length, so cosine similarity is a dot product.
// - A turn compares at most EMBEDDING_SCAN_LIMIT stored vectors (this NPC's and global facts,
//   newest first). Facts beyond that, facts without a current vector, and any embedding
//   failure fall back to the keyword search in lib/knowledge.php.

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/knowledge.php';

const EMBEDDING_PROVIDERS = ['mock' => 'Mock (hashed words, not semantic; no service)', 'openai' => 'OpenAI-compatible /v1/embeddings'];
const EMBEDDING_DEFAULT_URL = 'https://api.openai.com/v1/embeddings';
const EMBEDDING_DEFAULT_MODEL = 'text-embedding-3-small';
const EMBEDDING_MOCK_DIMS = 64;
const EMBEDDING_MAX_DIMS = 4096;
const EMBEDDING_BATCH = 16;              // texts per provider call
const EMBEDDING_REINDEX_LIMIT = 64;      // facts per Reindex press
const EMBEDDING_SCAN_LIMIT = 200;        // stored vectors compared per turn
const EMBEDDING_MIN_SCORE = 0.35;        // cosine similarity a fact needs to count as a match
const EMBEDDING_MAX_RESPONSE_BYTES = 4000000;
const EMBEDDING_MAX_INPUT_CHARS = 1000;

// The message is a short error code that is safe to store in the connector audit.
final class EmbeddingError extends RuntimeException
{
}

// The embeddings settings when they are switched on, otherwise null.
function embedding_settings(array $config): ?array
{
    $settings = $config['embeddings'] ?? null;
    return is_array($settings) && !empty($settings['enabled']) ? $settings : null;
}

// Vectors belong to one endpoint, full model name and requested size. Changing any of them
// makes the old vectors ineligible for retrieval and Reindex selects those facts again.
// The key is excluded: rotating a credential does not change the embedding space.
function embedding_model_id(array $settings): string
{
    return ($settings['provider'] ?? 'mock') === 'mock' ? 'mock:hash-' . EMBEDDING_MOCK_DIMS
        : 'openai:' . hash('sha256', json_encode([
            (string)($settings['url'] ?? ''), (string)($settings['model'] ?? ''), (int)($settings['dims'] ?? 0),
        ], JSON_UNESCAPED_SLASHES));
}

// A unit-length copy of $vector, or null unless it is a list of 1-4096 finite numbers (exactly
// $dims of them when $dims > 0) with a non-zero length.
function embedding_validate(mixed $vector, int $dims = 0): ?array
{
    if (!is_array($vector) || !array_is_list($vector) || count($vector) < 1 || count($vector) > EMBEDDING_MAX_DIMS
        || ($dims > 0 && count($vector) !== $dims)) {
        return null;
    }
    $sum = 0.0;
    foreach ($vector as $value) {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
            return null;
        }
        $sum += $value * $value;
    }
    $norm = sqrt($sum);
    if (!is_finite($norm) || $norm < 1e-9) {
        return null;
    }
    return array_map(fn($value) => round($value / $norm, 6), $vector);
}

// Mock vector: each word of 3+ letters (minus common words) adds +1 or -1 to one of 64 slots
// chosen by its hash. Shared words give similar vectors; synonyms do not. Null for no words.
function embedding_mock(string $text): ?array
{
    preg_match_all('/[\p{L}\p{N}]{3,32}/u', mb_strtolower($text), $matches);
    $vector = array_fill(0, EMBEDDING_MOCK_DIMS, 0);
    foreach (array_diff($matches[0], QUERY_STOPWORDS) as $word) {
        $hash = crc32($word);
        $vector[$hash % EMBEDDING_MOCK_DIMS] += ($hash >> 16) & 1 ? 1 : -1;
    }
    return embedding_validate($vector, EMBEDDING_MOCK_DIMS);
}

// One unit vector per text, in order. Throws EmbeddingError with a short code on any problem;
// never retries and never logs the texts, the URL or the provider's reply.
function embed_texts(array $settings, array $texts): array
{
    if (!$texts || count($texts) > EMBEDDING_BATCH) {
        throw new EmbeddingError('bad_input');
    }
    $texts = array_map(fn($text) => mb_substr((string)$text, 0, EMBEDDING_MAX_INPUT_CHARS), array_values($texts));
    $provider = $settings['provider'] ?? 'mock';
    if ($provider === 'mock') {
        return array_map(fn($text) => embedding_mock($text) ?? throw new EmbeddingError('no_words'), $texts);
    }
    if ($provider !== 'openai') {
        throw new EmbeddingError('unknown_provider');
    }
    $model = (string)($settings['model'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.:\/@+-]{1,200}$/D', $model)) {
        throw new EmbeddingError('model_missing');
    }
    $url = (string)($settings['url'] ?? '') ?: EMBEDDING_DEFAULT_URL;
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
        throw new EmbeddingError('bad_url');
    }
    $dims = max(0, min(EMBEDDING_MAX_DIMS, (int)($settings['dims'] ?? 0)));
    $headers = ['Content-Type: application/json'];
    $key = (string)($settings['api_key'] ?? '');
    if ($key !== '') {
        $headers[] = "Authorization: Bearer $key";
    }
    $body = json_encode(['model' => $model, 'input' => $texts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        [$status, $response] = http_post($url, $headers, $body, max(1, min(30, (int)($settings['timeout_seconds'] ?? 5))),
            EMBEDDING_MAX_RESPONSE_BYTES);
    } catch (Throwable $error) {
        app_log('embedding request failed: ' . $error->getMessage());
        throw new EmbeddingError('request_failed');
    }
    if ($status !== 200) {
        app_log("embedding provider returned HTTP $status");
        throw new EmbeddingError('http_error');
    }
    $data = json_decode($response, true, 8);
    $items = is_array($data) ? ($data['data'] ?? null) : null;
    if (!is_array($items) || !array_is_list($items) || count($items) !== count($texts)) {
        throw new EmbeddingError('bad_response');
    }
    $vectors = [];
    foreach ($items as $position => $item) {
        $index = is_array($item) ? ($item['index'] ?? $position) : null;
        if (!is_int($index) || $index < 0 || $index >= count($texts) || isset($vectors[$index])) {
            throw new EmbeddingError('bad_response');
        }
        $vector = embedding_validate($item['embedding'] ?? null, $dims);
        if ($vector === null || ($vectors && count($vector) !== count(reset($vectors)))) {
            throw new EmbeddingError($dims > 0 ? 'bad_vector_or_dims' : 'bad_vector');
        }
        $vectors[$index] = $vector;
    }
    ksort($vectors);
    return $vectors;
}

// The best stored facts for $query (a unit vector): this NPC's and global facts embedded with
// $modelId, at most EMBEDDING_SCAN_LIMIT of them, newest first. Returns at most $limit rows
// ['id', 'topic', 'fact', 'global', 'score'] with score >= EMBEDDING_MIN_SCORE, best first.
// A stored vector that does not decode to the query's size is skipped.
function embedding_search(\PgSql\Connection $db, string $npcId, array $query, string $modelId, int $limit): array
{
    $result = db_query($db,
        'SELECT id, topic, fact, npc_id IS NULL AS global, embedding::text AS embedding FROM ex_knowledge
         WHERE (npc_id = $1 OR npc_id IS NULL) AND embedding_model = $2 AND embedding IS NOT NULL
         ORDER BY id DESC LIMIT $3',
        [$npcId, $modelId, EMBEDDING_SCAN_LIMIT]);
    $hits = [];
    foreach (pg_fetch_all($result) as $row) {
        $vector = embedding_validate(json_decode($row['embedding'], true, 3), count($query));
        if ($vector === null) {
            continue;
        }
        $score = 0.0;
        foreach ($query as $i => $value) {
            $score += $value * $vector[$i];
        }
        if ($score >= EMBEDDING_MIN_SCORE) {
            $hits[] = ['id' => (int)$row['id'], 'topic' => $row['topic'], 'fact' => $row['fact'],
                'global' => $row['global'] === 't', 'score' => round($score, 3)];
        }
    }
    usort($hits, fn($a, $b) => [$b['score'], $a['global'], $a['id']] <=> [$a['score'], $b['global'], $b['id']]);
    return array_slice($hits, 0, $limit);
}

// How many facts have a vector for $modelId, and how many need one.
function embedding_stats(\PgSql\Connection $db, string $modelId): ?array
{
    $result = @pg_query_params($db,
        'SELECT count(*), count(*) FILTER (WHERE embedding IS NOT NULL AND embedding_model = $1) FROM ex_knowledge', [$modelId]);
    if ($result === false) {
        return null;
    }
    [$total, $indexed] = array_map('intval', pg_fetch_row($result));
    return ['total' => $total, 'indexed' => $indexed, 'missing' => $total - $indexed];
}

// Embeds up to EMBEDDING_REINDEX_LIMIT facts that have no vector for the current model, in
// batches, outside any transaction. Each vector is written only if the fact's text is still
// exactly what was embedded (an edit meanwhile clears it instead). Stops at the first failure.
// $audit receives one connector-audit row per batch. Returns counts and the error code, if any.
function embedding_reindex(\PgSql\Connection $db, array $settings, callable $audit): array
{
    $modelId = embedding_model_id($settings);
    $rows = pg_fetch_all(db_query($db,
        'SELECT id, topic, fact FROM ex_knowledge WHERE embedding IS NULL OR embedding_model IS DISTINCT FROM $1
         ORDER BY id LIMIT $2',
        [$modelId, EMBEDDING_REINDEX_LIMIT]));
    $done = ['indexed' => 0, 'changed' => 0, 'error' => null];
    foreach (array_chunk($rows, EMBEDDING_BATCH) as $batch) {
        $started = microtime(true);
        try {
            $vectors = embed_texts($settings, array_map(fn($row) => $row['topic'] . ': ' . $row['fact'], $batch));
        } catch (EmbeddingError $error) {
            $done['error'] = $error->getMessage();
            $audit(['status' => 'failed', 'error_code' => $done['error'], 'duration_ms' => event_duration_ms($started),
                'detail' => 'reindex batch of ' . count($batch) . ' facts']);
            break;
        }
        foreach ($batch as $i => $row) {
            $saved = db_query($db,
                'UPDATE ex_knowledge SET embedding = $2::jsonb, embedding_model = $3 WHERE id = $1 AND topic = $4 AND fact = $5',
                [$row['id'], json_encode($vectors[$i]), $modelId, $row['topic'], $row['fact']]);
            $done[pg_affected_rows($saved) === 1 ? 'indexed' : 'changed']++;
        }
        $audit(['status' => 'ok', 'duration_ms' => event_duration_ms($started), 'detail' => 'reindex batch of ' . count($batch) . ' facts']);
    }
    return $done;
}
