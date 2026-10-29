<?php

declare(strict_types=1);

/**
 * Client for the MyŠkoda Public API.
 *
 * Read: GET /api/v1/vehicles/{vin} (with a file cache because of the rate limit).
 * Write: all remote commands of the API (air conditioning, auxiliary heating, ventilation, charging).
 * They are only executed if $commandsEnabled is set and return HTTP 202 on success.
 */
final class SkodaClient
{
    /** operationId => [HTTP method, path suffix, body required] */
    public const COMMANDS = [
        'startAirConditioning'   => ['POST', '/air-conditioning/start', true],
        'stopAirConditioning'    => ['POST', '/air-conditioning/stop', false],
        'startAuxiliaryHeating'  => ['POST', '/auxiliary-heating/start', true],
        'stopAuxiliaryHeating'   => ['POST', '/auxiliary-heating/stop', false],
        'startActiveVentilation' => ['POST', '/active-ventilation/start', false],
        'stopActiveVentilation'  => ['POST', '/active-ventilation/stop', false],
        'startCharging'          => ['POST', '/charging/start', false],
        'stopCharging'           => ['POST', '/charging/stop', false],
        'setChargingLimit'       => ['PUT', '/charging/limit', true],
        'setChargeMode'          => ['PUT', '/charging/mode', true],
        'updateChargingProfile'  => ['PUT', '/charging-profiles/{id}', true],
    ];

    public const CHARGE_MODES = [
        'MANUAL', 'TIMER', 'TIMER_CHARGING_WITH_CLIMATISATION', 'PREFERRED_CHARGING_TIMES',
        'ONLY_OWN_CURRENT', 'IMMEDIATE_DISCHARGING', 'HOME_STORAGE_CHARGING',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $cacheDir,
        private readonly int $cacheTtl,
        private readonly int $minRefreshInterval,
        private readonly bool $commandsEnabled = false,
    ) {
    }

    /**
     * @param array{targetTemperature?: array{value: float, unit: string}, airConditioningWithoutExternalPower?: bool} $config
     */
    public function startAirConditioning(string $vin, array $config): array
    {
        return $this->command($vin, 'startAirConditioning', $config);
    }

    public function stopAirConditioning(string $vin): array
    {
        return $this->command($vin, 'stopAirConditioning');
    }

    /**
     * @param array{spin: string, targetTemperature?: array{value: float, unit: string}, durationInSeconds?: int, startMode?: string} $config
     */
    public function startAuxiliaryHeating(string $vin, array $config): array
    {
        return $this->command($vin, 'startAuxiliaryHeating', $config);
    }

    public function stopAuxiliaryHeating(string $vin): array
    {
        return $this->command($vin, 'stopAuxiliaryHeating');
    }

    public function startActiveVentilation(string $vin): array
    {
        return $this->command($vin, 'startActiveVentilation');
    }

    public function stopActiveVentilation(string $vin): array
    {
        return $this->command($vin, 'stopActiveVentilation');
    }

    public function startCharging(string $vin): array
    {
        return $this->command($vin, 'startCharging');
    }

    public function stopCharging(string $vin): array
    {
        return $this->command($vin, 'stopCharging');
    }

    /** Target state of charge in percent (vehicles usually accept 50–100 in steps of 10). */
    public function setChargingLimit(string $vin, int $targetStateOfChargeInPercent): array
    {
        return $this->command($vin, 'setChargingLimit', ['targetStateOfChargeInPercent' => $targetStateOfChargeInPercent]);
    }

    public function setChargeMode(string $vin, string $chargeMode): array
    {
        return $this->command($vin, 'setChargeMode', ['chargeMode' => $chargeMode]);
    }

    /**
     * The vehicle applies the profile as a whole – always send the complete profile.
     * The id in the body must match the id in the path (otherwise HTTP 412).
     */
    public function updateChargingProfile(string $vin, array $profile): array
    {
        if (!isset($profile['id'])) {
            throw new InvalidArgumentException('Das Ladeprofil benötigt eine id.');
        }
        return $this->command($vin, 'updateChargingProfile', $profile, ['id' => (string) $profile['id']]);
    }

    /**
     * Executes a remote command. It succeeded on HTTP 202 (request accepted – the vehicle
     * executes it asynchronously).
     *
     * @param array<string,string> $pathParams
     */
    public function command(string $vin, string $operation, ?array $body = null, array $pathParams = []): array
    {
        if (!$this->commandsEnabled) {
            throw new RuntimeException('Fernbefehle sind deaktiviert (config: commands_enabled).');
        }
        if (!isset(self::COMMANDS[$operation])) {
            throw new InvalidArgumentException('Unbekannter Befehl: ' . $operation);
        }
        [$method, $suffix, $needsBody] = self::COMMANDS[$operation];
        if ($needsBody && $body === null) {
            throw new InvalidArgumentException($operation . ' benötigt eine Konfiguration.');
        }
        foreach ($pathParams as $name => $value) {
            $suffix = str_replace('{' . $name . '}', rawurlencode($value), $suffix);
        }
        if (str_contains($suffix, '{')) {
            throw new InvalidArgumentException($operation . ': Pfadparameter fehlt.');
        }

        $result = $this->request($method, '/api/v1/vehicles/' . rawurlencode($vin) . $suffix, $needsBody ? $body : null);
        // After a command the next page view should load fresh data.
        @unlink($this->cacheFile($vin));

        return $result;
    }

