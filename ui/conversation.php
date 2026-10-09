<?php
require __DIR__ . '/common.php';
// Saved biographies for the NPC picker. Read-only and bounded; the page still works without them.
$savedNpcs = [];
$npcNotice = '';
$config = ui_config();
$db = $config === null ? null : ui_database($config);
$list = $db === null ? false : @pg_query($db, 'SELECT npc_id, name FROM ex_npc_bios ORDER BY lower(name), npc_id LIMIT 50');
if ($list === false) {
    $npcNotice = 'Saved biographies could not be loaded; type an NPC ID and name instead.';
} else {
    $savedNpcs = pg_fetch_all($list);
}
$title = 'Conversation';
$active = 'conversation';
require __DIR__ . '/tmpl/header.php';
?>
<section class="chim-panel">
    <p>Send real test turns using the configured reply mode. This can call your provider and writes retained conversation history for the chosen session and NPC. Actions are displayed here, not executed.</p>
    <form id="conversation">
        <input type="hidden" id="csrf" value="<?= e($csrf) ?>">
        <label for="npc-pick">NPC</label>
        <select id="npc-pick">
            <?php foreach ($savedNpcs as $npc): ?><option value="<?= e($npc['npc_id']) ?>" data-name="<?= e($npc['name']) ?>"><?= e($npc['name']) ?> (<?= e($npc['npc_id']) ?>)</option><?php endforeach; ?>
            <option value=""<?= $savedNpcs ? '' : ' selected' ?>>Other NPC (type the ID and name)</option>
        </select>
        <?php if ($npcNotice !== ''): ?><p class="help" role="status"><?= e($npcNotice) ?></p><?php endif; ?>
        <div class="fields">
            <div><label for="npc-id">NPC ID</label><input type="text" id="npc-id" value="npc_guide" maxlength="64" pattern="[A-Za-z0-9_.:\-]{1,64}" required></div>
            <div><label for="npc">NPC name</label><input type="text" id="npc" value="Guide" maxlength="64" required></div>
        </div>
        <div class="fields">
            <div><label for="player">Player name</label><input type="text" id="player" value="Traveler" maxlength="64" required></div>
            <div><label for="session">Session ID <small>(history and checkpoints are per session)</small></label>
                <input type="text" id="session" value="dashboard-demo" maxlength="64" pattern="[A-Za-z0-9_.:\-]{1,64}" required></div>
        </div>
        <div class="fields">
            <div><label for="location">Context: location <small>(optional)</small></label><input type="text" id="location" maxlength="200"></div>
            <div><label for="time-of-day">Context: time of day <small>(optional)</small></label><input type="text" id="time-of-day" maxlength="200"></div>
        </div>
        <label for="text">Your message</label>
        <textarea id="text" maxlength="1000" rows="3" required></textarea>
        <div class="links">
            <button id="send">Send</button>
            <button class="secondary" type="button" id="cancel" disabled>Cancel</button>
            <button class="secondary" type="button" id="new-session">New session</button>
        </div>
    </form>
    <p class="help">Changing the NPC or session discards any reply still on its way and cancels it on the server, so a reply is never shown for the wrong NPC. Cancellation may not stop provider computation.</p>
</section>
<section class="chim-panel">
    <h2>Game event test</h2>
    <p>Sends <code>event.php</code> for the session and NPC above. <strong>Log only</strong> stores the event in Logs without a model call, history or actions. <strong>NPC responds</strong> asks the chosen NPC to react, like a turn.</p>
    <form id="game-event">
        <div class="fields">
            <div><label for="event-type">Event type</label><select id="event-type">
                <option>location_entered</option><option>item_given</option><option>combat_started</option><option>combat_ended</option>
            </select></div>
            <div><label for="event-respond">Mode</label><select id="event-respond">
                <option value="log">Log only</option><option value="respond">NPC responds</option>
            </select></div>
        </div>
        <label for="event-text">Event text</label>
        <input type="text" id="event-text" maxlength="300" required value="The player gave the NPC a brass lantern.">
        <div class="links"><button id="send-event">Send game event</button></div>
    </form>
