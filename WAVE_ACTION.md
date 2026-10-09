# Worked extension: wave

This is an optional exercise in your own copies. The shipped default remains
`follow_player` only. No new dependency, endpoint or schema is needed. `wave` has no
parameters: it means the current NPC waves. Keep the original follow handler.

## 1. Server: teach and allow the action

In `lib/llm.php`, insert this branch in `mock_reply()` right after the `dance` branch:

```php
if (str_contains($text, 'wave')) {
    return 'Hello there! [ACTION:wave]';
}
```

Replace the last instruction in `openai_reply()`'s `$system` string with:

```php
. "If you agree to follow the player, end with [ACTION:follow_player]. "
. "If asked to wave, end with [ACTION:wave]. Use no other tags.";
```

In your private `config/config.php`, change only the action allowlist:

```php
'allowed_actions' => ['follow_player', 'wave'],
```

For a project that intentionally ships wave, make the same change in
`config/config.example.php`. Keep `extract_actions()` unchanged: it removes tags from
spoken text and returns only allowlisted names. Never execute raw model text.

## 2. Client: give wave one explicit game method

Add this public method to `GameAdapter` in `src/game_adapter.h`:

```cpp
// The current NPC performs the engine's wave animation on the game thread.
virtual void wave(const std::string& npcId) = 0;
```

Add this public console implementation to `src/console_game.h`:

```cpp
void wave(const std::string& npcId) override
{
    std::cout << "* " << npcId << " waves (simulated).\n";
}
```

In `src/main.cpp`'s `applyResult()`, insert this branch after the `follow_player` branch,
before the final rejection `else`. It reports the action like `follow_player` does:

```cpp
} else if (action.name == "wave" && isAllowed(config, action.name)) {
    game.wave(context.npcId);
    if (reportable) {
        reports.push_back({action.name, "handled", ""});
    }
```

The client already accepts an action without `args`, so `ai_client.cpp` needs no change.

In your private client `config.json`, set:

```json
"allowed_actions": ["follow_player", "wave"]
```

Keep the braces/other settings of the existing JSON object. To ship wave deliberately,
update `config.default.json` too. A real adapter must implement wave using its engine's
actual animation API, on the game thread. The console output is only a simulation.

## 3. Keep both contracts identical

In BOTH copies of PROTOCOL.md, define `wave` as an optional allowlisted action with no
parameters, and add a mock row: wave / Hello there! / action wave. Explain that
clients without an explicit handler reject it. This optional action uses the existing
version-1 action shape; no version bump is needed. An incompatible shape needs a bump.

## 4. Prove both allowlists

Build x64 and x86 with `scripts/build.ps1`. Run PHP lint and server smoke checks.
Then run `example_mod.exe --say "wave"` against your mock server:

| Case | Expected |
|---|---|
| Server and client allow wave | Clean subtitle `Hello there!`, then simulated wave |
| Server excludes wave | HTTP reply has `actions: []`, `rejected_actions: ["wave"]`; the console shows the subtitle only |
| Client excludes wave | `Rejected action not allowed by this mod: wave`; no wave |
| Follow request | Existing follow action still works |
| Unknown dance | Server rejects dance as before |

Use a fresh request ID for each HTTP probe. Keep cancellation and stale-response checks
unchanged. Only claim a real wave after testing the engine implementation in game.
