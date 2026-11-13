<?php

declare(strict_types=1);

/**
 * GA4 саб-скилл «overview»: сводка по дням — sessions, totalUsers,
 * screenPageViews, engagedSessions, bounceRate, averageSessionDuration.
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
        echo "    --limit <n>        Максимум строк (по умолчанию покрывает весь период, минимум 90)\n";
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

    // Одна строка на день: лимит по умолчанию покрывает весь диапазон
    // (GA4 runReport допускает limit до 100000). Явный --limit меньше числа
    // дней — предупреждаем об усечении, молча не обрезаем.
    $rangeDays = (int)round((strtotime($date2) - strtotime($date1)) / 86400) + 1;
    $limit = $args['limit'] ?? max(90, $rangeDays);
    if ($limit < $rangeDays) {
        echo "  ⚠️  --limit $limit меньше числа дней в периоде ($rangeDays): отчёт будет усечён.\n\n";
    }

    $report = $client->runReport($propertyId, [
        'dateRanges' => [['startDate' => $date1, 'endDate' => $date2]],
        'dimensions' => [['name' => 'date']],
        'metrics' => [
            ['name' => 'sessions'],
            ['name' => 'totalUsers'],
            ['name' => 'screenPageViews'],
            ['name' => 'engagedSessions'],
            ['name' => 'bounceRate'],
            ['name' => 'averageSessionDuration'],
        ],
        'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
        'limit' => $limit,
    ]);

    $rows = $client->flatRows($report);
    $timestamp = Ga4Client::getFileTimestamp();
    $reportDir = Ga4Client::createReportDir();

    $csvFile = "$reportDir/overview_$timestamp.csv";
    $mdFile = "$reportDir/overview_$timestamp.md";
    $subtitle = sprintf('Сайт: %s (%s), период: %s', $site, $propertyId, Ga4Client::periodLabel($date1, $date2));
    Ga4Client::saveCsv($rows, $csvFile);
    Ga4Client::saveMarkdown($rows, $mdFile, 'GA4: сводка по дням', $subtitle);

    echo "  Отчёты:\n";
    echo "    - $csvFile\n";
    echo "    - $mdFile\n";
    echo "\n  Строк в отчёте: " . count($rows) . "\n";
} catch (Throwable $e) {
    echo "\n  Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