</section>
<section class="chim-panel" aria-live="polite">
    <h2>Conversation</h2>
    <p id="status">Ready for a message.</p>
    <p id="trimmed" class="help" hidden>Older lines were removed from this view (it keeps the last 100). Stored history is unchanged.</p>
    <ol id="transcript" class="reply"></ol>
    <div class="links"><button class="secondary" type="button" id="clear">Clear this view</button></div>
    <p class="help">Clearing the view does not delete stored history. Each request appears in <a href="logs.php">Logs</a> with what was retrieved for it.</p>
</section>
<script>
const $ = id => document.getElementById(id);
const send = $('send');
const cancel = $('cancel');
const status = $('status');
let generation = 0;      // bumped by every send, cancel and NPC or session change
let pending = null;      // {requestId, sessionId, npcId} of the reply still wanted
let cancelling = 0;      // server cancels not yet answered; new turns wait for them
let logging = false;     // a log-only event is being sent
const MAX_VIEW_LINES = 100;

// One place decides which buttons work. A late server cancel bumps the NPC's generation, so a
// turn sent before it is answered could be made stale by it: Send waits until it is answered.
function updateControls() {
    send.disabled = pending !== null || cancelling > 0;
    $('send-event').disabled = pending !== null || cancelling > 0 || logging;
    cancel.disabled = pending === null;
}

