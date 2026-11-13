<?php

declare(strict_types=1);

/**
 * GA4 саб-скилл «realtime»: сейчас на сайте — активные пользователи
 * (activeUsers) и просмотры (screenPageViews) в разрезах страниц
 * (unifiedScreenName), стран (country), устройств (deviceCategory)
 * и по минутам (minutesAgo).
 *
 * Использование:
 *   php scripts/realtime.php [--site <name>] [--limit N]
 *
 * Отчёты: CSV + Markdown в ga4_reports/YYYY-MM-DD/ (текущий каталог).
 * Секреты не логируются. Общий CLI-раннер — Ga4Client::runCli() (realtime-режим:
 * без dateRanges и периода, заголовок «Realtime-срез»).
 */

require_once __DIR__ . '/../../../Ga4Client.php';

Ga4Client::runCli(
    $argv,
    [
        'realtime' => true,
        'defaultLimit' => 50,
        'limitHelp' => 'Максимум строк на каждый срез (по умолчанию 50)',
        'helpNote' => 'Окно realtime — последние ~30 минут (ограничение GA4 Realtime API).',
    ],
    static function (Ga4Client $client, array $ctx): array {
        $limit = $ctx['limit'] ?? $ctx['defaultLimit'];

        $run = static fn (array $body): array => $client->flatRows($client->runRealtimeReport($ctx['propertyId'], $body));
        $users = [['name' => 'activeUsers']];
        $desc = static fn (): array => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]];

        // Страницы, где сейчас пользователи
        $pages = $run([
            'dimensions' => [['name' => 'unifiedScreenName']],
            'metrics' => [['name' => 'activeUsers'], ['name' => 'screenPageViews']],
            'orderBys' => $desc(),
            'limit' => $limit,
        ]);

        // География активного трафика (sessionSource в realtime-схеме v1beta нет —
        // проверено 13.11.2026: HTTP 400 "sessionSource is not a valid dimension")
        $countries = $run([
            'dimensions' => [['name' => 'country']],
            'metrics' => $users,
            'orderBys' => $desc(),
            'limit' => min($limit, 25),
        ]);

        // Устройства активного трафика
        $devices = $run([
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics' => $users,
            'orderBys' => $desc(),
            'limit' => 10,
        ]);

        // Поминутная динамика активных пользователей (minutesAgo=0 — текущая минута)
        $minutes = $run([
            'dimensions' => [['name' => 'minutesAgo']],
            'metrics' => $users,
            'orderBys' => [['dimension' => ['dimensionName' => 'minutesAgo'], 'desc' => false]],
            'limit' => 30,
        ]);

        // Итог «активные пользователи» — отдельный запрос БЕЗ dimensions:
        // сумма activeUsers по срезам завышает (один визит на N страницах = N).
        $totalUsers = (int)($run(['metrics' => $users])[0]['activeUsers'] ?? 0);

        return [
            ['prefix' => 'realtime_pages', 'title' => 'GA4 Realtime: страницы', 'rows' => $pages],
            ['prefix' => 'realtime_countries', 'title' => 'GA4 Realtime: страны', 'rows' => $countries],
            ['prefix' => 'realtime_devices', 'title' => 'GA4 Realtime: устройства', 'rows' => $devices],
            [
                'prefix' => 'realtime_minutes',
                'title' => 'GA4 Realtime: активные пользователи по минутам',
                'rows' => $minutes,
                'summary' => "Активные пользователи сейчас: $totalUsers",
            ],
        ];
    },
);
