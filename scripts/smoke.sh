#!/usr/bin/env bash
# Quick endpoint checks against a running server that uses the mock LLM.
# Usage: bash scripts/smoke.sh [base_url]
#   base_url defaults to http://127.0.0.1:8081/ExampleServer
#   Run as a user that can read the config (for example dwemer).
#   SMOKE_STALE=1 also tests cancellation; needs llm.mock_delay_seconds >= 2.
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${EXAMPLE_AI_CONFIG:-$APP_DIR/config/config.php}"
BASE="${1:-http://127.0.0.1:8081/ExampleServer}"
TOKEN="$(php -r '$c = require $argv[1]; echo $c["token"];' "$CONFIG")"
RUN="$(date +%s)$RANDOM"
CANCEL="{\"protocol\":1,\"session_id\":\"smoke-$RUN\",\"npc_id\":\"npc_smoke\"}"
failures=0

# post <path> <body> [token]: prints the body, then the HTTP status on its own line.
post() {
    local auth=()
    if [ -n "${3:-}" ]; then auth=(-H "Authorization: Bearer $3"); fi
    curl -sS -m 30 -w '\n%{http_code}' -H 'Content-Type: application/json' "${auth[@]}" --data "$2" "$BASE/$1"
}

# expect <name> <status> <text> <response>
expect() {
    local status="${4##*$'\n'}"
    if [ "$status" = "$2" ] && [[ "$4" == *"$3"* ]]; then
        echo "PASS $1"
    else
        echo "FAIL $1 (wanted HTTP $2 containing $3): ${4//$'\n'/ }"
        failures=$((failures + 1))
    fi
}

# turn_json <request_id> <text>
turn_json() {
    printf '{"protocol":1,"request_id":"%s","session_id":"smoke-%s","npc":{"id":"npc_smoke","name":"Smokey"},"player":{"name":"Tester"},"context":{"location":"Test Hall"},"text":"%s"}' "$1" "$RUN" "$2"
}

big_text="$(head -c 20000 /dev/zero | tr '\0' a)"

expect "health" 200 '"ok":true' "$(curl -sS -m 10 -w '\n%{http_code}' "$BASE/health.php")"
expect "wrong method" 405 'method_not_allowed' "$(curl -sS -m 10 -w '\n%{http_code}' -H "Authorization: Bearer $TOKEN" "$BASE/turn.php")"
expect "no token" 401 'unauthorized' "$(post turn.php "$(turn_json "r-$RUN-1" hello)")"
expect "wrong token" 401 'unauthorized' "$(post turn.php "$(turn_json "r-$RUN-1" hello)" wrong-token-123456)"
expect "malformed json" 400 'bad_json' "$(post turn.php '{"protocol":1,' "$TOKEN")"
expect "wrong protocol" 400 'bad_request' "$(post turn.php '{"protocol":2}' "$TOKEN")"
expect "bad npc id" 400 'npc.id' "$(post turn.php '{"protocol":1,"request_id":"abcdefgh","session_id":"s","npc":{"id":"bad id!","name":"x"},"player":{"name":"p"},"text":"hi"}' "$TOKEN")"
expect "too large" 413 'too_large' "$(post turn.php "{\"protocol\":1,\"text\":\"$big_text\"}" "$TOKEN")"
expect "mock reply" 200 'Smokey heard you say: hello' "$(post turn.php "$(turn_json "r-$RUN-2" hello)" "$TOKEN")"
expect "follow allowed" 200 '"actions":[{"name":"follow_player","args":{"target_id":"npc_smoke"}}]' "$(post turn.php "$(turn_json "r-$RUN-3" 'follow me')" "$TOKEN")"
expect "dance rejected" 200 '"actions":[],"rejected_actions":["dance"]' "$(post turn.php "$(turn_json "r-$RUN-4" 'dance for me')" "$TOKEN")"
expect "duplicate id" 409 'duplicate_request' "$(post turn.php "$(turn_json "r-$RUN-4" 'dance for me')" "$TOKEN")"
# result_json <request_id> <status>: what the client says it did with follow_player.
result_json() {
    printf '{"protocol":1,"request_id":"%s","session_id":"smoke-%s","npc_id":"npc_smoke","results":[{"name":"follow_player","status":"%s"}]}' "$1" "$RUN" "$2"
}
expect "result reported" 200 '"repeat":false' "$(post result.php "$(result_json "r-$RUN-3" handled)" "$TOKEN")"
expect "result repeat" 200 '"repeat":true' "$(post result.php "$(result_json "r-$RUN-3" handled)" "$TOKEN")"
expect "result changed" 409 'already_reported' "$(post result.php "$(result_json "r-$RUN-3" failed)" "$TOKEN")"
expect "result not returned" 409 'action_not_returned' "$(post result.php "$(result_json "r-$RUN-4" handled)" "$TOKEN")"
expect "result unknown" 404 'unknown_request' "$(post result.php "$(result_json "r-$RUN-unknown" handled)" "$TOKEN")"
expect "result bad status" 400 'bad_request' "$(post result.php "$(result_json "r-$RUN-3" done)" "$TOKEN")"
# event_json <request_id> <respond>: one game event for the smoke NPC.
event_json() {
    printf '{"protocol":1,"request_id":"%s","session_id":"smoke-%s","type":"item_given","npc":{"id":"npc_smoke","name":"Smokey"},"player":{"name":"Tester"},"text":"The tester hands over a lantern.","respond":%s}' "$1" "$RUN" "$2"
}
expect "event log only" 200 '"respond":false,"logged":true' "$(post event.php "$(event_json "e-$RUN-1" false)" "$TOKEN")"
expect "event duplicate" 409 'duplicate_request' "$(post event.php "$(event_json "e-$RUN-1" false)" "$TOKEN")"
expect "event respond" 200 'Smokey heard you say: Game event (item_given)' "$(post event.php "$(event_json "e-$RUN-2" true)" "$TOKEN")"
expect "cancel" 200 '"generation":' "$(post cancel.php "$CANCEL" "$TOKEN")"

