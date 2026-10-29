<?php
/**
 * @var ?string $configError
 * @var ?string $dbError
 * @var string $vin
 * @var string $rangeKey
 * @var int $page
 * @var int $from
 * @var int $to
 * @var int $interval
 * @var int $maxGap
 * @var int $refuels
 * @var array $stats
 * @var array $rows
 * @var array $places
 * @var ?array $snapshot
 * @var Closure $points
 * @var Closure $url
 */

$distance = isset($stats['min_mileage'], $stats['max_mileage']) ? $stats['max_mileage'] - $stats['min_mileage'] : null;
$fuelPoints = $points('fuel_level_percent');
$socPoints = $points('battery_soc_percent');
$powerPoints = $points('charge_power_kw');
$hasCharged = (bool) array_filter($powerPoints, static fn (array $p): bool => $p[1] > 0);
$pages = max(1, (int) ceil($stats['count'] / PER_PAGE));
$hasFuel = (bool) array_filter($rows, static fn (array $r): bool => $r['fuel_level_percent'] !== null);
$hasSoc = (bool) array_filter($rows, static fn (array $r): bool => $r['battery_soc_percent'] !== null);

/** The lock state is stored as reliableLockStatus (LOCKED/UNLOCKED) or as locked (YES/NO) */
$lockChip = static function (?string $value): string {
    $value = ['YES' => 'LOCKED', 'NO' => 'UNLOCKED'][$value] ?? $value;
    return chip($value);
};

$chartCard = static fn (string $title, string $chart, string $note = ''): string =>
    '<article class="card card-chart"><header class="card-head"><h2>' . e($title) . '</h2>'
    . ($note !== '' ? '<span class="meta">' . e($note) . '</span>' : '') . '</header>' . $chart . '</article>';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verlauf · Fahrzeugdaten</title>
    <?php if ($places): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='15' fill='%230e3a2f'/><circle cx='16' cy='16' r='7' fill='%2378faae'/></svg>">
</head>
<body>

<header class="hero hero-compact">
    <nav class="topnav" aria-label="Seiten">
        <a href="./">Übersicht</a>
        <a href="history.php" aria-current="page">Verlauf</a>
    </nav>
    <div class="hero-inner">
        <div class="hero-text">
            <p class="eyebrow">Fahrzeughistorie</p>
            <h1>Verlauf</h1>
            <div class="identity">
                <?php if ($vin !== ''): ?><span class="vin">VIN <?= e($vin) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
</header>

<main>
<?php if ($configError || $dbError): ?>
    <div class="alert alert-error"><?= e($configError ?? $dbError) ?></div>
