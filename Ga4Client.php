<?php

declare(strict_types=1);

/**
 * Клиент Google Analytics 4 Data API v1beta (только чтение).
 *
 * Аутентификация: service account JSON → JWT RS256 (openssl_sign) →
 * access_token через oauth2.googleapis.com/token. Access-токен кэшируется
 * в ga4_token.json (текущий каталог или каталог скилла) с expires_at;
 * при истечении получается заново автоматически.
 *
 * Конфиг: ga4_config.json, поиск по порядку:
 *   1) env GA4_CONFIG_FILE;
 *   2) текущий каталог (getcwd);
 *   3) каталог скилла (__DIR__, общий для всех агентов этой установки);
 *   4) корень репозитория при раскладке TasK (dirname(__DIR__, 4) с .git).
 * Формат: {"properties": {"site": "propertyId"}, "service_account_file": "path",
 *          "default_site": "site"}. service_account_file — абсолютный или
 * относительно каталога конфига.
 *
 * Секреты (private_key, access_token, client_email) не логируются и не выводятся.
 *
 * Документация: https://developers.google.com/analytics/devguides/reporting/data/v1
 */
final class Ga4Client
{
    private const API_BASE = 'https://analyticsdata.googleapis.com/v1beta';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    /** Scope только на чтение: отчёты доступны, изменение свойств — нет. */
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
    private const CONFIG_FILE = 'ga4_config.json';
    private const TOKEN_FILE = 'ga4_token.json';
    private const GITIGNORE_ENTRIES = ['ga4_config.json', 'ga4_token.json', 'ga4_reports/'];

    /** JWT живёт 3600 секунд; обновляем с запасом. */
    private const TOKEN_LIFETIME_SECONDS = 3600;
    private const TOKEN_REFRESH_MARGIN_SECONDS = 120;

    /** Повторы при 429/5xx: до MAX_RETRIES повторов после первой попытки. */
    private const MAX_RETRIES = 3;
    private const MAX_BACKOFF_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const REQUEST_TIMEOUT_SECONDS = 60;

    private const DATE_FORMAT = 'Y-m-d';

    private array $serviceAccount;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    /**
     * @param string $serviceAccountFile путь к JSON-ключу сервисного аккаунта
     *                                   (абсолютный или относительно каталога конфига)
     */
    public function __construct(string $serviceAccountFile)
    {
        $this->serviceAccount = self::readServiceAccount($serviceAccountFile);
    }

    // --- Конфиг и установка -------------------------------------------------

    /**
     * Следит, чтобы секреты (конфиг, кэш токена) и отчёты не попали в git.
     * Защищает корень установки (каталог скилла standalone или корень репозитория
     * в раскладке TasK); текущий каталог — только если он вне корня установки.
     */
    public static function checkGitignore(): void
    {
        $skillDir = __DIR__;
        $cwd = getcwd();
        $outer = dirname(__DIR__, 4); // раскладка TasK: docs/agents/skills/google-analytics-core
        $outerIsRepo = is_dir($outer . '/.git');
        $skillInsideOuter = $outerIsRepo && str_starts_with($skillDir . '/', $outer . '/');

        // Если скилл внутри репозитория — достаточно его корневого .gitignore.
        $covered = $skillInsideOuter ? $outer : $skillDir;
        $dirs = [$covered];
        if ($cwd !== $covered && !str_starts_with($cwd . '/', $covered . '/')) {
            $dirs[] = $cwd;
        }
        foreach (array_unique($dirs) as $dir) {
            self::ensureGitignore($dir);
        }
    }

    /**
     * Каталоги поиска конфига: текущий каталог → каталог скилла (общий конфиг
     * для всех агентов, использующих эту установку) → корень репозитория
     * при раскладке TasK.
     *
     * @return list<string>
     */
    public static function configDirs(): array
    {
        $dirs = [getcwd(), __DIR__];
        $outer = dirname(__DIR__, 4);
        if (is_dir($outer . '/.git')) {
            $dirs[] = $outer;
        }
        return array_values(array_unique(array_filter($dirs, static fn (string $dir): bool => is_string($dir) && $dir !== '')));
    }

