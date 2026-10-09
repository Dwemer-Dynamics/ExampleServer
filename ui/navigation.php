<?php
// Static navigation shared by the navbar and section tabs. Add pages here.
return [
    'home' => ['label' => 'Home', 'href' => 'index.php', 'groups' => [
        'Overview' => ['index' => ['Home', 'index.php']],
    ]],
    'roleplay' => ['label' => 'Roleplay', 'href' => 'conversation.php', 'groups' => [
        'Dialogue' => [
            'conversation' => ['Conversation', 'conversation.php'],
            'history' => ['History', 'history.php'],
            'checkpoints' => ['Checkpoints', 'checkpoints.php'],
            'memory' => ['Memory', 'memory.php'],
        ],
    ]],
    'configuration' => ['label' => 'Configuration', 'href' => 'settings.php', 'groups' => [
        'Settings' => [
            'settings' => ['Server settings', 'settings.php'],
            'api_keys' => ['API keys', 'api_keys.php'],
        ],
        'AI & Voice' => [
            'llm' => ['LLM', 'llm.php'],
            'voice' => ['Voice', 'voice.php'],
            'decision' => ['NPC selection', 'decision_settings.php'],
            'memory_settings' => ['Memory settings', 'memory_settings.php'],
        ],
        'World & Behavior' => [
            'npc_bios' => ['NPC bios', 'npc_bios.php'],
            'profiles' => ['Profiles', 'profiles.php'],
        ],
    ]],
    'control' => ['label' => 'Control Panel', 'href' => 'logs.php', 'groups' => [
        'Diagnostics' => [
            'logs' => ['Logs', 'logs.php'],
            'connector_calls' => ['Connector calls', 'connector_calls.php'],
            'diagnostics' => ['Diagnostics', 'diagnostics.php'],
            'backups' => ['Backups', 'backups.php'],
        ],
        'Page examples' => [
            'examples/index' => ['Examples', 'examples/index.php'],
            'examples/blank' => ['Blank', 'examples/blank.php'],
            'examples/form' => ['Form', 'examples/form.php'],
            'examples/table' => ['Table', 'examples/table.php'],
        ],
    ]],
];
