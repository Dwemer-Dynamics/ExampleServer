-- Event log: one row per finished turn, cancel or checkpoint restore. Safe to run more
-- than once. Independent of ex_turns: history pruning and checkpoint restores never touch
-- it. lib/events.php keeps the newest event_log_limit rows (default 10000) by id.
--
-- status is what the SERVER finished, never what the game did:
--   complete    the server finished (for a turn: the reply was prepared and saved;
--               not proof the game received or used it)
--   failed      the server refused or could not finish (error_code says why)
--   cancelled   a stored cancel event proves a cancel made this turn stale
--   superseded  this turn's generation was invalidated; exact cause unknown (a newer
--               turn, a restore, or a cancel whose event was not stored)
-- client_report is what the game client later said it did with the returned actions
-- (result.php). It is untrusted client input, not a verified game state.
CREATE TABLE IF NOT EXISTS ex_events (
    id bigserial PRIMARY KEY,
    created_at timestamptz NOT NULL DEFAULT now(),
    kind text NOT NULL CHECK (kind IN ('turn', 'cancel', 'restore')),
    status text NOT NULL CHECK (status IN ('complete', 'failed', 'cancelled', 'superseded')),
    request_id text CHECK (request_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    session_id text NOT NULL CHECK (session_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    npc_id text CHECK (npc_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    generation bigint,
    error_code text CHECK (error_code ~ '^[a-z_]{1,40}$'),
    provider text CHECK (char_length(provider) <= 32),
    duration_ms integer CHECK (duration_ms >= 0),
    player_text text CHECK (char_length(player_text) <= 1000),
    reply text CHECK (char_length(reply) <= 1000),
    actions jsonb NOT NULL DEFAULT '[]',
    rejected_actions jsonb NOT NULL DEFAULT '[]',
    detail text CHECK (char_length(detail) <= 200),
    client_report jsonb,
    client_reported_at timestamptz,
    context jsonb CHECK (jsonb_typeof(context) = 'object' AND char_length(context::text) <= 5000)
);
-- context: the turn's validated game context (at most 10 keys of a-z and _ up to 32
-- characters, values up to 200), NULL when none was sent. This line upgrades a database
-- that ran an earlier, unreleased draft of this migration; elsewhere it does nothing.
ALTER TABLE ex_events ADD COLUMN IF NOT EXISTS context jsonb
    CHECK (jsonb_typeof(context) = 'object' AND char_length(context::text) <= 5000);
CREATE INDEX IF NOT EXISTS ex_events_session_idx ON ex_events (session_id, id);
CREATE INDEX IF NOT EXISTS ex_events_request_idx ON ex_events (request_id, id);
