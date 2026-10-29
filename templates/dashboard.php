<?php
/**
 * @var ?string $configError
 * @var ?array $result
 * @var ?array $flash
 * @var string $vin
 * @var SkodaClient $client
 * @var array $config
 * @var ?string $dbError
 * @var bool $historyEnabled
 */

$data = $result['data'] ?? null;
$vehicle = $data['vehicle'] ?? [];
$h = $result['headers'] ?? [];

$status = $vehicle['status'] ?? null;
$fuel = $vehicle['fuelStatus'] ?? null;
$odo = $vehicle['odometer'] ?? null;
$park = $vehicle['parkingPosition'] ?? null;
$ac = $vehicle['airConditioning'] ?? null;
$aux = $vehicle['auxiliaryHeating'] ?? null;
$vent = $vehicle['activeVentilation'] ?? null;
$charging = $vehicle['charging'] ?? null;
$profiles = $vehicle['chargingProfiles']['profiles'] ?? [];
$currentProfile = $vehicle['chargingProfiles']['currentVehiclePositionProfile'] ?? null;
$battery = $charging['status']['battery'] ?? null;

$supportedOps = isset($vehicle['operations']) ? array_column($vehicle['operations'], 'name') : null;
$isSupported = static fn (string $op): bool => $supportedOps === null || in_array($op, $supportedOps, true);

// Unsupported parts are not a problem – only show real outages/deactivations.
$apiWarnings = array_values(array_filter(
    $data['errors'] ?? [],
    static fn (array $err): bool => !str_ends_with($err['type'] ?? '', '_UNSUPPORTED'),
));

$engines = array_values(array_filter([$fuel['primaryEngineRange'] ?? null, $fuel['secondaryEngineRange'] ?? null]));
$overall = $status['overall'] ?? [];
$detail = $status['detail'] ?? [];

$lat = $park['gpsCoordinates']['latitude'] ?? null;
$lon = $park['gpsCoordinates']['longitude'] ?? null;

$csrf = $_SESSION['csrf'];
$commandsEnabled = $client->commandsEnabled();
$off = $commandsEnabled ? '' : ' disabled';
$spinConfigured = trim((string) ($config['spin'] ?? '')) !== '';

/** Form for a remote command; the buttons live outside and reference it via form="…" */
$form = static function (string $op, string $fields = '', string $class = '', string $idSuffix = '') use ($csrf): string {
    $confirm = e((OPERATION_LABELS[$op] ?? $op) . ' jetzt ausführen?');
    return '<form method="post" id="cmd-' . e($op . $idSuffix) . '" class="cmd ' . $class . '" data-confirm="' . $confirm . '">'
        . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
        . '<input type="hidden" name="operation" value="' . e($op) . '">'
        . $fields
        . '</form>';
};

/** Button that submits the form of a command */
$button = static fn (string $op, string $text, string $variant = 'primary'): string =>
    '<button class="btn btn-' . $variant . '" form="cmd-' . e($op) . '"' . $off . '>' . e($text) . '</button>';

/** Slider with value display; the API does not define the limits, these are common values */
$slider = static function (string $label, string $name, float $min, float $max, float $step, float $value, string $unit) use ($off): string {
    $value = max($min, min($max, $value));
    $decimals = fmod($step, 1.0) === 0.0 ? 0 : 1;
    return '<label class="slider"><span>' . e($label) . '<output>' . e(num($value, $decimals)) . ' ' . e($unit) . '</output></span>'
        . '<input type="range" name="' . e($name) . '" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . $value . '"'
        . ' data-unit="' . e($unit) . '" data-decimals="' . $decimals . '"' . $off . '></label>';
};

$pageTitle = $vehicle['name'] ?? 'Mein Škoda';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · Fahrzeugdaten</title>
    <?php if (isset($lat, $lon)): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='15' fill='%230e3a2f'/><circle cx='16' cy='16' r='7' fill='%2378faae'/></svg>">
</head>
<body>

<header class="hero">
    <?php if ($historyEnabled): ?>
        <nav class="topnav" aria-label="Seiten">
            <a href="./" aria-current="page">Übersicht</a>
            <a href="history.php">Verlauf</a>
        </nav>
    <?php endif; ?>
    <div class="hero-inner">
        <div class="hero-text">
            <p class="eyebrow">Fahrzeugübersicht</p>
            <h1><?= e($pageTitle) ?></h1>
            <div class="identity">
                <?php if (!empty($vehicle['licensePlate'])): ?>
                    <span class="plate"><span class="plate-eu">D</span><?= e($vehicle['licensePlate']) ?></span>
                <?php endif; ?>
                <?php if ($vin !== ''): ?><span class="vin">VIN <?= e($vin) ?></span><?php endif; ?>
            </div>
            <?php if ($data): ?>
                <ul class="summary">
                    <?php if (isset($overall['locked'])): ?>
                        <li class="summary-<?= tone($overall['locked']) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 <?= $overall['locked'] === 'NO' ? '7.5-2' : '8 0v3' ?>"/></svg>
                            <?= $overall['locked'] === 'YES' ? 'Verriegelt' : ($overall['locked'] === 'NO' ? 'Nicht verriegelt' : 'Verriegelung unbekannt') ?>
                        </li>
                    <?php endif; ?>
                    <?php if (isset($park['state'])): ?>
                        <li class="summary-<?= tone($park['state']) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            <?= e(label($park['state'])) ?>
                        </li>
                    <?php endif; ?>
                    <?php if (isset($status['carCapturedTimestamp']) || isset($odo['carCapturedTimestamp'])): ?>
                        <li class="summary-muted">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            Fahrzeug meldete <?= ago($status['carCapturedTimestamp'] ?? $odo['carCapturedTimestamp']) ?>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php if (!empty($vehicle['renderUrl'])): ?>
            <img class="hero-car" src="<?= e($vehicle['renderUrl']) ?>" alt="<?= e($pageTitle) ?>">
        <?php endif; ?>
        <a class="btn btn-ghost refresh" href="?refresh=1" title="Daten neu vom Fahrzeug laden">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/></svg>
            Aktualisieren
        </a>
    </div>
