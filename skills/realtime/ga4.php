<?php

declare(strict_types=1);

/**
 * GA4 саб-скилл «realtime»: сейчас на сайте — активные пользователи
 * (activeUsers) и просмотры (screenPageViews) в разрезах страниц
 * (unifiedScreenName), стран (country), устройств (deviceCategory)
 * и по минутам (minutesAgo).
 *
 * Использование:
 *   php ga4.php [--site <name>] [--limit N]
 *
 * Отчёты: CSV + Markdown в ga4_reports/YYYY-MM-DD/ (текущий каталог).
 * Realtime API без dateRanges: окно ~последние 30 минут.
 * Итог «активные пользователи» — отдельным запросом БЕЗ dimensions:
 * сумма activeUsers по срезам завышает (один визит на N страницах = N).
 * Секреты не логируются.
 */

require_once __DIR__ . '/../../Ga4Client.php';

try {
    $args = Ga4Client::parseCliArgs($argv);

    if ($args['help']) {
        echo "\n  Использование:\n";
        echo "    php ga4.php [--site <name>] [--limit N]\n\n";
        echo "  Опции:\n";
        echo "    --site <name>   Сайт из properties конфига (иначе default_site)\n";
        echo "    --limit <n>     Максимум строк на каждый срез (по умолчанию 50)\n";
        echo "\n  Окно realtime — последние ~30 минут (ограничение GA4 Realtime API).\n";
        exit(0);
    }

    Ga4Client::checkGitignore();
    $config = Ga4Client::loadConfig();

    $site = Ga4Client::resolveSiteName($config, $args['site']);
    $propertyId = Ga4Client::getPropertyId($config, $args['site']);
    $client = new Ga4Client($config['service_account_file']);
    $limit = $args['limit'] ?? 50;

    echo "\n  Сайт: $site ($propertyId)\n";
    echo '  Realtime-срез: ' . date('Y-m-d H:i:s') . "\n\n";

    // Страницы, где сейчас пользователи
    $pages = $client->flatRows($client->runRealtimeReport($propertyId, [
        'dimensions' => [['name' => 'unifiedScreenName']],
        'metrics' => [['name' => 'activeUsers'], ['name' => 'screenPageViews']],
        'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
        'limit' => $limit,
    ]));

    // География активного трафика (sessionSource в realtime-схеме v1beta нет —
    // проверено 13.11.2026: HTTP 400 "sessionSource is not a valid dimension")
    $countries = $client->flatRows($client->runRealtimeReport($propertyId, [
        'dimensions' => [['name' => 'country']],
        'metrics' => [['name' => 'activeUsers']],
        'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
        'limit' => min($limit, 25),
    ]));

    // Устройства активного трафика
    $devices = $client->flatRows($client->runRealtimeReport($propertyId, [
        'dimensions' => [['name' => 'deviceCategory']],
        'metrics' => [['name' => 'activeUsers']],
        'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
        'limit' => 10,
    ]));

    // Поминутная динамика активных пользователей (minutesAgo=0 — текущая минута)
    $minutes = $client->flatRows($client->runRealtimeReport($propertyId, [
        'dimensions' => [['name' => 'minutesAgo']],
        'metrics' => [['name' => 'activeUsers']],
        'orderBys' => [['dimension' => ['dimensionName' => 'minutesAgo'], 'desc' => false]],
        'limit' => 30,
    ]));

    // Итог «активные пользователи» — отдельный запрос БЕЗ dimensions:
    // сумма activeUsers по срезам завышает (один визит на N страницах = N).
    $totalRows = $client->flatRows($client->runRealtimeReport($propertyId, [
        'metrics' => [['name' => 'activeUsers']],
    ]));
    $totalUsers = (int)($totalRows[0]['activeUsers'] ?? 0);

    $timestamp = Ga4Client::getFileTimestamp();
    $reportDir = Ga4Client::createReportDir();
    $subtitle = sprintf('Сайт: %s (%s), realtime на %s', $site, $propertyId, date('Y-m-d H:i:s'));

    $reports = [
        'pages' => [$pages, 'GA4 Realtime: страницы'],
        'countries' => [$countries, 'GA4 Realtime: страны'],
        'devices' => [$devices, 'GA4 Realtime: устройства'],
        'minutes' => [$minutes, 'GA4 Realtime: активные пользователи по минутам'],
    ];

    echo "  Отчёты:\n";
    foreach ($reports as $name => [$rows, $title]) {
        $csvFile = "$reportDir/realtime_{$name}_$timestamp.csv";
        $mdFile = "$reportDir/realtime_{$name}_$timestamp.md";
        Ga4Client::saveCsv($rows, $csvFile);
        Ga4Client::saveMarkdown($rows, $mdFile, $title, $subtitle);

        echo "    $name:\n";
        echo "      - $csvFile\n";
        echo "      - $mdFile\n";
        echo '      строк: ' . count($rows) . "\n";
    }

    echo "\n  Активные пользователи сейчас: $totalUsers\n";
} catch (Throwable $e) {
    echo "\n  Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
