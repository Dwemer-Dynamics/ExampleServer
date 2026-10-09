-- NPC biographies, small knowledge facts and named session checkpoints.
-- Safe to run more than once. Adds no rows: sample data is opt-in through
-- scripts/seed_example.php.

-- One short biography per stable NPC id (the same id the game sends as npc.id).
CREATE TABLE IF NOT EXISTS ex_npc_bios (
    npc_id text PRIMARY KEY CHECK (npc_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 64),
    role text NOT NULL DEFAULT '' CHECK (char_length(role) <= 64),
    bio text NOT NULL CHECK (char_length(bio) BETWEEN 1 AND 1000),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Small facts. npc_id NULL means the fact is global (any NPC may use it).
-- The search column is filled by PostgreSQL from topic and fact.
CREATE TABLE IF NOT EXISTS ex_knowledge (
    id bigserial PRIMARY KEY,
    npc_id text CHECK (npc_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    topic text NOT NULL CHECK (char_length(topic) BETWEEN 1 AND 64),
    fact text NOT NULL CHECK (char_length(fact) BETWEEN 1 AND 300),
    search tsvector GENERATED ALWAYS AS (to_tsvector('simple', topic || ' ' || fact)) STORED,
    created_at timestamptz NOT NULL DEFAULT now()
);
-- One fact per topic in each scope, so the seed script can use ON CONFLICT DO NOTHING.
CREATE UNIQUE INDEX IF NOT EXISTS ex_knowledge_topic_idx ON ex_knowledge (coalesce(npc_id, ''), lower(topic));
CREATE INDEX IF NOT EXISTS ex_knowledge_search_idx ON ex_knowledge USING gin (search);

-- Named copies of one session's conversation lines, made by scripts/checkpoint.php.
-- turns is a JSON array of {npc_id, role, text, created_at}, oldest first.
-- History pruning in turn.php never touches these copies.
CREATE TABLE IF NOT EXISTS ex_checkpoints (
    id bigserial PRIMARY KEY,
    session_id text NOT NULL,
    name text NOT NULL,
    turn_count integer NOT NULL,
    turns jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (session_id, name)
);
