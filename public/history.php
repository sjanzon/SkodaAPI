<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/view.php';

/** Selectable periods: key => [label, seconds (null = everything)] */
const RANGES = [
    '24h' => ['24 Std.', 86400],
    '7d'  => ['7 Tage', 7 * 86400],
    '30d' => ['30 Tage', 30 * 86400],
    '1y'  => ['1 Jahr', 365 * 86400],
    'all' => ['Alles', null],
];
const PER_PAGE = 50;
/** Maximum number of points per chart */
const CHART_POINTS = 400;

$vin = strtoupper(trim((string) $config['vin']));
$configError = configError($config);
$rangeKey = isset(RANGES[$_GET['range'] ?? '']) ? $_GET['range'] : '7d';
$page = max(1, (int) ($_GET['page'] ?? 1));
$interval = max(60, (int) $config['fetch_interval']);

$dbError = null;
$repo = null;
if ($configError === null) {
    try {
        $repo = createRepository($config);
        if ($repo === null) {
            $dbError = 'Die Historie ist deaktiviert (config.local.php → db.driver = none).';
        }
    } catch (Throwable $ex) {
        $dbError = 'Keine Verbindung zur Datenbank: ' . $ex->getMessage();
    }
}

$to = time();
$from = RANGES[$rangeKey][1] === null ? 0 : $to - RANGES[$rangeKey][1];
$stats = ['count' => 0, 'first' => null, 'last' => null, 'min_mileage' => null, 'max_mileage' => null];
$series = [];
$rows = [];
$places = [];
$snapshot = null;

if ($repo !== null) {
    $stats = $repo->stats($vin, $from, $to);
    if ($from === 0) {
        // "Everything": start the axis at the first snapshot
        $from = $stats['first'] ?? $to - 86400;
    }
    $bucket = max(1, (int) ceil(($to - $from) / CHART_POINTS));
    $series = $repo->series($vin, $from, $to, $bucket);
    $rows = $repo->page($vin, $from, $to, PER_PAGE, ($page - 1) * PER_PAGE);
    $places = $repo->places($vin, $from, $to);
    if (isset($_GET['snapshot'])) {
        $snapshot = $repo->find($vin, (int) $_GET['snapshot']);
    }
}

/** Points [time, value] of one column, without empty values */
$points = static function (string $column) use ($series): array {
    $result = [];
    foreach ($series as $row) {
        if ($row[$column] !== null) {
            $result[] = [$row['t'], $row[$column]];
        }
    }
    return $result;
};

// Refuelling: the fuel level rises by more than 10 percentage points above the last low. A rise
// spread over several points counts once.
$refuels = 0;
$low = null;
$previousFuel = null;
$filling = false;
foreach ($points('fuel_level_percent') as [, $level]) {
    if ($previousFuel === null || $level < $previousFuel) {
        $low = $level;
        $filling = false;
    } elseif ($level > $previousFuel && !$filling && $level - $low > 10) {
        $refuels++;
        $filling = true;
    }
    $previousFuel = $level;
}

// Gaps longer than this break the chart line (the cron job was not running, API errors, …)
$maxGap = max(3 * $interval, (int) (2.5 * max(1, (int) ceil(($to - $from) / CHART_POINTS))));

/** Link to this page with changed query parameters */
$url = static function (array $changes) use ($rangeKey, $page): string {
    $query = array_filter(array_merge(['range' => $rangeKey, 'page' => $page > 1 ? $page : null], $changes), static fn ($v) => $v !== null);
    return '?' . http_build_query($query);
};

require __DIR__ . '/../templates/history.php';
