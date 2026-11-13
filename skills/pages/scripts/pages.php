<?php

declare(strict_types=1);

/**
 * GA4 саб-скилл «pages»: топ-страницы — pagePath + unifiedScreenName
 * с метриками screenPageViews, totalUsers, sessions, userEngagementDuration,
 * bounceRate.
 *
 * Использование:
 *   php scripts/pages.php [--site <name>] [--days N | --date1 D --date2 D] [--limit N]
 *
 * Отчёты: CSV + Markdown в ga4_reports/YYYY-MM-DD/ (текущий каталог).
 * Секреты не логируются. Общий CLI-раннер — Ga4Client::runCli().
 */

require_once __DIR__ . '/../../../Ga4Client.php';

Ga4Client::runCli(
    $argv,
    [
        'title' => 'GA4: топ-страницы',
        'defaultLimit' => 50,
    ],
    static function (Ga4Client $client, array $ctx): array {
        $rows = $client->flatRows($client->runReport($ctx['propertyId'], [
            'dateRanges' => [['startDate' => $ctx['date1'], 'endDate' => $ctx['date2']]],
            'dimensions' => [['name' => 'pagePath'], ['name' => 'unifiedScreenName']],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'totalUsers'],
                ['name' => 'sessions'],
                ['name' => 'userEngagementDuration'],
                ['name' => 'bounceRate'],
            ],
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit' => $ctx['limit'] ?? $ctx['defaultLimit'],
        ]));

        return [['prefix' => 'pages', 'title' => $ctx['title'], 'rows' => $rows]];
    },
);
