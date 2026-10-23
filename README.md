# loyalty

Бэкенд программы лояльности приложения «Шнырь-курьер» (такси и доставка): промокоды, скидки на следующий заказ, награды за приглашённых друзей. Рынки: Россия, Казахстан, Узбекистан, Кыргызстан. Владелец: команда `loyalty`, дежурный канал `#loyalty-duty`.

Сервис написал подрядчик «Северсофт», в феврале 2026 года код передан команде архивом.

## Как устроено

Любая механика лояльности это кампания: **триггер → условия → награда**. Кампании заводятся в админке и лежат в таблице `campaigns`. Значения условий и параметры наград (проценты, сроки, суммы) живут в строках кампаний, в коде их нет.

| Триггер | Откуда приходит | Пример кампании |
|---|---|---|
| `promo_code` | Пользователь ввёл промокод в приложении или открыл ссылку `shnyr://promo?code=…` | Промокод на скидку |
| `payment_succeeded` | Событие оплаты заказа от платёжной команды | Скидка на следующий заказ за товары из акции |
| `user_registered` | Событие регистрации от сервиса пользователей | Награда пригласившему за друга |

| Тип награды | Что это | Таблица |
|---|---|---|
| `discount` | Скидка на заказ: процент с потолком или фиксированная сумма, со сроком действия. Списывается при заказе | `user_discounts` |
| `referral_reward` | Деньги пригласившему на карту через `payout-gateway` | `referral_rewards` |

Глобальные лимиты, общие для всех кампаний, лежат в `config/app.yaml`.

### Контекст

```mermaid
flowchart LR
  user(["Пользователь<br/>приложение «Шнырь-курьер»"])
  staff(["Саппорт, маркетинг,<br/>дежурный loyalty"])

  loyalty["<b>loyalty</b><br/>промокоды, скидки,<br/>награды за друзей"]

  users["Сервис пользователей"]
  payments["Платёжный сервис"]
  orders["Заказы и pricing"]
  payout["payout-gateway<br/>выплаты на карту"]
  notif["notifications<br/>пуши"]

  user -->|промокод, мои скидки,<br/>мои приглашения| loyalty
  staff -->|админка| loyalty
  orders -->|скидка на заказ, списание| loyalty
  users -.->|user-registered| loyalty
  payments -.->|payment.succeeded| loyalty
  loyalty -->|выплата награды| payout
  loyalty -->|пуш о скидке| notif
```

Сплошные стрелки это синхронные HTTP-вызовы, пунктир это события Kafka.

### Контейнеры

```mermaid
flowchart LR
  subgraph K8S["Kubernetes, неймспейс loyalty"]
    subgraph POD["Деплоймент loyalty, 8 подов"]
      api["HTTP API<br/>приложение и админка"]
      cons["Консьюмеры<br/>payment-succeeded<br/>user-registered"]
      work["Воркеры<br/>reward-payouts<br/>discount-expiry"]
    end
    tools["loyalty-tools<br/>утилиты дежурного"]
  end

  db[("Postgres loyalty")]
  ro[("Реплика loyalty-ro")]

  subgraph KAFKA["Kafka"]
    tPay["payments.payment-succeeded"]
    tReg["users.user-registered"]
    tRetry["loyalty.*.retry / loyalty.*.dlq"]
  end

  payout["payout-gateway"]
  notif["notifications"]

  tPay --> cons
  tReg --> cons
  cons -.-> tRetry
  api --> db
  cons --> db
  work --> db
  tools --> db
  db --> ro
  work --> payout
  cons --> notif
```

API, консьюмеры и воркеры работают в каждом поде одного деплоймента, на все страны сразу. Read-реплика `loyalty-ro` доступна только разработчикам команды.

### Компоненты приложения

Гексагональная архитектура: входящие адаптеры (HTTP-ручки, консьюмеры, воркеры) вызывают сервисы ядра, сервисы ходят наружу только через порты, порты реализуют исходящие адаптеры (репозитории Postgres и HTTP-клиенты).

```mermaid
flowchart LR
  subgraph IN["Входящие адаптеры"]
    appApi["HTTP: API приложения<br/>промокод, скидки, приглашения"]
    adminApi["HTTP: API админки<br/>кампании, компенсационная скидка"]
    cPay["Kafka: payment-succeeded<br/>группа loyalty-payments"]
    cReg["Kafka: user-registered<br/>группа loyalty-referrals"]
    wPay["Воркер reward-payouts<br/>каждые 5 мин"]
    wExp["Воркер discount-expiry<br/>раз в час"]
  end

  subgraph CORE["Ядро: сервисы"]
    promoSvc["PromoService"]
    campSvc["CampaignService<br/>подбор кампаний, условия"]
    discSvc["DiscountService"]
    rewSvc["ReferralService"]
  end

  subgraph OUT["Исходящие адаптеры"]
    campRepo[("CampaignRepository<br/>campaigns")]
    discRepo[("DiscountRepository<br/>user_discounts")]
    rewRepo[("ReferralRewardRepository<br/>referral_rewards")]
    payoutCl["PayoutGatewayClient"]
    notifCl["NotificationsClient"]
  end

  appApi --> promoSvc & discSvc & rewSvc
  promoSvc -->|promo_code| campSvc
  adminApi --> promoSvc & campSvc & discSvc & rewSvc
  cPay --> campSvc
  cReg --> campSvc
  campSvc -->|discount| discSvc
  campSvc -->|referral_reward| rewSvc
  wPay --> rewSvc
  wExp --> discSvc

  campSvc --> campRepo
  promoSvc --> campRepo
  discSvc --> discRepo
  discSvc --> notifCl
  rewSvc --> rewRepo
  rewSvc --> payoutCl
```

