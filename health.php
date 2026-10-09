<?php
// GET to check that the server, its config and its database are ready. No token needed.

require __DIR__ . '/lib/app.php';

$config = start_request('GET', public: true);
$db = db_connect($config);
// Says which schema is needed and how to get it; never applies migrations itself.
require_schema($db);

// expected_schema, capabilities, baseline and server_version are optional protocol 1 additions.
send_json(200, ['ok' => true, 'service' => 'example-ai-server', 'schema' => SCHEMA_VERSION,
    'expected_schema' => SCHEMA_VERSION, 'capabilities' => SERVER_CAPABILITIES, 'baseline' => BASELINE_ID,
    'server_version' => SERVER_VERSION]);
