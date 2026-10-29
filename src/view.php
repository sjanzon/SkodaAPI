<?php

declare(strict_types=1);

/* View helpers */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function toDate(?string $iso): ?DateTimeImmutable
{
    if (!$iso) {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } catch (Exception) {
        return null;
    }
}

function fmtTime(?string $iso): string
{
    return toDate($iso)?->format('d.m.Y H:i') ?? '–';
}

/** "vor 5 Min." with the absolute time as tooltip */
function ago(?string $iso): string
{
    $date = toDate($iso);
    if (!$date) {
        return '';
    }
    $diff = time() - $date->getTimestamp();
    $text = match (true) {
        $diff < 0 => 'in ' . relSpan(-$diff),
        $diff < 60 => 'gerade eben',
        default => 'vor ' . relSpan($diff),
    };
    return '<time datetime="' . e($date->format(DATE_ATOM)) . '" title="' . e($date->format('d.m.Y H:i:s')) . '">' . e($text) . '</time>';
}

function relSpan(int $seconds): string
{
    return match (true) {
        $seconds < 3600 => max(1, intdiv($seconds, 60)) . ' Min.',
        $seconds < 86400 => intdiv($seconds, 3600) . ' Std.',
        default => intdiv($seconds, 86400) . ' Tg.',
    };
}

function num(int|float|null $value, int $decimals = 0): string
{
    return $value === null ? '–' : number_format((float) $value, $decimals, ',', '.');
}

function has(?array $data, string ...$keys): bool
{
    foreach ($keys as $key) {
        if (isset($data[$key])) {
            return true;
        }
    }
    return false;
}

const LABELS = [
    'YES' => 'Ja', 'NO' => 'Nein', 'LOCKED' => 'Verriegelt', 'UNLOCKED' => 'Entriegelt',
    'OPEN' => 'Offen', 'OPENED' => 'Geöffnet', 'TRUNK_OPENED' => 'Kofferraum offen', 'CLOSED' => 'Geschlossen',
    'ON' => 'An', 'OFF' => 'Aus', 'UNKNOWN' => 'Unbekannt', 'UNSUPPORTED' => 'Nicht vorhanden',
    'PARKED' => 'Geparkt', 'IN_MOTION' => 'Unterwegs',
    'COOLING' => 'Kühlt', 'HEATING' => 'Heizt', 'HEATING_AUXILIARY' => 'Standheizung heizt', 'PREHEATING' => 'Vorheizen',
    'VENTILATION' => 'Lüftet', 'COMPLETED' => 'Abgeschlossen',
    'GASOLINE' => 'Benzin', 'DIESEL' => 'Diesel', 'HYBRID' => 'Hybrid', 'CNG' => 'Erdgas', 'LPG' => 'Autogas', 'ELECTRIC' => 'Elektro',
    'CONNECT_CABLE' => 'Kabel anschließen', 'CHARGING' => 'Lädt', 'CONSERVING' => 'Erhaltungsladung',
    'READY_FOR_CHARGING' => 'Bereit zum Laden', 'DISCHARGING' => 'Entlädt', 'CHARGING_INTERRUPTED' => 'Laden unterbrochen',
    'CONNECTED' => 'Verbunden', 'DISCONNECTED' => 'Getrennt', 'AC' => 'AC', 'DC' => 'DC',
    'MANUAL' => 'Sofort laden', 'TIMER' => 'Abfahrtszeiten', 'TIMER_CHARGING_WITH_CLIMATISATION' => 'Abfahrtszeiten mit Klima',
    'PREFERRED_CHARGING_TIMES' => 'Bevorzugte Ladezeiten', 'ONLY_OWN_CURRENT' => 'Nur eigener Strom',
    'IMMEDIATE_DISCHARGING' => 'Sofort entladen', 'HOME_STORAGE_CHARGING' => 'Heimspeicher laden',
    'ACTIVATED' => 'Aktiv', 'DEACTIVATED' => 'Inaktiv', 'PERMANENT' => 'Dauerhaft', 'REDUCED' => 'Reduziert', 'MAXIMUM' => 'Maximal',
    'ONE_OFF' => 'Einmalig', 'RECURRING' => 'Wiederkehrend',
    'MONDAY' => 'Mo', 'TUESDAY' => 'Di', 'WEDNESDAY' => 'Mi', 'THURSDAY' => 'Do', 'FRIDAY' => 'Fr', 'SATURDAY' => 'Sa', 'SUNDAY' => 'So',
];

function label(?string $value): string
{
    return $value === null ? '–' : (LABELS[$value] ?? ucfirst(strtolower(str_replace('_', ' ', $value))));
}

