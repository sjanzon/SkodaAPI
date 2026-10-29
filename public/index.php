<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/view.php';

session_start();

const OPERATION_LABELS = [
    'startAirConditioning' => 'Klimatisierung starten',
    'stopAirConditioning' => 'Klimatisierung stoppen',
    'startAuxiliaryHeating' => 'Standheizung starten',
    'stopAuxiliaryHeating' => 'Standheizung stoppen',
    'startActiveVentilation' => 'Aktive Lüftung starten',
    'stopActiveVentilation' => 'Aktive Lüftung stoppen',
    'startCharging' => 'Laden starten',
    'stopCharging' => 'Laden stoppen',
    'setChargingLimit' => 'Ladelimit setzen',
    'setChargeMode' => 'Lademodus setzen',
    'updateChargingProfile' => 'Ladeprofil ändern',
];

/** Command groups for the UI: key => [title, operations] */
const COMMAND_GROUPS = [
    'airConditioning' => ['Klimatisierung', ['startAirConditioning', 'stopAirConditioning']],
    'auxiliaryHeating' => ['Standheizung', ['startAuxiliaryHeating', 'stopAuxiliaryHeating']],
    'activeVentilation' => ['Aktive Lüftung', ['startActiveVentilation', 'stopActiveVentilation']],
    'charging' => ['Laden', ['startCharging', 'stopCharging', 'setChargingLimit', 'setChargeMode']],
    'chargingProfiles' => ['Ladeprofil', ['updateChargingProfile']],
];

$vin = strtoupper(trim((string) $config['vin']));
$configError = configError($config);
$client = createClient($config);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/** Executes the command requested via the form. */
function runCommand(SkodaClient $client, string $vin, string $operation, array $in, string $configuredSpin): array
{
    $spin = static function () use ($in, $configuredSpin): string {
        $spin = $configuredSpin !== '' ? $configuredSpin : (string) ($in['spin'] ?? '');
        if (!preg_match('/^[0-9]{4}$/', $spin)) {
            throw new InvalidArgumentException('Die S-PIN muss aus 4 Ziffern bestehen.');
        }
        return $spin;
    };
    $temp = static fn (): array => [
        'value' => (float) str_replace(',', '.', (string) ($in['temperature'] ?? '21')),
        'unit' => 'CELSIUS',
    ];

    return match ($operation) {
        'startAirConditioning' => $client->startAirConditioning($vin, [
            'targetTemperature' => $temp(),
            'airConditioningWithoutExternalPower' => !empty($in['withoutExternalPower']),
        ]),
        'startAuxiliaryHeating' => $client->startAuxiliaryHeating($vin, array_filter([
            'spin' => $spin(),
            'targetTemperature' => $temp(),
            'durationInSeconds' => ($in['duration'] ?? '') !== '' ? (int) $in['duration'] * 60 : null,
            'startMode' => in_array($in['startMode'] ?? '', ['HEATING', 'VENTILATION'], true) ? $in['startMode'] : null,
        ], static fn ($v) => $v !== null)),
        'setChargingLimit' => $client->setChargingLimit($vin, (int) ($in['limit'] ?? 80)),
        'setChargeMode' => in_array($in['chargeMode'] ?? '', SkodaClient::CHARGE_MODES, true)
            ? $client->setChargeMode($vin, $in['chargeMode'])
            : throw new InvalidArgumentException('Ungültiger Lademodus.'),
        'updateChargingProfile' => (static function () use ($client, $vin, $in): array {
            $base = json_decode((string) ($in['base'] ?? ''), true);
            if (!is_array($base) || !isset($base['id'])) {
                throw new InvalidArgumentException('Das Ladeprofil konnte nicht gelesen werden – bitte Seite neu laden.');
            }
            return $client->updateChargingProfile($vin, buildChargingProfile($base, (array) ($in['p'] ?? [])));
        })(),
        default => $client->command($vin, $operation),
    };
}

/**
 * Applies the form values to the original profile. The API always expects the complete profile,
 * so all fields that were not edited are kept unchanged.
 */
