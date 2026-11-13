<?php

declare(strict_types=1);

/**
 * GA4 саб-скилл «traffic»: источники трафика — группы каналов
 * (sessionDefaultChannelGroup) и детали источник/канал (sessionSourceMedium)
 * с метриками качества: sessions, totalUsers, engagedSessions, bounceRate,
 * averageSessionDuration.
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
        echo "    --limit <n>        Максимум строк на каждый отчёт (по умолчанию 50)\n";
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

    $limit = $args['limit'] ?? 50;
    $metrics = [
        ['name' => 'sessions'],
        ['name' => 'totalUsers'],
        ['name' => 'engagedSessions'],
        ['name' => 'bounceRate'],
        ['name' => 'averageSessionDuration'],
    ];

    // Группы каналов (system-defined channel groups: Organic Search, Direct, Referral…)
    $channels = $client->flatRows($client->runReport($propertyId, [
        'dateRanges' => [['startDate' => $date1, 'endDate' => $date2]],
        'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
        'metrics' => $metrics,
        'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
        'limit' => 30,
    ]));

    // Детали: источник/канал (google / organic, yandex / organic, (direct) / (none)…)
    $sources = $client->flatRows($client->runReport($propertyId, [
        'dateRanges' => [['startDate' => $date1, 'endDate' => $date2]],
        'dimensions' => [['name' => 'sessionSourceMedium']],
        'metrics' => $metrics,
        'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
        'limit' => $limit,
    ]));

    $timestamp = Ga4Client::getFileTimestamp();
    $reportDir = Ga4Client::createReportDir();
    $subtitle = sprintf('Сайт: %s (%s), период: %s', $site, $propertyId, Ga4Client::periodLabel($date1, $date2));

    $files = [];
    foreach (['channels' => $channels, 'sources' => $sources] as $name => $rows) {
        $csvFile = "$reportDir/traffic_{$name}_$timestamp.csv";
        $mdFile = "$reportDir/traffic_{$name}_$timestamp.md";
        Ga4Client::saveCsv($rows, $csvFile);
        Ga4Client::saveMarkdown(
            $rows,
            $mdFile,
            $name === 'channels' ? 'GA4: группы каналов' : 'GA4: источники (источник/канал)',
            $subtitle,
        );
        $files[$name] = [$csvFile, $mdFile];
    }

    echo "  Отчёты:\n";
    foreach ($files as $name => $paths) {
        echo "    $name:\n";
        foreach ($paths as $path) {
            echo "      - $path\n";
        }
        $rows = $name === 'channels' ? $channels : $sources;
        echo "      строк: " . count($rows) . "\n";
    }
} catch (Throwable $e) {
    echo "\n  Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
