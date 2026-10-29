<?php

declare(strict_types=1);

/*
 * Shared setup for the web pages and the CLI: loads the configuration and provides the API client
 * and the snapshot repository.
 */

require __DIR__ . '/SkodaClient.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/SnapshotRepository.php';

$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone']);

/** Returns an error message if the minimum configuration is missing, otherwise null */
function configError(array $config): ?string
{
    if ($config['api_key'] === '') {
        return 'Kein API-Key konfiguriert (config.local.php → api_key bzw. SKODA_API_KEY).';
    }
    if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', strtoupper(trim((string) $config['vin'])))) {
        return 'Keine gültige 17-stellige VIN konfiguriert (config.local.php → vin bzw. SKODA_VIN).';
    }
    return null;
}

function createClient(array $config): SkodaClient
{
    return new SkodaClient(
        $config['base_url'],
        $config['api_key'],
        $config['cache_dir'],
        (int) $config['cache_ttl'],
        (int) $config['min_refresh_interval'],
        (bool) $config['commands_enabled'],
    );
}

/** @return ?SnapshotRepository null when the history is disabled */
function createRepository(array $config): ?SnapshotRepository
{
    $db = Database::fromConfig($config['db']);
    return $db ? new SnapshotRepository($db) : null;
}