function buildChargingProfile(array $profile, array $in): array
{
    $percent = static fn (mixed $v, int $min, int $max): int => max($min, min($max, (int) $v));
    $time = static function (mixed $v): string {
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string) $v)) {
            throw new InvalidArgumentException('Ungültige Uhrzeit im Ladeprofil: ' . $v);
        }
        return (string) $v;
    };
    $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    $name = trim((string) ($in['name'] ?? $profile['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Das Ladeprofil braucht einen Namen.');
    }
    $profile['name'] = $name;

    $settings = &$profile['settings'];
    $settings['targetStateOfChargeInPercent'] = $percent($in['target'] ?? $settings['targetStateOfChargeInPercent'] ?? 80, 50, 100);
    if (isset($settings['minBatteryStateOfCharge'])) {
        $settings['minBatteryStateOfCharge']['enabled'] = !empty($in['minEnabled']);
        $settings['minBatteryStateOfCharge']['minimumBatteryStateOfChargeInPercent'] =
            $percent($in['min'] ?? $settings['minBatteryStateOfCharge']['minimumBatteryStateOfChargeInPercent'] ?? 20, 0, 100);
    }
    if (isset($settings['maxChargingCurrent']) && in_array($in['maxCurrent'] ?? '', ['REDUCED', 'MAXIMUM'], true)) {
        $settings['maxChargingCurrent'] = $in['maxCurrent'];
    }
    if (isset($settings['autoUnlockPlugWhenCharged'])) {
        $settings['autoUnlockPlugWhenCharged'] = !empty($in['autoUnlock']) ? 'PERMANENT' : 'OFF';
    }
    unset($settings);

    foreach ($profile['preferredChargingTimes'] ?? [] as $i => $slot) {
        $edit = $in['times'][$i] ?? [];
        $profile['preferredChargingTimes'][$i]['enabled'] = !empty($edit['enabled']);
        $profile['preferredChargingTimes'][$i]['startTime'] = $time($edit['start'] ?? $slot['startTime']);
        $profile['preferredChargingTimes'][$i]['endTime'] = $time($edit['end'] ?? $slot['endTime']);
    }

    foreach ($profile['timers'] ?? [] as $i => $timer) {
        $edit = $in['timers'][$i] ?? [];
        $selected = array_values(array_intersect($days, (array) ($edit['days'] ?? [])));
        $timer['enabled'] = !empty($edit['enabled']);
        $timer['time'] = $time($edit['time'] ?? $timer['time'] ?? '');
        $timer['type'] = ($edit['type'] ?? $timer['type'] ?? '') === 'ONE_OFF' ? 'ONE_OFF' : 'RECURRING';
        if ($timer['type'] === 'ONE_OFF') {
            $timer['oneOffDay'] = $selected[0] ?? throw new InvalidArgumentException('Ein einmaliger Timer braucht einen Wochentag.');
            unset($timer['recurringOn']);
        } else {
            if (!$selected) {
                throw new InvalidArgumentException('Ein wöchentlicher Timer braucht mindestens einen Wochentag.');
            }
            $timer['recurringOn'] = $selected;
            unset($timer['oneOffDay']);
        }
        $profile['timers'][$i] = $timer;
    }

    return $profile;
}

// --- Execute a remote command (POST with CSRF token only, followed by a redirect) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $configError === null) {
    $operation = (string) ($_POST['operation'] ?? '');
    $label = OPERATION_LABELS[$operation] ?? $operation;
    $flash = ['ok' => false, 'message' => ''];

    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $flash['message'] = 'Ungültiges Formular-Token – bitte Seite neu laden.';
    } elseif (!$client->commandsEnabled()) {
        $flash['message'] = 'Fernbefehle sind deaktiviert (config.local.php → commands_enabled).';
    } elseif (!isset(SkodaClient::COMMANDS[$operation])) {
        $flash['message'] = 'Unbekannter Befehl.';
    } else {
        try {
            $result = runCommand($client, $vin, $operation, $_POST, trim((string) $config['spin']));
            if ($result['status'] === 202) {
                $flash = ['ok' => true, 'message' => "„{$label}“ wurde an das Fahrzeug übermittelt. Die Ausführung erfolgt asynchron."];
            } else {
                $p = $result['problem'] ?? [];
                $flash['message'] = "„{$label}“ fehlgeschlagen (HTTP {$result['status']}): " . ($p['detail'] ?? $p['title'] ?? 'Unbekannter Fehler');
            }
        } catch (Throwable $ex) {
            $flash['message'] = "„{$label}“: " . $ex->getMessage();
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$result = $configError === null ? $client->getVehicle($vin, $config['include'], isset($_GET['refresh'])) : null;

// Store freshly loaded data in the history as well – it costs no extra request
$dbError = null;
if ($result !== null && !$result['fromCache']) {
    try {
        createRepository($config)?->save($vin, $result, 'web');
    } catch (Throwable $ex) {
        $dbError = 'Die Daten konnten nicht in der Datenbank gespeichert werden: ' . $ex->getMessage();
    }
}
$historyEnabled = strtolower((string) ($config['db']['driver'] ?? '')) !== 'none';

require __DIR__ . '/../templates/dashboard.php';
