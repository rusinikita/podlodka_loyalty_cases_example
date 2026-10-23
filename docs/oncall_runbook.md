# Ранбук дежурного: команда лояльности

Дежурство недельное, с понедельника по воскресенье. Канал `#loyalty-duty`, обращения регистрирует duty-bot. Ниже то, что дежурный делает руками.

Доступы: read-реплика `loyalty-ro` для диагностики, основная база `loyalty` только для правок из этого ранбука, `kubectl` в неймспейс `loyalty`.

## 1. «Почему не применился промокод»

Админка показывает саппорту только «недоступен», причину приходится доставать из базы.

```sql
-- что за код и какие у кампании условия
SELECT id, slug, promo_code, is_active, starts_at, ends_at, conditions, reward_params
FROM campaigns
WHERE promo_code = :code;

-- что пользователь делал с промокодами
SELECT d.id, c.slug, c.promo_code, d.channel, d.created_at, d.used_at, d.used_order_id
FROM user_discounts d
JOIN campaigns c ON c.id = d.campaign_id
WHERE d.user_id = :user_id
  AND c.trigger = 'promo_code'
ORDER BY d.created_at DESC
LIMIT 20;

-- сколько всего активаций у кода
SELECT count(*) FROM user_discounts WHERE campaign_id = :campaign_id;

-- месячный лимит пользователя
SELECT count(*)
FROM user_discounts d
JOIN campaigns c ON c.id = d.campaign_id
WHERE d.user_id = :user_id
  AND c.trigger = 'promo_code'
  AND d.used_at >= date_trunc('month', now());
```

Дальше сверить по порядку: код существует → кампания активна и не истекла → сегмент → город → время суток → минимальная сумма → лимит на пользователя → лимит активаций кампании → месячный лимит пользователя. Первое несовпадение и есть причина.

## 2. Пачка промокодов для маркетинга или партнёра

Нужна роль `loyalty_admin`.

```
kubectl -n loyalty exec deploy/loyalty-tools -- \
  python tools/gen_promo_batch.py --slug <slug> --prefix <PREFIX> --count <N> --amount <RUB> --expires <YYYY-MM-DD>
```

Скрипт вставляет в `campaigns` по кампании на каждый код (`<slug>_0001`, `<slug>_0002`, …) с одинаковыми условиями и кладёт CSV с кодами в `/tmp/batches/`. Забрать файл через `kubectl cp` и отправить заказчику в тред.

Скрипт умеет только случайные коды с префиксом и фиксированную скидку в рублях. Именные коды и скидку в процентах правим руками в CSV и загружаем через `tools/import_promo_csv.py`.

## 3. «Не пришла награда за друга»

```sql
SELECT id, user_id, invitee_id, amount, status, status_changed_at, payout_id, created_at
FROM referral_rewards
WHERE invitee_id = :invitee_id;
```

| Что видим | Что отвечаем |
|---|---|
| Записи нет | Приглашение не привязалось: друг зарегистрировался не по ссылке или с другого устройства. Награды нет |
| `status = 1` (`Pending`) | Выплата не прошла. Смотрим лог `reward-payouts` по id награды: `no_payout_method` значит, что у пригласившего нет привязанной и подтверждённой карты. Пусть привяжет карту в профиле, выплата уйдёт в течение 5 минут |
| `status = 2` (`Paid`) | Выплачено. Статус выплаты по `payout_id` в `payout-gateway`. Банк зачисляет до 3 рабочих дней |

Награда всегда уходит пригласившему, на карту, привязанную в момент выплаты. Повторно не выплачиваем.

## 4. Компенсационная скидка

Админка → Пользователь → Скидки → «Выдать скидку». Указать процент, срок и номер обращения в комментарии. Доступно роли `loyalty_operator` и выше, лимит в `config/app.yaml` (`discounts.compensation`). Решение о компенсации и её размер принимает саппорт, дежурный только выдаёт.

## 5. «Не пришла скидка на следующий заказ»

```sql
SELECT d.id, c.slug, d.payment_id, d.percent, d.expires_at, d.used_at, d.created_at
FROM user_discounts d
LEFT JOIN campaigns c ON c.id = d.campaign_id
WHERE d.user_id = :user_id
  AND d.created_at >= :order_date
ORDER BY d.created_at;
```

Если скидки нет, проверить в админке заказа, что заказ оплачен, не отменён и не корпоративный. Если всё так, выдать скидку вручную, как в п. 4, с комментарием «доначисление» и номером обращения.

## 6. Изменение параметров кампании и продление скидки

Админка → Лояльность → Кампании → «Редактировать»: срок, лимит активаций, города и остальные условия. Роль `loyalty_admin`.

Продлить скидку одному пользователю в админке нельзя, это апдейт в основной базе:

```sql
UPDATE user_discounts SET expires_at = :new_date WHERE id = :discount_id AND user_id = :user_id;
```

## 7. Выгрузка выплат для финансов

Раз в месяц, по запросу финансов.

```sql
SELECT date_trunc('day', status_changed_at) AS day,
       count(*)    AS rewards,
       sum(amount) AS paid_rub
FROM referral_rewards
WHERE status = 2
  AND status_changed_at >= :month_start AND status_changed_at < :month_end
GROUP BY 1
ORDER BY 1;
```

Выгрузить в CSV и отправить в тред.

## Константы, о которых часто спрашивают

| Параметр | Где |
|---|---|
| Промокодов на заказ | `config/app.yaml`, `promo.max_per_order` |
| Промокодов в месяц на пользователя | `config/app.yaml`, `promo.max_per_month` |
| Скидки не суммируются | `config/app.yaml`, `discounts.stackable` |
| Компенсационная скидка: процент и срок | `config/app.yaml`, `discounts.compensation` |
| Срок скидки на следующий заказ | Кампания, `reward_params.ttl_days` |
| Сумма награды за друга | Кампания, `reward_params.amount` |
