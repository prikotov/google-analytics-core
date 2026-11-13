<?php

declare(strict_types=1);

/**
 * GA4 саб-скилл «pages»: топ-страницы — pagePath + unifiedScreenName
 * с метриками screenPageViews, totalUsers, sessions, userEngagementDuration,
 * bounceRate.
 *
 * Использование:
 *   php ga4.php [--site <name>] [--days N | --date1 D --date2 D] [--limit N]
 *
 * Отчёты: CSV + Markdown в ga4_reports/YYYY-MM-DD/ (текущий каталог).
 * Секреты не логируются.
 */

require_once __DIR__ . '/../../Ga4Client.php';

try {
    $args = Ga4Client::parseCliArgs($argv);

    if ($args['help']) {
        echo "\n  Использование:\n";
        echo "    php ga4.php [--site <name>] [--days N | --date1 D --date2 D] [--limit N]\n\n";
        echo "  Опции:\n";
        echo "    --site <name>      Сайт из properties конфига (иначе default_site)\n";
        echo "    --days <n>         Последние N дней, включая сегодня (по умолчанию 28)\n";
        echo "    --date1/--date2    Явный период YYYY-MM-DD (оба или ни одного)\n";
        echo "    --limit <n>        Максимум строк (по умолчанию 50)\n";
        exit(0);
    }

    [$date1, $date2] = Ga4Client::dateRange($args['days'], $args['date1'], $args['date2']);

    Ga4Client::checkGitignore();
    $config = Ga4Client::loadConfig();

    $site = Ga4Client::resolveSiteName($config, $args['site']);
    $propertyId = Ga4Client::getPropertyId($config, $args['site']);
    $client = new Ga4Client($config['service_account_file']);

    echo "\n  Сайт: $site ($propertyId)\n";
    echo '  Период: ' . Ga4Client::periodLabel($date1, $date2) . "\n\n";

    $rows = $client->flatRows($client->runReport($propertyId, [
        'dateRanges' => [['startDate' => $date1, 'endDate' => $date2]],
        'dimensions' => [['name' => 'pagePath'], ['name' => 'unifiedScreenName']],
        'metrics' => [
            ['name' => 'screenPageViews'],
            ['name' => 'totalUsers'],
            ['name' => 'sessions'],
            ['name' => 'userEngagementDuration'],
            ['name' => 'bounceRate'],
        ],
        'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
        'limit' => $args['limit'] ?? 50,
    ]));

    $timestamp = Ga4Client::getFileTimestamp();
    $reportDir = Ga4Client::createReportDir();
    $subtitle = sprintf('Сайт: %s (%s), период: %s', $site, $propertyId, Ga4Client::periodLabel($date1, $date2));

    $csvFile = "$reportDir/pages_$timestamp.csv";
    $mdFile = "$reportDir/pages_$timestamp.md";
    Ga4Client::saveCsv($rows, $csvFile);
    Ga4Client::saveMarkdown($rows, $mdFile, 'GA4: топ-страницы', $subtitle);

    echo "  Отчёты:\n";
    echo "    - $csvFile\n";
    echo "    - $mdFile\n";
    echo "\n  Строк в отчёте: " . count($rows) . "\n";
} catch (Throwable $e) {
    echo "\n  Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
