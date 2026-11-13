---
name: google-analytics-core
description: "Google Analytics 4 Data API: скиллы отчётов для AI-агентов. Применяй, когда нужны данные GA4: трафик по дням, источники и каналы, топ-страницы, realtime-активность."
license: MIT
---

# Google Analytics 4 Core

Библиотека `Ga4Client.php` (Data API v1beta, service account, только чтение;
общий API-клиент и CLI-раннер `runCli` для саб-скиллов) и каталог саб-скиллов.
Конфиг общий: `ga4_config.json` (см. README.md).

## Доступные skills

### ga4-overview — `skills/overview/scripts/overview.php`
Сводка трафика по дням:
- sessions, totalUsers, screenPageViews
- engagedSessions, bounceRate, averageSessionDuration

### ga4-traffic — `skills/traffic/scripts/traffic.php`
Источники трафика:
- Группы каналов: sessionDefaultChannelGroup
- Детали: sessionSourceMedium (google / organic, yandex / organic…)
- Метрики качества: вовлечённость, bounceRate, длительность сессии

### ga4-pages — `skills/pages/scripts/pages.php`
Топ-страницы:
- pagePath + unifiedScreenName
- Просмотры, пользователи, суммарное время вовлечённости, bounceRate

### ga4-realtime — `skills/realtime/scripts/realtime.php`
Сейчас на сайте (последние ~30 минут):
- Активные пользователи и просмотры
- Страницы, страны и устройства realtime
- Поминутная динамика (minutesAgo)

## Общие параметры

```bash
php skills/<skill>/scripts/<skill>.php [--site <name>] [--days N | --date1 D --date2 D] [--limit N]
```

- `--site` — сайт из properties конфига; без него — `default_site`.
- `--days N` — последние N дней (по умолчанию 28); либо пара `--date1/--date2`.
- Отчёты: CSV + Markdown в `ga4_reports/YYYY-MM-DD/` текущего каталога;
  вывод — путь отчёта и число строк.

Подробности и трактовка метрик — в `skills/<skill>/SKILL.md`, справка API —
в `references/api.md`, шаблон аналитического отчёта — в `templates/report.md`.

> **Важно:** bounceRate GA4 = 1 − engagementRate. Это НЕ «отказы» Яндекс.Метрики:
> сессия считается вовлечённой при длительности ≥10 сек, конверсии или ≥2 просмотров.
> Прямое сравнение с Метрикой некорректно.
