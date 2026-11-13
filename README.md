# Google Analytics 4 Core

> Библиотека и каталог skills для работы с Google Analytics 4 (Data API v1beta)

Этот пакет содержит `Ga4Client` — общий API-клиент и CLI-раннер для всех саб-скиллов GA4:
- Аутентификация service account (JWT RS256 → access_token, кэш токена)
- Запросы runReport / runRealtimeReport / getMetadata
- CLI-раннер `runCli()`: парсинг аргументов, конфиг, сохранение и вывод отчётов
- Экспорт отчётов в CSV/Markdown

PHP 8.4+, без composer-зависимостей (нужны расширения `curl` и `openssl`).

## Skills на основе этого пакета

Все саб-скиллы живут внутри репо в `skills/`:

| Skill | Содержание |
|-------|------------|
| [skills/overview](skills/overview/SKILL.md) | Сводка по дням: sessions, totalUsers, screenPageViews, engagedSessions, bounceRate, averageSessionDuration |
| [skills/traffic](skills/traffic/SKILL.md) | Источники: группы каналов + источник/канал с метриками качества |
| [skills/pages](skills/pages/SKILL.md) | Топ-страницы: просмотры, пользователи, вовлечённость |
| [skills/realtime](skills/realtime/SKILL.md) | Сейчас на сайте: активные пользователи, страницы, страны, устройства (последние ~30 мин) |

## Установка

Skills совместимы с различными AI-агентами. Пример ниже — для раскладки OpenCode;
для других инструментов используйте их каталог skills.

```bash
git clone https://github.com/prikotov/google-analytics-core.git .opencode/skills/google-analytics-core
```

Требования: PHP 8.4+ с расширениями `openssl` и `curl` (`php -m | grep -E 'openssl|curl'`).

## Настройка service account (единожды)

1. **Google Cloud Console** → IAM и администрирование → Сервисные аккаунты:
   создайте сервисный аккаунт (или используйте существующий, например от Search Console).
2. Создайте **ключ** сервисного аккаунта: Действия → Создать ключ → JSON.
   Скачанный файл — это JSON с полями `client_email` и `private_key`.
3. **Google Analytics 4** → Администратор → выберите свойство →
   **Управление доступом к свойству (Property access management)** → «+» →
   добавьте email сервисного аккаунта (`client_email` из ключа) с ролью
   **«Просмотр» (Viewer)**. Повторите для каждого нужного свойства GA4.
4. propertyId смотрите в Администраторе → Свойство → Информация о ресурсе
   (или в `gtag('config', 'G-XXXX')`-потоках: число вида `558541716`).

## Конфигурация

```bash
cp .opencode/skills/google-analytics-core/ga4_config.example.json ./ga4_config.json
```

Заполните:

```json
{
  "properties": {
    "site1.com": "558541716",
    "site2.com": "559035138"
  },
  "service_account_file": "/absolute/path/to/service-account-key.json",
  "default_site": "site1.com"
}
```

- `properties` — карта «имя сайта» → propertyId.
- `service_account_file` — путь к JSON-ключу: абсолютный или относительно каталога конфига.
- `default_site` — сайт по умолчанию (запуск без `--site`).

Поиск конфига: env `GA4_CONFIG_FILE` → `ga4_config.json` в текущем каталоге →
в каталоге скилла → в корне репозитория (раскладка TasK). Конфиг в каталоге скилла
позволяет всем агентам этой установки работать из любой рабочей директории.

## Использование

```bash
php skills/overview/scripts/overview.php                     # сводка, 28 дней
php skills/overview/scripts/overview.php --site site1.com --days 7   # 7 дней
php skills/traffic/scripts/traffic.php -s site1.com --date1 2026-11-01 --date2 2026-11-13
php skills/pages/scripts/pages.php --days 28 --limit 20
php skills/realtime/scripts/realtime.php                     # последние ~30 минут
```

Каждый запуск создаёт CSV + Markdown в `ga4_reports/YYYY-MM-DD/` текущего каталога
и печатает путь отчёта и число строк. Общие опции: `--site`, `--days`,
`--date1/--date2`, `--limit`, `--help`.

Примеры запросов агенту:

- «Покажи трафик prikotov.pro за последнюю неделю» → ga4-overview (`--days 7`)
- «Откуда приходит трафик и какой канал качественнее?» → ga4-traffic
- «Какие страницы самые популярные за месяц?» → ga4-pages
- «Есть ли сейчас кто-то на сайте?» → ga4-realtime

Структура отчётов агента — `templates/report.md`; справка по API и квотам —
`references/api.md`.

## Структура

```
your-project/
├── ga4_config.json            # Конфиг (создаётся вручную, не коммитится)
├── ga4_token.json             # Кэш access-токена (создаётся автоматически)
├── ga4_reports/               # Отчёты CSV/MD (создаются автоматически)
│   └── YYYY-MM-DD/
└── .opencode/skills/google-analytics-core/
    ├── Ga4Client.php          # Библиотека: auth, запросы, экспорт
    ├── ga4_config.example.json
    ├── SKILL.md               # Каталог саб-скиллов
    ├── README.md
    ├── templates/report.md    # Шаблон аналитического отчёта
    ├── references/api.md      # Справка GA4 Data API v1beta
    └── skills/
        ├── overview/  (SKILL.md + scripts/overview.php)
        ├── traffic/   (SKILL.md + scripts/traffic.php)
        ├── pages/     (SKILL.md + scripts/pages.php)
        └── realtime/  (SKILL.md + scripts/realtime.php)
```

## Безопасность

- `Ga4Client::checkGitignore()` при каждом запуске добавляет `ga4_config.json`,
  `ga4_token.json`, `ga4_reports/` в `.gitignore` корня установки (и текущего
  каталога, если он вне установки) — секреты не попадут в git.
- Кэш токена (`ga4_token.json`) создаётся с правами 0600, токен живёт 1 час.
- Секреты (private_key, access_token, client_email) не логируются и не попадают
  в отчёты: в вывод только имя сайта и propertyId.
- Ключ SA имеет scope только на чтение (`analytics.readonly`); в GA4 ему нужна
  лишь роль Viewer.

## Ограничения

- bounceRate GA4 = 1 − engagementRate — **не** «отказы» Яндекс.Метрики (см.
  `references/api.md`); прямое сравнение между системами некорректно.
- Данные GA4 отстают на часы (стандартные отчёты); сегодня в отчётах — частичные
  данные. Realtime — окно ~30 минут.
- Квоты: 1200 core-токенов/час и 2400 realtime-токенов/час на свойство (HTTP 429
  при исчерпании; клиент ретраит с backoff и `Retry-After`).
- Глубина истории — по лимитам свойства GA4 (обычно 14+ месяцев для стандартных отчётов).
