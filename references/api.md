# Справка по API: GA4 Data API v1beta

Проверено живыми запросами 13.11.2026 (properties 558541716, 559035138).

## Подключение

- Base URL: `https://analyticsdata.googleapis.com/v1beta`
- Аутентификация: `Authorization: Bearer <access_token>`.
- Токен: service account JSON → JWT RS256 (alg/typ → claims: iss=client_email,
  scope=`https://www.googleapis.com/auth/analytics.readonly`, aud=token_uri,
  iat, exp=+3600) → POST `https://oauth2.googleapis.com/token`
  `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=<JWT>`.
- Доступ: сервисному аккаунту нужна роль **Viewer («Просмотр»)** в каждом
  свойстве: GA4 → Администратор → Управление доступом к свойству.
- Область применения: только чтение. Admin API, Measurement Protocol — out of scope.

## Методы

| Метод | Путь | Тело |
|---|---|---|
| runReport | POST `/properties/{PROPERTY_ID}:runReport` | JSON-отчёт |
| runRealtimeReport | POST `/properties/{PROPERTY_ID}:runRealtimeReport` | JSON-отчёт (без dateRanges) |
| metadata | GET `/properties/{PROPERTY_ID}/metadata` | — (каталог dimensions/metrics) |
| metadata (универсальный) | GET `/v1beta/metadata/ga4` | — |

## Формат тела runReport

```json
{
  "dateRanges": [{"startDate": "2026-11-07", "endDate": "2026-11-13"}],
  "dimensions": [{"name": "date"}],
  "metrics": [{"name": "sessions"}, {"name": "totalUsers"}],
  "orderBys": [{"dimension": {"dimensionName": "date"}}],
  "limit": 400
}
```

- Даты: `YYYY-MM-DD` либо относительные: `NdaysAgo`, `yesterday`, `today`.
  Отсчёт — по таймзоне свойства. `NdaysAgo` включает сегодня.
- Сортировка: `orderBys: [{"metric": {"metricName": "sessions"}, "desc": true}]`.
- `limit` — proto int64: API принимает и число, и строку; клиент передаёт число.
  Максимум 250 000 строк на запрос, по умолчанию 10 000. `limit = 0` → только
  агрегаты. `rowCount: true`-поведение: total-число строк приходит в ответе
  (`rowCount`), выборка страницами — `offset` + `limit`.
- Метрики-доли (`bounceRate`, `engagementRate`) приходят дробью 0–1;
  длительности (`averageSessionDuration`, `userEngagementDuration`) — в секундах.
- Имена полей сверяй с `metadata`: неизвестное имя → HTTP 400.

### runRealtimeReport

- Без `dateRanges`; окно — последние ~30 минут (`minuteRanges` можно сузить).
- Dimensions ограничены официальной realtime-схемой: `unifiedScreenName`,
  `country`, `countryId`, `city`, `cityId`, `deviceCategory`, `minutesAgo`,
  `eventName`, `appVersion`, `platform`, `streamId`, `streamName`,
  `audienceId`, `audienceName`, `audienceResourceName`.
  **`sessionSource`/`sessionMedium` в realtime-схеме нет** — проверено живым
  запросом 13.11.2026: HTTP 400 «sessionSource is not a valid dimension».
  Источники активного трафика — только через обычный runReport за сегодня.
- Metrics ограничены: `activeUsers`, `screenPageViews`, `eventCount`,
  `keyEvents`, `purchaseRevenue`.
- `minutesAgo`: `0` — текущая минута, максимум 29.
- Ответ тот же формат: dimensionHeaders/metricHeaders + rows.

## Формат ответа

- `dimensionHeaders[i].name` / `metricHeaders[i].name` — порядок соответствует
  `dimensionValues[i]` / `metricValues[i]` каждой строки `rows[]`.
- Числа приходят **строками** (int64/double) — приводи к числу при расчётах.
- Пустой `rows` — валидный ответ «нет данных за период», не ошибка.
- `propertyQuota` в ответе — оставшиеся квотные токены (при
  `returnPropertyQuota: true`).

## Квоты (Data API v1beta, на свойство)

- Core: **1200 токенов/час**, 480 000 токенов/день на свойство.
- Realtime: **2400 токенов/час** на свойство.
- Стандартный отчёт стоит ~10 токенов; стоимость растёт с длиной периода
  и кардинальностью выборок. `checkCompatibility` и metadata квоту не тратят
  практически.
- При исчерпании — HTTP 429 `RESOURCE_EXHAUSTED`; квота восстанавливается
  скользящим окном в течение часа. Клиент делает до 3 повторов с backoff
  и учитывает заголовок `Retry-After`.

## Типовые ошибки

| HTTP | gRPC-статус | Причина | Действие |
|---|---|---|---|
| 400 | INVALID_ARGUMENT | неизвестный dimension/metric, плохой формат дат | сверяй с metadata |
| 401 | UNAUTHENTICATED | токен истёк/неверный | удали кэш `ga4_token.json`, повтори |
| 403 | PERMISSION_DENIED | у SA нет роли на свойство | GA4 → Администратор → Управление доступом → Viewer для email из ключа |
| 404 | NOT_FOUND | неверный propertyId | проверь properties в конфиге |
| 429 | RESOURCE_EXHAUSTED | квота | подожди (Retry-After), уменьши число запросов |
| 500/503 | INTERNAL/UNAVAILABLE | сбой на стороне Google | клиент ретраит с backoff |

## bounceRate GA4 vs «отказы» Яндекс.Метрики

- GA4: **bounceRate = 1 − engagementRate**. Сессия **вовлечена**, если было ≥2
  просмотров, ИЛИ событие-конверсия, ИЛИ длительность ≥10 секунд. Bounce —
  сессия с ровно одним просмотром, без конверсии и короче 10 секунд.
- Метрика: отказ — визит длительностью менее 15 секунд либо с единственным
  просмотром страницы (без иных действий).
- Следствие: bounceRate GA4 систематически **ниже** процента отказов Метрики —
  GA4 «прощает» короткие сессии с активностью. Сравнивать метрики напрямую
  между системами нельзя; тренды внутри одной системы — можно.

## Источники

- Документация: https://developers.google.com/analytics/devguides/reporting/data/v1
- Справочник dimension/metrics: https://ga-dev-tools.google/ga4/dimensions-metrics-explorer/
- Квоты: https://developers.google.com/analytics/devguides/reporting/data/v1/quotas