    private static function ensureGitignore(string $dir): void
    {
        $gitignoreFile = $dir . '/.gitignore';

        if (!file_exists($gitignoreFile)) {
            file_put_contents($gitignoreFile, implode("\n", self::GITIGNORE_ENTRIES) . "\n");
            echo "  ⚠️  Создан .gitignore с защитой секретных файлов: $gitignoreFile\n\n";
            return;
        }

        $lines = array_map('trim', file($gitignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        $missing = [];
        foreach (self::GITIGNORE_ENTRIES as $entry) {
            if (!in_array($entry, $lines, true)) {
                $missing[] = $entry;
            }
        }

        if (!empty($missing)) {
            $content = rtrim((string)file_get_contents($gitignoreFile)) . "\n";
            foreach ($missing as $entry) {
                $content .= $entry . "\n";
            }
            file_put_contents($gitignoreFile, $content);
            echo "  ⚠️  Добавлено в .gitignore ($gitignoreFile): " . implode(', ', $missing) . "\n\n";
        }
    }

    /**
     * Загружает конфиг: env GA4_CONFIG_FILE → ga4_config.json в текущем каталоге →
     * в каталоге скилла → в корне репозитория при раскладке TasK.
     * При отсутствии создаёт шаблон в текущем каталоге и выводит инструкцию
     * настройки service account, затем завершает работу (fail-fast, без запросов к API).
     *
     * @return array{properties: array<string, string>, default_site: ?string,
     *               service_account_file: string, config_dir: string}
     */
    public static function loadConfig(): array
    {
        $envFile = getenv('GA4_CONFIG_FILE');
        if (is_string($envFile) && $envFile !== '') {
            if (!file_exists($envFile)) {
                echo "\n  GA4_CONFIG_FILE указывает на несуществующий файл: $envFile\n";
                exit(1);
            }
            return self::readConfigFile($envFile);
        }

        $candidates = array_map(static fn (string $dir): string => $dir . '/' . self::CONFIG_FILE, self::configDirs());

        $configFile = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $configFile = $candidate;
                break;
            }
        }

        if ($configFile === null) {
            $configFile = $candidates[0];
            file_put_contents($configFile, self::configTemplate());

            echo "\n  Создан файл конфигурации: $configFile\n";
            echo "  Заполните в нём:\n";
            echo "    - properties: список сайтов с их propertyId ({\"сайт\": \"000000000\"});\n";
            echo "    - service_account_file: путь к JSON-ключу сервисного аккаунта\n";
            echo "      (абсолютный или относительно каталога конфига);\n";
            echo "    - default_site: имя сайта по умолчанию (для запуска без --site).\n\n";
            echo "  Как настроить доступ (service account):\n";
            echo "  1. Google Cloud Console → IAM и администрирование → Сервисные аккаунты:\n";
            echo "     создайте аккаунт и ключ (JSON), скачайте файл ключа.\n";
            echo "  2. Google Analytics 4 → Администратор → Управление доступом к свойству:\n";
            echo "     добавьте email сервисного аккаунта (поле client_email в ключе)\n";
            echo "     с ролью «Просмотр» (Viewer) — для каждого нужного свойства GA4.\n";
            echo "  Документация: https://support.google.com/analytics/answer/9375564\n\n";
            exit(1);
        }

        return self::readConfigFile($configFile);
    }

