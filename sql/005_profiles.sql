-- Character profiles and which NPC uses which profile. Safe to run more than once; adds no
-- rows, so existing NPCs keep answering exactly as before until a profile is assigned or made
-- the default.
--
-- A profile has a name, a system prompt and optional overrides. NULL means "inherit the
-- private config". Profiles never hold URLs or API keys: the only LLM modes a profile may
-- switch to (mock, dwemerllm) need neither, and a model override keeps the configured
-- provider's URL and key (lib/profiles.php).
CREATE TABLE IF NOT EXISTS ex_profiles (
    id bigserial PRIMARY KEY,
    name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 64),
    system_prompt text NOT NULL DEFAULT '' CHECK (char_length(system_prompt) <= 2000),
    llm_mode text CHECK (llm_mode IN ('mock', 'dwemerllm')),
    llm_model text CHECK (llm_model ~ '^[A-Za-z0-9_.:/@+-]{1,200}$'),
    tts_voice text CHECK (tts_voice ~ '^[A-Za-z0-9_.:/@+-]{1,64}$'),
    is_default boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    -- dwemerllm serves whatever model is loaded; the configured model belongs to another mode.
    CHECK (llm_mode IS DISTINCT FROM 'dwemerllm' OR llm_model IS NOT NULL)
);
CREATE UNIQUE INDEX IF NOT EXISTS ex_profiles_name_idx ON ex_profiles (lower(name));
-- At most one default profile. NPCs without their own assignment use it; there is none until
-- you choose one.
CREATE UNIQUE INDEX IF NOT EXISTS ex_profiles_default_idx ON ex_profiles (is_default) WHERE is_default;

-- One profile per NPC id (the same id the game sends as npc.id). Deleting a profile removes
-- its assignments; those NPCs then use the default profile, or the config.
CREATE TABLE IF NOT EXISTS ex_npc_profiles (
    npc_id text PRIMARY KEY CHECK (npc_id ~ '^[A-Za-z0-9_.:-]{1,64}$'),
    profile_id bigint NOT NULL REFERENCES ex_profiles (id) ON DELETE CASCADE,
    assigned_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ex_npc_profiles_profile_idx ON ex_npc_profiles (profile_id, npc_id);