/** Colour tone of a status value: ok | warn | active | muted */
function tone(?string $value): string
{
    return match ($value) {
        'YES', 'LOCKED', 'CLOSED', 'OFF', 'PARKED', 'COMPLETED', 'CONNECTED', 'ACTIVATED', 'READY_FOR_CHARGING' => 'ok',
        'NO', 'UNLOCKED', 'OPEN', 'OPENED', 'TRUNK_OPENED', 'ON', 'CHARGING_INTERRUPTED', 'CONNECT_CABLE' => 'warn',
        'IN_MOTION', 'COOLING', 'HEATING', 'HEATING_AUXILIARY', 'PREHEATING', 'VENTILATION', 'CHARGING', 'CONSERVING', 'DISCHARGING' => 'active',
        default => 'muted',
    };
}

function chip(?string $value, ?string $text = null): string
{
    if ($value === null) {
        return '';
    }
    return '<span class="chip chip-' . tone($value) . '">' . e($text ?? label($value)) . '</span>';
}

function temperature(?array $t): string
{
    if (!isset($t['value'])) {
        return '–';
    }
    return num($t['value'], fmod((float) $t['value'], 1.0) === 0.0 ? 0 : 1) . ' ' . (($t['unit'] ?? '') === 'FAHRENHEIT' ? '°F' : '°C');
}

/** 270° gauge (e.g. fuel level). $percent 0–100 or null. */
function gauge(?float $percent, string $value, string $unit, string $caption, string $class = ''): string
{
    $p = $percent === null ? 0 : max(0, min(100, $percent));
    $arc = 75; // 270° of 360°
    $valueArc = round($arc * $p / 100, 2);
    $low = $percent !== null && $percent < 15 ? ' is-low' : '';
    return <<<SVG
        <figure class="gauge {$class}{$low}">
            <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle class="gauge-track" cx="60" cy="60" r="50" pathLength="100" stroke-dasharray="{$arc} 100" transform="rotate(135 60 60)"/>
                <circle class="gauge-value" cx="60" cy="60" r="50" pathLength="100" stroke-dasharray="{$valueArc} 100" transform="rotate(135 60 60)"/>
            </svg>
            <figcaption>
                <span class="gauge-number">{$value}<small>{$unit}</small></span>
                <span class="gauge-caption">{$caption}</span>
            </figcaption>
        </figure>
        SVG;
}

/** Top view of the vehicle; doors, windows, lids and lights are coloured by their status. */
function carDiagram(array $overall, array $detail): string
{
    $state = static fn (?string $v): string => match ($v) {
        'OPEN', 'OPENED' => 'open',
        'CLOSED' => 'closed',
        default => 'unknown',
    };
    $doors = $state($overall['doors'] ?? null);
    $windows = $state($overall['windows'] ?? null);
    $trunk = $state($detail['trunk'] ?? null);
    $bonnet = $state($detail['bonnet'] ?? null);
    $sunroofValue = $detail['sunroof'] ?? 'UNSUPPORTED';
    $lights = ($overall['lights'] ?? null) === 'ON' ? 'on' : 'off';
    $locked = ($overall['locked'] ?? null) === 'YES' ? 'locked' : (($overall['locked'] ?? null) === 'NO' ? 'unlocked' : 'unknown');

    $sunroof = $sunroofValue === 'UNSUPPORTED' ? '' :
        '<rect class="part ' . $state($sunroofValue) . '" x="58" y="150" width="44" height="46" rx="6"/>';
    $lockIcon = $locked === 'unlocked'
        ? '<path d="M71 210v-8a9 9 0 0 1 17-4" class="lock-shackle"/>'
        : '<path d="M71 210v-8a9 9 0 0 1 18 0v8" class="lock-shackle"/>';

    return <<<SVG
        <svg class="car car-lights-{$lights} car-{$locked}" viewBox="0 0 160 330" role="img" aria-label="Fahrzeugstatus von oben">
            <g class="beams"><path d="M42 24 L26 0 L58 0 Z"/><path d="M118 24 L102 0 L134 0 Z"/></g>
            <!-- Body -->
            <path class="body" d="M80 12 C120 12 136 24 138 60 L140 270 C140 304 124 318 80 318 C36 318 20 304 20 270 L22 60 C24 24 40 12 80 12 Z"/>
            <!-- Bonnet -->
            <path class="part {$bonnet}" d="M80 18 C114 18 128 28 130 62 L128 86 C112 80 96 78 80 78 C64 78 48 80 32 86 L30 62 C32 28 46 18 80 18 Z"/>
            <!-- Windscreen -->
            <path class="glass {$windows}" d="M36 96 C64 86 96 86 124 96 L118 128 C94 122 66 122 42 128 Z"/>
            <!-- Roof -->
            <rect class="roof" x="44" y="134" width="72" height="108" rx="10"/>
            {$sunroof}
            <!-- Rear window -->
            <path class="glass {$windows}" d="M44 248 C66 254 94 254 116 248 L122 268 C96 276 64 276 38 268 Z"/>
            <!-- Trunk -->
            <path class="part {$trunk}" d="M36 280 C64 288 96 288 124 280 L126 296 C120 310 104 314 80 314 C56 314 40 310 34 296 Z"/>
            <!-- Doors -->
            <g class="doors {$doors}">
                <rect class="door door-lf" x="14" y="104" width="9" height="62" rx="3"/>
                <rect class="door door-lr" x="14" y="172" width="9" height="62" rx="3"/>
                <rect class="door door-rf" x="137" y="104" width="9" height="62" rx="3"/>
                <rect class="door door-rr" x="137" y="172" width="9" height="62" rx="3"/>
            </g>
            <!-- Side windows -->
            <g class="side-glass {$windows}">
                <rect x="27" y="108" width="5" height="54" rx="2"/><rect x="27" y="176" width="5" height="54" rx="2"/>
                <rect x="128" y="108" width="5" height="54" rx="2"/><rect x="128" y="176" width="5" height="54" rx="2"/>
            </g>
            <!-- Headlights / tail lights -->
            <g class="lamps"><ellipse cx="42" cy="26" rx="10" ry="4"/><ellipse cx="118" cy="26" rx="10" ry="4"/></g>
            <g class="tail"><rect x="30" y="300" width="18" height="5" rx="2"/><rect x="112" y="300" width="18" height="5" rx="2"/></g>
            <!-- Lock -->
            <g class="lock lock-{$locked}">
                <circle cx="80" cy="214" r="17"/>
                {$lockIcon}
                <rect x="68" y="208" width="24" height="17" rx="3" class="lock-body"/>
            </g>
        </svg>
        SVG;
}

const MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

/**
 * Rounded axis range with 3–6 ticks.
 *
 * @return array{0: float, 1: float, 2: list<float>} [min, max, ticks]
 */
function niceScale(float $min, float $max): array
{
    if ($max - $min < 1e-9) {
        $pad = max(1.0, abs($max) * 0.05);
        $min -= $pad;
        $max += $pad;
    }
    $rough = ($max - $min) / 4;
    $magnitude = 10 ** floor(log10($rough));
    $step = $magnitude * match (true) {
        $rough / $magnitude <= 1 => 1,
        $rough / $magnitude <= 2 => 2,
        $rough / $magnitude <= 2.5 => 2.5,
        $rough / $magnitude <= 5 => 5,
        default => 10,
    };
    $niceMin = floor($min / $step) * $step;
    $niceMax = ceil($max / $step) * $step;
    $ticks = [];
    for ($v = $niceMin; $v <= $niceMax + $step / 2; $v += $step) {
        $ticks[] = round($v, 6);
    }
    return [$niceMin, $niceMax, $ticks];
}

/**
 * Time axis ticks aligned to local hours, days, weeks or months; at most about 7 ticks.
 *
 * @return list<array{0: int, 1: string}> [unix time, label]
 */
function timeTicks(int $from, int $to): array
{
    $span = max(1, $to - $from);
    $start = (new DateTimeImmutable('@' . $from))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $ticks = [];

    foreach ([1, 2, 3, 6, 12] as $hours) {
        if ($span / ($hours * 3600) > 7) {
            continue;
        }
        $t = $start->setTime((int) $start->format('G'), 0);
        while ($t < $start || (int) $t->format('G') % $hours !== 0) {
            $t = $t->modify('+1 hour');
        }
        for (; $t->getTimestamp() <= $to; $t = $t->modify("+{$hours} hours")) {
            $ticks[] = [$t->getTimestamp(), $t->format($t->format('H') === '00' ? 'd.m.' : 'H:i')];
        }
        return $ticks;
    }

    foreach ([1, 2, 7, 14] as $days) {
        if ($span / ($days * 86400) > 7) {
            continue;
        }
        $t = $start->setTime(0, 0);
        if ($days >= 7) {
            $t = $t->modify('monday this week');
        }
        while ($t < $start) {
            $t = $t->modify("+{$days} days");
        }
        for (; $t->getTimestamp() <= $to; $t = $t->modify("+{$days} days")) {
            $ticks[] = [$t->getTimestamp(), $t->format('d.m.')];
        }
        return $ticks;
    }

    $months = 12;
    foreach ([1, 2, 3, 6] as $candidate) {
        if ($span / ($candidate * 30.44 * 86400) <= 7) {
            $months = $candidate;
            break;
        }
    }
    $t = $start->modify('first day of this month')->setTime(0, 0);
    while ($t < $start || ((int) $t->format('n') - 1) % $months !== 0) {
        $t = $t->modify('+1 month');
    }
    $years = max(1, (int) ceil($span / (365.25 * 86400) / 7));
    for (; $t->getTimestamp() <= $to; $t = $t->modify("+{$months} months")) {
        $month = (int) $t->format('n');
        if ($months === 12 && (int) $t->format('Y') % $years !== 0) {
            continue;
        }
        $ticks[] = [$t->getTimestamp(), $month === 1 ? $t->format('Y') : MONTHS[$month - 1]];
    }
    return $ticks;
}