// The browser sends only ordinary protocol input and a CSRF token; PHP adds authorization.
async function request(operation, body) {
    const response = await fetch('api.php?operation=' + operation, {
        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': $('csrf').value},
        body: JSON.stringify(body)
    });
    let result = null;
    try { result = await response.json(); } catch { throw new Error('The server sent an unreadable reply (HTTP ' + response.status + ').'); }
    if (!response.ok || !result.ok) throw new Error((result.error?.code ? result.error.code + ': ' : '') + (result.error?.message || 'Request failed.'));
    return result;
}
const newId = prefix => prefix + Array.from(crypto.getRandomValues(new Uint8Array(12)), b => b.toString(16).padStart(2, '0')).join('');
function target() {
    const id = $('npc-id').value.trim();
    const session = $('session').value.trim();
    if (!/^[A-Za-z0-9_.:-]{1,64}$/.test(id)) throw new Error('NPC ID must be 1-64 characters of A-Z a-z 0-9 _ . : -');
    if (!/^[A-Za-z0-9_.:-]{1,64}$/.test(session)) throw new Error('Session ID must be 1-64 characters of A-Z a-z 0-9 _ . : -');
    const context = {};
    if ($('location').value.trim()) context.location = $('location').value.trim();
    if ($('time-of-day').value.trim()) context.time_of_day = $('time-of-day').value.trim();
    return {id, name: $('npc').value.trim(), session, player: $('player').value.trim(), context};
}
function addLine(who, text, extra) {
    const item = document.createElement('li');
    const name = document.createElement('strong');
    name.textContent = who + ': ';
    item.append(name, text);
    if (extra) { const small = document.createElement('small'); small.textContent = ' ' + extra; item.append(small); }
    $('transcript').append(item);
    // Only this view is trimmed; stored history is never changed here.
    while ($('transcript').children.length > MAX_VIEW_LINES) {
        $('transcript').firstElementChild.remove();
        $('trimmed').hidden = false;
    }
}
function actionText(result) {
    const actions = result.actions.map(a => a.name + (a.args?.target_id ? ' (target ' + a.args.target_id + ')' : ''));
    return 'Actions: ' + (actions.join(', ') || 'none') + (result.rejected_actions.length ? '; refused by server: ' + result.rejected_actions.join(', ') : '');
}
// Drops the wanted reply and tells the server, so a late reply is never applied.
// The reply is discarded at once; Send stays off until the server answers the cancel, or the
// cancel fails (then sending again is allowed, by hand only).
async function dropPending(reason) {
    ++generation;
    const old = pending;
    pending = null;
    if (!old) { updateControls(); return false; }
    ++cancelling;
    updateControls();
    status.textContent = reason + ' The pending reply was discarded. Waiting for the server to confirm the cancel…';
    try {
        await request('cancel', {protocol: 1, session_id: old.sessionId, npc_id: old.npcId});
        return true;
    } catch (error) {
        status.textContent = reason + ' Reply discarded locally; server cancellation failed: ' + error.message + ' You can send again.';
        return false;
    } finally {
        --cancelling;
        updateControls();
    }
}
// Shared by messages and "NPC responds" events: one wanted reply at a time.
async function exchange(operation, body, t, label) {
    const current = ++generation;
    pending = {requestId: body.request_id, sessionId: t.session, npcId: t.id};
    updateControls();
    status.textContent = 'Waiting for ' + (t.name || t.id) + '…';
    addLine(label, operation === 'event' ? body.text + ' (' + body.type + ')' : body.text, '[' + t.session + ' / ' + t.id + ']');
    try {
        const result = await request(operation, body);
        // Apply only the reply this view still wants, for the same request and NPC.
        if (current !== generation || !pending) return;  // cancelled or replaced: stale, ignored
        if (result.request_id !== pending.requestId || result.npc_id !== pending.npcId) {
            status.textContent = 'Ignored a reply for another request or NPC; nothing was shown. Nothing was retried; send again to try a new request.';
            return;
        }
        addLine(t.name || t.id, result.reply, actionText(result) + ' · request ' + result.request_id);
        status.textContent = 'Reply received.' + (result.logged === false ? ' The server could not log it.' : '');
    } catch (error) {
        if (current === generation) { status.textContent = 'Not sent or failed (request ' + body.request_id + '): ' + error.message + ' Nothing was retried; send again to try a new request.'; }
    } finally {
        if (current === generation) { pending = null; updateControls(); }
    }
}
$('conversation').addEventListener('submit', event => {
    event.preventDefault();
    if (send.disabled) return;
    let t;
    try { t = target(); } catch (error) { status.textContent = error.message; return; }
    exchange('turn', {protocol: 1, request_id: newId('ui-'), session_id: t.session, npc: {id: t.id, name: t.name},
        player: {name: t.player}, context: t.context, text: $('text').value}, t, t.player || 'You');
});
$('game-event').addEventListener('submit', async event => {
    event.preventDefault();
    if ($('send-event').disabled) return;
    let t;
    try { t = target(); } catch (error) { status.textContent = error.message; return; }
    const body = {protocol: 1, request_id: newId('ui-evt-'), session_id: t.session, type: $('event-type').value,
        text: $('event-text').value, context: t.context, npc: {id: t.id, name: t.name}};
    if ($('event-respond').value === 'respond') {
        body.respond = true;
        body.player = {name: t.player};
        return exchange('event', body, t, 'Game event');
    }
    // One log-only event at a time, so a repeated click cannot store it twice.
    logging = true;
    updateControls();
    try {
        const result = await request('event', body);
        addLine('Game event', body.text + ' (' + body.type + ')', 'logged only, request ' + result.request_id);
        status.textContent = 'Event stored in Logs. No reply, history or actions.';
    } catch (error) {
        status.textContent = 'Event not stored: ' + error.message;
    } finally {
        logging = false;
        updateControls();
    }
});
cancel.addEventListener('click', () => dropPending('Cancelled.').then(ok => { if (ok) status.textContent = 'Cancelled. The server confirmed it; old replies are discarded. You can send again.'; }));
// Any change of who or which session invalidates the reply still on its way.
for (const id of ['npc-id', 'npc', 'session']) {
    $(id).addEventListener('input', () => {
        if (id === 'npc-id') $('npc-pick').value = Array.from($('npc-pick').options).some(o => o.value === $('npc-id').value) ? $('npc-id').value : '';
        if (pending) dropPending('NPC or session changed.');
    });
}
$('npc-pick').addEventListener('change', () => {
    const option = $('npc-pick').selectedOptions[0];
    if (option.value !== '') { $('npc-id').value = option.value; $('npc').value = option.dataset.name; }
    if (pending) dropPending('NPC changed.');
});
$('new-session').addEventListener('click', () => {
    $('session').value = newId('ui-session-').slice(0, 30);
    if (pending) dropPending('New session started.');
    status.textContent = 'New session ' + $('session').value + '. Shared NPC bios and facts still apply; this session has no history yet.';
});
$('clear').addEventListener('click', () => { $('transcript').textContent = ''; $('trimmed').hidden = true; });
// Start with the first saved NPC, if any.
if ($('npc-pick').value !== '') $('npc-pick').dispatchEvent(new Event('change'));
</script>
<?php require __DIR__ . '/tmpl/footer.php'; ?>
