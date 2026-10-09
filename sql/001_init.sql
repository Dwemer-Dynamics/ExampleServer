-- Initial tables. Safe to run more than once.

-- Recent conversation lines, kept short per session and NPC.
CREATE TABLE IF NOT EXISTS ex_turns (
    id bigserial PRIMARY KEY,
    session_id text NOT NULL,
    npc_id text NOT NULL,
    role text NOT NULL CHECK (role IN ('player', 'npc')),
    text text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ex_turns_npc_idx ON ex_turns (session_id, npc_id, id);

-- Current generation per session and NPC. A turn whose generation is no longer
-- current (because of a newer turn or a cancel) is stale and is not saved.
CREATE TABLE IF NOT EXISTS ex_generations (
    session_id text NOT NULL,
    npc_id text NOT NULL,
    generation bigint NOT NULL,
    PRIMARY KEY (session_id, npc_id)
);

-- Request ids seen in the last day, so a repeated request is refused.
CREATE TABLE IF NOT EXISTS ex_requests (
    request_id text PRIMARY KEY,
    session_id text NOT NULL,
    npc_id text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ex_requests_created_idx ON ex_requests (created_at);
