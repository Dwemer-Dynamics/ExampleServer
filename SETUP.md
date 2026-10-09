# Setup: ExampleServer + ExampleMod

This walkthrough installs the example server inside DwemerDistro, builds the console
example client on Windows, and connects the two. Allow about 15 minutes. It is the
canonical reference for installation and operations; for orientation, the feature checklist
and troubleshooting by request ID, start with [START_HERE.md](START_HERE.md).

## What this is, and what it is not

- **ExampleServer** is a small PHP 8.2 + PostgreSQL server. It gives an NPC a reply (mock or
  a real LLM) and at most one safe action, `follow_player`.
- **ExampleMod** is a Windows console program that plays a fake game. It shows the
  `GameAdapter` boundary you replace with a real engine's APIs.
- It is **not** a plugin for any real game, and it is **not** part of CHIM, Stobe or
  Dialectic. These are independent repositories with no shared Git history or automatic
  upstream; only the dashboard's presentation is adapted from HerikaServer (see
  [UI_GUIDE.md](UI_GUIDE.md)). Do not expect to merge their updates into a project made from
  this example, or the other way round.
- This guide is a **manual install** into `/var/www/html/ExampleServer`; the official
  launcher and updater do not install, update or back up that copy. Alternatively, the
  launcher can install the server under **Mods > Custom mods** in its own folder and
  database; see [LAUNCHER_CUSTOM_MOD.md](LAUNCHER_CUSTOM_MOD.md). The client is always built
  as described in section 2.

## Requirements

| Where | What |
|---|---|
| Windows | DwemerDistro installed and started (it provides WSL, Apache with the shared custom mods port 19000, PostgreSQL, PHP 8.2). |
| Windows | Visual Studio 2022 with "Desktop development with C++" (MSVC, CMake). |
| Optional | An OpenAI-compatible LLM endpoint (cloud or local). Not needed for the mock. |
| Optional | DwemerDistro PocketTTS (audio.cpp, port 8086) and Parakeet (port 8022) or faster-whisper (port 9876) for voice. |

| Repository | URL |
|---|---|
| ExampleServer | <https://github.com/Dwemer-Dynamics/ExampleServer> |
| ExampleMod | <https://github.com/Dwemer-Dynamics/ExampleMod> |

Both offer `main` (default, the stable baseline) and `dev` (development). For your own
project, use your own repositories instead.

## 1. Install the server (inside DwemerDistro)

Open a DwemerDistro WSL shell as the `dwemer` user, for example from Windows:

```powershell
wsl -d DwemerAI4Skyrim3 -u dwemer
```

Clone the server into Apache's web root (add `-b dev` for the development branch):

```bash
git clone https://github.com/Dwemer-Dynamics/ExampleServer.git /var/www/html/ExampleServer
```

Optional, for a local repository that is not published anywhere: export its committed
history as a Git bundle on Windows and clone the bundle inside WSL. This avoids Git
ownership errors when WSL reads a Windows checkout.

```powershell
$bundleFolder = Join-Path $env:LOCALAPPDATA "DwemerDynamics"
New-Item -ItemType Directory -Force $bundleFolder
git -C "C:\path\to\ExampleServer" bundle create (Join-Path $bundleFolder "ExampleServer.bundle") --all
```

```bash
git clone /mnt/c/Users/<windows-user>/AppData/Local/DwemerDynamics/ExampleServer.bundle /var/www/html/ExampleServer
```

A bundle is a snapshot, not an update source: set a real remote with
`git remote set-url origin <your-repository-url>` before using `update.sh`.

Run the installer:

```bash
cd /var/www/html/ExampleServer
sudo bash scripts/install.sh
```

The installer:

- copies `config/config.example.php` to `config/config.php` **only if it is missing**, with
  a new random token;
