<?php
/*
 * Default configuration. Do not put credentials here – override values in config.local.php
 * (see config.local.example.php) or via environment variables (used by the Docker setup).
 *
 * Precedence: defaults < environment variables < config.local.php
 */

$env = static function (string $name): ?string {
    $value = getenv($name);
    return $value === false || $value === '' ? null : $value;
};
$bool = static fn (?string $v): ?bool => $v === null ? null : filter_var($v, FILTER_VALIDATE_BOOLEAN);
$int = static fn (?string $v): ?int => $v === null ? null : (int) $v;

$defaults = [
    'api_key'   => '',
    'vin'       => '',
    'base_url'  => 'https://public.api.connect.skoda-auto.cz',
    // The API currently allows only 20 requests per hour and VIN – responses are therefore cached.
    'cache_ttl' => 600,            // seconds until the dashboard loads fresh data automatically
    'min_refresh_interval' => 180, // minimum seconds between two manual refreshes
    'cache_dir' => __DIR__ . '/var/cache',
    'timezone'  => 'Europe/Berlin',
    // Remote commands must be enabled deliberately
    'commands_enabled' => false,
    // S-PIN (4 digits) for the auxiliary heating. Empty = asked for in the browser when starting.
    'spin'      => '',
    // Parts to request. Empty = everything the vehicle supports (unsupported parts are simply
    // missing then, without *_UNSUPPORTED errors).
    'include'   => [],

    // Interval in seconds for storing a snapshot in the database (bin/fetch.php).
    // 600 = every 10 minutes = 6 of the 20 hourly requests; the rest is left for the dashboard.
    'fetch_interval' => 600,
    // Delete snapshots older than this many days (0 = keep forever)
    'history_retention_days' => 0,

    // Database for the history: 'sqlite', 'mysql' or 'none' (history disabled)
    'db' => [
        'driver'   => 'sqlite',
        'sqlite_path' => __DIR__ . '/var/skoda.sqlite',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'skoda',
        'username' => 'skoda',
        'password' => '',
    ],
];

$fromEnv = array_filter([
    'api_key'          => $env('SKODA_API_KEY'),
    'vin'              => $env('SKODA_VIN'),
    'spin'             => $env('SKODA_SPIN'),
    'commands_enabled' => $bool($env('SKODA_COMMANDS_ENABLED')),
    'timezone'         => $env('SKODA_TIMEZONE'),
    'cache_ttl'        => $int($env('SKODA_CACHE_TTL')),
    'fetch_interval'   => $int($env('SKODA_FETCH_INTERVAL')),
    'history_retention_days' => $int($env('SKODA_HISTORY_RETENTION_DAYS')),
    'db' => array_filter([
        'driver'      => $env('DB_DRIVER'),
        'sqlite_path' => $env('DB_SQLITE_PATH'),
        'host'        => $env('DB_HOST'),
        'port'        => $int($env('DB_PORT')),
        'database'    => $env('DB_DATABASE'),
        'username'    => $env('DB_USERNAME'),
        'password'    => $env('DB_PASSWORD'),
    ], static fn ($v) => $v !== null),
], static fn ($v) => $v !== null && $v !== []);

$local = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];

$config = array_replace_recursive($defaults, $fromEnv, $local);
// Lists are replaced as a whole, not merged by index
$config['include'] = $local['include'] ?? $defaults['include'];

return $config;