</header>

<main>
<?php if ($configError): ?>
    <div class="alert alert-error"><?= e($configError) ?></div>
<?php else: ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['ok'] ? 'success' : 'error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($dbError): ?>
        <div class="alert alert-warn"><?= e($dbError) ?></div>
    <?php endif; ?>
    <?php if (!empty($result['notice'])): ?>
        <div class="alert alert-info"><?= e($result['notice']) ?></div>
    <?php endif; ?>
    <?php foreach ($apiWarnings as $err): ?>
        <div class="alert alert-warn"><strong><?= e($err['type'] ?? '') ?></strong> <?= e($err['description'] ?? '') ?></div>
    <?php endforeach; ?>

    <?php if (!$data): ?>
        <?php $p = $result['problem'] ?? []; ?>
        <div class="alert alert-error">
            <strong>Abruf fehlgeschlagen (HTTP <?= e($result['status']) ?>)</strong><br>
            <?= e($p['detail'] ?? $p['title'] ?? 'Unbekannter Fehler') ?>
        </div>
    <?php else: ?>

    <!-- Key figures -->
    <section class="kpis">
        <?php if (isset($odo['mileageInKm'])): ?>
            <article class="card kpi">
                <header class="card-head"><h2>Kilometerstand</h2><span class="meta"><?= ago($odo['carCapturedTimestamp'] ?? null) ?></span></header>
                <div class="kpi-body">
                    <div class="odometer" aria-label="<?= e(num($odo['mileageInKm'])) ?> Kilometer">
                        <?php foreach (str_split(str_pad((string) $odo['mileageInKm'], 6, '0', STR_PAD_LEFT)) as $digit): ?><span><?= e($digit) ?></span><?php endforeach; ?>
                    </div>
                    <span class="kpi-unit">Kilometer</span>
                </div>
            </article>
        <?php endif; ?>

        <?php foreach ($engines as $engine): ?>
            <?php $level = $engine['currentFuelLevelInPercent'] ?? $engine['currentSoCInPercent'] ?? null; ?>
            <?php if ($level === null && !isset($engine['remainingRangeInKm'])) continue; ?>
            <?php $isElectric = ($engine['engineType'] ?? '') === 'ELECTRIC'; ?>
            <article class="card kpi kpi-gauge">
                <header class="card-head"><h2><?= $isElectric ? 'Akku' : 'Tank' ?></h2><?= chip($engine['engineType'] ?? null) ?></header>
                <div class="kpi-body">
                    <?= gauge($level !== null ? (float) $level : null, $level !== null ? num($level) : '–', '%', isset($engine['remainingRangeInKm']) ? num($engine['remainingRangeInKm']) . ' km' : '', $isElectric ? 'gauge-electric' : 'gauge-fuel') ?>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($battery && !$engines): ?>
            <article class="card kpi kpi-gauge">
                <header class="card-head"><h2>Akku</h2></header>
                <div class="kpi-body">
                    <?= gauge(isset($battery['stateOfChargeInPercent']) ? (float) $battery['stateOfChargeInPercent'] : null, num($battery['stateOfChargeInPercent'] ?? null), '%', isset($battery['remainingCruisingRangeInMeters']) ? num($battery['remainingCruisingRangeInMeters'] / 1000) . ' km' : '', 'gauge-electric') ?>
                </div>
            </article>
        <?php endif; ?>

        <?php if (isset($fuel['totalRangeInKm'])): ?>
            <article class="card kpi">
                <header class="card-head"><h2>Reichweite</h2><span class="meta"><?= ago($fuel['carCapturedTimestamp'] ?? null) ?></span></header>
                <div class="kpi-body">
                    <svg class="kpi-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21 9 3M20 21 15 3M12 5v2M12 11v2M12 17v2"/></svg>
                    <span class="kpi-number"><?= num($fuel['totalRangeInKm']) ?></span>
                    <span class="kpi-unit">Kilometer</span>
                    <?php if (isset($fuel['adBlueRange'])): ?>
                        <span class="kpi-note">AdBlue reicht für <?= num($fuel['adBlueRange']) ?> km</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endif; ?>
    </section>

    <section class="grid">
        <!-- Vehicle status -->
        <?php if ($status): ?>
            <article class="card card-status">
                <header class="card-head"><h2>Fahrzeugstatus</h2><span class="meta"><?= ago($status['carCapturedTimestamp'] ?? null) ?></span></header>
                <div class="status-layout">
                    <?= carDiagram($overall, $detail) ?>
                    <ul class="status-list">
                        <?php
                        $rows = [
                            'Verriegelung' => $overall['locked'] ?? null,
                            'Türen & Klappen' => $overall['doorsLocked'] ?? null,
                            'Türen' => $overall['doors'] ?? null,
                            'Fenster' => $overall['windows'] ?? null,
                            'Schiebedach' => ($detail['sunroof'] ?? 'UNSUPPORTED') === 'UNSUPPORTED' ? null : $detail['sunroof'],
                            'Kofferraum' => $detail['trunk'] ?? null,
                            'Motorhaube' => $detail['bonnet'] ?? null,
                            'Licht' => $overall['lights'] ?? null,
                        ];
                        $rowLabel = static fn (string $name, string $value): string => match (true) {
                            $name === 'Verriegelung' => ['YES' => 'Verriegelt', 'NO' => 'Entriegelt'][$value] ?? label($value),
                            $name === 'Türen & Klappen' && $value === 'YES' => 'Verriegelt & zu',
                            $name === 'Türen & Klappen' && $value === 'NO' => 'Zu, nicht verriegelt',
                            default => label($value),
                        };
                        ?>
                        <?php foreach ($rows as $name => $value): ?>
                            <?php if ($value === null || ($value === 'UNSUPPORTED')) continue; ?>
                            <li><span><?= e($name) ?></span><?= chip($value, $rowLabel($name, $value)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </article>
        <?php endif; ?>

        <!-- Parking position -->
        <?php if ($park): ?>
            <article class="card card-map">
                <header class="card-head"><h2>Parkposition</h2><?= chip($park['state'] ?? null) ?></header>
                <?php if ($lat !== null && $lon !== null): ?>
                    <div class="map" id="parking-map" role="img" aria-label="Karte der Parkposition"
                         data-lat="<?= e(sprintf('%F', $lat)) ?>" data-lon="<?= e(sprintf('%F', $lon)) ?>"
                         data-label="<?= e($park['formattedAddress'] ?? $pageTitle) ?>"></div>
                    <?php if (!empty($park['formattedAddress'])): ?><p class="address"><?= e($park['formattedAddress']) ?></p><?php endif; ?>
                    <p class="links">
                        <span class="mono"><?= e(sprintf('%.5F, %.5F', $lat, $lon)) ?></span>
                        <a href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= e(sprintf('%F,%F', $lat, $lon)) ?>" target="_blank" rel="noopener">Route planen</a>
                        <a href="https://www.openstreetmap.org/?mlat=<?= e($lat) ?>&amp;mlon=<?= e($lon) ?>#map=17/<?= e($lat) ?>/<?= e($lon) ?>" target="_blank" rel="noopener">Große Karte</a>
                    </p>
                <?php elseif (($park['state'] ?? '') === 'IN_MOTION'): ?>
                    <div class="empty">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 17h14M7 17l1.5-5h7L17 17M6 17v2M18 17v2"/><circle cx="8" cy="15" r=".5"/><circle cx="16" cy="15" r=".5"/></svg>
                        Das Fahrzeug ist gerade unterwegs – eine Parkposition gibt es erst nach dem Abstellen.
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <!-- Air conditioning -->
        <?php if ($ac): ?>
            <article class="card">
                <header class="card-head"><h2>Klimatisierung</h2><?= chip($ac['state'] ?? null) ?></header>
                <?php if (isset($ac['targetTemperature'])): ?>
                    <p class="big temp"><?= temperature($ac['targetTemperature']) ?><small>Ziel</small></p>
                <?php endif; ?>
                <dl class="facts">
                    <?php if (!empty($ac['estimatedReachOfTargetTemperatureAt'])): ?><dt>Ziel erreicht</dt><dd><?= fmtTime($ac['estimatedReachOfTargetTemperatureAt']) ?></dd><?php endif; ?>
                    <?php if (isset($ac['airConditioningWithoutExternalPower'])): ?><dt>Ohne Ladekabel</dt><dd><?= $ac['airConditioningWithoutExternalPower'] ? 'Erlaubt' : 'Nicht erlaubt' ?></dd><?php endif; ?>
                    <?php if (isset($ac['airConditioningAtUnlock'])): ?><dt>Start beim Entriegeln</dt><dd><?= $ac['airConditioningAtUnlock'] ? 'Ja' : 'Nein' ?></dd><?php endif; ?>
                    <?php foreach (['front' => 'Frontscheibenheizung', 'rear' => 'Heckscheibenheizung'] as $k => $name): ?>
                        <?php if (isset($ac['windowHeating'][$k]) && $ac['windowHeating'][$k] !== 'UNSUPPORTED'): ?><dt><?= e($name) ?></dt><dd><?= chip($ac['windowHeating'][$k]) ?></dd><?php endif; ?>
                    <?php endforeach; ?>
                </dl>
                <p class="meta"><?= ago($ac['carCapturedTimestamp'] ?? null) ?></p>
            </article>
        <?php endif; ?>

        <!-- Auxiliary heating -->
        <?php if ($aux): ?>
            <article class="card">
                <header class="card-head"><h2>Standheizung</h2><?= chip($aux['state'] ?? null) ?></header>
                <?php if (isset($aux['targetTemperature'])): ?>
                    <p class="big temp"><?= temperature($aux['targetTemperature']) ?><small>Ziel</small></p>
                <?php endif; ?>
                <dl class="facts">
                    <?php if (isset($aux['startMode'])): ?><dt>Modus</dt><dd><?= e(['HEATING' => 'Heizen', 'VENTILATION' => 'Lüften'][$aux['startMode']] ?? $aux['startMode']) ?></dd><?php endif; ?>
                    <?php if (isset($aux['durationInSeconds'])): ?><dt>Laufzeit</dt><dd><?= num($aux['durationInSeconds'] / 60) ?> Min.</dd><?php endif; ?>
                    <?php if (!empty($aux['estimatedReachOfTargetTemperatureAt'])): ?><dt>Ziel erreicht</dt><dd><?= fmtTime($aux['estimatedReachOfTargetTemperatureAt']) ?></dd><?php endif; ?>
                </dl>
                <p class="meta"><?= ago($aux['carCapturedTimestamp'] ?? null) ?></p>
            </article>
        <?php endif; ?>

        <!-- Active ventilation -->
        <?php if ($vent): ?>
            <article class="card">
                <header class="card-head"><h2>Aktive Lüftung</h2><?= chip($vent['state'] ?? null) ?></header>
                <dl class="facts">
                    <?php if (isset($vent['durationInSeconds'])): ?><dt>Laufzeit</dt><dd><?= num($vent['durationInSeconds'] / 60) ?> Min.</dd><?php endif; ?>
                </dl>
                <p class="meta"><?= ago($vent['carCapturedTimestamp'] ?? null) ?></p>
            </article>
        <?php endif; ?>

        <!-- Charging -->
        <?php if ($charging): ?>
            <?php $cs = $charging['status'] ?? []; $set = $charging['settings'] ?? []; $soc = $battery['stateOfChargeInPercent'] ?? null; $target = $set['targetStateOfChargeInPercent'] ?? null; ?>
            <article class="card card-wide">
                <header class="card-head"><h2>Laden</h2><?= chip($cs['state'] ?? null) ?></header>
                <?php if ($soc !== null): ?>
                    <div class="soc">
                        <div class="soc-bar">
                            <div class="soc-fill<?= ($cs['state'] ?? '') === 'CHARGING' ? ' is-charging' : '' ?>" style="width: <?= max(0, min(100, (int) $soc)) ?>%"></div>
                            <?php if ($target !== null): ?><div class="soc-target" style="left: <?= max(0, min(100, (int) $target)) ?>%" title="Ladeziel <?= e($target) ?> %"></div><?php endif; ?>
                        </div>
                        <div class="soc-legend">
                            <span><strong><?= num($soc) ?> %</strong> geladen</span>
                            <?php if ($target !== null): ?><span>Ziel <?= num($target) ?> %</span><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="stat-row">
                    <?php if (isset($battery['remainingCruisingRangeInMeters'])): ?><div><span><?= num($battery['remainingCruisingRangeInMeters'] / 1000) ?> km</span><small>E-Reichweite</small></div><?php endif; ?>
                    <?php if (isset($cs['chargePowerInKw'])): ?><div><span><?= num($cs['chargePowerInKw'], 1) ?> kW</span><small>Ladeleistung</small></div><?php endif; ?>
                    <?php if (isset($cs['chargingRateInKilometersPerHour'])): ?><div><span><?= num($cs['chargingRateInKilometersPerHour']) ?> km/h</span><small>Ladegeschwindigkeit</small></div><?php endif; ?>
                    <?php if (isset($cs['remainingTimeToFullyChargedInMinutes'])): ?><div><span><?= e(sprintf('%d:%02d h', intdiv($cs['remainingTimeToFullyChargedInMinutes'], 60), $cs['remainingTimeToFullyChargedInMinutes'] % 60)) ?></span><small>Restzeit</small></div><?php endif; ?>
                </div>
                <dl class="facts">
                    <?php if (!empty($cs['fullyChargedAt'])): ?><dt>Voll geladen</dt><dd><?= fmtTime($cs['fullyChargedAt']) ?></dd><?php endif; ?>
                    <?php if (isset($cs['chargeType']) && $cs['chargeType'] !== 'OFF'): ?><dt>Ladeart</dt><dd><?= e($cs['chargeType']) ?></dd><?php endif; ?>
                    <?php if (isset($cs['plugConnectionState'])): ?><dt>Ladekabel</dt><dd><?= chip($cs['plugConnectionState']) ?></dd><?php endif; ?>
                    <?php if (isset($cs['plugLockState'])): ?><dt>Stecker</dt><dd><?= chip($cs['plugLockState']) ?></dd><?php endif; ?>
                    <?php if (isset($set['preferredChargeMode'])): ?><dt>Lademodus</dt><dd><?= e(label($set['preferredChargeMode'])) ?></dd><?php endif; ?>
                    <?php if (isset($set['chargingCareMode'])): ?><dt>Akkuschonung</dt><dd><?= chip($set['chargingCareMode']) ?><?= isset($set['batteryCareModeTargetValueInPercent']) ? ' <small>(' . num($set['batteryCareModeTargetValueInPercent']) . ' %)</small>' : '' ?></dd><?php endif; ?>
                    <?php if (isset($set['maxChargeCurrentAc'])): ?><dt>AC-Ladestrom</dt><dd><?= e(label($set['maxChargeCurrentAc'])) ?><?= isset($set['maxChargeCurrentAcAmpere']) ? ' (' . num($set['maxChargeCurrentAcAmpere']) . ' A)' : '' ?></dd><?php endif; ?>
                    <?php if (isset($set['autoUnlockPlugWhenCharged'])): ?><dt>Stecker entriegeln</dt><dd><?= e(label($set['autoUnlockPlugWhenCharged'])) ?></dd><?php endif; ?>
                    <?php if (!empty($charging['isVehicleInSavedLocation'])): ?><dt>Standort</dt><dd>Gespeicherter Ladeort</dd><?php endif; ?>
                </dl>
                <p class="meta"><?= ago($charging['carCapturedTimestamp'] ?? null) ?></p>
            </article>
        <?php endif; ?>

        <!-- Charging profiles -->
        <?php if ($profiles): ?>
            <article class="card card-full">
                <header class="card-head"><h2>Ladeprofile</h2><span class="meta"><?= ago($vehicle['chargingProfiles']['carCapturedTimestamp'] ?? null) ?></span></header>
                <div class="profiles">
                    <?php foreach ($profiles as $profile): $ps = $profile['settings'] ?? []; $isHere = ($currentProfile['id'] ?? null) === ($profile['id'] ?? false); ?>
                        <section class="profile<?= $isHere ? ' is-current' : '' ?>">
                            <h3><?= e($profile['name'] ?? 'Profil') ?> <?= $isHere ? '<span class="chip chip-active">Fahrzeug hier</span>' : '' ?></h3>
                            <dl class="facts">
                                <?php if (isset($ps['targetStateOfChargeInPercent'])): ?><dt>Ladeziel</dt><dd><?= num($ps['targetStateOfChargeInPercent']) ?> %</dd><?php endif; ?>
                                <?php if (!empty($ps['minBatteryStateOfCharge']['enabled'])): ?><dt>Sofort laden unter</dt><dd><?= num($ps['minBatteryStateOfCharge']['minimumBatteryStateOfChargeInPercent'] ?? null) ?> %</dd><?php endif; ?>
                                <?php if (isset($ps['maxChargingCurrent'])): ?><dt>Ladestrom</dt><dd><?= e(label($ps['maxChargingCurrent'])) ?></dd><?php endif; ?>
                                <?php if ($isHere && isset($currentProfile['nextChargingTime'])): ?><dt>Nächste Ladung</dt><dd><?= e($currentProfile['nextChargingTime']) ?> Uhr</dd><?php endif; ?>
                            </dl>
                            <?php if (!empty($profile['timers'])): ?>
                                <ul class="timers">
                                    <?php foreach ($profile['timers'] as $timer): ?>
                                        <li class="<?= !empty($timer['enabled']) ? 'is-on' : 'is-off' ?>">
                                            <strong><?= e($timer['time'] ?? '--:--') ?></strong>
                                            <span><?= e(($timer['type'] ?? '') === 'ONE_OFF' ? label($timer['oneOffDay'] ?? null) . ' (einmalig)' : implode(' ', array_map('label', $timer['recurringOn'] ?? []))) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (!empty($profile['preferredChargingTimes'])): ?>
                                <ul class="timers">
                                    <?php foreach ($profile['preferredChargingTimes'] as $slot): ?>
                                        <li class="<?= !empty($slot['enabled']) ? 'is-on' : 'is-off' ?>"><strong><?= e($slot['startTime'] ?? '') ?>–<?= e($slot['endTime'] ?? '') ?></strong><span>Bevorzugt</span></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endif; ?>
    </section>

    <!-- Remote commands -->
    <section class="card commands">
        <header class="card-head">
            <h2>Fernbefehle</h2>
            <?php if (!$commandsEnabled): ?><span class="chip chip-muted">Deaktiviert</span><?php endif; ?>
        </header>
        <p class="meta">
            Befehle werden vom Fahrzeug asynchron ausgeführt und zählen zum Rate-Limit.
        </p>

        <?php
        $groups = ['supported' => [], 'unsupported' => []];
        foreach (COMMAND_GROUPS as $key => [$title, $ops]) {
            $any = array_filter($ops, $isSupported);
            $groups[$any ? 'supported' : 'unsupported'][$key] = $title;
        }

        $icons = [
            'airConditioning' => '<path d="M12 3v18M4.2 7.5l15.6 9M19.8 7.5l-15.6 9M9.5 4.5 12 7l2.5-2.5M9.5 19.5 12 17l2.5 2.5"/>',
            'auxiliaryHeating' => '<path d="M12 21c3.9 0 6.5-2.6 6.5-6.3 0-4-3.2-6-4.2-9.7-1.9 1.5-2.8 3.4-2.8 5.3-1-.9-1.5-1.9-1.7-3C7.6 9.2 5.5 11.5 5.5 14.7 5.5 18.4 8.1 21 12 21z"/>',
            'activeVentilation' => '<path d="M3 8h10.5a2.5 2.5 0 1 0-2.5-2.5M3 12h15.5a2.5 2.5 0 1 1-2.5 2.5M3 16h7.5a2.5 2.5 0 1 1-2.5 2.5"/>',
            'charging' => '<path d="M13 2 5 13h6l-1 9 8-11h-6z"/>',
            'chargingProfiles' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
        ];

        // Two columns for 2 or 4 regular boxes so none ends up alone in a row (the charging profile is always full width)
        $gridClass = static fn (array $group): string =>
            in_array(count(array_diff(array_keys($group), ['chargingProfiles'])), [2, 4], true) ? ' cols-2' : '';

        /** Form for a charging profile; the original travels along as JSON, the server only applies the edited fields */
        $profileEditor = static function (array $profile, int $index) use ($form, $slider, $off): string {
            $settings = $profile['settings'] ?? [];
            $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
            $switch = static fn (string $name, bool $checked, string $text, bool $hideText = false): string =>
                '<label class="switch' . ($hideText ? ' switch-bare' : '') . '"><span' . ($hideText ? ' class="visually-hidden"' : '') . '>' . e($text) . '</span>'
                . '<input type="checkbox" role="switch" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . $off . '></label>';
            $segmented = static function (string $legend, string $name, array $options, string $current) use ($off): string {
                $html = '';
                foreach ($options as $value => $text) {
                    $html .= '<label><input type="radio" name="' . e($name) . '" value="' . e($value) . '"' . ($value === $current ? ' checked' : '') . $off . '><span>' . e($text) . '</span></label>';
                }
                return '<fieldset class="segmented"><legend>' . e($legend) . '</legend><div class="seg">' . $html . '</div></fieldset>';
            };

            // General
            $general = '<label class="field">Name<input type="text" name="p[name]" maxlength="40" required value="' . e($profile['name'] ?? '') . '"' . $off . '></label>'
                . $slider('Ladeziel', 'p[target]', 50, 100, 10, (int) ($settings['targetStateOfChargeInPercent'] ?? 80), '%');
            if (isset($settings['minBatteryStateOfCharge'])) {
                $min = $settings['minBatteryStateOfCharge'];
                $general .= '<div class="min-soc">' . $switch('p[minEnabled]', !empty($min['enabled']), 'Sofort laden unter Mindestladung')
                    . $slider('Mindestladung', 'p[min]', 0, 70, 10, (int) ($min['minimumBatteryStateOfChargeInPercent'] ?? 20), '%') . '</div>';
            }
            if (isset($settings['maxChargingCurrent'])) {
                $general .= $segmented('Ladestrom', 'p[maxCurrent]', ['REDUCED' => 'Reduziert', 'MAXIMUM' => 'Maximal'], (string) $settings['maxChargingCurrent']);
            }
            if (isset($settings['autoUnlockPlugWhenCharged'])) {
                $general .= $switch('p[autoUnlock]', $settings['autoUnlockPlugWhenCharged'] === 'PERMANENT', 'Stecker nach dem Laden entriegeln');
            }

            // Preferred charging times
            $times = '';
            foreach ($profile['preferredChargingTimes'] ?? [] as $i => $slot) {
                $times .= '<div class="slot">' . $switch("p[times][$i][enabled]", !empty($slot['enabled']), 'Ladezeit aktiv', true)
                    . '<input type="time" name="p[times][' . $i . '][start]" value="' . e($slot['startTime'] ?? '') . '" aria-label="Beginn" required' . $off . '>'
                    . '<span class="slot-sep">bis</span>'
                    . '<input type="time" name="p[times][' . $i . '][end]" value="' . e($slot['endTime'] ?? '') . '" aria-label="Ende" required' . $off . '></div>';
            }

            // Timers
            $timers = '';
            foreach ($profile['timers'] ?? [] as $i => $timer) {
                $type = ($timer['type'] ?? '') === 'ONE_OFF' ? 'ONE_OFF' : 'RECURRING';
                $selected = $type === 'ONE_OFF' ? [$timer['oneOffDay'] ?? ''] : ($timer['recurringOn'] ?? []);
                $chips = '';
                foreach ($days as $day) {
                    $chips .= '<label class="day"><input type="checkbox" name="p[timers][' . $i . '][days][]" value="' . $day . '"' . (in_array($day, $selected, true) ? ' checked' : '') . $off . '>'
                        . '<span>' . e(label($day)) . '</span></label>';
                }
                $timers .= '<div class="timer-edit" data-timer>'
                    . '<div class="timer-row">' . $switch("p[timers][$i][enabled]", !empty($timer['enabled']), 'Timer aktiv', true)
                    . '<input type="time" name="p[timers][' . $i . '][time]" value="' . e($timer['time'] ?? '') . '" aria-label="Uhrzeit" required' . $off . '>'
                    . $segmented('Wiederholung', "p[timers][$i][type]", ['ONE_OFF' => 'Einmalig', 'RECURRING' => 'Wöchentlich'], $type) . '</div>'
                    . '<div class="days" role="group" aria-label="Wochentage">' . $chips . '</div></div>';
            }

            $schedule = '<section class="profile-part"><h4>Bevorzugte Ladezeiten</h4>'
                . ($times ?: '<p class="empty-inline">Keine Ladezeiten angelegt.</p>') . '</section>'
                . '<section class="profile-part"><h4>Timer</h4>'
                . ($timers ?: '<p class="empty-inline">Keine Timer angelegt.</p>') . '</section>';

            return $form('updateChargingProfile',
                '<input type="hidden" name="base" value="' . e(json_encode($profile, JSON_UNESCAPED_UNICODE)) . '">'
                . '<div class="profile-editor"><section class="profile-part"><h4>Allgemein</h4>' . $general . '</section>'
                . '<div class="profile-part-group">' . $schedule . '</div></div>'
                . '<div class="cg-actions"><button class="btn btn-primary"' . $off . '>Profil speichern</button></div>',
                'profile-form', '-' . $index);
        };

        /** Box for a command group: header with status, settings, Stop/Start always at the bottom */
        $renderGroup = static function (string $key, string $title, bool $unsupported) use ($form, $button, $profileEditor, $slider, $icons, $ac, $aux, $vent, $charging, $profiles, $off, $spinConfigured): string {
            $state = match ($key) {
                'airConditioning' => $ac['state'] ?? null,
                'auxiliaryHeating' => $aux['state'] ?? null,
                'activeVentilation' => $vent['state'] ?? null,
                'charging' => $charging['status']['state'] ?? null,
                default => null,
            };
            $settings = '';
            $hidden = '';
            $actions = '';
            switch ($key) {
                case 'airConditioning':
                    $settings = $form('startAirConditioning',
                        $slider('Zieltemperatur', 'temperature', 16, 29.5, 0.5, (float) ($ac['targetTemperature']['value'] ?? 21), '°C')
                        . '<label class="switch"><span>Auch ohne Stromanschluss</span><input type="checkbox" role="switch" name="withoutExternalPower" value="1"'
                        . (($ac['airConditioningWithoutExternalPower'] ?? true) ? ' checked' : '') . $off . '></label>');
                    $hidden = $form('stopAirConditioning');
                    $actions = $button('stopAirConditioning', 'Stoppen', 'secondary') . $button('startAirConditioning', 'Starten');
                    break;
                case 'auxiliaryHeating':
                    $mode = ($aux['startMode'] ?? '') === 'VENTILATION' ? 'VENTILATION' : 'HEATING';
                    $segments = '';
                    foreach (['HEATING' => 'Heizen', 'VENTILATION' => 'Lüften'] as $value => $text) {
                        $segments .= '<label><input type="radio" name="startMode" value="' . $value . '"' . ($value === $mode ? ' checked' : '') . $off . '><span>' . $text . '</span></label>';
                    }
                    $settings = $form('startAuxiliaryHeating',
                        // Without an S-PIN in the configuration a dialog asks for it after submitting
                        ($spinConfigured ? '' : '<input type="hidden" name="spin" data-spin>')
                        . '<fieldset class="segmented"><legend>Modus</legend><div class="seg">' . $segments . '</div></fieldset>'
                        . $slider('Zieltemperatur', 'temperature', 16, 29.5, 0.5, (float) ($aux['targetTemperature']['value'] ?? 21), '°C')
                        . $slider('Laufzeit', 'duration', 10, 60, 10, isset($aux['durationInSeconds']) ? intdiv((int) $aux['durationInSeconds'], 60) : 30, 'Min.'));
                    $hidden = $form('stopAuxiliaryHeating');
                    $actions = $button('stopAuxiliaryHeating', 'Stoppen', 'secondary') . $button('startAuxiliaryHeating', 'Starten');
                    break;
                case 'activeVentilation':
                    $hidden = $form('startActiveVentilation') . $form('stopActiveVentilation');
                    $actions = $button('stopActiveVentilation', 'Stoppen', 'secondary') . $button('startActiveVentilation', 'Starten');
                    break;
                case 'charging':
                    $modes = $charging['settings']['availableChargeModes'] ?? SkodaClient::CHARGE_MODES;
                    $modeOptions = '';
                    foreach ($modes as $mode) {
                        $modeOptions .= '<option value="' . e($mode) . '"' . ($mode === ($charging['settings']['preferredChargeMode'] ?? '') ? ' selected' : '') . '>' . e(label($mode)) . '</option>';
                    }
                    $settings = $form('setChargingLimit',
                            $slider('Ladeziel', 'limit', 50, 100, 10, (int) ($charging['settings']['targetStateOfChargeInPercent'] ?? 80), '%')
                            . '<button class="btn btn-secondary btn-small"' . $off . '>Setzen</button>', 'inline')
                        . $form('setChargeMode',
                            '<label class="field">Lademodus<select name="chargeMode"' . $off . '>' . $modeOptions . '</select></label>'
                            . '<button class="btn btn-secondary btn-small"' . $off . '>Setzen</button>', 'inline');
                    $hidden = $form('startCharging') . $form('stopCharging');
                    $actions = $button('stopCharging', 'Stoppen', 'secondary') . $button('startCharging', 'Starten');
                    break;
                case 'chargingProfiles':
                    // Without profiles from the vehicle show an example so the editor is visible
                    $list = $profiles ?: [[
                        'id' => 0, 'name' => 'Zuhause',
                        'settings' => [
                            'targetStateOfChargeInPercent' => 80, 'maxChargingCurrent' => 'MAXIMUM', 'autoUnlockPlugWhenCharged' => 'OFF',
                            'minBatteryStateOfCharge' => ['enabled' => false, 'minimumBatteryStateOfChargeInPercent' => 20],
                        ],
                        'preferredChargingTimes' => [['id' => 1, 'enabled' => true, 'startTime' => '22:00', 'endTime' => '06:00']],
                        'timers' => [['id' => 1, 'enabled' => true, 'time' => '07:00', 'type' => 'RECURRING', 'recurringOn' => ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY']]],
                    ]];
                    foreach (array_values($list) as $i => $profile) {
                        $settings .= (count($list) > 1 ? '<h4 class="profile-title">' . e($profile['name'] ?? 'Profil') . '</h4>' : '') . $profileEditor($profile, $i);
                    }
                    break;
            }

            return '<div class="command-group' . ($unsupported ? ' is-unsupported' : '') . '">'
                . '<header class="cg-head"><span class="cg-icon"><svg viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$key] ?? '') . '</svg></span>'
                . '<h3>' . e($title) . '</h3>' . chip($state) . '</header>'
                . ($settings !== '' ? '<div class="cg-settings">' . $settings . '</div>' : '')
                . ($actions !== '' ? '<div class="cg-actions">' . $actions . '</div>' : '')
                . ($hidden !== '' ? '<div hidden>' . $hidden . '</div>' : '')
                . '</div>';
        };
        ?>

        <?php if (!$commandsEnabled): ?>
            <div class="alert alert-info">Zum Freischalten in <code>config.local.php</code> <code>'commands_enabled' =&gt; true</code> setzen.</div>
        <?php endif; ?>

        <?php if ($groups['supported']): ?>
            <div class="command-groups<?= $gridClass($groups['supported']) ?>">
                <?php foreach ($groups['supported'] as $key => $title): ?>
                    <?= $renderGroup($key, $title, false) ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-inline">Dieses Fahrzeug meldet keine unterstützten Fernbefehle.</p>
        <?php endif; ?>

        <?php if ($groups['unsupported']): ?>
            <details class="more">
                <summary>Weitere API-Befehle <span class="chip chip-muted">vom Fahrzeug nicht unterstützt</span></summary>
                <p class="meta">Diese Befehle sind implementiert, das Fahrzeug meldet sie aber nicht als unterstützt. Ein Aufruf endet voraussichtlich mit HTTP 422.</p>
                <div class="command-groups<?= $gridClass($groups['unsupported']) ?>">
                    <?php foreach ($groups['unsupported'] as $key => $title): ?>
                        <?= $renderGroup($key, $title, true) ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </section>

    <?php endif; ?>

    <!-- Fetch / connection -->
    <footer class="card footer">
        <?php
        $limit = (int) ($h['ratelimit-limit'] ?? 0);
        $remaining = isset($h['ratelimit-remaining']) ? (int) $h['ratelimit-remaining'] : null;
        $resetAt = isset($h['ratelimit-reset'], $result['fetchedAt']) ? $result['fetchedAt'] + (int) $h['ratelimit-reset'] : null;
        $expires = toDate($h['x-api-key-expires-at'] ?? null);
        $daysLeft = $expires ? (int) floor(($expires->getTimestamp() - time()) / 86400) : null;
        ?>
        <div class="footer-stats">
            <div>
                <small>Daten geladen</small>
                <span><?= isset($result['fetchedAt']) ? e(date('d.m.Y H:i', $result['fetchedAt'])) : '–' ?></span>
                <em><?= !empty($result['fromCache']) ? 'aus dem Cache' : 'live von der API' ?></em>
            </div>
            <?php if ($remaining !== null && $limit > 0): ?>
                <div>
                    <small>Anfragen übrig</small>
                    <span><?= $remaining ?> / <?= $limit ?></span>
                    <div class="meter"><div style="width: <?= round(100 * $remaining / $limit) ?>%" class="<?= $remaining <= 3 ? 'is-low' : '' ?>"></div></div>
                    <?php if ($resetAt && $resetAt > time()): ?><em>volles Kontingent um <?= e(date('H:i', $resetAt)) ?></em><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($expires): ?>
                <div>
                    <small>API-Key gültig bis</small>
                    <span><?= e($expires->format('d.m.Y')) ?></span>
                    <em class="<?= $daysLeft !== null && $daysLeft < 14 ? 'warn' : '' ?>">noch <?= e($daysLeft) ?> Tage</em>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($data): ?>
            <details class="raw">
                <summary>Rohdaten der API</summary>
                <h3>Antwort-Header <small>HTTP <?= e($result['status'] ?? '–') ?></small></h3>
                <pre><?php foreach ($h as $name => $value) { echo e($name), ': ', e($value), "\n"; } ?></pre>
                <h3>Body (JSON)</h3>
                <pre><?= e(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </details>
        <?php endif; ?>
    </footer>
<?php endif; ?>
</main>

<dialog id="spin-dialog" class="spin-dialog" aria-labelledby="spin-title">
    <form method="dialog">
        <h2 id="spin-title">S-PIN eingeben</h2>
        <p class="meta" data-spin-label></p>
        <div class="spin-dots" aria-live="polite" aria-label="Eingegebene Ziffern"><span></span><span></span><span></span><span></span></div>
        <div class="keypad">
            <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit): ?>
                <button type="button" data-key="<?= $digit ?>"><?= $digit ?></button>
            <?php endforeach; ?>
            <span></span>
            <button type="button" data-key="0">0</button>
            <button type="button" data-key="back" aria-label="Letzte Ziffer löschen">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 5H9l-6 7 6 7h12zM17 9l-6 6M11 9l6 6"/></svg>
            </button>
        </div>
        <div class="spin-actions">
            <button type="button" class="btn btn-secondary" value="cancel" data-spin-cancel>Abbrechen</button>
            <button type="button" class="btn btn-primary" data-spin-ok disabled>Ausführen</button>
        </div>
    </form>
</dialog>

<script>
    // Show the parking position with Leaflet
    window.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('parking-map');
        if (!el || !window.L) {
            return;
        }
        var pos = [parseFloat(el.dataset.lat), parseFloat(el.dataset.lon)];
        var map = L.map(el, { scrollWheelZoom: false, tap: false }).setView(pos, 16);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);
        var icon = L.divIcon({
            className: 'car-marker',
            html: '<span><svg viewBox="0 0 24 24"><path d="M5 16V11l2-5h10l2 5v5M5 16h14M7 16v2M17 16v2M5 11h14"/><circle cx="8" cy="13.5" r="1"/><circle cx="16" cy="13.5" r="1"/></svg></span>',
            iconSize: [44, 54],
            iconAnchor: [22, 52],
            popupAnchor: [0, -48]
        });
        L.marker(pos, { icon: icon, title: el.dataset.label }).addTo(map).bindPopup(el.dataset.label);
        L.circle(pos, { radius: 40, color: 'transparent', fillColor: '#78faae', fillOpacity: .25 }).addTo(map);
        // Mouse wheel zoom only after clicking into the map so page scrolling does not get stuck
        map.on('click', function () { map.scrollWheelZoom.enable(); });
        map.on('mouseout', function () { map.scrollWheelZoom.disable(); });
    });

    // Value display of the sliders
    document.querySelectorAll('label.slider input[type=range]').forEach(function (input) {
        var output = input.closest('label').querySelector('output');
        var update = function () {
            var decimals = parseInt(input.dataset.decimals, 10);
            output.textContent = parseFloat(input.value).toLocaleString('de-DE', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' ' + input.dataset.unit;
            input.style.setProperty('--fill', ((input.value - input.min) / (input.max - input.min) * 100) + '%');
        };
        input.addEventListener('input', update);
        update();
    });

    // Charging profile timers: "Einmalig" (one-off) allows only one weekday
    document.querySelectorAll('[data-timer]').forEach(function (timer) {
        var isOneOff = function () { return !!timer.querySelector('input[type=radio][value=ONE_OFF]:checked'); };
        var keepOne = function (keep) {
            timer.querySelectorAll('.days input:checked').forEach(function (box) {
                if (box !== keep) { box.checked = false; }
            });
        };
        timer.addEventListener('change', function (ev) {
            if (!isOneOff()) {
                return;
            }
            keepOne(ev.target.closest('.days') ? ev.target : timer.querySelector('.days input:checked'));
        });
    });

    // S-PIN dialog with keypad; replaces the confirmation prompt for these commands
    var spinDialog = document.getElementById('spin-dialog');
    var spinForm = null;
    var spinValue = '';

    function renderSpin() {
        spinDialog.querySelectorAll('.spin-dots span').forEach(function (dot, i) {
            dot.classList.toggle('is-filled', i < spinValue.length);
        });
        spinDialog.querySelector('[data-spin-ok]').disabled = spinValue.length !== 4;
    }

    function pressSpin(key) {
        if (key === 'back') {
            spinValue = spinValue.slice(0, -1);
        } else if (/^[0-9]$/.test(key) && spinValue.length < 4) {
            spinValue += key;
        }
        renderSpin();
    }

    function openSpinDialog(form) {
        spinForm = form;
        spinValue = '';
        spinDialog.querySelector('[data-spin-label]').textContent = form.dataset.confirm;
        renderSpin();
        spinDialog.showModal();
    }

    spinDialog.querySelectorAll('[data-key]').forEach(function (btn) {
        btn.addEventListener('click', function () { pressSpin(btn.dataset.key); });
    });
    spinDialog.addEventListener('keydown', function (ev) {
        if (/^[0-9]$/.test(ev.key)) {
            pressSpin(ev.key);
        } else if (ev.key === 'Backspace') {
            pressSpin('back');
        } else if (ev.key === 'Enter' && spinValue.length === 4) {
            spinDialog.querySelector('[data-spin-ok]').click();
        } else {
            return;
        }
        ev.preventDefault();
    });
    spinDialog.querySelector('[data-spin-cancel]').addEventListener('click', function () { spinDialog.close(); });
    spinDialog.addEventListener('close', function () { spinValue = ''; renderSpin(); });
    spinDialog.querySelector('[data-spin-ok]').addEventListener('click', function () {
        if (!spinForm || spinValue.length !== 4) {
            return;
        }
        spinForm.querySelector('[data-spin]').value = spinValue;
        spinDialog.close();
        spinForm.submit();
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.querySelector('[data-spin]')) {
                ev.preventDefault();
                openSpinDialog(form);
            } else if (!window.confirm(form.dataset.confirm)) {
                ev.preventDefault();
            }
        });
    });
</script>
</body>
</html>
