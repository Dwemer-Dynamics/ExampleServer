<?php
// Loads the stored biography and a few matching knowledge facts for one turn.
// This file only defines functions, so requesting it over HTTP prints nothing.
// The result is reference data for the model, never instructions or commands.

const MAX_FACTS = 3;
const MAX_BIO_CHARS = 600;
const MAX_FACT_CHARS = 300;
const MAX_QUERY_WORDS = 8;

// Common words that would match almost every fact. Kept short on purpose.
const QUERY_STOPWORDS = ['about', 'does', 'from', 'have', 'know', 'that', 'tell', 'there', 'they',
    'this', 'what', 'when', 'where', 'which', 'with', 'would', 'could', 'your', 'please'];

// Turns player text into an OR search such as "market | open".
// Only letters and digits survive, so the search string cannot contain query syntax.
function knowledge_search_terms(string $text): string
{
    preg_match_all('/[\p{L}\p{N}]{4,32}/u', mb_strtolower($text), $matches);
    $words = array_diff(array_unique($matches[0]), QUERY_STOPWORDS);
    return implode(' | ', array_slice($words, 0, MAX_QUERY_WORDS));
}

// Square brackets are replaced so stored text can never form an [ACTION:...] tag.
function knowledge_clean(string $text, int $maxChars): string
{
    return mb_substr(strtr($text, '[]', '()'), 0, $maxChars);
}

// Returns ['bio' => ?array, 'facts' => list of ['topic', 'fact', 'scope']].
// Facts are scoped to this NPC or global, at most MAX_FACTS, best match first.
// Fails with 503 not_migrated if migration 002 has not been applied.
// $trace, if given, receives the search terms and, per returned fact, its id, how it matched
// ('vector' with its score, or 'keyword') for the event log's retrieval snapshot; the returned
// knowledge (and so the prompt) does not include them.
// $vectorHits are embedding_search() results (lib/embeddings.php). They come first; keyword
// matches fill any remaining places, so facts without a current vector are still found.
function load_knowledge(\PgSql\Connection $conn, string $npcId, string $playerText, ?array &$trace = null,
                        array $vectorHits = []): array
{
    $bio = @pg_query_params($conn, 'SELECT name, role, bio FROM ex_npc_bios WHERE npc_id = $1', [$npcId]);
    if ($bio === false) {
        app_log('knowledge query failed: ' . pg_last_error($conn));
        fail(503, 'not_migrated', 'The database has not been migrated.');
    }
    $row = pg_fetch_assoc($bio);
    $knowledge = ['bio' => null, 'facts' => []];
    if ($row !== false) {
        $knowledge['bio'] = [
            'name' => knowledge_clean($row['name'], 64),
            'role' => knowledge_clean($row['role'], 64),
            'bio' => knowledge_clean($row['bio'], MAX_BIO_CHARS),
        ];
    }

    $terms = knowledge_search_terms($playerText);
    $trace = ['terms' => $terms, 'facts' => []];
    $add = function (array $fact, bool $global, array $match) use (&$knowledge, &$trace): void {
        $trace['facts'][] = ['id' => (int)$fact['id']] + $match;
        $knowledge['facts'][] = [
            'topic' => knowledge_clean($fact['topic'], 64),
            'fact' => knowledge_clean($fact['fact'], MAX_FACT_CHARS),
            'scope' => $global ? 'global' : 'npc',
        ];
    };
    foreach (array_slice($vectorHits, 0, MAX_FACTS) as $hit) {
        $add($hit, $hit['global'], ['match' => 'vector', 'score' => $hit['score']]);
    }
    $left = MAX_FACTS - count($knowledge['facts']);
    if ($terms === '' || $left <= 0) {
        return $knowledge;
    }
    $facts = @pg_query_params($conn,
        "SELECT id, topic, fact, npc_id IS NULL AS global FROM ex_knowledge
         WHERE (npc_id = $1 OR npc_id IS NULL) AND search @@ to_tsquery('simple', $2) AND NOT (id = ANY ($4::bigint[]))
         ORDER BY ts_rank(search, to_tsquery('simple', $2)) DESC, npc_id IS NULL, id
         LIMIT $3",
        [$npcId, $terms, $left, '{' . implode(',', array_column($trace['facts'], 'id')) . '}']);
    if ($facts === false) {
        app_log('knowledge query failed: ' . pg_last_error($conn));
        fail(503, 'not_migrated', 'The database has not been migrated.');
    }
    foreach (pg_fetch_all($facts) as $fact) {
        $add($fact, $fact['global'] === 't', ['match' => 'keyword']);
    }
    return $knowledge;
}
