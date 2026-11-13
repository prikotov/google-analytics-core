<?php

declare(strict_types=1);

/**
 * GA4 саб-скилл «traffic»: источники трафика — группы каналов
 * (sessionDefaultChannelGroup) и детали источник/канал (sessionSourceMedium)
 * с метриками качества: sessions, totalUsers, engagedSessions, bounceRate,
 * averageSessionDuration.
 *
 * Использование:
 *   php scripts/traffic.php [--site <name>] [--days N | --date1 D --date2 D] [--limit N]
 *
 * Отчёты: CSV + Markdown в ga4_reports/YYYY-MM-DD/ (текущий каталог).
 * Секреты не логируются. Общий CLI-раннер — Ga4Client::runCli().
 */

require_once __DIR__ . '/../../../Ga4Client.php';

Ga4Client::runCli(
    $argv,
    [
        'defaultLimit' => 50,
        'limitHelp' => 'Максимум строк на каждый отчёт (по умолчанию 50)',
    ],
    static function (Ga4Client $client, array $ctx): array {
        $dateRange = ['startDate' => $ctx['date1'], 'endDate' => $ctx['date2']];
        $limit = $ctx['limit'] ?? $ctx['defaultLimit'];
        $metrics = [
            ['name' => 'sessions'],
            ['name' => 'totalUsers'],
            ['name' => 'engagedSessions'],
            ['name' => 'bounceRate'],
            ['name' => 'averageSessionDuration'],
        ];

        // Отчёт по одному измерению с сортировкой по убыванию сессий;
        // каналы всегда целиком (системных групп ~8), источники — с --limit.
        $run = static fn (string $dimension, int $limit): array => $client->flatRows($client->runReport($ctx['propertyId'], [
            'dateRanges' => [$dateRange],
            'dimensions' => [['name' => $dimension]],
            'metrics' => $metrics,
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit' => $limit,
        ]));

        return [
            [
                'prefix' => 'traffic_channels',
                'title' => 'GA4: группы каналов',
                'rows' => $run('sessionDefaultChannelGroup', 30),
            ],
            [
                'prefix' => 'traffic_sources',
                'title' => 'GA4: источники (источник/канал)',
                'rows' => $run('sessionSourceMedium', $limit),
            ],
        ];
    },
);
