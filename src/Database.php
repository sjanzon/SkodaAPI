<?php

declare(strict_types=1);

/**
 * PDO connection for SQLite or MySQL/MariaDB. The schema is created automatically on connect.
 */
final class Database
{
    private function __construct(
        public readonly PDO $pdo,
        public readonly string $driver,
    ) {
    }

    /**
     * @param array{driver?: string, sqlite_path?: string, host?: string, port?: int, database?: string, username?: string, password?: string} $config
     * @return ?self null when the history is disabled (driver 'none')
     */
    public static function fromConfig(array $config): ?self
    {
        $driver = strtolower((string) ($config['driver'] ?? 'sqlite'));
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        switch ($driver) {
            case 'none':
            case '':
                return null;

            case 'sqlite':
                $path = (string) ($config['sqlite_path'] ?? '');
                if ($path === '') {
                    throw new RuntimeException('Kein SQLite-Pfad konfiguriert (db.sqlite_path).');
                }
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                // WAL lets the dashboard read while the cron job writes
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                break;

            case 'mysql':
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config['host'] ?? '127.0.0.1',
                    (int) ($config['port'] ?? 3306),
                    $config['database'] ?? 'skoda',
                );
                $pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $options);
                break;

            default:
                throw new RuntimeException('Unbekannter Datenbanktreiber: ' . $driver . ' (erlaubt: sqlite, mysql, none).');
        }

        $db = new self($pdo, $driver);
        $db->migrate();

        return $db;
    }

    /** INSERT that silently skips rows violating a unique key */
    public function insertIgnore(): string
    {
        return $this->driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    }

    private function migrate(): void
    {
        if ($this->driver === 'mysql') {
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS vehicle_snapshots (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    vin CHAR(17) NOT NULL,
                    fetched_at BIGINT NOT NULL,
                    captured_at BIGINT NULL,
                    source VARCHAR(10) NOT NULL,
                    mileage_km INT NULL,
                    fuel_level_percent DOUBLE NULL,
                    battery_soc_percent DOUBLE NULL,
                    total_range_km DOUBLE NULL,
                    electric_range_km DOUBLE NULL,
                    charging_state VARCHAR(40) NULL,
                    charge_power_kw DOUBLE NULL,
                    locked VARCHAR(20) NULL,
                    parking_state VARCHAR(20) NULL,
                    latitude DOUBLE NULL,
                    longitude DOUBLE NULL,
                    address VARCHAR(255) NULL,
                    payload MEDIUMTEXT NOT NULL,
                    UNIQUE KEY uq_vin_fetched (vin, fetched_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL);
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS vehicle_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vin TEXT NOT NULL,
                fetched_at INTEGER NOT NULL,
                captured_at INTEGER NULL,
                source TEXT NOT NULL,
                mileage_km INTEGER NULL,
                fuel_level_percent REAL NULL,
                battery_soc_percent REAL NULL,
                total_range_km REAL NULL,
                electric_range_km REAL NULL,
                charging_state TEXT NULL,
                charge_power_kw REAL NULL,
                locked TEXT NULL,
                parking_state TEXT NULL,
                latitude REAL NULL,
                longitude REAL NULL,
                address TEXT NULL,
                payload TEXT NOT NULL,
                UNIQUE (vin, fetched_at)
            )
            SQL);
    }
}
