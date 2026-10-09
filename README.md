# ExampleServer

A small example AI server for DwemerDistro, and a template for your own AI NPC mod's
server. A game sends what the player said to an NPC; the server answers with the NPC's
reply and, at most, one safe action (`follow_player`).

Its companion client is **[ExampleMod](https://github.com/Dwemer-Dynamics/ExampleMod)**, a
Windows console "fake game" that shows the game side. Neither is a plugin for a real game.

## Features

- Plain PHP 8.2 with built-in extensions and its own PostgreSQL database. No Composer, no
  framework.
- Mock replies by default, so the first run needs no provider or key. OpenAI, OpenRouter, a
  local OpenAI-compatible server or DwemerDistro LLM Studio when you configure one.
- Per-session conversation history, NPC bios and knowledge facts, session checkpoints,
  backups and an event log you can search by request ID.
- Game events (log only, or the NPC reacts), cancellation and stale-reply protection.
- Optional, off by default: voice (PocketTTS, Parakeet or faster-whisper), NPC selection
  (`decision.php`), profiles, advanced memory and vector search over facts.
- A dashboard with working configuration, roleplay test and control panel pages.
- Installs manually or from the DwemerDistro launcher's **Custom mods** view, with `main`
  and `dev` branches.

## Quick start

**From the launcher:** open **Mods > Custom mods > Add custom mod** and paste
`https://github.com/Dwemer-Dynamics/ExampleServer`. See
[LAUNCHER_CUSTOM_MOD.md](LAUNCHER_CUSTOM_MOD.md).

**Manually**, inside the DwemerDistro WSL shell as `dwemer`:

```bash
git clone https://github.com/Dwemer-Dynamics/ExampleServer.git /var/www/html/ExampleServer
cd /var/www/html/ExampleServer
sudo bash scripts/install.sh
curl http://127.0.0.1:8081/ExampleServer/health.php
bash scripts/smoke.sh
```

Then open the dashboard at `http://127.0.0.1:8081/ExampleServer/`. To build and pair the
client, follow [SETUP.md](SETUP.md); it covers both repositories.

## Documentation

| Read | For |
|---|---|
| [START_HERE.md](START_HERE.md) | Orientation, feature status, end-to-end walkthrough, troubleshooting by request ID, porting checklist |
| [SETUP.md](SETUP.md) | Install, pairing, LLM and voice setup, backups, updates, migrations, checkpoints, removal |
| [CONNECTORS.md](CONNECTORS.md) | Copyable LLM, voice, NPC selection, profile and memory settings |
| [PROTOCOL.md](PROTOCOL.md) | The request and reply contract (identical in both repositories) |
| [UI_GUIDE.md](UI_GUIDE.md) | Dashboard pages, a guided tour, and adding your own pages |
| [LAUNCHER_CUSTOM_MOD.md](LAUNCHER_CUSTOM_MOD.md) | Launcher install, `main`/`dev` branches, publishing your own copy |
| [MAKE_IT_YOURS.md](MAKE_IT_YOURS.md) | Renaming and customising your copy |
| [WAVE_ACTION.md](WAVE_ACTION.md) | Worked exercise: adding a game action |
| [AGENTS.md](AGENTS.md) | Rules for coding agents |
| [ExampleMod README](https://github.com/Dwemer-Dynamics/ExampleMod/blob/main/README.md) | Client commands, settings and smoke checks |
| [AGENT_PLAYBOOK.md](https://github.com/Dwemer-Dynamics/ExampleMod/blob/main/AGENT_PLAYBOOK.md) | Prompt for porting to your engine with a coding agent |

## Limits

- A template, not a product: no real game is supported until someone ports and tests the
  client in that game.
- Meant for one PC or a trusted local network. The dashboard has **no login**, there is
  **no rate limiting**, and the token travels over plain HTTP. Anyone who can reach the
  dashboard can change settings and API keys, restore checkpoints and create backups.
- The checkout sits inside Apache's web root. Private config and `lib/` files print nothing
  through PHP, but nothing here stops Apache from serving other files such as `.git/`.
  Check your install as [START_HERE.md](START_HERE.md#deployment-and-exposure) describes.
- The model can only *ask* for allowlisted actions; the server and the game each check
  them, and nothing runs commands. Failed requests are never retried automatically, and
  cancellation may not stop computation at the provider.
- Real LLM providers and voice services are optional code paths. The smoke checks cover
  mock mode only; test your own providers before relying on them.

## Licence

Copyright (C) 2026 Dwemer Dynamics. Licensed under the GNU General Public License,
version 3 only (`GPL-3.0-only`); see [LICENSE](LICENSE). The dashboard presentation adapted
from HerikaServer keeps its MIT notice; see
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