<?php else: ?>

    <nav class="range-tabs" aria-label="Zeitraum">
        <?php foreach (RANGES as $key => [$label]): ?>
            <a href="<?= e($url(['range' => $key, 'page' => null, 'snapshot' => null])) ?>"<?= $key === $rangeKey ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($stats['count'] === 0): ?>
        <div class="card empty">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18M7 15l4-4 3 3 5-6"/></svg>
            <p>In diesem Zeitraum wurden noch keine Daten gespeichert.<br>
                Die Historie füllt sich, sobald der Cronjob <code>php bin/fetch.php</code> läuft
                (alle <?= e(num($interval / 60)) ?> Min.) oder das Dashboard frische Daten lädt.</p>
        </div>
    <?php else: ?>

    <section class="kpis kpis-history">
        <article class="card kpi">
            <header class="card-head"><h2>Gefahrene Strecke</h2></header>
            <div class="kpi-body">
                <span class="kpi-number"><?= num($distance) ?></span>
                <span class="kpi-unit">Kilometer</span>
                <?php if (isset($stats['max_mileage'])): ?><span class="kpi-note">Stand <?= num($stats['max_mileage']) ?> km</span><?php endif; ?>
            </div>
        </article>
        <article class="card kpi">
            <header class="card-head"><h2>Datenpunkte</h2></header>
            <div class="kpi-body">
                <span class="kpi-number"><?= num($stats['count']) ?></span>
                <span class="kpi-unit">Snapshots</span>
                <span class="kpi-note">Abruf alle <?= e(num($interval / 60)) ?> Min.</span>
            </div>
        </article>
        <?php if ($fuelPoints): ?>
            <article class="card kpi">
                <header class="card-head"><h2>Tankvorgänge</h2></header>
                <div class="kpi-body">
                    <span class="kpi-number"><?= num($refuels) ?></span>
                    <span class="kpi-unit">erkannt</span>
                    <span class="kpi-note">Füllstand +10 % oder mehr</span>
                </div>
            </article>
        <?php endif; ?>
        <article class="card kpi">
            <header class="card-head"><h2>Zeitraum</h2></header>
            <div class="kpi-body">
                <span class="kpi-range"><?= e(date('d.m.Y H:i', (int) $stats['first'])) ?><small>bis</small><?= e(date('d.m.Y H:i', (int) $stats['last'])) ?></span>
            </div>
        </article>
    </section>

    <section class="grid grid-charts">
        <?= $chartCard('Kilometerstand', lineChart($points('mileage_km'), $from, $to, 'km', 0, 'chart-mileage', null, $maxGap)) ?>
        <?php if ($fuelPoints): ?>
            <?= $chartCard('Tankfüllstand', lineChart($fuelPoints, $from, $to, '%', 0, 'chart-fuel', [0, 100], $maxGap)) ?>
        <?php endif; ?>
        <?php if ($socPoints): ?>
            <?= $chartCard('Akkustand', lineChart($socPoints, $from, $to, '%', 0, 'chart-electric', [0, 100], $maxGap)) ?>
        <?php endif; ?>
        <?= $chartCard('Reichweite', lineChart($points('total_range_km'), $from, $to, 'km', 0, 'chart-range', null, $maxGap)) ?>
        <?php if ($hasCharged): ?>
            <?= $chartCard('Ladeleistung', lineChart($powerPoints, $from, $to, 'kW', 1, 'chart-power', null, $maxGap)) ?>
        <?php endif; ?>
    </section>

    <?php if ($places): ?>
        <article class="card card-places">
            <header class="card-head"><h2>Parkorte</h2><span class="meta"><?= count($places) ?> Orte</span></header>
            <div class="map map-wide" id="places-map" role="img" aria-label="Karte der Parkorte"
                 data-places="<?= e(json_encode(array_map(static fn (array $p): array => [
                     $p['lat'], $p['lon'], $p['address'] ?? '', $p['count'], date('d.m.Y H:i', $p['last']),
                 ], $places), JSON_UNESCAPED_UNICODE)) ?>"></div>
        </article>
    <?php endif; ?>

    <?php if ($snapshot): ?>
        <article class="card snapshot" id="snapshot">
            <header class="card-head">
                <h2>Snapshot vom <?= e(date('d.m.Y H:i:s', $snapshot['fetched_at'])) ?></h2>
                <a class="btn btn-secondary btn-small" href="<?= e($url(['snapshot' => null])) ?>#table">Schließen</a>
            </header>
            <dl class="facts">
                <dt>Quelle</dt><dd><?= $snapshot['source'] === 'cron' ? 'Cronjob' : 'Dashboard' ?></dd>
                <?php if ($snapshot['captured_at']): ?><dt>Fahrzeug meldete</dt><dd><?= e(date('d.m.Y H:i', $snapshot['captured_at'])) ?></dd><?php endif; ?>
            </dl>
            <pre><?= e(json_encode($snapshot['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </article>
    <?php endif; ?>

    <article class="card" id="table">
        <header class="card-head"><h2>Snapshots</h2><span class="meta"><?= num($stats['count']) ?> Einträge</span></header>
        <div class="table-wrap">
            <table class="history-table">
                <thead>
                <tr>
                    <th>Zeitpunkt</th>
                    <th class="num">Kilometer</th>
                    <?php if ($hasFuel): ?><th class="num">Tank</th><?php endif; ?>
                    <?php if ($hasSoc): ?><th class="num">Akku</th><?php endif; ?>
                    <th class="num">Reichweite</th>
                    <th>Verriegelung</th>
                    <th>Parkposition</th>
                    <th>Quelle</th>
                    <th><span class="visually-hidden">Details</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr<?= ($snapshot['id'] ?? null) === $row['id'] ? ' class="is-selected"' : '' ?>>
                        <td class="nowrap"><?= e(date('d.m.Y H:i', $row['fetched_at'])) ?></td>
                        <td class="num"><?= num($row['mileage_km']) ?></td>
                        <?php if ($hasFuel): ?><td class="num"><?= $row['fuel_level_percent'] !== null ? num($row['fuel_level_percent']) . ' %' : '–' ?></td><?php endif; ?>
                        <?php if ($hasSoc): ?><td class="num"><?= $row['battery_soc_percent'] !== null ? num($row['battery_soc_percent']) . ' %' : '–' ?></td><?php endif; ?>
                        <td class="num"><?= $row['total_range_km'] !== null ? num($row['total_range_km']) . ' km' : '–' ?></td>
                        <td><?= $lockChip($row['locked']) ?: '–' ?></td>
                        <td class="address-cell"><?= $row['parking_state'] === 'IN_MOTION' ? chip('IN_MOTION') : e($row['address'] ?? '–') ?></td>
                        <td><span class="source source-<?= e($row['source']) ?>"><?= $row['source'] === 'cron' ? 'Cron' : 'Web' ?></span></td>
                        <td><a href="<?= e($url(['snapshot' => $row['id']])) ?>#snapshot">Details</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten der Tabelle">
                <?php if ($page > 1): ?><a class="btn btn-secondary btn-small" href="<?= e($url(['page' => $page - 1, 'snapshot' => null])) ?>#table">Neuere</a><?php endif; ?>
                <span>Seite <?= $page ?> von <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-secondary btn-small" href="<?= e($url(['page' => $page + 1, 'snapshot' => null])) ?>#table">Ältere</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </article>

    <?php endif; ?>
<?php endif; ?>
</main>

<script>
    // Tooltip with value and time of the nearest point
    document.querySelectorAll('.chart[data-chart]').forEach(function (chart) {
        var data = JSON.parse(chart.dataset.chart);
        var plot = chart.querySelector('.chart-plot');
        var cursor = chart.querySelector('.chart-cursor');
        var dot = cursor.querySelector('.chart-dot');
        var tip = cursor.querySelector('.chart-tip');
        var span = Math.max(1, data.to - data.from);
        var format = new Intl.NumberFormat('de-DE', { minimumFractionDigits: data.decimals, maximumFractionDigits: data.decimals });
        var dateFormat = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

        function nearest(time) {
            var lo = 0, hi = data.points.length - 1;
            while (hi - lo > 1) {
                var mid = (lo + hi) >> 1;
                if (data.points[mid][0] < time) { lo = mid; } else { hi = mid; }
            }
            return Math.abs(data.points[lo][0] - time) <= Math.abs(data.points[hi][0] - time) ? data.points[lo] : data.points[hi];
        }

        function show(ev) {
            var box = plot.getBoundingClientRect();
            var point = nearest(data.from + (ev.clientX - box.left) / box.width * span);
            var x = (point[0] - data.from) / span * 100;
            cursor.hidden = false;
            cursor.style.left = x + '%';
            dot.style.top = (100 - (point[1] - data.min) / (data.max - data.min) * 100) + '%';
            tip.textContent = format.format(point[1]) + ' ' + data.unit + ' · ' + dateFormat.format(new Date(point[0] * 1000));
            tip.classList.toggle('is-left', x > 70);
            tip.classList.toggle('is-right', x < 30);
        }

        plot.addEventListener('pointermove', show);
        plot.addEventListener('pointerdown', show);
        plot.addEventListener('pointerleave', function () { cursor.hidden = true; });
    });

    // Parking places on a map; marker size grows with the number of snapshots
    window.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('places-map');
        if (!el || !window.L) {
            return;
        }
        var places = JSON.parse(el.dataset.places);
        var map = L.map(el, { scrollWheelZoom: false, tap: false });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);
        var max = Math.max.apply(null, places.map(function (p) { return p[3]; }));
        var bounds = [];
        places.forEach(function (p) {
            var popup = document.createElement('div');
            popup.innerHTML = '<strong></strong><br><span></span>';
            popup.querySelector('strong').textContent = p[2] || (p[0].toFixed(4) + ', ' + p[1].toFixed(4));
            popup.querySelector('span').textContent = p[3] + ' Snapshots, zuletzt ' + p[4];
            L.circleMarker([p[0], p[1]], {
                radius: 6 + 10 * Math.sqrt(p[3] / max),
                color: '#0e3a2f', weight: 2, fillColor: '#78faae', fillOpacity: .75
            }).addTo(map).bindPopup(popup);
            bounds.push([p[0], p[1]]);
        });
        map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
        map.on('click', function () { map.scrollWheelZoom.enable(); });
        map.on('mouseout', function () { map.scrollWheelZoom.disable(); });
    });
</script>
</body>
</html>
