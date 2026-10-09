# Start here

**ExampleServer** (PHP 8.2 + PostgreSQL, runs inside DwemerDistro WSL) and **ExampleMod** (a
C++17 Windows console "fake game") are two small templates. Use them, with a coding agent,
to build the server and client of your own AI NPC mod. This page gets you oriented, shows
what works today, walks one request from start to finish, and tells you where each detail
is documented.

What these templates are not:

- **Not a plugin for any real game.** ExampleMod has no engine SDK and no game hooks. A
  real mod replaces its console `GameAdapter`. No game is supported until someone builds
  and tests a port in that game.
- **Not part of CHIM, Stobe or Dialectic.** These templates are independent repositories
  with no shared Git history and no automatic upstream from those products. Only the
  dashboard's presentation is adapted from HerikaServer, and it keeps its MIT notice
  ([THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)). Port anything else by hand.
- **Not a hardened online service.** It is meant for loopback use inside DwemerDistro WSL
  (see [Deployment and exposure](#deployment-and-exposure)).

The two repositories are separate:

- Server: <https://github.com/Dwemer-Dynamics/ExampleServer>
- Client: <https://github.com/Dwemer-Dynamics/ExampleMod>

Both have a `main` branch (default, the stable baseline) and a `dev` branch (development).

## Which document answers what

Each topic has one canonical document. Other pages link to it instead of repeating it.

| Need | Canonical document |
|---|---|
| Orientation, status, walkthrough, troubleshooting by request ID, server files, event log, porting checklist | This page |
| Short overview, quick start and limits | [README.md](README.md) |
| Install, permissions, pairing, LLM and voice setup, backups, updates, versions, migrations, checkpoints, removal, troubleshooting by symptom | [SETUP.md](SETUP.md) |
| Copyable LLM, voice, NPC selection, profile and memory/embedding settings | [CONNECTORS.md](CONNECTORS.md) |
| The request and reply contract (identical in both repositories) | [PROTOCOL.md](PROTOCOL.md) |
| Dashboard pages, guided dashboard tour, adding pages | [UI_GUIDE.md](UI_GUIDE.md) |
| Installing from the DwemerDistro launcher, `main`/`dev` branches | [LAUNCHER_CUSTOM_MOD.md](LAUNCHER_CUSTOM_MOD.md) |
| Renaming and customising your copies | [MAKE_IT_YOURS.md](MAKE_IT_YOURS.md) |
| Adding a game action (worked `wave` exercise) | [WAVE_ACTION.md](WAVE_ACTION.md) |
| Rules for coding agents | [AGENTS.md](AGENTS.md), [ExampleMod AGENTS.md](https://github.com/Dwemer-Dynamics/ExampleMod/blob/main/AGENTS.md) |
| Client commands, settings and smoke checks | [ExampleMod README](https://github.com/Dwemer-Dynamics/ExampleMod/blob/main/README.md) |
| Prompt for porting to your engine with an agent | [AGENT_PLAYBOOK.md](https://github.com/Dwemer-Dynamics/ExampleMod/blob/main/AGENT_PLAYBOOK.md) |
| Licence and third-party notices | [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) |

## Before you start

Requirements are in [SETUP.md, Requirements](SETUP.md#requirements). In short: DwemerDistro
installed and started (WSL, Apache with the shared custom mods port 19000, PostgreSQL,
PHP 8.2) and Visual Studio
2022 with "Desktop development with C++". Nothing else is needed for the first run.

Everything starts in **mock mode**, so the first run needs no provider, key or service:

| Setting | Shipped default | Where |
|---|---|---|
| `llm.mode` | `mock`: predictable replies, no network | `config/config.php` |
| `tts.enabled`, `stt.enabled` | `false` (PocketTTS and Parakeet preselected) | `config/config.php` |
| `decision.enabled` | `false`: always answers with the baseline NPC | `config/config.php` |
| `allowed_actions` | `["follow_player"]` | server config and client `config.default.json` |
| `history_limit` | `10` lines per session + NPC | `config/config.php` |
| `event_log_limit` | `10000` rows | `config/config.php` |
| `voice_enabled` | `false` | client `config.default.json` (override in `config.json`) |
| `session_id` | `demo-save-1` (the dashboard tester uses `dashboard-demo`) | client `config.default.json` |
| Database | `example_ai_mod` | `config/config.php` (`database.name`) |

Private files are never committed: the server's `config/config.php` (token, database
password, API keys) and the client's `config.json` (server URL and token). Both are
git-ignored. Shipped defaults live in `config/config.example.php` and `config.default.json`.

### Deployment and exposure

The intended setup is one PC: Apache inside DwemerDistro WSL serves the server on
`http://127.0.0.1:19000/ExampleServer`, and the client runs on the same Windows machine.
Port 19000 is DwemerDistro's shared loopback port for all custom mods (`CUSTOM_MODS_PORT`,
19000-19999, set up by `sudo ddistro_custom_mod setup-web`); if you change it, run
`setup-web` again and update the client's `server_url`. Keep it on loopback or a trusted
local network:

- The dashboard has **no login** and there is **no rate limiting**. Anyone who can reach
  the dashboard can change settings and API keys, restore checkpoints and create backups.
- The token travels over **plain HTTP**.
- The checkout lives **inside Apache's web root**. When Apache runs PHP correctly, the
  private `config/config.php` only returns an array and `lib/` files only define functions,
  so requesting them prints nothing; endpoints and `health.php` do print their responses.
  If PHP is not enabled for the folder, Apache may serve PHP source as text, which is why
  `scripts/smoke.sh` checks the config. Nothing in this repository stops Apache from serving
  other files in the folder: the `.git/` folder (the full committed history),
  Markdown, `sql/` and `scripts/`. Whether they are served depends on your Apache
  configuration. `scripts/smoke.sh` checks only that the token is not visible at
  `config/config.php`; it does not check `.git/`. Check your install:

  ```bash
  curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:19000/ExampleServer/.git/HEAD
  ```

  `200` means anyone who can reach that port can download your committed history.
  Never commit secrets, and do not expose port 19000, or port 8081 (the official web
  port, which also serves `/var/www/html`), beyond a trusted network. These
  templates do not provide a secure online deployment.

What the server does enforce:

- Mod API requests except `health.php` need the token from `config/config.php`.
- Every dashboard change is a CSRF-checked POST. Its fixed bridge (turn, cancel, event,
  speak, listen, decision) adds the API token in PHP; keys are never sent to the browser,
  stored in the database or written to logs.
- Request bodies, text, context, history and replies have fixed size limits.
- All SQL uses parameters. Errors sent to the game are generic; details go to the Apache
  log.
- The model can only *ask* for actions. The server returns only allowlisted names, and the
  game checks again. Nothing runs commands.

## Feature and status checklist

"Working" means the current source implements it end to end. `scripts/smoke.sh` covers
only the mock turn, event, result, cancel and request-id paths. The client smoke checks add the console
behaviour. Checkpoints, backups, migrations and the dashboard are checked by hand (steps
below). "Working" never means tested in a real game or with a real provider.

| Feature | Status | Default | Details |
|---|---|---|---|
| Player line to NPC reply (`turn.php`) | Working (mock) | on | [PROTOCOL](PROTOCOL.md#post-turnphp) |
| Real LLM: `openai`, `openrouter`, `local`, `dwemerllm` | Code path present; needs your model (and key where required); not covered by smoke checks | off (mock) | [CONNECTORS](CONNECTORS.md#llm-llmmode) |
| Game events, log only or NPC responds (`event.php`) | Working | log only | [PROTOCOL](PROTOCOL.md#post-eventphp) |
| NPC bios and knowledge facts | Working. Keyword match with PostgreSQL full-text search (`simple`), at most 3 facts | empty until you add or seed | [Walkthrough 5](#5-npc-bios-and-knowledge) |
| Profiles: per-NPC system prompt, model / keyless mode / TTS voice overrides, default profile | Working (mock); real model and voice overrides use your providers | none (config only) | [Walkthrough 13](#13-profiles-memory-and-vector-search-optional) |
| Advanced memory: per-session summary and diary, reviewable candidate facts with provenance | Working with mock notes; real-model notes need your provider | off | [SETUP 5b](SETUP.md#5b-profiles-memory-and-tracing-optional) |
| Vector search over facts (embeddings stored as JSON, cosine, keyword fallback) | Mock vectors working (hashed words, not semantic); OpenAI-compatible provider code present, not verified with a real service | off | [CONNECTORS](CONNECTORS.md#memory-and-vector-search-optional) |
| Request ids for voice and NPC selection, connector audit (Control Panel > Connector calls) | Working; client prints every id | on | [PROTOCOL](PROTOCOL.md#optional-request-ids-tracing) |
| Conversation history per session + NPC | Working | 10 lines | [PROTOCOL](PROTOCOL.md#post-turnphp) |
| Session checkpoints (CLI and dashboard) | Working | none | [SETUP 6](SETUP.md#bios-knowledge-and-session-checkpoints) |
| Full backups (`backup.sh`) and dashboard database backups with verify restore | Working | none | [SETUP 6](SETUP.md#6-back-up-and-update) |
| Cancel and stale-reply protection | Working | on | [PROTOCOL](PROTOCOL.md#how-stale-replies-are-prevented) |
| Actions | `follow_player` only. `wave` is an exercise, not enabled | `follow_player` | [WAVE_ACTION](WAVE_ACTION.md) |
| Client result reports (`result.php`) | Working; untrusted client claims | on in the client | [PROTOCOL](PROTOCOL.md#optional-post-resultphp) |
| NPC selection (`decision.php`, mock or OpenRouter JEV) | Mock working; JEV needs a key, not covered by smoke checks | off | [CONNECTORS](CONNECTORS.md#npc-selection-decision-optional) |
| Text to speech: PocketTTS (audio.cpp, 8086, recommended start) or OpenAI-compatible WAV | Code path present; opt-in; services installed separately; real audio not verified by these docs | off | [CONNECTORS](CONNECTORS.md#voice) |
| Speech to text: Parakeet (8022, default, recommended start) or faster-whisper (9876) | As above | off | [CONNECTORS](CONNECTORS.md#voice) |
| Dashboard working pages | Working: Home, Conversation, History, Checkpoints, Memory, Server settings, API keys, LLM, Voice, NPC selection, Memory settings, NPC bios, Profiles, Logs, Connector calls, Diagnostics, Backups | no login | [UI_GUIDE](UI_GUIDE.md#what-the-dashboard-does) |
| Dashboard samples | **Preview only, save nothing:** `ui/examples/` blank, form and table | | [UI_GUIDE](UI_GUIDE.md#working-tools-and-previews) |
| Versions, migrations, upgrade refusal | Working | `0.1.0`, `baseline-1`, protocol 1, `007_connector_calls` | [SETUP 6](SETUP.md#6-back-up-and-update) |
| Launcher custom-mod manifest (`dwemer-mod.json`) | Present; offers `main` (default) and `dev` | | [LAUNCHER_CUSTOM_MOD](LAUNCHER_CUSTOM_MOD.md) |
| Windows client x64 and x86 | Builds with `scripts\build.ps1` | | [ExampleMod README](https://github.com/Dwemer-Dynamics/ExampleMod/blob/main/README.md) |
| Real game plugin | **Not provided.** The console fake game is the only client | | [Porting](#porting-to-a-real-engine) |

## End-to-end walkthrough

Each step lists what you should see. Commands marked WSL run in the DwemerDistro shell as
`dwemer`, in the server folder; Windows commands run next to `example_mod.exe`.

### 1. Install and check the server

Follow [SETUP.md section 1](SETUP.md#1-install-the-server-inside-dwemerdistro). Then (WSL):

```bash
curl http://127.0.0.1:19000/ExampleServer/health.php
bash scripts/smoke.sh
```

Health answers `"ok":true`, `"schema":"007_connector_calls"`, `"baseline":"baseline-1"` and
`"server_version":"0.1.0"`. The smoke script ends with `All checks passed.` On the dashboard
(`http://127.0.0.1:19000/ExampleServer/`), **Control Panel > Diagnostics** should say Ready.

### 2. Build the client and pair it

Clone the client and build both architectures (Windows):

```powershell
git clone https://github.com/Dwemer-Dynamics/ExampleMod.git
cd ExampleMod
powershell -ExecutionPolicy Bypass -File scripts\build.ps1
```

Output goes to `%LOCALAPPDATA%\DwemerDynamics\ExampleMod\build\x64\Release\` and
`...\x86\Release\`. Use the architecture your game uses; the console works with both.
`example_mod.exe --version` prints `example_mod 0.1.0 (baseline-1, protocol 1)`.

Pair privately (WSL), then copy the file next to `example_mod.exe` as `config.json`
([SETUP.md section 3](SETUP.md#3-connect-them)):

```bash
sudo -u dwemer php scripts/pair_client.php --out /home/dwemer/example-client.json
```

`example_mod.exe --health` prints `Server is ready.` and then
`Versions: client 0.1.0, client baseline baseline-1, server 0.1.0, server baseline baseline-1, schema 007_connector_calls, protocol 1.`

### 3. First request, result and log

```text
> example_mod.exe --say "Follow me"
[Guide] Of course. Lead the way.
* npc_guide is now following you (simulated).
* Result reported to server: follow_player handled (client-reported, console simulation).
```

What happened: `--say` is a one-shot command. The client read the context and sent
`turn.php` with a fresh `request_id` synchronously on its main thread (`runTurn()` called
from `wmain()`), since a console command has nothing else to keep responsive. Interactive
mode (`example_mod.exe` with no arguments) instead runs each request on a worker thread and
applies results on the main loop; a real engine must do the same, because the game thread
must never wait on HTTP. The server validated it, loaded history, bio and
facts, asked the mock and kept only the allowlisted `follow_player` (targeted at
`npc_guide`). It saved the exchange and logged the turn. The client checked the reply's
request ID, NPC and instance, then ran its own `follow_player` handler and reported the
result.

Open **Control Panel > Logs**: the newest `turn` row is `complete`. Its Details show
"Retrieved for this request" and, under "Client reported (untrusted)", the report.
`complete` means the server finished, not that a game ran anything.

To see the raw JSON, use the curl example in
[PROTOCOL.md, Try it with curl](PROTOCOL.md#try-it-with-curl). You choose that
`request_id`, so you can find it in Logs by ID.

### 4. Game events: log only versus respond

```text
> example_mod.exe --event location_entered "The player entered the market."
* Event location_entered logged by the server (log only; no reply or actions).
> example_mod.exe --react item_given "The player gave a lantern. follow me"
```

`--event` sends `respond: false`: one `game_event` row in Logs, with no model call, history
or actions. `--react` sends `respond: true`: a normal turn with the text
`Game event (item_given): ...`, with a reply, history, actions and a result report. Only
the four types `location_entered`, `item_given`, `combat_started` and `combat_ended` exist.
The game decides when to send events; the server never triggers anything itself.

### 5. NPC bios and knowledge

```bash
php scripts/seed_example.php     # WSL: two fictional NPCs and four facts, never overwrites
```

```text
> example_mod.exe --say "Who are you?"
[Guide] I am Mira Stonebridge, Market guide. ...
> example_mod.exe --say "When does the market open?"
[Guide] Here is what I know: The market square stalls open at dawn ...
```

How retrieval works: the server takes up to 8 words of 4-32 letters or digits from the
line (minus a short stopword list) and runs an OR search on PostgreSQL full-text search with
the `simple` configuration. It returns at most 3 facts for this `npc.id` or global facts,
best match first, plus the stored bio. They reach the model as quoted reference data. This
is keyword matching, not semantic search: "When does the market open?" matches the fact
with "market", but a synonym such as "bazaar" does not. Edit bios and facts on
**Configuration > NPC bios**. Bios and facts are shared by every session. Each turn's Logs
details keep a copy of what was retrieved.

### 6. Sessions, history and context

- History is keyed by `session_id` + `npc.id`. The server sends the newest
  `history_limit` lines (0-50, default 10) to the model, and keeps only that many.
- Use one `session_id` per save game and a **stable** engine ID as `npc.id`. Bios, facts and
  history all follow that ID.
- `context` is an optional object of at most 10 short entries (keys `a-z` and `_`), such
  as `location` and `time_of_day`. It goes into the prompt and the log, but not into
  history.
- Interactive client: `/session other-save` switches session (pending replies are dropped
  and cancelled), `/npc scout` faces the other fake NPC. On the dashboard, **New session**
  starts a fresh one: facts still apply and the event shows 0 history lines.

### 7. Checkpoints versus backups

| | Session checkpoint | Full backup |
|---|---|---|
| What | Named copy of one session's conversation lines | `pg_dump` of the whole database (`backup.sh` also copies `config/config.php`) |
| Restores | Only that session's history; bios, facts, config, other sessions and Logs stay | The whole database, by hand |
| Tools | `php scripts/checkpoint.php` (`save`, `list`, `restore`, `delete`), **Roleplay > Checkpoints** | `sudo bash scripts/backup.sh`; **Control Panel > Backups** (database only) |
| In-flight turns | Restore makes every in-flight turn of the session answer `409 stale` | Stop the client first |

Matching a checkpoint to a game save is manual, by `session_id`. To verify a backup without
touching the live database, use the dashboard's **Verify restore** or the disposable-database
commands in [SETUP.md section 6](SETUP.md#6-back-up-and-update). Neither is proof until you
compare table counts.

### 8. NPC selection and the explicit-target boundary

`decision.php` answers one question: which of up to 8 NPCs the game offers should answer
this line. It writes nothing, returns no reply or actions, and always returns one of the
offered IDs. On any problem it returns the baseline. JEV waits at most 1.5 seconds and
reads at most 16 KB.

Call it only when the player has **no** target. If the game already has an explicit or
crosshair target, skip `decision.php`, do not send it the transcript, and send the turn to
that NPC. An action's target always comes from the turn's `npc.id`, never from the model or
`decision.php`.

```text
> example_mod.exe --decide "Scout, is the trail safe?"
* Decision: npc_guide (baseline, disabled). Display only; still talking to the same NPC.
```

With `decision` enabled and provider `mock`, it prints `npc_scout (provider, matched)`.

### 9. Actions and the worked extension

The model can only *ask* with `[ACTION:name]`. The server returns only names in its
`allowed_actions`, and the client runs a name only if it is in its own `allowed_actions`
**and** has an explicit handler in `applyResult()`. Nothing maps action names to commands.
Try it:

- `--say "dance"`: reply only; the server lists `dance` in `rejected_actions`.
- Client `"allowed_actions": []`: `Rejected action not allowed by this mod: follow_player`.

To add your own action, follow [WAVE_ACTION.md](WAVE_ACTION.md): one prompt line, both
allowlists, one `GameAdapter` method, one handler and both `PROTOCOL.md` files.

### 10. Cancellation and stale replies

Set `mock_delay_seconds` to 3 (dashboard **LLM** page or `config/config.php`), start the
interactive client and type `follow me`. Before it answers, try `/cancel`, `/npc scout`,
`/unload` or `/session other-save`. The reply and its action are dropped, and the server is
told to cancel. Logs shows the turn as `cancelled` or `superseded`.

The safety checks, in order:

1. **Server generation fence.** Each turn and each cancel moves the NPC's generation up. A
   turn whose generation is no longer current answers `409 stale` and saves nothing.
2. **Request ID.** The client applies only the reply whose `request_id` it is waiting for,
   and at most once.
3. **NPC identity.** On the game thread, right before applying, `isSameNpc()` confirms that
   the captured NPC is still loaded and is the same instance. `args.target_id` must equal
   the NPC the request was sent to.
4. **Main-thread adapter.** Only the game thread calls `GameAdapter`. HTTP runs on worker
   threads and results come back through the mailbox in `main.cpp`.

Cancellation does not necessarily stop computation at the LLM provider. Failed requests
are never retried automatically. Server check: `SMOKE_STALE=1 bash scripts/smoke.sh` with
`mock_delay_seconds` of 2 or more.

### 11. Voice (optional)

Voice is opt-in, and the voice services are installed and started separately. The
templates never install or start them. Start with PocketTTS (audio.cpp, port 8086) for
text-to-speech and Parakeet (port 8022) for speech-to-text, the recommended DwemerDistro
starting choices; existing setups often have them already. Check each in the DwemerDistro
launcher, install it there if missing, and start it before enabling or testing. Enable `tts`/`stt` on the dashboard
**Voice** page or in `config/config.php`, and set `"voice_enabled": true` in the client.
faster-whisper (port 9876) remains supported: choose it on the Voice page or use its
[CONNECTORS](CONNECTORS.md#voice) block. An `stt` section without `provider` is treated as
faster-whisper, so older configs keep working.

```text
> example_mod.exe --say "follow me"           with "voice_enabled": true
> example_mod.exe --listen C:\path\speech.wav
```

The client saves reply WAVs in `%TEMP%\dwemer-ai-example\` and does not play them. Text
always comes first. A voice failure prints `Voice unavailable, text only: ...`, or asks the
player to type. Every turn ends with a line such as
`Trace: turn req-..., speech-to-text stt-..., voice tts-... (ok)`; the voice ids appear on
**Control Panel > Connector calls** with that turn as their parent. These docs make no claim that real speech was tested with a running
service. Report voice as working only after you test it with your own services and
recordings.

### 12. A real LLM and API keys

Pick a mode and paste its block from [CONNECTORS.md](CONNECTORS.md), or use the dashboard
**LLM** page. Every mode except `mock` needs an explicit `model`:

- `openai`: OpenAI, or any OpenAI-compatible `base_url`. The key is usually needed.
- `openrouter`: the key is required.
- `local`: your own OpenAI-compatible server at `base_url`. The key is optional.
- `dwemerllm`: DwemerDistro LLM Studio at `127.0.0.1:1234`. Load a model there first. A
  key is never sent.

Keys go only in `config/config.php`, or through **Configuration > API keys**, which shows
only Saved / Not set and never the key. Test buttons run only when pressed and may call
your provider. A failing provider gives the game `502 llm_unavailable`, and the reason
goes to the Apache error log.

### 13. Profiles, memory and vector search (optional)

All three start off or empty. With the mock LLM:

1. **Profiles**: Configuration > World & Behavior > **Profiles**. Create "Scout persona" with a
   short prompt, save, assign `npc_guide`. In the client (or Conversation) send
   `what is your profile?`: `My profile is Scout persona.` The turn's Logs details name the
   profile. Unassign it and the NPC answers from the config again. A profile can override the
   model, switch only to `mock` or LLM Studio, and set a TTS voice; empty fields inherit.
2. **Memory**: Configuration > AI & Voice > **Memory settings**, tick Enabled and Automatic
   notes, save. Send two lines, then send `what do you remember?`: the reply quotes the saved
   (labelled mock) summary. Roleplay > **Memory** for your session and `npc_guide` shows the
   summary and diary; edit, regenerate, delete entries or clear them there.
3. **Candidate facts**: also tick Automatic fact proposals. Send
   `Remember that the north bridge is closed.` Roleplay > Memory lists a candidate with its
   session and request id. Approve it: it becomes a fact for this NPC in every session (NPC
   bios shows "Approved candidate from session ...").
4. **Checkpoint boundary**: save a checkpoint, send more lines (the summary changes and maybe
   a candidate appears), restore: the summary and diary go back, later candidates are deleted,
   approved facts stay.
5. **Vector search**: on Memory settings enable `mock`, save, press **Reindex**. Ask about the
   bridge: the turn's Logs details show the fact with match `vector` and its score. Edit the
   fact on NPC bios: its vector is cleared (Reindex count drops) and keyword search still
   finds it.

## Troubleshooting by request ID

Every turn and game event carries a `request_id` (8-64 characters of
`A-Z a-z 0-9 _ . : -`, new for every request, refused if reused within a day). Voice and NPC
selection requests carry one too (optional; the server makes a `srv-` id when an older client
sends none). The console client generates `req-`, `evt-`, `stt-`, `tts-` and `dec-` ids and
prints them on success and failure (`Trace: turn ...`, `request dec-...`). The dashboard
testers show them too. With curl or your own engine, you choose them, so log them in your
client.

The Apache error log names the id on every server line: `example-ai [req-...]: ...`. Voice,
NPC selection, embedding and memory calls are on **Control Panel > Connector calls**; filter by
an id to see the call and every call made for it (its children, such as a turn's voice).

Find a request: **Control Panel > Logs**, filter **Request ID** (or open
`http://127.0.0.1:19000/ExampleServer/ui/logs.php?request=<id>`). If you do not know the ID,
filter by session, NPC, kind, status or time (UTC), then open **Details**.

| What Logs shows for the ID | Meaning | Next step |
|---|---|---|
| No row | Inconclusive on its own. Common causes: the request was rejected before logging (`400` validation, `401` token, `405` method, `413` size, `500`/`503` not configured or not migrated) or never sent; the server could not store the row (the response then says `"logged": false`); the row was pruned by retention (`event_log_limit`, newest 10000 by default); you are looking at another install or database; or a filter (session, NPC, kind, status, time) hides it. `speak.php`, `listen.php` and `decision.php` write Connector calls rows instead of Logs rows | Clear the filters, confirm the install URL, then read the HTTP status and code the client printed (with curl, also the `logged` field); see [SETUP troubleshooting](SETUP.md#troubleshooting) |
| `turn` `failed`, `llm_unavailable` | The provider failed or is misconfigured | Search the Apache error log for `example-ai [<id>]`. Provider reply bodies are never logged |
| `turn` `failed`, `duplicate_request` | The ID was reused within a day. The first request's row is unchanged | Generate a new ID per request; never retry automatically |
| `turn` `cancelled` | A cancel for that NPC arrived before the save | Expected after `/cancel`, `/npc`, `/unload`, `/session` or quit |
| `turn` `superseded` | A newer turn or a checkpoint restore made it stale (or the cancel event was not kept) | Expected; the client should already have dropped it |
| `turn` `complete`, no client report | The server finished, but no report was stored. The client sent none (the reply had no actions, was dropped as stale or NPC gone, reports are off, `logged` was `false`, or all workers were busy), or it sent one that failed (network error, timeout, or the server refused it) | Check the client output for `Dropped ...`, `Ignored a stale reply`, `result report skipped` or `Result report failed` |
| `turn` `complete`, report `rejected` or `failed` | The client refused or failed the action | Check both `allowed_actions` lists and the handler |
| `game_event` `complete` | A log-only event was stored | Nothing more happens for it, by design |
| `restore` or `cancel` rows | These have no request ID | Filter by session and kind |

A `result.php` call answers `404 unknown_request` when no retained `complete` turn matches
the ID, session and NPC (for example after `event_log_limit` pruned it), and
`409 action_not_returned` or `409 already_reported` for mismatched reports.

### What Logs stores

Migration `003_event_log` adds `ex_events`: one row per finished turn (complete, failed,
cancelled or superseded), cancel and checkpoint restore, with ids, generation, player text,
the turn's validated `context` object (at most 10 short entries), the cleaned reply, returned
and rejected action names, the llm mode name, a short error code and the server time. Never
the token, keys, prompts, provider URLs or raw provider replies.

Migration `004_game_events` adds log-only game events (`event.php`) and, for each turn, a
retrieval snapshot: the search words, the stored bio and the matched facts (with fact id and
scope) the server gave the model, and how many session history lines were sent. Logs shows it
on the event's detail page. It is a copy, so later fact edits and checkpoint restores do not
change it. Logs keeps the newest `event_log_limit` rows (default 10000), separately from
`history_limit` and checkpoints, so history pruning and restores do not remove events.
`ex_requests` only blocks a repeated `request_id` (turn or event) for a day.

Migration `007_connector_calls` adds the Connector calls audit: `speak.php`, `listen.php` and
`decision.php` accept an optional `request_id` (made by the server when missing) and return
it. Each row has ids, parent turn, provider, status and timing, never audio, transcripts,
prompts or keys. The row is written after the reply, so NPC selection keeps its 1.5 second
limit.

`complete` means the server finished the turn, not that the game got it. A client may send
an optional report to `result.php` ([PROTOCOL.md](PROTOCOL.md#optional-post-resultphp)); Logs
shows it as "Client reported (untrusted)". Without one, nothing says the game ran an action.

## Versions and migrations

These are versioned separately. Never treat one as another:

| Name | Current | Changes when | Set in |
|---|---|---|---|
| Server release | `0.1.0` | You release your server | `lib/version.php` (`SERVER_VERSION`) |
| Client release | `0.1.0` | You release your client | ExampleMod `CMakeLists.txt` (`project(... VERSION)`) |
| Protocol | `1` | A change a version-1 peer would misread; bump both repositories | `PROTOCOL.md`, both sides' code |
| Baseline | `baseline-1` | The template's feature set; a support label, not a release | `lib/app.php`, client `ai_client.h` |
| Schema | `007_connector_calls` | You add `sql/NNN_name.sql` and append it to `SCHEMA_MIGRATIONS` | `lib/app.php` |

Migration behaviour (details and exit codes in
[SETUP.md section 6](SETUP.md#6-back-up-and-update)):

- **Upgrade:** `scripts/migrate.php` (run by `install.sh` and `update.sh`) applies missing
  migrations in order, one transaction per file. It is safe to run twice; the second run
  applies nothing.
- **Failure:** the failed file is rolled back and earlier ones stay. `migrate.php` exits
  `2`; `update.sh` exits `3` and prints the backup it took first.
- **Rollback:** there are no down migrations. To go back, restore the backup by hand.
- **Database too new:** if the database has migrations this code does not know, health and
  every database write answer `503 schema_too_new`, and `migrate.php` refuses (exit `3`).
  Update the code; never downgrade a database by hand.

## Server files

| File | Purpose |
|---|---|
| `health.php` | `GET`: is the server ready? No token. |
| `turn.php` | `POST`: one player line, one NPC reply plus allowed actions. |
| `event.php` | `POST`: one game event. Log only by default; `respond: true` asks the named NPC to react like a turn. |
| `lib/turn.php` | The turn pipeline shared by `turn.php` and `event.php` (duplicate check, generation, profile, history, knowledge and memory, save, opt-in memory updates). |
| `cancel.php` | `POST`: drop the in-flight turn for an NPC. |
| `speak.php`, `listen.php` | Optional voice: text to WAV, WAV to text. |
| `decision.php` | Optional: which offered NPC should answer. Selection only, no chat or actions. |
| `result.php` | Optional: the client reports what it did with a turn's actions (untrusted). |
| `lib/events.php` | Event log writes and retention, shared by turns, cancels, restores and Logs. |
| `lib/app.php` | Config, token check, input limits, database, HTTP helper. |
| `lib/llm.php` | Mock and OpenAI-compatible replies, `llm.mode` presets, action allowlist. |
| `lib/decision.php` | Mock and OpenRouter decisions provider for `decision.php`; baseline fallback. |
| `lib/knowledge.php` | Loads the NPC's stored bio and up to three matching facts for a turn (vector matches first when on, then keywords). |
| `lib/profiles.php` | Which profile an NPC uses and how its overrides apply to the config. |
| `lib/memory.php` | Optional summaries, diaries and candidate facts; generation-fenced writes. |
| `lib/embeddings.php` | Optional embedding vectors: mock or OpenAI-compatible provider, validation, search, reindex. |
| `lib/audit.php` | Connector audit rows (`ex_connector_calls`) for voice, NPC selection, embedding and memory calls. |
| `config/config.example.php` | Every setting, with comments. Copied to `config/config.php` (untracked). |
| `sql/*.sql` | Migrations, applied once each by `scripts/migrate.php`. |
| `scripts/` | `install.sh`, `update.sh`, `backup.sh`, `smoke.sh`, `migrate.php`, `pair_client.php`, optional `seed_example.php` and `checkpoint.php`. |
| `ui/` | The dashboard; see [UI_GUIDE.md](UI_GUIDE.md). |

Profiles, advanced memory and vector search are optional: a fresh install and an old config
behave exactly as before. Profiles never hold URLs or keys. Memory is stored as JSON in this
database, so no extension is needed; candidate facts are never used until approved, and
approved facts record where they came from.

## Updating and private files

`sudo bash scripts/update.sh` refuses tracked local edits, backs up the database and
config, fast-forwards only, then migrates; it never resets, cleans or deletes anything
([SETUP.md section 6](SETUP.md#6-back-up-and-update)). A manual install follows the branch
it was cloned from (`main` by default; clone with `-b dev` for development).

Your project is your own copy. Give it its own repository. `update.sh` above only updates
an installed copy from the branch it tracks; it does not merge template changes into your
project. If you made your repository with GitHub's **Use this template**, its Git history is unrelated
to the template's: compare later template changes and copy or adapt the ones you want by
hand. If your repository is a clone that keeps the template's history, you can instead keep
the template as an extra remote and merge reviewed updates on a working branch.

Files to keep private and out of commits and packages: `config/config.php`,
`config/config.php.lock`, `config/.config-*.php`, the client `config.json`,
`example-client.json` from pairing, backup folders and `*.dump` files.

## Launcher install and multiple copies

`dwemer-mod.json` lets the DwemerDistro launcher install this server under **Mods > Custom
mods** from `https://github.com/Dwemer-Dynamics/ExampleServer`, in its own folder, database
and config, with `main` as the default branch and `dev` offered for switching. See
[LAUNCHER_CUSTOM_MOD.md](LAUNCHER_CUSTOM_MOD.md).

You can run several copies at once, as long as each has its own database:

- Manual installs: a different `/var/www/html/custom-mods/<hyphenated-id>` folder and a
  different `database.name` per copy ([SETUP.md, A separate copy with its own database](SETUP.md#a-separate-copy-with-its-own-database)).
- Launcher installs: your own repository and a different `id` per published project. It
  cannot change after install.
- Never point two copies, or another product, at the same database.

## Packaging, licensing and private data

The templates have no packaging script. When you share your project:

- Server: publish the Git repository, or `git archive` of a commit, which contains
  committed files only. Never include `config/config.php`, backups, dumps or logs.
- Client: ship `example_mod.exe` (renamed for your project) with `config.default.json`.
  Never ship a `config.json` that has a token. Build output stays outside the source tree
  (`%LOCALAPPDATA%\DwemerDynamics\ExampleMod\build` by default).
- Licences: both repositories are GPL-3.0-only (`LICENSE`); a project based on them must
  follow its terms, including offering source with any binaries you share. Add your own
  copyright notice and keep the existing ones listed in each repository's
  `THIRD_PARTY_NOTICES.md`: `LICENSES/Original-MIT.txt`, `ui/HERIKA-LICENSE` (dashboard
  presentation) and `third_party/nlohmann/LICENSE.MIT` (JSON library).
- Keep public docs generic. Leave out personal names, local paths, private endpoints and
  credential values.

## Porting to a real engine

Hand the agent [AGENT_PLAYBOOK.md](https://github.com/Dwemer-Dynamics/ExampleMod/blob/main/AGENT_PLAYBOOK.md) with both repositories
and your engine's real SDK. The port must provide:

- [ ] A main-thread tick or callback. Only it calls `GameAdapter`.
- [ ] Worker threads, or async HTTP, for every server call. The game never blocks on the AI.
- [ ] A stable NPC ID, NPC and player names, and a few context entries.
- [ ] A `session_id` per save game.
- [ ] Subtitle or message display.
- [ ] `isSameNpc()` using a real engine handle or instance marker.
- [ ] One explicit handler per allowlisted action, using the engine's own behaviour (for
      example, follow).
- [ ] Request-ID and stale checks, no automatic retries, and both allowlists unchanged.
- [ ] A private `config.json` location for the server URL and token.
- [ ] Optional: WAV playback on the NPC and microphone capture to WAV.
- [ ] The correct architecture (x64 or x86) for the game, built without warnings.

Evidence levels. Report each one separately, and claim only what you actually ran:

1. Server lint, migrations twice and `scripts/smoke.sh` in mock mode.
2. The console client's smoke checks ([ExampleMod README](https://github.com/Dwemer-Dynamics/ExampleMod/blob/main/README.md#smoke-checks)).
3. Real provider calls (LLM, voice, JEV), each tested on its own.
4. In-game: talk to an NPC, see the subtitle, follow, cancel a slow reply, unload the NPC
   while a reply is pending, and stop the server, all without a crash or hang.

Until level 4 has been done in a specific game, do not describe the mod as working in that
game. Passing console checks says nothing about an engine.
