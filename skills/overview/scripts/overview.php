<?php

declare(strict_types=1);

/**
 * GA4 саб-скилл «overview»: сводка по дням — sessions, totalUsers,
 * screenPageViews, engagedSessions, bounceRate, averageSessionDuration.
 *
 * Использование:
 *   php scripts/overview.php [--site <name>] [--days N | --date1 D --date2 D] [--limit N]
 *
 * Отчёты: CSV + Markdown в ga4_reports/YYYY-MM-DD/ (текущий каталог).
 * Секреты не логируются. Общий CLI-раннер — Ga4Client::runCli().
 */

require_once __DIR__ . '/../../../Ga4Client.php';

Ga4Client::runCli(
    $argv,
    [
        'title' => 'GA4: сводка по дням',
        'defaultLimit' => 90,
        'limitHelp' => 'Максимум строк (по умолчанию покрывает весь период, минимум 90)',
    ],
    static function (Ga4Client $client, array $ctx): array {
        [$date1, $date2] = [$ctx['date1'], $ctx['date2']];

        // Одна строка на день: лимит по умолчанию покрывает весь диапазон
        // (GA4 runReport допускает limit до 100000). Явный --limit меньше числа
        // дней — предупреждаем об усечении, молча не обрезаем.
        $rangeDays = (int)round((strtotime($date2) - strtotime($date1)) / 86400) + 1;
        $limit = $ctx['limit'] ?? max($ctx['defaultLimit'], $rangeDays);
        if ($limit < $rangeDays) {
            echo "  ⚠️  --limit $limit меньше числа дней в периоде ($rangeDays): отчёт будет усечён.\n\n";
        }

        $rows = $client->flatRows($client->runReport($ctx['propertyId'], [
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
        ]));

        return [['prefix' => 'overview', 'title' => $ctx['title'], 'rows' => $rows]];
    },
);
