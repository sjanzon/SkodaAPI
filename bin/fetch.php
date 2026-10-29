<?php

declare(strict_types=1);

/*
 * Fetches the vehicle data and stores it as a snapshot in the database.
 *
 * Run it every minute via cron – it only calls the API once `fetch_interval` has elapsed since the
 * last stored snapshot, so the interval can be changed in the configuration without touching the
 * crontab. Snapshots taken by the dashboard count as well, so no request is wasted.
 *
 *   php bin/fetch.php            fetch if the interval has elapsed
 *   php bin/fetch.php --force    fetch now (the client still enforces min_refresh_interval)
 *   php bin/fetch.php --daemon   run forever and check every minute (Docker worker)
 *   php bin/fetch.php --migrate  only create the database schema
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

/** Allowed early start so a cron run at the full minute is not skipped because of a slow request */
const TOLERANCE_SECONDS = 30;

$options = getopt('', ['force', 'daemon', 'migrate', 'help']);

if (isset($options['help'])) {
    echo "Usage: php bin/fetch.php [--force] [--daemon] [--migrate]\n";
    exit(0);
}

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/** One fetch cycle; returns the process exit code */
function runOnce(array $config, bool $force): int
{
    $error = configError($config);
    if ($error !== null) {
        logLine('ERROR ' . $error);
        return 2;
    }

    $repo = createRepository($config);
    if ($repo === null) {
        logLine('ERROR Die Historie ist deaktiviert (db.driver = none).');
        return 2;
    }

    $vin = strtoupper(trim((string) $config['vin']));
    $interval = max(60, (int) $config['fetch_interval']);
    $last = $repo->lastFetchedAt($vin);

    if (!$force && $last !== null && time() - $last < $interval - TOLERANCE_SECONDS) {
        // Not due yet – stay quiet to keep the log short
        return 0;
    }

    $result = createClient($config)->getVehicle($vin, $config['include'], true);

    if ($result['status'] !== 200 || $result['data'] === null) {
        $problem = $result['problem'] ?? [];
        logLine('ERROR HTTP ' . $result['status'] . ': ' . ($problem['detail'] ?? $problem['title'] ?? 'Unbekannter Fehler'));
        return 1;
    }

    $stored = $repo->save($vin, $result, 'cron');
    $remaining = $result['headers']['ratelimit-remaining'] ?? '?';
    if ($stored) {
        logLine("OK Snapshot gespeichert (Anfragen übrig: {$remaining}).");
    } else {
        logLine('SKIP ' . ($result['notice'] ?? 'Keine neuen Daten.'));
    }

    $deleted = $repo->prune((int) $config['history_retention_days']);
    if ($deleted > 0) {
        logLine("OK {$deleted} alte Snapshots gelöscht.");
    }

    return 0;
}

if (isset($options['migrate'])) {
    try {
        $repo = createRepository($config);
        logLine($repo ? 'OK Datenbankschema ist aktuell (' . $config['db']['driver'] . ').' : 'Die Historie ist deaktiviert (db.driver = none).');
        exit(0);
    } catch (Throwable $ex) {
        logLine('ERROR ' . $ex->getMessage());
        exit(1);
    }
}

if (!isset($options['daemon'])) {
    try {
        exit(runOnce($config, isset($options['force'])));
    } catch (Throwable $ex) {
        logLine('ERROR ' . $ex->getMessage());
        exit(1);
    }
}

logLine('Worker gestartet, Intervall ' . (int) $config['fetch_interval'] . ' s.');
while (true) {
    try {
        // Reload the configuration each cycle so changes apply without a restart
        $config = require __DIR__ . '/../config.php';
        runOnce($config, false);
    } catch (Throwable $ex) {
        // Keep running – e.g. the database container may still be starting
        logLine('ERROR ' . $ex->getMessage());
    }
    sleep(60 - (int) date('s'));
}
