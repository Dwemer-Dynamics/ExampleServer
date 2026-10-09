-- Connector audit: one row per voice, NPC selection, embedding or memory call. Safe to run
-- more than once. Separate from ex_events (conversation outcomes) and from history: nothing
-- here is ever given to the model. lib/audit.php keeps the newest event_log_limit rows.
--
-- Rows hold only ids, a fixed kind, the provider name from a fixed list, a status, a short
-- error code, the time taken and a short server-written detail. Never audio, transcripts,
-- prompts, provider bodies, URLs or keys.
--   status ok        the provider answered and the answer was used
--          failed    the call failed (error_code says why); a fallback was used if there is one
--          disabled  the feature is off in config; no provider was contacted
--          fallback  NPC selection returned the game's baseline without a provider failure
--          stale     memory work finished, but its turn was cancelled, replaced or restored
--                    away first, so nothing was saved
CREATE TABLE IF NOT EXISTS ex_connector_calls (
    id bigserial PRIMARY KEY,
    created_at timestamptz NOT NULL DEFAULT now(),
    kind text NOT NULL CHECK (kind IN ('tts', 'stt', 'decision', 'embedding', 'memory', 'extraction')),
    provider text CHECK (provider ~ '^[a-z0-9_]{1,32}$'),
    status text NOT NULL CHECK (status IN ('ok', 'failed', 'disabled', 'fallback', 'stale')),
    error_code text CHECK (error_code ~ '^[a-z_]{1,40}$'),
    duration_ms integer CHECK (duration_ms >= 0),
    request_id text CHECK (request_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    parent_request_id text CHECK (parent_request_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    session_id text CHECK (session_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    npc_id text CHECK (npc_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    detail text CHECK (char_length(detail) <= 200)
);
CREATE INDEX IF NOT EXISTS ex_connector_calls_request_idx ON ex_connector_calls (request_id, id);
CREATE INDEX IF NOT EXISTS ex_connector_calls_parent_idx ON ex_connector_calls (parent_request_id, id);