- sets `config/` to owner `www-data`, group `dwemer` (the checkout owner's primary group),
  mode `2770`, and `config/config.php` (plus an existing `config.php.lock`) to
  `www-data`:`dwemer` mode `660`, keeping the file contents. Run it again to repair them;
- creates the configured database (default `example_ai_mod`) **only if it is missing**, owned by the existing
  `dwemer` role (no new roles, grants or server settings);
- applies new migrations from `sql/` (safe to repeat);
- changes nothing in Apache, PHP or any other product.

Running it again keeps your config and data.

Then let DwemerDistro serve it on the shared custom mods port (safe to repeat; it only
writes the distro's own Apache site and never changes official servers):

```bash
sudo ddistro_custom_mod setup-web
```

All custom PHP mods share this one loopback port, `CUSTOM_MODS_PORT` in
`/etc/dwemerdistro_services.conf` (default `19000`, allowed `19000`-`19999`), each under
its own path and with its own database. Official server ports such as 8081 are unchanged.
If the port is busy, `setup-web` says so and changes nothing. To use another port, set
`CUSTOM_MODS_PORT` as root, run `sudo ddistro_custom_mod setup-web` again, then update
every client's `server_url` (pair again, below) to the new port. The examples here
assume `19000`.

Check it:

```bash
curl http://127.0.0.1:19000/ExampleServer/health.php
# {"protocol":1,"ok":true,"service":"example-ai-server","schema":"007_connector_calls","expected_schema":"007_connector_calls","capabilities":["turn","cancel","result","game_event","action_target","decision","speak","listen","profiles","memory","trace"],"baseline":"baseline-1","server_version":"0.1.0"}
bash scripts/smoke.sh
```

> **Routing not yet verified on every install.** The shared port serves only
> `/var/www/html/ExampleServer` and `/var/www/html/custom-mods/<id>`. If `health.php`
> returns 404 or 403, check the folder is at one of those paths and that
> `sudo ddistro_custom_mod setup-web` succeeded.

### A separate copy with its own database

Use a distinct folder and database when another ExampleServer is already installed.
The shared port serves only `/ExampleServer` and `/custom-mods/<id>`, so put the copy at
`/var/www/html/custom-mods/<id>`. The `<id>` is 3-32 lowercase letters and digits,
starting with a letter and joined by single hyphens (for example `my-project`, not
`my_project`); do not use an id the launcher installs, such as `example-server`. The
installer creates `database.name` from
your private config if it is missing. Use 1-63 lowercase letters, digits or underscores,
starting with a letter. Choose a new name for your own project; never point a copy at
another product's database.

```bash
sudo install -d -o dwemer -g dwemer /var/www/html/custom-mods/my-project
git clone https://github.com/Dwemer-Dynamics/ExampleServer.git /var/www/html/custom-mods/my-project
cd /var/www/html/custom-mods/my-project
# Before running install.sh:
cp config/config.example.php config/config.php
php -r '$p="config/config.php"; $s=file_get_contents($p); file_put_contents($p, str_replace("CHANGE_ME_TOKEN", bin2hex(random_bytes(24)), $s));'
# Edit only database.name in config/config.php to example_ai_my_project.
nano config/config.php
sudo bash scripts/install.sh
curl http://127.0.0.1:19000/custom-mods/my-project/health.php
# Pair from this folder too; its server_url is .../custom-mods/my-project.
```

Keep the generated token private. The existing config is preserved on later installer
runs. Do not run the default-database removal commands below against another copy's data.

### Who can read what

| Path | Owner:group | Mode | Why |
|---|---|---|---|
| checkout files | `dwemer`:`www-data` (inherited) | default | Apache (`www-data`) only reads code. |
| `config/` | `www-data`:`dwemer` | `2770` | New files (lock, temporary saves) inherit the `dwemer` group. |
| `config/config.php`, `config.php.lock` | `www-data`:`dwemer` | `660` | Token and database password; the dashboard saves it, `dwemer` reads and writes it through the group. |
| backups (`backup.sh`) | `dwemer` | `700` / `600` | Kept in `/home/dwemer/example-ai-server-backups`, outside the web root. |
| dashboard backups | web server user | `700` / `600` | `backup_dir` in the config, or an app folder in the system temp directory. |

The API keeps conversation state in its own database. Dashboard CSRF checks use PHP's
session storage.

**Saving settings from the dashboard.** The settings pages rewrite `config/config.php`
atomically: they take `config/config.php.lock`, read the latest file, change only their own
section, write a temporary file in `config/` and rename it over the config, keeping its
owner, group and mode. They never change `token`, `database` or keys they do not know, but
comments in the file are not kept. They refuse to save, with a clear message and no
change, when:

- `config/` or `config/config.php` is not writable by the web server user (the folder needs
  write access for the lock and temporary files);
- the web server user does not own the file (a rename would change the owner);
- the web server user is not in the file's group and `config/` is not setgid with that group
  (the temporary file would get a different group);
- the config path, or its folder, is a symbolic link.

The installer sets up the ownership that allows saving (`www-data` does not need to be in
the `dwemer` group). If you changed it by hand, run `sudo bash scripts/install.sh` again;
the dashboard itself never changes the config's owner or group. Check **Control Panel > Diagnostics** ("Dashboard can save
settings") after any change.
Do not rely on `.htaccess` to protect secrets. Secrets are kept in `config/config.php`,
which prints nothing when served through PHP. `scripts/smoke.sh` checks that the token is
not visible at `config/config.php`. It does not check other files in the checkout: nothing
here stops Apache from serving `.git/` or other non-PHP files. Check that yourself as
[START_HERE.md](START_HERE.md#deployment-and-exposure) describes.

You can also open `http://127.0.0.1:19000/ExampleServer/` directly in your browser; there is
no dashboard login. Keep it on localhost or a trusted network: anyone who can open it can
change settings and keys. It offers settings, LLM, Voice, NPC selection and API key pages,
a conversation test, retained history, checkpoints, diagnostics and backups. Its test
session is `dashboard-demo` by default, separate from the console client's default session. See [UI_GUIDE.md](UI_GUIDE.md) to extend its
blank, form and table example pages.

## 2. Build the client (Windows)

```powershell
git clone https://github.com/Dwemer-Dynamics/ExampleMod.git
cd ExampleMod
powershell -ExecutionPolicy Bypass -File scripts\build.ps1
```

This builds x64 and x86 into `%LOCALAPPDATA%\DwemerDynamics\ExampleMod\build\x64\Release` and `...\x86\Release`
(change with `-BuildRoot`). `config.default.json` is copied next to each `example_mod.exe`.

## 3. Connect them

Write a private client config inside WSL. It goes outside the web root, as a new file with
mode 0600, in a folder you own that other users cannot write to (your home folder is
fine); an existing file or link is never replaced and the token is not printed:

```bash
cd /var/www/html/ExampleServer
sudo -u dwemer php scripts/pair_client.php --out /home/dwemer/example-client.json
```

The address is inferred for installs under `/var/www/html` (here
`http://127.0.0.1:19000/ExampleServer`; a copy in `/var/www/html/custom-mods/my-project` gets
`.../custom-mods/my-project`), on port 19000. If you changed `CUSTOM_MODS_PORT`, or the folder
is anywhere else, add `--base-url http://127.0.0.1:<port>/<path>`. The shared port listens
on loopback only. `--session-id` is optional.

Copy that file to Windows next to `example_mod.exe` and name it `config.json` (git-ignored),
for example from PowerShell:

```powershell
Copy-Item \\wsl.localhost\DwemerAI4Skyrim3\home\dwemer\example-client.json .\config.json
```

Then delete the WSL copy if you do not need it. Treat both copies like a password. You can
still write `config.json` by hand: copy `server_url` and the `token` from
`config/config.php`.

Run:

```text
> example_mod.exe --health
Server is ready.
Versions: client 0.1.0, client baseline baseline-1, server 0.1.0, server baseline baseline-1, schema 007_connector_calls, protocol 1.
> example_mod.exe --say "Follow me"
[Guide] Of course. Lead the way.
* npc_guide is now following you (simulated).
> example_mod.exe
* Type to talk. Commands: /cancel  /listen <file.wav>  /decide <text>  /event <type> <text>  /react <type> <text>  /npc guide|scout  /session <id>  /unload  /load  /quit
```

After `--say "Follow me"` the client also sends an optional result report, printed as
`Result reported to server: follow_player handled (client-reported, console simulation).`
Open Control Panel > Logs: the turn shows as `complete` and its details show the report
under "Client reported (untrusted)". It is what the console client says, not a game engine.

To see cancellation, set `'mock_delay_seconds' => 3` in `config/config.php`, type a line,
then `/cancel` before the reply arrives. Logs then shows the turn as `cancelled`.

If the server runs on another PC, set `server_url` to that host. The token travels in plain
HTTP, so only do this on a trusted local network.

## 4. Use a real LLM (optional)

The easiest way is the dashboard:

1. Open **Configuration > LLM** and choose a **Reply mode**: `openai`, `openrouter`,
   `local` (any OpenAI-compatible server you run, such as LM Studio) or `dwemerllm`
   (DwemerDistro LLM Studio). `mock` needs nothing.
2. For `local`, set **Base URL** to the server's `/v1` address, for example
   `http://127.0.0.1:1234/v1`. For `openai` it is optional (empty means
   `https://api.openai.com/v1`); `openrouter` and `dwemerllm` use fixed URLs.
3. Set **Model** to the exact model name your provider or local server lists. Every mode
   except `mock` needs one.
4. If the provider needs a key, open **Configuration > API keys**, paste it into the `llm.api_key`
   slot and press **Save keys**. The key is never shown again; `dwemerllm` never receives it.
5. Back on **Configuration > LLM**, press **Save LLM settings** (saving never contacts the
   provider), then **Send test**. The test shows the reply and its time, or, on failure, a
   short reason such as `HTTP 401` (key refused), `HTTP 404` (URL or model not found) or a
   network error (could not connect, timed out). Provider replies are never shown. A test
   may cost money; it saves no history and runs no actions.

Or edit `config/config.php` in WSL. `mode` is one of `mock`, `openai`, `openrouter`, `local`
or `dwemerllm`; every mode except `mock` needs an explicit `model`. Copyable blocks for each
are in [CONNECTORS.md](CONNECTORS.md). For example:

```php
'llm' => [
    'mode' => 'openai',
    'base_url' => 'https://api.openai.com/v1',   // or any OpenAI-compatible server
    'model' => 'your-model-name',
    'api_key' => '',                             // paste your key; empty for servers without keys
    'timeout_seconds' => 30,
    'max_tokens' => 200,
    'mock_delay_seconds' => 0,
],
```

Changes apply on the next request; no restart needed. If the model fails, the game gets
`502 llm_unavailable` and the reason is in the Apache error log.

## 5. Voice (optional)

Voice is off by default and is never needed for text. To try it:

1. Start PocketTTS (audio.cpp) and/or Parakeet from the DwemerDistro launcher.
2. In `config/config.php` (or on the dashboard Voice page) set
   `'tts' => ['enabled' => true, ...]` and/or `'stt' => ['enabled' => true, ...]`. The
   default URLs are `http://127.0.0.1:8086/v1/audio/speech` and
   `http://127.0.0.1:8022/v1/audio/transcriptions`; change them if your services run
   elsewhere. To use faster-whisper instead of Parakeet, see [CONNECTORS.md](CONNECTORS.md).
   For PocketTTS a blank model or voice saves the defaults `pocket-tts` and `alba`.
3. Test on the dashboard **Configuration > Voice** page after saving: **Speak** should play
   audio, and for Parakeet choose a short WAV file (at most 10 MB) under **Test
   transcription** and press **Transcribe**; the transcript appears in the box below. Both
   show a request id that **Control Panel > Connector calls** lists.
4. In the client `config.json` set `"voice_enabled": true`. Replies are saved as WAV files
   in `%TEMP%\dwemer-ai-example\`. Use `/listen <file.wav>` or `--listen <file.wav>` for
   speech input.

Every voice call has a request id. The client prints it with the turn it belongs to, for
example `Trace: turn req-..., voice tts-... (ok)`, and **Control Panel > Connector calls** lists
the call (provider, status, time taken; never audio or text) under that turn. A profile with
a TTS voice changes the voice for its NPCs (the client sends the NPC id with the speech).

Only these services are supported: PocketTTS, an OpenAI-compatible speech endpoint that
returns WAV (`'provider' => 'openai'`, see [CONNECTORS.md](CONNECTORS.md)), Parakeet and
faster-whisper.
Other DwemerDistro voice services are not wired in. When a voice service is off, fails or
hears nothing usable, the client says so and keeps text or asks the player to type.

## 5b. Profiles, memory and tracing (optional)

Nothing here is needed for the walkthrough, and all of it is off or empty after install. The
installer applies migrations `005_profiles`, `006_memory` and `007_connector_calls` (also on an
upgrade from `004_game_events`); they add tables and columns only and change no existing row.

- **Profiles**: Configuration > World & Behavior > **Profiles**. Create a profile (name and
  system prompt; model, reply mode and TTS voice are optional and inherit the config when
  empty), then assign NPC ids. Tick **Default** to apply it to every NPC without its own
  profile. A profile can switch only to `mock` or LLM Studio (`dwemerllm`), so a stored profile
  never sends your key to another provider. Keys stay in `config/config.php`.
- **Memory**: Configuration > AI & Voice > **Memory settings**. **Enabled** gives each turn
  this session's saved summary and newest diary entries. **Automatic notes** and **Automatic
  fact proposals** call the NPC's model after each successful reply (extra calls; with a paid
  provider they cost money). Review on Roleplay > **Memory**: edit or regenerate the summary,
  add or delete diary entries, approve or reject candidate facts, or clear one session's
  memory for one NPC.
- **Vector search**: on Memory settings choose `mock` (hashed words, needs nothing, not
  semantic) or `openai` (any OpenAI-compatible `/v1/embeddings` URL; set the key on API keys),
  enable it, save, then press **Reindex**. Turns fall back to the keyword search whenever the
  embedding call fails or a fact has no current vector. Editing a fact clears its vector.
- **Tracing**: every turn, event, voice and NPC selection request has a request id. Search for
  it on Logs, Connector calls or in the Apache error log (`example-ai [<id>]: ...`).

Checkpoints copy the summary and diary with the session's lines; restoring one puts them back
and deletes candidate facts the session proposed after the checkpoint. Approved facts are
shared knowledge and stay. Backups and their "verify restore" include every new table.

## 6. Back up and update

```bash
cd /var/www/html/ExampleServer
sudo bash scripts/backup.sh      # database + config to /home/dwemer/example-ai-server-backups/<time>/
sudo bash scripts/update.sh      # refuses local edits, backs up, fast-forwards, migrates
```

- `update.sh` stops if tracked files have local changes. Keep your settings in
  `config/config.php` (untracked) and your code changes in your own fork.
- It only fast-forwards. If your branch has no upstream or has diverged it stops; merge by hand.
- It stops if the update would start tracking a file you have here untracked or ignored,
  such as `config/config.php`, because git would overwrite it without asking.
- It never deletes the database or the config, and never resets or restores anything itself.
- If a migration fails, that migration is rolled back (earlier ones stay), the code is
  already updated, and `update.sh` exits `3` and prints the backup folder. Fix the cause and
  run `sudo -u dwemer php scripts/migrate.php` again, or restore that backup by hand (below).

**Versions.** `health.php` and **Control Panel > Diagnostics** show four different things:
`server_version` (this server's release, `0.1.0`), `protocol` (the client/server contract, `1`), `baseline` (which example template feature set
this code is, `baseline-1`; not a release number) and the schema (the ordered `sql/` migrations
this code needs, listed in `lib/app.php` `SCHEMA_MIGRATIONS`). `scripts/migrate.php` applies
missing migrations in name order, one transaction per file, and lets only one runner work at a
time (a second one waits). It refuses, changing nothing, when the database has migrations this
code does not know (exit `3`; update the code) or has a later migration without an earlier
one (exit `3`; restore a backup), or when `sql/` and `SCHEMA_MIGRATIONS` differ (exit `3`). A
failed file exits `2`. Add a new migration as a new `sql/NNN_name.sql` and append its name to
`SCHEMA_MIGRATIONS`; never edit an applied file. Health, the dashboard and every database write
(turns, events, cancel, result, checkpoints, bios and facts, seeding) answer `not_migrated`,
`schema_too_new` or `schema_mismatch` until both match. To release your own server, change
only `SERVER_VERSION` in `lib/version.php`; the client's version is `project(... VERSION)` in
the client's `CMakeLists.txt`.
- Restore a backup:
  `sudo -u postgres pg_restore --clean --if-exists -d YOUR_DATABASE < /path/to/backup/YOUR_DATABASE.dump`
  This overwrites that database. To inspect a backup first, restore it into a new,
  disposable database and compare, leaving the live one alone:

  ```bash
  sudo -u postgres createdb --owner=dwemer --template=template0 example_ai_restore_check
  sudo -u postgres pg_restore --no-owner --role=dwemer -d example_ai_restore_check < /path/to/backup/YOUR_DATABASE.dump
  sudo -u postgres psql -d example_ai_restore_check -c 'SELECT count(*) FROM ex_turns'
  sudo -u postgres dropdb example_ai_restore_check   # only this disposable copy
  ```

- **Dashboard backups** (**Control Panel > Backups**) are database-only: `pg_dump` of this
  app's database, without `config/config.php`, at most 20 files of 100 MB, 60 seconds per
  run. They go to `backup_dir` from the config (an absolute path outside the web root,
  owned by the web server user, mode `700`) or, if empty, an app folder in the system temp
  directory that may be emptied on restart. Files are listed, never downloaded.
  **Verify restore** restores one listed dump into a new database named
  `<database>_verify_<random>` with the configured role, which needs `CREATEDB`; it then
  compares table counts and migrations with the live database. It never restores over an
  existing database and never drops anything: remove the verify database yourself with
  `dropdb` when finished. To really restore, use the command line steps above.

### Bios, knowledge and session checkpoints

NPC bios and knowledge facts are edited on the dashboard's **Configuration > NPC bios**
page and stored in this database. Nothing is added automatically. To try retrieval with two
fictional NPCs (Mira = `npc_guide`, Oren = `npc_scout`) and a few facts, run once (safe to
repeat; it never overwrites your edits):

```bash
php scripts/seed_example.php
```

A **checkpoint** is a named copy of one session's conversation lines, inside this database.
A **backup** (`backup.sh`) is a full dump of the whole database plus config. Restoring a
checkpoint changes only that session's lines and memory notes (summary and diary), and deletes
candidate facts the session proposed after it; bios, approved knowledge, profiles, config and
other sessions stay as they are.

```bash
php scripts/checkpoint.php save    demo-save-1 before-boss
php scripts/checkpoint.php list    demo-save-1
php scripts/checkpoint.php restore demo-save-1 before-boss
php scripts/checkpoint.php delete  demo-save-1 before-boss
```

Run them as `dwemer` (who can read the config). **Roleplay > Checkpoints** on the dashboard
does the same with the same limits and locking; restore and delete ask for confirmation
first. The server never reads game saves: you
choose which checkpoint matches a game save, using the client's `session_id`. Stop or
cancel pending client requests before restoring; any turn still in flight for that session
is answered `409 stale` and not saved. Each session keeps at most 20 checkpoints of up to
2000 lines.

## 7. Remove

Back up first, then:

```bash
sudo -u postgres dropdb YOUR_DATABASE    # use this copy's configured name; deletes its data
rm -rf /var/www/html/ExampleServer
```

## Troubleshooting

| Symptom | Check |
|---|---|
| `health.php` 404 | Apache route; see the routing note above. |
| `500 server_not_configured` | `config/config.php` missing, unreadable by `www-data`, or token still `CHANGE_ME_TOKEN`. |
| `503 database_unavailable` | PostgreSQL running? Database settings in `config/config.php`? |
| `503 not_migrated` | Run `sudo bash scripts/install.sh` again. |
| `503 schema_mismatch` | This code's `sql/` folder and `SCHEMA_MIGRATIONS` in `lib/app.php` differ. Add the new file name to the list, in order. |
| `503 schema_too_new` | The database came from newer code (or a newer copy shares its name). Update this checkout; never downgrade the database by hand. |
| `migrate.php` exit `1` | Config missing or invalid, or the database connection failed. Nothing was changed. |
| `migrate.php` exit `3` | Refused, nothing changed: `sql/` does not match `SCHEMA_MIGRATIONS` (add the new file name to the list), unknown newer migrations (update the code) or a gap in the order (restore a backup). |
| `migrate.php` exit `4` | Connected, but the migration lock or table could not be used. |
| `update.sh`: "would track ..." | The upstream started tracking a file you have privately. Keep your copy; ask upstream to remove it. |
| `update.sh` exit `3` | Code updated, a migration failed and was rolled back. Use the printed backup path and steps. |
| Client `--health`: `server baseline not reported` | Older server; everything in protocol 1 still works. Update both for the newest examples. |
| Client: `WinHTTP error 12029` | Server not reachable at `server_url`. |
| Client: `401 unauthorized` | Token in `config.json` does not match the server. |
| A reply ignores the profile | Is the NPC id assigned (Profiles), or is a default profile marked? The turn's Logs details show which profile was used. |
| Memory settings: Reindex disabled | Vector search is off or the database is not migrated. Enable it and save first. |
| Connector calls shows `embedding` `failed` | The embeddings URL, model, key or vector size is wrong; turns used keyword search. Details (without the provider reply) are in the Apache error log under the call's parent request id. |
| Memory call `stale` | A newer turn, cancel, clear or checkpoint restore came first, so nothing was saved. Expected. |