/**
 * Responsive line chart. The plot is an SVG stretched to its box (strokes do not scale); the axis
 * labels are HTML, so text stays crisp at any width. The data is attached for the hover tooltip.
 *
 * @param list<array{0: float, 1: float}> $points [unix time, value], oldest first
 * @param ?array{0: float, 1: float} $fixed fixed value range, e.g. [0, 100] for percentages
 * @param int $maxGap break the line where two points are further apart (seconds, 0 = never)
 */
function lineChart(array $points, int $from, int $to, string $unit, int $decimals = 0, string $class = '', ?array $fixed = null, int $maxGap = 0): string
{
    if (!$points) {
        return '<p class="empty-inline">Keine Daten in diesem Zeitraum.</p>';
    }
    $values = array_column($points, 1);
    [$min, $max, $yTicks] = $fixed
        ? [(float) $fixed[0], (float) $fixed[1], range($fixed[0], $fixed[1], ($fixed[1] - $fixed[0]) / 4)]
        : niceScale(min($values), max($values));
    $span = max(1, $to - $from);
    $x = static fn (float $t): float => round(($t - $from) / $span * 1000, 2);
    $y = static fn (float $v): float => round(100 - ($v - $min) / ($max - $min) * 100, 2);

    // Split into segments where data is missing for a longer time
    $segments = [[]];
    $previous = null;
    foreach ($points as [$t, $v]) {
        if ($previous !== null && $maxGap > 0 && $t - $previous > $maxGap) {
            $segments[] = [];
        }
        $segments[array_key_last($segments)][] = [$x($t), $y($v)];
        $previous = $t;
    }
    $line = '';
    $area = '';
    foreach ($segments as $segment) {
        $coords = implode(' L', array_map(static fn (array $p): string => $p[0] . ' ' . $p[1], $segment));
        // A single point would be invisible – draw it as a short dash
        $line .= 'M' . $coords . (count($segment) === 1 ? ' h2' : '') . ' ';
        $area .= 'M' . $segment[0][0] . ' 100 L' . $coords . ' L' . $segment[array_key_last($segment)][0] . ' 100 Z ';
    }

    $grid = '';
    $yLabels = '';
    foreach ($yTicks as $tick) {
        $grid .= '<line x1="0" x2="1000" y1="' . $y($tick) . '" y2="' . $y($tick) . '"/>';
        $yLabels .= '<span style="top:' . $y($tick) . '%">' . e(num($tick, fmod((float) $tick, 1.0) === 0.0 ? 0 : 1)) . '</span>';
    }
    $xLabels = '';
    foreach (timeTicks($from, $to) as [$t, $text]) {
        $grid .= '<line class="v" x1="' . $x($t) . '" x2="' . $x($t) . '" y1="0" y2="100"/>';
        $xLabels .= '<span style="left:' . round(($t - $from) / $span * 100, 2) . '%">' . e($text) . '</span>';
    }

    $data = e(json_encode([
        'from' => $from, 'to' => $to, 'min' => $min, 'max' => $max, 'unit' => $unit, 'decimals' => $decimals,
        'points' => array_map(static fn (array $p): array => [(int) round($p[0]), round($p[1], max(1, $decimals))], $points),
    ]));

    return <<<HTML
        <figure class="chart {$class}" data-chart="{$data}">
            <div class="chart-y" aria-hidden="true">{$yLabels}</div>
            <div class="chart-plot">
                <svg viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true">
                    <g class="chart-grid">{$grid}</g>
                    <path class="chart-area" d="{$area}"/>
                    <path class="chart-line" d="{$line}"/>
                </svg>
                <div class="chart-cursor" hidden><span class="chart-dot"></span><span class="chart-tip"></span></div>
            </div>
            <div class="chart-x" aria-hidden="true">{$xLabels}</div>
        </figure>
        HTML;
}
