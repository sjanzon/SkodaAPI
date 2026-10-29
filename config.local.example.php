<?php
// Copy to config.local.php and fill in. Never commit config.local.php – it contains credentials.
return [
    // API key from the MyŠkoda app (see README)
    'api_key' => '',
    // 17-character vehicle identification number (VIN), e.g. from the MyŠkoda app or the registration document
    'vin' => '',
    // Allow remote commands (air conditioning, auxiliary heating, ventilation, charging)
    'commands_enabled' => false,
    // S-PIN for the auxiliary heating; commented out = asked for in the browser
    // 'spin' => '1234',

    // Store a snapshot every 10 minutes
    'fetch_interval' => 600,

    // SQLite (default) ...
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/var/skoda.sqlite',
    ],

    // ... or MySQL / MariaDB
    // 'db' => [
    //     'driver'   => 'mysql',
    //     'host'     => '127.0.0.1',
    //     'port'     => 3306,
    //     'database' => 'skoda',
    //     'username' => 'skoda',
    //     'password' => 'secret',
    // ],
];