# Tracing (optional protocol 1 request ids). Decision always answers 200; voice may be on or off,
# so only the echoed id is checked there.
DECIDE='"transcript":"Scout, wait.","baseline_id":"npc_guide","candidates":[{"id":"npc_guide"},{"id":"npc_scout","name":"Scout"}]'
expect "decision echoes request id" 200 "\"request_id\":\"dec-$RUN\"" "$(post decision.php "{\"protocol\":1,\"request_id\":\"dec-$RUN\",$DECIDE}" "$TOKEN")"
expect "decision makes request id" 200 '"request_id":"srv-' "$(post decision.php "{\"protocol\":1,$DECIDE}" "$TOKEN")"
expect "decision bad request id" 400 'request_id' "$(post decision.php "{\"protocol\":1,\"request_id\":\"bad id\",$DECIDE}" "$TOKEN")"
# header_has <name> <header line, lowercase> <response headers>
header_has() {
    if [[ "$(printf '%s' "$3" | tr 'A-Z' 'a-z')" == *"$2"* ]]; then
        echo "PASS $1"
    else
        echo "FAIL $1 (wanted header $2)"
        failures=$((failures + 1))
    fi
}
speak_body="{\"protocol\":1,\"request_id\":\"tts-$RUN\",\"parent_request_id\":\"r-$RUN-2\",\"npc_id\":\"npc_smoke\",\"text\":\"hello\"}"
header_has "speak echoes request id" "x-request-id: tts-$RUN" "$(curl -sS -m 30 -D - -o /dev/null \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" --data "$speak_body" "$BASE/speak.php")"
# Not WAV on purpose: the reply is bad_audio (or stt_disabled), so no provider is contacted.
header_has "listen echoes request id" "x-request-id: stt-$RUN" "$(curl -sS -m 30 -D - -o /dev/null -H 'Content-Type: audio/wav' \
    -H "Authorization: Bearer $TOKEN" -H "X-Request-Id: stt-$RUN" -H "X-Parent-Request-Id: r-$RUN-2" --data 'not a wav' "$BASE/listen.php")"
expect "health lists trace" 200 '"trace"' "$(curl -sS -m 10 -w '\n%{http_code}' "$BASE/health.php")"

if curl -sS -m 10 "$BASE/config/config.php" | grep -q -- "$TOKEN"; then
    echo "FAIL config not served: the token is visible over HTTP"
    failures=$((failures + 1))
else
    echo "PASS config not served"
fi

if [ "${SMOKE_STALE:-0}" = "1" ]; then
    out="$(mktemp)"
    post turn.php "$(turn_json "r-$RUN-5" 'slow hello')" "$TOKEN" > "$out" &
    sleep 1
    post cancel.php "$CANCEL" "$TOKEN" > /dev/null
    wait
    expect "cancelled turn is stale" 409 '"code":"stale"' "$(cat "$out")"
    rm -f "$out"
fi

if [ "$failures" -gt 0 ]; then
    echo "$failures check(s) failed."
    exit 1
fi
echo "All checks passed."
