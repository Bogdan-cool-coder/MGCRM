# MacroData Relations

Автоматически сгенерированная документация связей между таблицами MacroData.

> **Note:** MACRODATA.md отсутствует локально (файл в .gitignore). Этот файл создан вручную
> на основе текущих моделей в `app/Models/MacroData/`. Для полной сверки с документацией
> схемы MACRO нужно наличие MACRODATA.md и запуск `macrodata:generate-relations`.

**Формат:**
- Таблица → поля с `_id` → связанная таблица
- Метод = название relation в модели (camelCase)
- Статус: ✅ реализовано / ⚠️ не реализовано

---

## estate_buys

**Model:** `EstateBuys`

| Поле | Связь | Метод | Тип | Статус |
|------|-------|-------|-----|--------|
| `contacts_id` | `contacts.id` | `contacts()` | belongsTo | ✅ |
| `status` | `estate_statuses.status_id` | `estateStatuses()` | belongsTo | ✅ |
| `status_reason_id` | `estate_statuses_reasons.status_reason_id` | `estateStatusesReasons()` | belongsTo | ✅ |
| `manager_id` | `users.id` | `usersManager()` | belongsTo | ✅ |
| `call_center_manager_id` | `users.id` | `usersCallCenterManager()` | belongsTo | ✅ |
| `departments_id` | `company_departments.id` | `companyDepartments()` | belongsTo | ✅ |
| `estate_sell_id` | `estate_sells.estate_sell_id` | `estateSells()` | belongsTo | ✅ |
| `house_id` | `estate_houses.house_id` | `estateHouses()` | belongsTo | ✅ |
| `first_meetings_id` | `estate_meetings.meetings_id` | `estateMeetingsFirst()` | belongsTo | ✅ |
| `first_meetings_house_id` | `estate_meetings.meetings_id` | `estateMeetingsFirstHouse()` | belongsTo | ✅ |
| `first_meetings_office_id` | `estate_meetings.meetings_id` | `estateMeetingsFirstOffice()` | belongsTo | ✅ |
| `deal_id` | `estate_deals.deal_id` | `estateDeals()` | belongsTo | ✅ |
| `contacts_mediator_id` | `contacts.id` | `contactsMediator()` | belongsTo | ✅ |
| `mediator_agency_id` | `contacts.id` | `contactsMediatorAgency()` | belongsTo | ✅ |
| `advertising_channel_id` | `estate_advertising_channels.id` | `estateAdvertisingChannels()` | belongsTo | ✅ |
| `first_house_interest` | `estate_houses.house_id` | `estateHousesFirstInterest()` | belongsTo | ✅ |
| `first_complex_interest` | `geo_city_complex.id` | `geoCityComplexFirstInterest()` | belongsTo | ✅ |
| `estate_buy_id` | `estate_buys_attr.estate_buy_id` | `estateBuysAttrs()` | hasMany | ✅ |
| `estate_buy_id` | `estate_tags.estate_id` | `estateTagsRelation()` | hasMany | ✅ |
| `estate_buy_id` | `estate_buys_utm.estate_buy_id` | `estateBuysUtm()` | hasOne | ✅ |
| `estate_buy_id` | `tasks.estate_id` | `tasks()` | hasMany | ✅ |
| `estate_buy_id` | `estate_meetings.estate_buy_id` | `estateMeetings()` | hasMany | ✅ |

---

## estate_buys_utm

**Model:** `EstateBuysUtm`

| Поле | Связь | Метод | Тип | Статус |
|------|-------|-------|-----|--------|
| `estate_buy_id` | `estate_buys.estate_buy_id` | `estateBuys()` | belongsTo | ✅ |

---

## estate_tags

**Model:** `EstateTags`

| Поле | Связь | Метод | Тип | Статус |
|------|-------|-------|-----|--------|
| `tags_id` | `tags.id` | `tags()` | belongsTo | ✅ |

---

## estate_deals

**Model:** `EstateDeals`

> Полный список связей EstateDeals не перечислен — см. модель напрямую.
> Ключевые для отчётов:

| Поле | Связь | Метод | Тип | Статус |
|------|-------|-------|-----|--------|
| `estate_sell_id` | `estate_sells.estate_sell_id` | `estateSells()` | belongsTo | ✅ |
| `deal_id` | `finances.deal_id` | `finances()` | hasMany | ✅ |

---

## tasks

**Model:** `Tasks`

| Поле | Связь | Метод | Тип | Статус |
|------|-------|-------|-----|--------|
| `contacts_id` | `contacts.id` | `contacts()` | belongsTo | ✅ |
| `assigner_id` | `users.id` | `usersAssigner()` | belongsTo | ✅ |
| `manager_id` | `users.id` | `usersManager()` | belongsTo | ✅ |
| `estate_id` | `estate_buys.estate_buy_id` | `estateBuy()` | belongsTo | ✅ |

---

---

## Связи, используемые в виджетах (group_by dot-path)

`WidgetDataService` поддерживает `group_by.fields: ["relation.column"]` (R1, одиночный hop).
Только BelongsTo / HasOne — HasMany отклоняется (дублирует строки). Синтаксис конфига:

```json
{
  "group_by": { "fields": ["usersManager.users_name"] },
  "chart": { "label_field": "usersManager.users_name", "value_field": "total" }
}
```

Ключевые связи для виджетов и поле-имя:

| primary_model | relation | поле-имя | Описание |
|---|---|---|---|
| `EstateDeals` | `usersManager()` | `users_name` | Менеджер сделки |
| `EstateDeals` | `usersDealManager()` | `users_name` | Менеджер по сделке (альтернатива) |
| `EstateBuys` | `usersManager()` | `users_name` | Менеджер лида |
| `EstateBuys` | `estateAdvertisingChannels()` | `name` | Рекламный канал |
| `EstateBuys` | `estateHouses()` | `complex_name` или `public_house_name` | Дом / корпус |
| `EstateSells` | `estateHouses()` | `complex_name` или `public_house_name` | Дом |
| `EstateHouses` | `geoCityComplex()` | `geo_complex_name` | ЖК (название) |
| `EstateDeals` | `companyDepartments()` | `department_name` | Отдел |

> Поле `users_name` — varchar(255) в таблице `users`. Не `name`.
> Поле `geo_complex_name` — в таблице `geo_city_complex`.
> Поле `name` — в таблице `estate_advertising_channels`.

---

## Полиморфные связи (не реализованы)

Таблицы `estate_buys`, `estate_sells`, `estate_deals`, `promos` используют `entity_id`
для полиморфных ссылок из других сущностей. Реализация через `morphMap` не приоритетна.

⚠️ Помечено как нереализованное — требует `morphMap` и согласования типов.

---

## Summary

- Tables with documented relations: **5** (estate_buys, estate_buys_utm, estate_tags, estate_deals, tasks)
- Full model count: **64**
- Для полного списка — запустить `macrodata:generate-relations` при наличии `MACRODATA.md`
