<?php
// Optional: adds two fictional NPC bios and a few facts so retrieval can be tried.
// Nothing runs this automatically. Existing bios and facts are never overwritten:
// rows that already exist (same NPC id, or same topic in the same scope) are skipped.
// Usage: php scripts/seed_example.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/app.php';

// npc_guide is the NPC the ExampleMod console client talks to.
$bios = [
    ['npc_guide', 'Mira Stonebridge', 'Market guide', 'Mira knows every stall in the town square. She enjoys helping travelers and remembers landmarks better than names.'],
    ['npc_scout', 'Oren Reed', 'Woodland scout', 'Oren watches the trails beyond town. He speaks carefully, carries a worn map and prefers a quiet campfire to a crowded inn.'],
];
// [npc_id or null for global, topic, fact]
$facts = [
    ['npc_guide', 'Market hours', 'The market square stalls open at dawn and close at sunset.'],
    ['npc_guide', 'Lost travelers', 'Mira sends lost travelers to the stone fountain, the easiest landmark in town.'],
    ['npc_scout', 'North trail', 'The north trail floods after heavy rain, so Oren takes the ridge path instead.'],
    [null, 'Old mill', 'The old mill by the river burned down three winters ago and was never rebuilt.'],
];

$db = db_connect(load_config());
require_schema($db);
$added = 0;
foreach ($bios as [$npcId, $name, $role, $bio]) {
    $result = db_query($db,
        'INSERT INTO ex_npc_bios (npc_id, name, role, bio) VALUES ($1, $2, $3, $4) ON CONFLICT DO NOTHING',
        [$npcId, $name, $role, $bio]);
    $added += pg_affected_rows($result);
}
foreach ($facts as [$npcId, $topic, $fact]) {
    $result = db_query($db,
        'INSERT INTO ex_knowledge (npc_id, topic, fact) VALUES ($1, $2, $3) ON CONFLICT DO NOTHING',
        [$npcId, $topic, $fact]);
    $added += pg_affected_rows($result);
}
$total = count($bios) + count($facts);
echo "Example rows added: $added, already present and kept: " . ($total - $added) . "\n";