## Модули

| Модуль | Что делает | Таблицы |
|---|---|---|
| `promo` | Ввод промокода: приводит ввод к виду кода (trim, верхний регистр), ищет кампанию по `campaigns.promo_code` и передаёт её в `campaigns` | Читает `campaigns` |
| `campaigns` | На триггер подбирает активные кампании, проверяет условия выдачи и выдаёт награду её типа | `campaigns` |
| `discounts` | Скидки: выдача по кампании и компенсационная из админки, проверка условий заказа и списание, гашение истёкших, пуш о новой скидке | `user_discounts` |
| `referrals` | Награды за друзей: создание, статусы, выплата на карту через `payout-gateway` | `referral_rewards` |
| `admin` | Ручки админки: кампании (включая промокодные), компенсационная скидка, награды пользователя | Пишет через остальные модули |

Условия кампании двух видов:

| Вид | Кто проверяет | Условия |
|---|---|---|
| Условия выдачи | `CampaignService` при триггере, по данным события или запроса приложения | `countries`, `segment`, `city_ids`, `per_user_limit`, `max_activations`, `sku_ids`, `payment_methods`, `invited` |
| Условия заказа | `DiscountService` при списании скидки | `time_from`, `time_to`, `min_order_amount` |

## Консьюмеры

| Консьюмер | Топик | Группа | Что делает |
|---|---|---|---|
| `payment-succeeded` | `payments.payment-succeeded` | `loyalty-payments` | Для активных кампаний с триггером `payment_succeeded` проверяет условия; подошла кампания со скидкой → скидка на следующий заказ → пуш |
| `user-registered` | `users.user-registered` | `loyalty-referrals` | Для активных кампаний с триггером `user_registered` проверяет условия (регистрация по приглашению: в событии есть `invited_by`) и создаёт награду пригласившему в статусе `Pending` |

Схемы событий: `api/events/`. Настройки групп: `config/consumers.yaml`.

## Воркеры

| Воркер | Расписание | Что делает |
|---|---|---|
| `reward-payouts` | При старте пода и дальше каждые 5 минут | Проходит награды в статусе `Pending` батчами и выплачивает их через `payout-gateway` с `idempotency_key` = id награды. Выплачено → `Paid`. У пользователя нет карты для выплат → награда остаётся `Pending` до следующего прохода |
| `discount-expiry` | Раз в час | Гасит истёкшие скидки |

Воркеры запускаются в каждом поде. Расписания: `config/workers.yaml`.

## Внешние зависимости

| Сервис | Владелец | Зачем | Спека |
|---|---|---|---|
| `payout-gateway` | Платёжная команда | Выплата награды на карту. Карты пользователей хранит он | `api/payout-gateway.yaml` |
| `notifications` | Команда CRM | Пуши пользователям | `api/notifications.yaml` |

В `api/` также лежат спеки соседних сервисов, которые мы пока не вызываем: `antifraud.yaml` (команда антифрода) и `order-history.yaml` (команда истории заказов).

## Платформа

| Что | Как |
|---|---|
| Консьюмеры | Обвязка платформы: сама читает топик, вызывает обработчик с дедлайном `processing_timeout`, коммитит оффсет после успешной обработки. Ошибка или дедлайн → повтор через ретрай-топик, после `retry.max_attempts` попыток сообщение уходит в DLQ. Непустой DLQ пейджит дежурного |
| Значения по умолчанию | `${PLATFORM_DEFAULT}` в конфигах подставляет платформа, у всех сервисов одинаково |
| Миграции | `migrations/`, только DDL. Катит платформенный пайплайн до деплоя приложения |
| Деплой | Один деплоймент `loyalty`, 8 подов |

## Данные

```mermaid
erDiagram
  campaigns ||--o{ user_discounts : "campaign_id"
  campaigns ||--o{ referral_rewards : "campaign_id"

  campaigns {
    bigserial id PK
    text slug UK
    text trigger "promo_code, payment_succeeded, user_registered"
    text promo_code UK "только для триггера promo_code"
    jsonb conditions
    text reward_type "discount, referral_reward"
    jsonb reward_params
    timestamptz starts_at
    timestamptz ends_at
    boolean is_active
    text created_by
    timestamptz created_at
  }
  user_discounts {
    bigserial id PK
    bigint user_id
    bigint campaign_id FK "null для компенсации"
    text channel "deeplink или manual у промокода"
    text payment_id
    smallint percent
    integer amount
    integer max_amount
    timestamptz expires_at
    timestamptz used_at
    bigint used_order_id
    text created_by "логин оператора для ручной выдачи"
    text comment
    timestamptz created_at
  }
  referral_rewards {
    bigserial id PK
    bigint campaign_id FK
    bigint user_id "пригласивший, получатель"
    bigint invitee_id "приглашённый"
    integer amount
    smallint status
    timestamptz status_changed_at
    uuid payout_id
    timestamptz created_at
  }
```

Один промокод это одна кампания. Пачка промокодов для партнёра это пачка кампаний с общим префиксом `slug` (`partner_pulse_oct_0001`, `partner_pulse_oct_0002`, …), её заводит дежурный скриптом (`docs/oncall_runbook.md`). Суммы в `reward_params` указаны в валюте страны кампании.

## Структура репозитория

```
README.md, CLAUDE.md, AGENTS.md
api/                 наш API (loyalty-api.yaml), спеки соседних сервисов
api/events/          схемы входящих событий
migrations/          DDL
config/              app.yaml, consumers.yaml, workers.yaml
docs/                ранбук дежурного, инструкция поддержки, роли админки
```
