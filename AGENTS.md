# AGENTS.md

Guidance for coding agents working on this example server, or on a project copied from it.

## Keep it simple

- Plain PHP 8.2 with built-in extensions. Do not add Composer, frameworks, background
  workers, queues or embeddings unless the user asks.
- One small file per endpoint at the top level; shared helpers in `lib/`. Library files
  only define functions and constants, so requesting them over HTTP prints nothing.
- Prefer clear, explicit code over abstractions.

## Stable boundaries

- **Protocol** (`PROTOCOL.md`): JSON with `"protocol": 1`. Adding optional fields is fine.
  Anything a version-1 client would misread needs a protocol bump in both this repo and
  the client.
- **Actions**: the model may only request actions with `[ACTION:name]` tags. The server
  returns only names in `allowed_actions`; the client checks its own allowlist and has one
  explicit handler per action. To add an action, update the prompt in `lib/llm.php`, the
  config allowlist, `PROTOCOL.md`, and the client handler together. Never execute model
  output as commands, SQL, paths or URLs.
- **Decisions**: `decision.php` only returns one of the ids the game offered, or the
  baseline on any failure. Keep it free of history, chat, memory and actions, and keep its
  provider call short (`DECISION_TIMEOUT_MS`), size-capped and without redirects. Its only
  database write is one connector-audit row sent after the reply (`reply_then()`), so audit
  logging never delays or changes a decision.
- **Profiles and memory**: profiles never store URLs or keys; a profile may only switch to a
  keyless mode (`lib/profiles.php`). Generated summaries, diaries and candidate facts are
  untrusted data: cleaned, size-capped, sent to the model only as quoted reference data, and
  candidates only after a person approves them. Memory writes from a turn go through
  `memory_write_if_current()` (session lock, then the generation row) and model calls stay
  outside transactions with no retry. Checkpoints copy and restore memory notes with the lines.
- **Tracing**: log with `app_log()` (it adds the request id); never log keys, prompts,
  transcripts, audio or provider bodies, and keep those out of `ex_connector_calls`.
- **Trust**: everything from the game is untrusted. Validate with `require_id()` /
  `require_text()` and keep the size limits. Keep the token check on every endpoint except
  `health.php`.
- **Game events**: `event.php` with `respond: false` only logs (no LLM, history or actions);
  `respond: true` must keep using `run_turn()` in `lib/turn.php`. No automatic triggers,
  queues or background work. Action targets come from the request's `npc.id`, never from
  model text or `decision.php`.
- **Stale replies**: a turn is saved only if its generation is still current
  (`next_generation()` + the `FOR UPDATE` check in `lib/turn.php`). Keep this when changing turns.
  `lock_session()` is taken first in generation starts, the final turn save and checkpoint
  restore; keep that order (session lock, then row locks) and keep LLM calls outside it.
- **Database**: only this example's own database. Every schema change is a new
  `sql/NNN_name.sql` that is safe to run twice (`IF NOT EXISTS`) and works on fresh installs
  and upgrades; append its name to `SCHEMA_MIGRATIONS` in `lib/app.php`. Never edit an applied migration. Use `db_query()` with parameters. Keep
  transactions database-only: no HTTP or LLM calls inside them.
- **Config and secrets**: settings live in untracked `config/config.php`. Add new settings
  to `config/config.example.php` with a safe default. Optional features (LLM key, TTS, STT)
  must fail cleanly with a clear error and never pretend to succeed.
- **Voice**: optional, off by default; text and mock come first. If the user wants voice,
  start with PocketTTS for TTS and Parakeet for STT (the recommended DwemerDistro choices)
  before the supported alternatives. Do not assume either is installed or running: within
  the scope the user has authorized, verify the service is available and running before
  enabling or testing voice, then confirm with the dashboard Voice page test.
- **Installers**: `install.sh` must stay safe to repeat, copy config only when missing,
  and change nothing outside this app's folder and database. `update.sh` must keep
  refusing dirty checkouts, back up first, and only fast-forward. Never add
  `git reset --hard`, `git clean`, or downloading and running remote scripts.
- **Web root**: do not rely on `.htaccess`. Keep config in PHP, never put secrets in
  non-PHP files inside the checkout, and verify config access on the installation.

## Checks before finishing

```bash
for f in *.php lib/*.php scripts/*.php config/*.php; do php -l "$f"; done
for f in scripts/*.sh; do bash -n "$f"; done
php scripts/migrate.php            # twice: second run must apply nothing
bash scripts/smoke.sh [base_url]   # against a mock-mode install
```

For cancellation, set `mock_delay_seconds` to 2 or more and run
`SMOKE_STALE=1 bash scripts/smoke.sh`. Report which checks ran and what was not tested
(real LLM, voice services, the client).
