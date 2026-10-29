<?php

declare(strict_types=1);

/**
 * Stores API responses as snapshots and reads them back for the history view.
 *
 * Frequently charted values are extracted into columns; the full response is kept as JSON in
 * `payload`, so nothing is lost if more values are evaluated later.
 */
final class SnapshotRepository
{
    /** Columns returned for charts and tables (everything except the payload) */
    private const COLUMNS = 'id, fetched_at, captured_at, source, mileage_km, fuel_level_percent, battery_soc_percent,'
        . ' total_range_km, electric_range_km, charging_state, charge_power_kw, locked, parking_state, latitude, longitude, address';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Stores a successful API result. A result with the same fetch time (e.g. served from the
     * cache) is ignored, so it is safe to call this for every result.
     *
     * @param array{data: ?array, status: int, fetchedAt: int} $result
     * @return bool true if a new snapshot was stored
     */
    public function save(string $vin, array $result, string $source): bool
    {
        if (($result['status'] ?? 0) !== 200 || !is_array($result['data'] ?? null)) {
            return false;
        }
        $row = self::extract($result['data']['vehicle'] ?? []);
        $row += [
            'vin' => $vin,
            'fetched_at' => (int) $result['fetchedAt'],
            'source' => $source,
            'payload' => json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $columns = array_keys($row);
        $sql = $this->db->insertIgnore() . ' INTO vehicle_snapshots (' . implode(', ', $columns) . ')'
            . ' VALUES (:' . implode(', :', $columns) . ')';
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute($row);

        return $stmt->rowCount() > 0;
    }

    public function lastFetchedAt(string $vin): ?int
    {
        $stmt = $this->db->pdo->prepare('SELECT MAX(fetched_at) FROM vehicle_snapshots WHERE vin = ?');
        $stmt->execute([$vin]);
        $value = $stmt->fetchColumn();

        return $value === null || $value === false ? null : (int) $value;
    }

    /**
     * Key figures for the period.
     *
     * @return array{count: int, first: ?int, last: ?int, min_mileage: ?int, max_mileage: ?int}
     */
    public function stats(string $vin, int $from, int $to): array
    {
        $stmt = $this->db->pdo->prepare('SELECT COUNT(*) AS count, MIN(fetched_at) AS first, MAX(fetched_at) AS last,'
            . ' MIN(mileage_km) AS min_mileage, MAX(mileage_km) AS max_mileage'
            . ' FROM vehicle_snapshots WHERE vin = ? AND fetched_at BETWEEN ? AND ?');
        $stmt->execute([$vin, $from, $to]);
        $row = $stmt->fetch() ?: [];

        return array_map(static fn ($v) => $v === null ? null : (int) $v, $row + ['count' => 0, 'first' => null, 'last' => null, 'min_mileage' => null, 'max_mileage' => null]);
    }

    /**
     * Chart data: the period is split into buckets of $bucketSeconds and the values of each
     * bucket are averaged (charge power: maximum), so even years of data stay small.
     *
     * @return list<array<string, ?float>> oldest first; `t` is the mean time of the bucket
     */
    public function series(string $vin, int $from, int $to, int $bucketSeconds): array
    {
        $bucketSeconds = max(1, $bucketSeconds);
        // Integer division: SQLite truncates integers with "/", MySQL needs DIV
        $bucket = '(fetched_at - ' . $from . ') ' . ($this->db->driver === 'mysql' ? 'DIV' : '/') . ' ' . $bucketSeconds;
        $stmt = $this->db->pdo->prepare('SELECT AVG(fetched_at) AS t, MAX(mileage_km) AS mileage_km,'
            . ' AVG(fuel_level_percent) AS fuel_level_percent, AVG(battery_soc_percent) AS battery_soc_percent,'
            . ' AVG(total_range_km) AS total_range_km, AVG(electric_range_km) AS electric_range_km,'
            . ' MAX(charge_power_kw) AS charge_power_kw'
            . ' FROM vehicle_snapshots WHERE vin = ? AND fetched_at BETWEEN ? AND ?'
            . ' GROUP BY ' . $bucket . ' ORDER BY t');
        $stmt->execute([$vin, $from, $to]);

        return array_map(
            static fn (array $row): array => array_map(static fn ($v) => $v === null ? null : (float) $v, $row),
            $stmt->fetchAll(),
        );
    }

    /**
     * Distinct parking places in the period (rounded to about 10 m), most frequent first.
     *
     * @return list<array{lat: float, lon: float, address: ?string, count: int, last: int}>
     */
    public function places(string $vin, int $from, int $to, int $limit = 200): array
    {
        $stmt = $this->db->pdo->prepare('SELECT ROUND(latitude, 4) AS lat, ROUND(longitude, 4) AS lon, MAX(address) AS address,'
            . ' COUNT(*) AS count, MAX(fetched_at) AS last'
            . ' FROM vehicle_snapshots WHERE vin = ? AND fetched_at BETWEEN ? AND ? AND latitude IS NOT NULL AND longitude IS NOT NULL'
            . ' GROUP BY ROUND(latitude, 4), ROUND(longitude, 4) ORDER BY count DESC LIMIT ' . max(1, $limit));
        $stmt->execute([$vin, $from, $to]);

        return array_map(static fn (array $row): array => [
            'lat' => (float) $row['lat'],
            'lon' => (float) $row['lon'],
            'address' => $row['address'],
            'count' => (int) $row['count'],
            'last' => (int) $row['last'],
        ], $stmt->fetchAll());
    }

    /**
     * One page of snapshots in the period, newest first (without payload).
     *
     * @return list<array<string, mixed>>
     */
    public function page(string $vin, int $from, int $to, int $limit, int $offset): array
    {
        $stmt = $this->db->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM vehicle_snapshots'
            . ' WHERE vin = ? AND fetched_at BETWEEN ? AND ? ORDER BY fetched_at DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $stmt->execute([$vin, $from, $to]);

        return array_map(self::cast(...), $stmt->fetchAll());
    }

    /** A single snapshot including the decoded API response */
    public function find(string $vin, int $id): ?array
    {
        $stmt = $this->db->pdo->prepare('SELECT ' . self::COLUMNS . ', payload FROM vehicle_snapshots WHERE vin = ? AND id = ?');
        $stmt->execute([$vin, $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row = self::cast($row);
        $row['payload'] = json_decode((string) $row['payload'], true);

        return $row;
    }

    /** Deletes snapshots older than the given number of days; returns the number of deleted rows */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $stmt = $this->db->pdo->prepare('DELETE FROM vehicle_snapshots WHERE fetched_at < ?');
        $stmt->execute([time() - $days * 86400]);

        return $stmt->rowCount();
    }

    /**
     * Picks the values worth charting from the vehicle part of the response.
     *
     * @return array<string, int|float|string|null>
     */
    public static function extract(array $vehicle): array
    {
        $fuel = $vehicle['fuelStatus'] ?? [];
        $engines = array_filter([$fuel['primaryEngineRange'] ?? null, $fuel['secondaryEngineRange'] ?? null]);
        $combustion = null;
        $electric = null;
        foreach ($engines as $engine) {
            if (($engine['engineType'] ?? '') === 'ELECTRIC') {
                $electric ??= $engine;
            } else {
                $combustion ??= $engine;
            }
        }
        $battery = $vehicle['charging']['status']['battery'] ?? [];
        $charging = $vehicle['charging']['status'] ?? [];
        $park = $vehicle['parkingPosition'] ?? [];
        $overall = $vehicle['status']['overall'] ?? [];

        $captured = null;
        foreach (['status', 'odometer', 'fuelStatus', 'charging', 'airConditioning'] as $part) {
            $iso = $vehicle[$part]['carCapturedTimestamp'] ?? null;
            if ($iso && ($time = strtotime($iso)) !== false) {
                $captured = max($captured ?? 0, $time);
            }
        }

        $electricRange = $electric['remainingRangeInKm'] ?? null;
        if ($electricRange === null && isset($battery['remainingCruisingRangeInMeters'])) {
            $electricRange = $battery['remainingCruisingRangeInMeters'] / 1000;
        }

        return [
            'captured_at' => $captured,
            'mileage_km' => isset($vehicle['odometer']['mileageInKm']) ? (int) $vehicle['odometer']['mileageInKm'] : null,
            'fuel_level_percent' => self::float($combustion['currentFuelLevelInPercent'] ?? null),
            'battery_soc_percent' => self::float($battery['stateOfChargeInPercent'] ?? $electric['currentSoCInPercent'] ?? null),
            'total_range_km' => self::float($fuel['totalRangeInKm'] ?? $combustion['remainingRangeInKm'] ?? $electricRange),
            'electric_range_km' => self::float($electricRange),
            'charging_state' => $charging['state'] ?? null,
            'charge_power_kw' => self::float($charging['chargePowerInKw'] ?? null),
            'locked' => $overall['reliableLockStatus'] ?? $overall['locked'] ?? null,
            'parking_state' => $park['state'] ?? null,
            'latitude' => self::float($park['gpsCoordinates']['latitude'] ?? null),
            'longitude' => self::float($park['gpsCoordinates']['longitude'] ?? null),
            'address' => isset($park['formattedAddress']) ? mb_substr((string) $park['formattedAddress'], 0, 255) : null,
        ];
    }

    private static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** PDO returns strings for some drivers – normalise the numeric columns */
    private static function cast(array $row): array
    {
        foreach (['id', 'fetched_at', 'captured_at', 'mileage_km'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['fuel_level_percent', 'battery_soc_percent', 'total_range_km', 'electric_range_km', 'charge_power_kw', 'latitude', 'longitude'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (float) $row[$key];
            }
        }

        return $row;
    }
}