    public function commandsEnabled(): bool
    {
        return $this->commandsEnabled;
    }

    /**
     * Returns the vehicle data – from the cache as long as it is fresh enough.
     * fromCache is false only if the data was just loaded from the API.
     *
     * @param list<string> $include
     * @return array{
     *     data: ?array, status: int, headers: array<string,string>, fetchedAt: int,
     *     fromCache: bool, problem: ?array, notice: ?string
     * }
     */
    public function getVehicle(string $vin, array $include, bool $forceRefresh = false): array
    {
        $cacheFile = $this->cacheFile($vin);
        $cached = $this->readCache($cacheFile);
        $age = $cached ? time() - $cached['fetchedAt'] : PHP_INT_MAX;

        if ($cached !== null) {
            if (!$forceRefresh && $age < $this->cacheTtl) {
                return $cached + ['fromCache' => true, 'notice' => null];
            }
            if ($forceRefresh && $age < $this->minRefreshInterval) {
                $wait = $this->minRefreshInterval - $age;
                return $cached + [
                    'fromCache' => true,
                    'notice' => "Daten sind erst {$age} s alt – erneutes Laden ist in {$wait} s möglich (Rate-Limit schonen).",
                ];
            }
            if ($this->retryBlockedUntil($cached) > time()) {
                $wait = $this->retryBlockedUntil($cached) - time();
                return $cached + [
                    'fromCache' => true,
                    'notice' => "Rate-Limit aktiv – nächster Abruf frühestens in {$wait} s.",
                ];
            }
        }

        $query = $include ? '?include=' . rawurlencode(implode(',', $include)) : '';
        $result = $this->request('GET', '/api/v1/vehicles/' . rawurlencode($vin) . $query);

        if ($result['status'] === 200) {
            $this->writeCache($cacheFile, $result);
            return $result + ['fromCache' => false, 'notice' => null];
        }

        // Error: keep showing the old data if available and pass the error along.
        if ($cached !== null) {
            if ($result['status'] === 429) {
                $cached['retryAfter'] = time() + (int) ($result['headers']['retry-after'] ?? 60);
                $this->writeCache($cacheFile, $cached);
            }
            return $cached + [
                'fromCache' => true,
                'notice' => 'Aktualisierung fehlgeschlagen (HTTP ' . $result['status'] . ') – es werden die zuletzt geladenen Daten angezeigt.',
                'problem' => $result['problem'],
            ];
        }

        return $result + ['fromCache' => false, 'notice' => null];
    }

    /**
     * @return array{data: ?array, status: int, headers: array<string,string>, fetchedAt: int, problem: ?array}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $requestHeaders = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];
        $options = [CURLOPT_HTTPGET => true];
        if ($method === 'POST' || $method === 'PUT') {
            $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body === null ? '' : json_encode($body)];
            if ($body !== null) {
                $requestHeaders[] = 'Content-Type: application/json';
            }
        } elseif ($method !== 'GET') {
            throw new InvalidArgumentException('Nicht unterstützte HTTP-Methode: ' . $method);
        }

        $headers = [];
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);

        if ($body === false) {
            return [
                'data' => null, 'status' => 0, 'headers' => [], 'fetchedAt' => time(),
                'problem' => ['title' => 'Verbindungsfehler', 'detail' => $curlError],
            ];
        }

        $json = json_decode($body, true);
        $isOk = $status >= 200 && $status < 300;

        return [
            'data' => $isOk && is_array($json) ? $json : null,
            'status' => $status,
            'headers' => $headers,
            'fetchedAt' => time(),
            'problem' => $isOk ? null : (is_array($json) ? $json : ['title' => 'HTTP ' . $status, 'detail' => substr($body, 0, 500)]),
        ];
    }

    private function cacheFile(string $vin): string
    {
        return $this->cacheDir . '/vehicle_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($vin)) . '.json';
    }

    private function retryBlockedUntil(array $cached): int
    {
        return (int) ($cached['retryAfter'] ?? 0);
    }

    private function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) && isset($data['fetchedAt']) ? $data : null;
    }

    private function writeCache(string $file, array $result): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0700, true);
        }
        unset($result['fromCache'], $result['notice']);
        file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
