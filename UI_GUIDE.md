# Dashboard guide

The dashboard uses plain PHP and the existing API, with presentation adapted from actual
HerikaServer assets and templates. This page describes what it does, gives a guided tour,
and shows how to add pages. To add one, start with the blank, form or table page under
`ui/examples/`. The form example saves nothing; the table uses labeled sample data.

## What the dashboard does

Open `http://127.0.0.1:8081/ExampleServer/` directly (a launcher install uses
`http://127.0.0.1:8081/custom-mods/example-server/ui/`). This baseline dashboard has no
login. Use localhost or a trusted network. There is no rate limiting.

- **Configuration**: Server settings (history length, known actions), LLM, Voice, NPC
  selection, Memory settings and API keys save into the private `config/config.php` (see
  [SETUP.md](SETUP.md#who-can-read-what) for the file ownership this needs). LLM, Voice and
  NPC selection have explicit test buttons; Memory settings has an explicit Reindex button.
  They run only when pressed and can call your provider. NPC bios and Profiles (under
  **World & Behavior**) save to the database.
- **Roleplay**: Conversation tester (choose a saved NPC or type an NPC ID and name, a
  session and optional context, then talk over several exchanges; plus a game event test),
  retained History, session Checkpoints (save, restore with confirmation, delete) and Memory
  (one session's summary and diary, candidate facts to approve or reject, clear).
- **Control Panel**: Logs (finished turns, cancels, checkpoint restores and log-only game
  events), Connector calls (voice, NPC selection, embedding and memory calls), Diagnostics
  (live readiness checks) and database-only Backups with a "verify restore" into a new
  database.

The tester calls the configured LLM (mock by default) and writes ordinary history. It
displays actions without executing them. Changing the NPC or session, or pressing Cancel,
discards the reply still on its way and cancels it on the server; cancellation may not stop
provider computation. Failed requests are never retried automatically. No page runs game
actions.

NPC bios is a working editor: biographies and small knowledge facts are saved in this
database and used by `turn.php`; facts can be searched (this NPC, global or both) and
edited. Bios and facts are **shared memory**: every session sees them and checkpoint
restores never change them. History is **per session and NPC** and is what a checkpoint
restore rolls back.

## Dashboard tour

After [SETUP.md](SETUP.md), with the default mock mode (no provider, voice and NPC selection
off). Each step names what you should see. The same flow from the console client is in
[START_HERE.md](START_HERE.md#end-to-end-walkthrough).

1. **Diagnostics** (`ui/diagnostics.php`): every required row is Ready, Migrations lists
   `007_connector_calls`, the Baseline row shows `baseline-1` (the example template, not a
   release) and the Protocol row shows the capabilities `health.php` reports.
2. Run `php scripts/seed_example.php` once, then open **NPC bios**: Mira Stonebridge
   (`npc_guide`) and Oren Reed (`npc_scout`) with facts. Search facts for `market`; edit
   "Market hours" and save: "Fact updated."
3. **Conversation**: Mira is preselected. Send `who are you` (answer from her bio), then
   `When does the market open?` (answer from your edited fact). Send `follow me`: the actions
   line shows `follow_player (target npc_guide)`.
4. **Logs**: open the Details of the `When does the market open?` turn. "Retrieved for this
   request" shows the search words, the bio and the fact by id and scope. Edit the fact
   again: that snapshot does not change.
5. **Shared memory versus session**: on Conversation press **New session** and ask about the
   market again: the fact still applies, and the new event shows 0 history lines. On
   **Checkpoints**, save the first session, send another line, restore: History goes back,
   the fact and the Logs snapshots stay.
6. **Game event test** on Conversation: **Log only** stores a `game_event` in Logs with no
   reply, history or actions. **NPC responds** gives a normal reply and history.
7. Cancellation: set `mock_delay_seconds` to 3 on the LLM page, send a line and change the
   NPC (or press Cancel) before it answers: the reply is never shown, and Logs shows the turn
   as cancelled or superseded.
8. **Profiles**: create "Scout persona" with a prompt, assign `npc_guide`, then ask Mira
   `what is your profile?` on Conversation: `My profile is Scout persona.` Logs shows the
   profile on that turn's details.
9. **Memory**: on Memory settings tick Enabled and Automatic notes, send two lines, then open
   Roleplay > Memory for `dashboard-demo` / `npc_guide`: a labelled mock summary and diary.
   Ask `what do you remember?` to see the summary reach the reply. Connector calls shows the
   memory call under the turn's request id.
10. Pair the console client ([SETUP.md, "Connect them"](SETUP.md#3-connect-them)) and
    continue with the client steps in [START_HERE.md](START_HERE.md#end-to-end-walkthrough).

The console client is a fake game: "following you (simulated)" is console output, not a
game engine. A Logs entry marked complete means the server finished; a client report is
what the client claims.

## Page structure

```text
index.php                 Redirect to ui/
ui/
  common.php              CSRF session, escaping and safe page helpers
  login.php               Compatibility redirect to the open dashboard
  index.php               Readiness on page load/manual refresh
  navigation.php          Top-level sections and grouped subpage links
  profiles.php            Saved profiles: prompt, optional model/mode/voice overrides, NPC assignment
  npc_bios.php            Saved NPC bios and knowledge facts with provenance (CSRF-checked POST forms)
  memory.php              One session's summary and diary, candidate facts to review, clear
  memory_settings.php     Memory and vector search settings (saved to config) and Reindex
  logs.php                Event log from ex_events: exact filters, 25-row pages, details
  connector_calls.php     Connector audit from ex_connector_calls: voice, selection, embedding, memory
  placeholder.php         Redirects old placeholder links to the working pages
  settings.php            History length and known allowed actions (saved to config)
  llm.php                 LLM connection settings and an explicit test
  voice.php               TTS/STT settings, speak (audio playback) and WAV transcription tests
  decision_settings.php   NPC selection settings and a test through decision.php
  api_keys.php            Four secret slots; Saved / Not set only, never the key
  checkpoints.php         Session checkpoints via lib/checkpoint.php, with confirmation
  diagnostics.php         Live readiness checks; no provider calls
  backups.php             Database-only pg_dump backups and verify-restore into a new database
  conversation.php        Browser form, optional session ID and stale-result checks
  api.php                 Fixed turn/cancel/event/speak/listen/decision bridge; token added only in PHP
  history.php             Filtered existing ex_turns, at most 50 rows
  tmpl/header.php         Shared navigation and opening HTML
  tmpl/footer.php         Closing HTML
  tmpl/navbar.php         HerikaServer centered brand/dropdown format
  tmpl/section_navigation.php Grouped link tabs, no iframes or tab-switching framework
  navbar.js               Local dropdown interaction, no Bootstrap dependency
  preview.js              Sample filters from the old Profiles preview; no page loads it now
  css/style_new.css       HerikaServer base typography excerpt
  css/navbar.css          HerikaServer navbar and brand-menu presentation excerpts
  css/chim-theme.css      HerikaServer shared controls, panels, tables and page headers
  css/hub-navigation.css  HerikaServer grouped section-tab presentation
  css/style.css           Example layout and minimal dropdown primitives
  images/question-mark.png Generated question-mark branding icon
  images/navbarback.png   Original HerikaServer star background
  HERIKA-LICENSE          Preserved upstream MIT notice
  examples/
    index.php             Links to copyable pages
    blank.php             Empty panel
    form.php              Validated, CSRF-protected demonstration
    table.php             Escaped sample rows
```

## Working tools and previews

Working tools: Home status, Conversation, History, Checkpoints, Memory, NPC bios, Profiles,
Server settings, LLM, Voice, NPC selection, Memory settings, API keys, Logs, Connector calls,
Diagnostics and Backups.
Fictional previews: `ui/examples/` (listed under **Control Panel > Page examples**). Previews
save nothing and read no private data. To make one real, replace its sample array (`$rows`)
with a bounded, parameterized source and a CSRF-checked save, or point a tab in
`ui/navigation.php` at your own page. `ui/npc_bios.php` and `ui/profiles.php` (which replaced
the old fictional Profiles preview) are worked examples of that change.

Profiles saves to `ex_profiles` and `ex_npc_profiles` (`sql/005_profiles.sql`) with four fixed
actions (`save`, `delete` with a confirmation box, `assign`, `unassign`). Overrides are
validated by `profile_validate()` in `lib/profiles.php`; there is no URL or key field, and the
page states what the empty fields inherit. Memory (`ui/memory.php`) and Memory settings follow
the same pattern; the only model or provider calls are the explicit Regenerate and Reindex
buttons, which release the PHP session first and save nothing on a stale or failed result.

NPC bios saves to `ex_npc_bios` and `ex_knowledge` (`sql/002_npc_knowledge.sql`). Each
write is a plain POST with the CSRF field and one of four fixed actions (`save_bio`,
`add_fact`, `edit_fact`, `delete_fact`), then a redirect back, so a refresh does not resubmit. Failed
checks keep the typed values and mark the field. Missing config, a stopped database or a
missing migration show a notice instead of the forms. Character choices are GET buttons,
so the page needs no JavaScript. Lists are capped at 50 rows; each scope at 50 facts.

## Add a page

Copy `ui/examples/blank.php` to `ui/my_page.php`. Change its relative includes from
`../common.php` to `common.php` and `../tmpl/` to `tmpl/`. Set its title and active key:

```php
<?php
require __DIR__ . '/common.php';
$title = 'My page';
$active = 'my_page';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <h2>My section</h2>
    <p>Add your content here.</p>
</section>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
```

Add `'my_page' => ['My page', 'my_page.php']` to the appropriate group in
`ui/navigation.php`. Its key must match your page's `$active` value. The navbar and section
tabs share that map, so the page needs no separate menu wiring.
Use `e($value)` whenever printing variable content. Use `.chim-panel`, `.fields`, `.links`
and `.table-wrap` rather than writing a different layout on every page.

## Prompt for a coding agent

```text
Read AGENTS.md, UI_GUIDE.md and the relevant example page first.
Add one plain PHP page using common.php and the shared header/footer.
Keep the same compact dark style. Put all game/server secrets on the server.
Keep this baseline dashboard open. Check CSRF for every state-changing POST.
Escape displayed values with e(). Parameterize and bound database queries.
Save config only through config_update() in lib/config_writer.php, and add any new key
to CONFIG_EDITABLE_KEYS deliberately. Never print secrets; show Saved / Not set.
Do not copy HerikaServer runtime code, add a framework, polling or a schema change
without a separate request.
Lint PHP and verify desktop/narrow layouts, keyboard navigation and error states.
Report exactly what changed and what was tested.
```

## Boundaries

Presentation comes from HerikaServer's `ui/tmpl/head.html`, `navbar.php` and `footer.html`,
plus `ui/css/style_new.css`, `navbar.css`, `chim-theme.css` and `hub-navigation.css`.
The centered brand-button markup, animated star background and CSS declarations are
reused locally, with example-page links. ExampleServer's branding uses blue accents,
a generated question-mark icon and a Times New Roman navbar label. The excerpts
retain the upstream layout and component rules with a blue accent palette;
`style.css` supplies page layout, the brand font and the small
dropdown primitives normally supplied by Bootstrap. The main wrapper follows
`HerikaServer/ui/home.php`'s `.container`, with the exact responsive widths and 1.5rem
gutters from its bundled `ui/lib/ui/bootstrap/bootstrap.min.css`. At 1280px the container
is 1140px wide and its usable content is 1116px. `navbar.js` supports click, outside
click, Escape, arrows and keyboard focus. No remote assets are required.

Headings, title and navigation use Times New Roman. Normal body text uses Arial. No custom
font file is loaded or included.

The top menu uses Home, Roleplay, Configuration and Control Panel. Section navigation
follows `HerikaServer/ui/tmpl/events_memories_navigation.php`, `ui/core/config_hub.php`
and `ui/control_panel.php`: labeled `.tab-group` blocks contain `.tab-button` links, with
the current page marked active. These are normal page links, not JavaScript tabs or
embedded iframes. Roleplay has a Dialogue group; Configuration has Settings, AI & Voice and
World & Behavior groups (Memory settings is in AI & Voice, Profiles in World & Behavior);
Roleplay's Dialogue group has Memory; Control Panel has Diagnostics (with Connector calls)
and Page examples. The map lives in
`ui/navigation.php`.

The pages in `ui/examples/` use fictional rows defined next to their display code. They are
not private information, game state or input to the conversation model, and none of them
reads or writes config, logs or database records.

HerikaServer's runtime bootstrap, version/profile queries, character selector, background
work and form-helper scripts are not included. Their server behavior does not belong in
this example. The upstream copyright/license is preserved in `ui/HERIKA-LICENSE`.

### ExampleServer icon

`ui/images/question-mark.png` is an original asset generated with the built-in image
generation tool and copied unchanged. It is separate from the retained HerikaServer
background and presentation styles. The transparent flat blue 2D question mark is
displayed at 50 × 50 pixels. Generation prompt:

> Create one extremely simple flat 2D question-mark icon for a website navbar. A single
> bold '?' with a separate circular dot. Solid medium blue #4da3ff, clean rounded
> silhouette, uniform flat fill. Centered square composition with small padding.
> Transparent background with true alpha. Readable at 50x50 pixels. No gradients,
> shading, highlights, lighting, glow, outlines, textures, metallic surfaces, circuits,
> bevels, shadows, 3D depth, frames, or other decorations. Minimal plain graphic design.
> Only the question mark.

The baseline dashboard opens directly without login. Use localhost or a trusted network.
The PHP session stores only a CSRF token. The browser never receives provider keys,
database credentials or the server token in page source/JavaScript. Private configuration
stays untracked; the installer generates a token for each install, and mod API endpoints
keep checking it.

The bridge accepts only turn, cancel, event, speak, listen and decision, checks CSRF, closes the PHP
session lock, then dispatches the existing handlers (so the voice and decision tests keep
those handlers' limits: 1000 characters of text, 10 MB of WAV, configured timeouts). Those handlers keep protocol/input validation,
database transactions and generation checks. A slow request cannot hold the session lock
and prevent a cancellation request. Concurrent PHP workers are still required by the web
server to handle cancellation while another request is running.

The conversation tester defaults to session `dashboard-demo` and NPC `npc_guide`; both can be changed on the page. Each browser send has a
fresh request ID. Cancel suppresses the old browser result and calls the server's cancel
handler. Other browser tabs using those IDs share history and can replace each other's
turns. Only retained lines are visible; changing a display filter never deletes history.

### Settings pages and the config writer

Server settings, LLM, Voice, NPC selection, Memory settings and API keys each save one
section through `config_update()` in `lib/config_writer.php`: lock file next to the config,
read the latest file, apply the change, write a temporary `var_export()` file in the same folder with the
config's owner, group and mode, read it back, rename it, invalidate OPcache. Only the
sections in `CONFIG_EDITABLE_KEYS` may change; `token`, `database`, `backup_dir` and unknown
keys are kept. File comments are not kept. If the file, folder or ownership does not allow
that exact sequence, the page shows why and nothing is written (SETUP.md section 1).

Settings offers only actions the example client handles (`follow_player`); actions added by
hand to the config stay allowed. API key fields are always blank password inputs; a blank
field keeps the saved key and a ticked clear box removes it. Tests run only on an explicit
POST and may call your provider. A failed LLM test shows one fixed reason (for example the
HTTP status with a short hint, a network problem or a missing key or model), never the
provider's reply body, and logs only the error class. Voice tests play the returned WAV
from a blob URL and let you edit or type the transcript.

Checkpoints share `lib/checkpoint.php` with `scripts/checkpoint.php`. Backups use
`lib/backup.php`: fixed `/usr/bin` binaries via `proc_open()` with an argument array, the
password in `PGPASSWORD`, size/time/output limits, a private 0700 folder and generated file
names only. Dashboard requests do not start workers, apply migrations or change other
products.

### Page layout references

Home adapts the compact widget headers/grid in `HerikaServer/ui/home.php`. Configuration
places grouped navigation before its compact title, matching `ui/core/config_hub.php`.
The Profiles sidebar/editor and compact setting rows follow `ui/core/core_profiles.php`
(`.llm-layout`, `.llm-left`, `.setting-row`). NPC bios adapts the biography form convention
in `ui/npc_upload.php` without upload/import functionality; it saves with ordinary forms. History uses a compact filter
bar and dense retained-record table; Logs uses the same filter toolbar and dense table with
keyset paging and a details view that separates server results from untrusted client reports. Shared responsive rules are in `ui/css/style.css`. No reference backend code,
iframes or polling is included. Keep sample edits visibly marked as unsaved.