    private static function configTemplate(): string
    {
        return json_encode([
            'properties' => [
                'example.com' => '000000000',
            ],
            'service_account_file' => '/absolute/path/to/service-account-key.json',
            'default_site' => 'example.com',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Читает и валидирует конфиг-файл. Fail-fast с понятной инструкцией.
     *
     * @return array{properties: array<string, string>, default_site: ?string,
     *               service_account_file: string, config_dir: string}
     */
    private static function readConfigFile(string $configFile): array
    {
        $config = json_decode((string)file_get_contents($configFile), true);

        if (!is_array($config)) {
            echo "\n  Не удалось прочитать конфигурацию: $configFile\n";
            echo "  Ожидается корректный JSON: properties (карта сайт → propertyId),\n";
            echo "  service_account_file (путь к JSON-ключу SA), default_site (опционально).\n";
            exit(1);
        }

        $propertiesRaw = $config['properties'] ?? null;
        if (!is_array($propertiesRaw) || $propertiesRaw === []) {
            echo "\n  В конфигурации $configFile нет properties (карта \"сайт\" → propertyId).\n";
            exit(1);
        }

        $properties = [];
        foreach ($propertiesRaw as $site => $propertyId) {
            if (!is_string($site) || $site === '' || self::isPlaceholder($propertyId)) {
                continue;
            }
            $properties[$site] = trim((string)$propertyId);
        }
        if ($properties === []) {
            echo "\n  Заполните properties в $configFile: {\"имя-сайта\": \"propertyId\"}.\n";
            exit(1);
        }

        $saFile = trim((string)($config['service_account_file'] ?? ''));
        if ($saFile === '' || self::isPlaceholder($saFile)) {
            echo "\n  Укажите service_account_file в $configFile — путь к JSON-ключу сервисного аккаунта.\n";
            exit(1);
        }
        if (str_starts_with($saFile, '~/')) {
            $home = getenv('HOME');
            $saFile = (is_string($home) && $home !== '' ? $home : '') . substr($saFile, 1);
        }
        if (!str_starts_with($saFile, '/')) {
            $saFile = dirname($configFile) . '/' . $saFile;
        }
        if (!file_exists($saFile)) {
            echo "\n  Файл ключа сервисного аккаунта не найден: $saFile\n";
            echo "  Исправьте service_account_file в $configFile.\n";
            exit(1);
        }

        $defaultSite = $config['default_site'] ?? null;
        if (is_string($defaultSite) && $defaultSite !== '' && !isset($properties[$defaultSite])) {
            echo "\n  default_site \"$defaultSite\" отсутствует в properties ($configFile).\n";
            echo "  Доступные сайты: " . implode(', ', array_keys($properties)) . "\n";
            exit(1);
        }

        return [
            'properties' => $properties,
            'default_site' => is_string($defaultSite) && $defaultSite !== '' ? $defaultSite : null,
            'service_account_file' => $saFile,
            'config_dir' => dirname($configFile),
        ];
    }

    /**
     * PropertyId для запроса: по имени сайта, иначе default_site,
     * иначе единственный сайт в properties. Ошибки — с перечислением доступных сайтов.
     *
     * @param array{properties: array<string, string>, default_site: ?string} $config
     */
    public static function getPropertyId(array $config, ?string $site): string
    {
        $properties = $config['properties'];

        if ($site !== null) {
            if (!isset($properties[$site])) {
                echo "\n  Сайт \"$site\" не найден в конфиге. Доступные: " . implode(', ', array_keys($properties)) . "\n";
                exit(1);
            }
            return $properties[$site];
        }

        $default = $config['default_site'] ?? null;
        if ($default !== null && isset($properties[$default])) {
            return $properties[$default];
        }

        if (count($properties) === 1) {
            return (string)reset($properties);
        }

        echo "\n  Укажите сайт: --site <имя> (или default_site в конфиге).\n";
        echo "  Доступные: " . implode(', ', array_keys($properties)) . "\n";
        exit(1);
    }

    /**
     * Имя сайта для подписи отчётов: переданное --site или default_site/единственный.
     *
     * @param array{properties: array<string, string>, default_site: ?string} $config
     */
    public static function resolveSiteName(array $config, ?string $site): string
    {
        if ($site !== null) {
            return $site;
        }
        return $config['default_site'] ?? array_key_first($config['properties']);
    }

    /**
     * Период отчёта: --days N (последние N дней, включая сегодня) либо
     * пара --date1/--date2 (оба или ни одного). Даты YYYY-MM-DD.
     *
     * @return array{0: string, 1: string} [startDate, endDate]
     */
    public static function dateRange(?int $days, ?string $date1, ?string $date2): array
    {
        $explicit = $date1 !== null || $date2 !== null;

        if ($explicit && ($date1 === null || $date2 === null)) {
            throw new Exception('--date1 и --date2 указываются парой (или используйте --days)');
        }

        if ($date1 !== null && $date2 !== null) {
            self::assertDate($date1, '--date1');
            self::assertDate($date2, '--date2');
            if ($date1 > $date2) {
                throw new Exception('--date1 больше --date2');
            }
            return [$date1, $date2];
        }

        $days ??= 28;
        if ($days < 1) {
            throw new Exception('--days должен быть >= 1');
        }
        $today = new DateTimeImmutable('today');
        $start = $today->modify('-' . ($days - 1) . ' days');

        return [$start->format(self::DATE_FORMAT), $today->format(self::DATE_FORMAT)];
    }

    private static function assertDate(string $date, string $argName): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $date);
        if ($parsed === false || $parsed->format(self::DATE_FORMAT) !== $date) {
            throw new Exception("$argName ожидается в формате YYYY-MM-DD, получено: $date");
        }
    }

    /**
     * Разбор общих аргументов CLI саб-скиллов: --site, --days, --date1, --date2,
     * --limit, --help/-h.
     *
     * @return array{site: ?string, days: ?int, date1: ?string, date2: ?string, limit: ?int, help: bool}
     */
    public static function parseCliArgs(array $argv): array
    {
        $args = ['site' => null, 'days' => null, 'date1' => null, 'date2' => null, 'limit' => null, 'help' => false];

        $i = 1;
        while ($i < count($argv)) {
            $arg = $argv[$i];

            if (in_array($arg, ['--site', '-s'], true) && isset($argv[$i + 1])) {
                $args['site'] = $argv[++$i];
            } elseif ($arg === '--days' && isset($argv[$i + 1])) {
                $args['days'] = (int)$argv[++$i];
            } elseif ($arg === '--date1' && isset($argv[$i + 1])) {
                $args['date1'] = $argv[++$i];
            } elseif ($arg === '--date2' && isset($argv[$i + 1])) {
                $args['date2'] = $argv[++$i];
            } elseif ($arg === '--limit' && isset($argv[$i + 1])) {
                $args['limit'] = (int)$argv[++$i];
            } elseif (in_array($arg, ['--help', '-h'], true)) {
                $args['help'] = true;
            } else {
                throw new Exception("Неизвестный аргумент: $arg (см. --help)");
            }
            $i++;
        }

        return $args;
    }

    /**
     * Строка-подпись периода для консоли и отчётов.
     */
    public static function periodLabel(string $date1, string $date2): string
    {
        return $date1 === $date2 ? $date1 : "$date1 … $date2";
    }

    // --- API ----------------------------------------------------------------

    /**
     * propertyId интерполируется в URL-путь — допускаем только цифры
     * (GA4 propertyId — число вида 293984563). Иначе InvalidArgumentException:
     * кривое значение из конфига не должно превращаться в подмену пути.
     */
    private static function assertPropertyId(string $propertyId): string
    {
        $id = trim($propertyId);
        if (preg_match('/^\d+$/', $id) !== 1) {
            throw new InvalidArgumentException("propertyId должен быть числом (например 293984563), получено: \"$propertyId\"");
        }

        return $id;
    }

    /**
     * Отчёт Data API: POST /v1beta/properties/{propertyId}:runReport.
     * Тело: dateRanges, dimensions, metrics, orderBys, limit…
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function runReport(string $propertyId, array $body): array
    {
        return $this->request('/properties/' . self::assertPropertyId($propertyId) . ':runReport', $body);
    }

    /**
     * Realtime-отчёт: POST /v1beta/properties/{propertyId}:runRealtimeReport.
     * Без dateRanges; ограничения: minutesAgo ≤ 30, свой набор dimensions/metrics.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function runRealtimeReport(string $propertyId, array $body): array
    {
        return $this->request('/properties/' . self::assertPropertyId($propertyId) . ':runRealtimeReport', $body);
    }

    /**
     * Каталог доступных dimensions/metrics свойства:
     * GET /v1beta/properties/{propertyId}/metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(string $propertyId): array
    {
        return $this->request('/properties/' . self::assertPropertyId($propertyId) . '/metadata', null);
    }

    /**
     * Плоские строки отчёта: имена dimensionHeaders/metricHeaders → ключи,
     * значения dimensionValues (строки) и metricValues (числа).
     * Даты dimension «date» (YYYYMMDD) приводятся к YYYY-MM-DD.
     *
     * @param array<string, mixed> $report
     * @return list<array<string, string|int|float>>
     */
    public function flatRows(array $report): array
    {
        $dimensionNames = [];
        foreach ($report['dimensionHeaders'] ?? [] as $i => $header) {
            $dimensionNames[$i] = is_array($header) && isset($header['name']) && is_string($header['name'])
                ? $header['name']
                : "dim{$i}";
        }
        $metricNames = [];
        foreach ($report['metricHeaders'] ?? [] as $i => $header) {
            $metricNames[$i] = is_array($header) && isset($header['name']) && is_string($header['name'])
                ? $header['name']
                : "metric{$i}";
        }

        $rows = [];
        foreach ($report['rows'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $flat = [];
            foreach ($row['dimensionValues'] ?? [] as $i => $value) {
                $name = $dimensionNames[$i] ?? "dim{$i}";
                $raw = is_array($value) ? (string)($value['value'] ?? '') : '';
                $flat[$name] = ($name === 'date' && preg_match('/^\d{8}$/', $raw) === 1)
                    ? substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)
                    : $raw;
            }
            foreach ($row['metricValues'] ?? [] as $i => $value) {
                $name = $metricNames[$i] ?? "metric{$i}";
                $raw = is_array($value) ? (string)($value['value'] ?? '') : '';
                $flat[$name] = is_numeric($raw) ? $raw + 0 : $raw;
            }
            $rows[] = $flat;
        }

        return $rows;
    }

    /**
     * Универсальный запрос к Data API. При сетевых ошибках, HTTP 429 и 5xx —
     * exponential backoff с учётом Retry-After (но не дольше MAX_BACKOFF_SECONDS),
     * до MAX_RETRIES повторов. HTTP 401 — кэш токена сбрасывается и запрос
     * повторяется один раз со свежим токеном (без зацикливания). Токен не логируется.
     *
     * @param array<string, mixed>|null $body null → GET, массив → POST JSON
     * @return array<string, mixed>
     */
    private function request(string $path, ?array $body): array
    {
        $attempt = 0;
        $response = false;
        $httpCode = 0;
        $tokenRetried = false;

        while (true) {
            $ch = curl_init(self::API_BASE . $path);
            if ($ch === false) {
                throw new RuntimeException('Не удалось инициализировать cURL');
            }

            /** @var array<string, string> $responseHeaders */
            $responseHeaders = [];
            $headers = [
                'Authorization: Bearer ' . $this->ensureToken(),
                'Accept: application/json',
            ];
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ];
            if ($body !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            }
            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);

            if ($response === false) {
                $errorCode = curl_errno($ch);
                $errorMessage = curl_error($ch);
                curl_close($ch);
                if ($attempt >= self::MAX_RETRIES) {
                    throw new RuntimeException(sprintf(
                        'Сетевая ошибка при обращении к %s (проверьте доступность сети), после %d повторов: [%d] %s',
                        self::API_BASE,
                        self::MAX_RETRIES,
                        $errorCode,
                        $errorMessage,
                    ));
                }
                self::sleepBackoff($attempt);
                $attempt++;
                continue;
            }

            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode === 401 && !$tokenRetried) {
                // Токен отклонён сервером (ротация ключа, revoke, чужой кэш):
                // сбрасываем память и файл кэша, один раз повторяем со свежим токеном.
                curl_close($ch);
                $tokenRetried = true;
                $this->invalidateToken();
                continue;
            }

