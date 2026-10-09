-- Advanced memory (optional; memory.enabled and embeddings.enabled are off by default).
-- Safe to run more than once; keeps every existing row and adds none.
--
-- ex_memory_notes: one summary and a short diary per session and NPC. They are derived from
-- that session's conversation, so checkpoints copy them and a restore puts the copy back.
-- source: manual (typed in the dashboard), generated (the configured model) or mock (the
-- predictable mock, not a model).
CREATE TABLE IF NOT EXISTS ex_memory_notes (
    id bigserial PRIMARY KEY,
    session_id text NOT NULL CHECK (session_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    npc_id text NOT NULL CHECK (npc_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    kind text NOT NULL CHECK (kind IN ('summary', 'diary')),
    text text NOT NULL CHECK (char_length(text) BETWEEN 1 AND 1000),
    source text NOT NULL CHECK (source IN ('manual', 'generated', 'mock')),
    source_request_id text CHECK (source_request_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ex_memory_notes_summary_idx ON ex_memory_notes (session_id, npc_id) WHERE kind = 'summary';
CREATE INDEX IF NOT EXISTS ex_memory_notes_idx ON ex_memory_notes (session_id, npc_id, kind, id);

-- ex_memory_candidates: facts the model proposed from one successful exchange. They are never
-- given to the model until a person approves them on the Memory page, which copies them into
-- ex_knowledge with their provenance. A checkpoint restore deletes the session's candidates
-- made after that checkpoint.
CREATE TABLE IF NOT EXISTS ex_memory_candidates (
    id bigserial PRIMARY KEY,
    session_id text NOT NULL CHECK (session_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    npc_id text NOT NULL CHECK (npc_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    topic text NOT NULL CHECK (char_length(topic) BETWEEN 1 AND 64),
    fact text NOT NULL CHECK (char_length(fact) BETWEEN 1 AND 300),
    source text NOT NULL CHECK (source IN ('generated', 'mock')),
    source_request_id text CHECK (source_request_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ex_memory_candidates_npc_idx ON ex_memory_candidates (npc_id, id);
CREATE INDEX IF NOT EXISTS ex_memory_candidates_session_idx ON ex_memory_candidates (session_id, created_at);

-- Provenance on shared facts: manual (dashboard, seed) or extracted (an approved candidate,
-- with the session and request it came from). Existing facts are manual.
ALTER TABLE ex_knowledge ADD COLUMN IF NOT EXISTS source text NOT NULL DEFAULT 'manual'
    CHECK (source IN ('manual', 'extracted'));
ALTER TABLE ex_knowledge ADD COLUMN IF NOT EXISTS source_session_id text
    CHECK (source_session_id ~ '^[A-Za-z0-9_.:-]{1,64}$');
ALTER TABLE ex_knowledge ADD COLUMN IF NOT EXISTS source_request_id text
    CHECK (source_request_id ~ '^[A-Za-z0-9_.:-]{1,64}$');

-- Optional embedding of "topic: fact": a JSON array of 1-4096 unit-length numbers, and the
-- provider:model id that made it. Only vectors with the configured id are searched.
ALTER TABLE ex_knowledge ADD COLUMN IF NOT EXISTS embedding jsonb
    CHECK (embedding IS NULL OR (CASE WHEN jsonb_typeof(embedding) = 'array'
        THEN jsonb_array_length(embedding) BETWEEN 1 AND 4096 AND char_length(embedding::text) <= 100000
        ELSE false END));
ALTER TABLE ex_knowledge ADD COLUMN IF NOT EXISTS embedding_model text
    CHECK (char_length(embedding_model) BETWEEN 1 AND 120);

-- Any change to a fact's topic or text makes its vector stale, whatever code made the change.
CREATE OR REPLACE FUNCTION ex_knowledge_clear_embedding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.topic IS DISTINCT FROM OLD.topic OR NEW.fact IS DISTINCT FROM OLD.fact THEN
        NEW.embedding := NULL;
        NEW.embedding_model := NULL;
    END IF;
    RETURN NEW;
END
$$;
DROP TRIGGER IF EXISTS ex_knowledge_clear_embedding ON ex_knowledge;
CREATE TRIGGER ex_knowledge_clear_embedding BEFORE UPDATE ON ex_knowledge
    FOR EACH ROW EXECUTE FUNCTION ex_knowledge_clear_embedding();

-- A checkpoint also copies the session's memory notes: a JSON array of
-- {npc_id, kind, text, source, source_request_id, created_at}. NULL on checkpoints made before
-- this migration; restoring one of those clears the session's notes instead.
ALTER TABLE ex_checkpoints ADD COLUMN IF NOT EXISTS memory jsonb
    CHECK (memory IS NULL OR jsonb_typeof(memory) = 'array');