            if ($httpCode === 429 || $httpCode >= 500) {
                $retryAfter = $responseHeaders['retry-after'] ?? null;
                curl_close($ch);
                if ($attempt >= self::MAX_RETRIES) {
                    throw new RuntimeException(sprintf(
                        'GA4 API недоступен [HTTP %d] после %d повторов: %s',
                        $httpCode,
                        self::MAX_RETRIES,
                        self::textSnippet((string)$response),
                    ));
                }
                sleep(self::retryDelaySeconds($attempt, $retryAfter));
                $attempt++;
                continue;
            }

            curl_close($ch);
            break;
        }

        $data = json_decode((string)$response, true);

        if (!is_array($data)) {
            throw new RuntimeException('Некорректный JSON в ответе GA4 API: ' . self::textSnippet((string)$response));
        }

        if ($httpCode !== 200) {
            throw new Ga4ApiException($httpCode, self::describeHttpError($httpCode, $data, (string)$response));
        }

        return $data;
    }

    // --- Токен ---------------------------------------------------------------

    /**
     * Действующий access-токен: из кэша (если жив) или свежий по JWT.
     */
    private function ensureToken(): string
    {
        if ($this->accessToken !== null && $this->tokenExpiresAt - self::TOKEN_REFRESH_MARGIN_SECONDS > time()) {
            return $this->accessToken;
        }

        $cached = $this->readTokenCache();
        if ($cached !== null) {
            $this->accessToken = $cached['access_token'];
            $this->tokenExpiresAt = $cached['expires_at'];
            return $this->accessToken;
        }

        $token = $this->obtainToken();
        $this->accessToken = $token['access_token'];
        $this->tokenExpiresAt = $token['expires_at'];
        $this->writeTokenCache($token);

        return $this->accessToken;
    }

    /**
     * Кэш токена: ga4_token.json в текущем каталоге, иначе в каталоге скилла.
     * Оба каталога защищены checkGitignore().
     */
    private function tokenCachePath(): string
    {
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '' && is_writable($cwd)) {
            return $cwd . '/' . self::TOKEN_FILE;
        }
        return __DIR__ . '/' . self::TOKEN_FILE;
    }

    /**
     * @return array{access_token: string, expires_at: int}|null
     */
    private function readTokenCache(): ?array
    {
        $file = $this->tokenCachePath();
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($file), true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        $expiresAt = is_array($data) ? ($data['expires_at'] ?? null) : null;

        if (!is_string($token) || $token === '' || !is_int($expiresAt)) {
            return null;
        }
        if ($expiresAt - self::TOKEN_REFRESH_MARGIN_SECONDS <= time()) {
            return null;
        }

        return ['access_token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Сброс токена в памяти и файла кэша: после отклонения токена сервером
     * (HTTP 401) следующий ensureToken() получит свежий токен по JWT.
     */
    private function invalidateToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = 0;

        $file = $this->tokenCachePath();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Атомарная запись кэша токена: tempnam (файл сразу с правами 0600) →
     * явный chmod → rename поверх старого файла. Права 0600 выставляются ДО
     * того, как в файл попадёт секрет; rename не оставляет частично записанный
     * файл. Кэш — best effort: неудача не ломает запуск (токен живёт в памяти).
     *
     * @param array{access_token: string, expires_at: int} $token
     */
    private function writeTokenCache(array $token): void
    {
        $file = $this->tokenCachePath();
        $tmp = tempnam(dirname($file), '.ga4_token_');
        if ($tmp === false) {
            return;
        }

        if (!chmod($tmp, 0600)
            || file_put_contents($tmp, json_encode($token) . "\n") === false
            || !rename($tmp, $file)
        ) {
            @unlink($tmp);
        }
    }

    /**
     * JWT RS256 → POST oauth2.googleapis.com/token → access_token + expires_at.
     *
     * @return array{access_token: string, expires_at: int}
     */
    private function obtainToken(): array
    {
        $now = time();
        $expiresIn = self::TOKEN_LIFETIME_SECONDS;
        $audience = $this->serviceAccount['token_uri'] ?? self::TOKEN_URI;

        $header = self::base64UrlEncode((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $claims = self::base64UrlEncode((string)json_encode([
            'iss' => $this->serviceAccount['client_email'],
            'scope' => self::SCOPE,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + $expiresIn,
        ], JSON_UNESCAPED_SLASHES));
        $signingInput = $header . '.' . $claims;

        $key = openssl_pkey_get_private($this->serviceAccount['private_key']);
        if ($key === false) {
            throw new RuntimeException('Не удалось загрузить private_key сервисного аккаунта (проверьте JSON-ключ)');
        }
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Не удалось подписать JWT сервисного аккаунта');
        }

        [$code, $resp] = self::httpPostForm($audience, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $signingInput . '.' . self::base64UrlEncode($signature),
        ]);

        if ($code !== 200 || !isset($resp['access_token']) || !is_string($resp['access_token']) || $resp['access_token'] === '') {
            $reason = isset($resp['error_description']) && is_string($resp['error_description'])
                ? $resp['error_description']
                : ('HTTP ' . $code);
            throw new RuntimeException('OAuth-сервер не вернул access_token (' . $reason . '); проверьте JSON-ключ сервисного аккаунта');
        }

        $lifetime = is_numeric($resp['expires_in'] ?? null) ? max((int)$resp['expires_in'], 60) : $expiresIn;

        return ['access_token' => $resp['access_token'], 'expires_at' => $now + $lifetime];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>} [HTTP-код, декодированный ответ]
     */
    private static function httpPostForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("HTTP POST к OAuth-серверу не удался: $url");
        }

        return [$code, json_decode((string)$body, true) ?: []];
    }

    /**
     * Читает JSON-ключ сервисного аккаунта и проверяет обязательные поля.
     *
     * @return array{client_email: string, private_key: string, token_uri: ?string}
     */
    private static function readServiceAccount(string $file): array
    {
        $data = json_decode((string)file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException("Файл ключа сервисного аккаунта не читается как JSON: ожидается JSON-ключ Google (type: service_account)");
        }

        $email = $data['client_email'] ?? null;
        $privateKey = $data['private_key'] ?? null;

        if (!is_string($email) || $email === '' || !is_string($privateKey) || !str_contains($privateKey, 'PRIVATE KEY')) {
            throw new RuntimeException("В ключе сервисного аккаунта ($file) нет client_email/private_key — это не JSON-ключ Google service account");
        }

        $tokenUri = $data['token_uri'] ?? null;

        return [
            'client_email' => $email,
            'private_key' => $privateKey,
            'token_uri' => is_string($tokenUri) && $tokenUri !== '' ? $tokenUri : null,
        ];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // --- Экспорт -------------------------------------------------------------

    /**
     * @param list<array<string, string|int|float>> $rows
     */
    public static function saveCsv(array $rows, string $filename): void
    {
        $fp = fopen($filename, 'w');
        fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM для Excel

        if (!empty($rows)) {
            fputcsv($fp, array_keys($rows[0]), ';', '"', '"');
            foreach ($rows as $row) {
                fputcsv($fp, $row, ';', '"', '"');
            }
        }

        fclose($fp);
    }

    /**
     * Markdown-отчёт: заголовок + подпись (сайт/период) + таблица строк.
     *
     * @param list<array<string, string|int|float>> $rows
     */
    public static function saveMarkdown(array $rows, string $filename, string $title, string $subtitle): void
    {
        $md = "# $title\n\n";
        $md .= "$subtitle\n\n";
        $md .= '_Сформировано: ' . date('Y-m-d H:i') . ', источник: GA4 Data API v1beta (google-analytics-core)_' . "\n\n";

        if (!empty($rows)) {
            $headers = array_keys($rows[0]);
            $md .= '| ' . implode(' | ', $headers) . " |\n";
            $md .= '| ' . implode(' | ', array_fill(0, count($headers), '---')) . " |\n";

            foreach ($rows as $row) {
                $md .= '| ' . implode(' | ', array_map(
                    static fn (string|int|float $v): string => self::escapeMarkdownCell((string)$v),
                    array_values($row),
                )) . " |\n";
            }
        } else {
            $md .= "_Нет данных за период_\n";
        }

        file_put_contents($filename, $md);
    }

    /**
     * Значения GA4 (например названия страниц) содержат «|» и переводы строк —
     * в Markdown-таблице они ломают колонки. Экранируем pipe, переводы строк
     * заменяем пробелом.
     */
    private static function escapeMarkdownCell(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
    }

    /**
     * Каталог отчётов: <cwd>/ga4_reports/YYYY-MM-DD/ (защищён .gitignore).
     */
    public static function createReportDir(): string
    {
        $reportDir = getcwd() . '/ga4_reports';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }

        $dateDir = $reportDir . '/' . date('Y-m-d');
        if (!is_dir($dateDir)) {
            mkdir($dateDir, 0755);
        }

        return $dateDir;
    }

    public static function getFileTimestamp(): string
    {
        return date('Y-m-d_H-i-s');
    }

    // --- Ошибки и паузы -------------------------------------------------------

    /**
     * Пауза перед повтором: exponential backoff (1с, 2с, 4с…).
     */
    private static function sleepBackoff(int $attempt): void
    {
        sleep(min(2 ** $attempt, self::MAX_BACKOFF_SECONDS));
    }

    /**
     * Пауза перед повтором для 429/5xx: exponential backoff, при наличии
     * Retry-After — большее из двух значений, но не дольше MAX_BACKOFF_SECONDS.
     */
    private static function retryDelaySeconds(int $attempt, ?string $retryAfter): int
    {
        $delay = min(2 ** $attempt, self::MAX_BACKOFF_SECONDS);

        if ($retryAfter !== null) {
            if (ctype_digit($retryAfter)) {
                $delay = max($delay, min((int)$retryAfter, self::MAX_BACKOFF_SECONDS));
            } else {
                $timestamp = strtotime($retryAfter);
                if ($timestamp !== false) {
                    $delay = max($delay, min(max($timestamp - time(), 0), self::MAX_BACKOFF_SECONDS));
                }
            }
        }

        return $delay;
    }

    /**
     * Человекочитаемое описание ошибки GA4 API с подсказкой по исправлению.
     * Ответ API может содержать message со статусом gRPC — текст секретов не содержит.
     */
    private static function describeHttpError(int $httpCode, array $data, string $rawResponse): string
    {
        $apiMessage = isset($data['error']['message']) && is_string($data['error']['message']) && $data['error']['message'] !== ''
            ? $data['error']['message']
            : self::textSnippet($rawResponse);

        switch ($httpCode) {
            case 400:
                return "Некорректный запрос [HTTP 400]. Проверьте имена dimensions/metrics (getMetadata) и формат тела. Ответ API: $apiMessage";
            case 401:
                return "Токен доступа отклонён [HTTP 401], в том числе после сброса кэша ga4_token.json и повтора со свежим токеном. Проверьте срок действия JSON-ключа сервисного аккаунта и его доступ к свойству.";
            case 403:
                return "Доступ запрещён [HTTP 403]. Добавьте сервисному аккаунту (email из ключа, поле client_email) роль «Просмотр»/Viewer в GA4: Администратор → свойство → Управление доступом. Ответ API: $apiMessage";
            case 429:
                return "Квота GA4 API исчерпана [HTTP 429]. Уменьшите число запросов или период; квоты восстанавливаются в течение часа. Ответ API: $apiMessage";
            default:
                return "Ошибка GA4 API [HTTP $httpCode]: $apiMessage";
        }
    }

    private static function textSnippet(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text;
    }

    private static function isPlaceholder(mixed $value): bool
    {
        if (!is_string($value)) {
            return true;
        }
        $trimmed = trim($value);

        return $trimmed === '' || str_starts_with($trimmed, 'ВАШ_') || str_starts_with($trimmed, 'YOUR_');
    }
}

/**
 * Ошибка GA4 Data API с HTTP-кодом (после исчерпания повторов).
 */
final class Ga4ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
