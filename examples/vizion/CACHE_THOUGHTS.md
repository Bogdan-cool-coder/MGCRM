# Схема кэширования ReportDataService

## Проблема

При построении чартов по relations с большим количеством записей (100k+) данные загружаются в RAM:

```php
$items = $query->with($relationChain)->get(); // Все записи в память
$grouped = $items->groupBy(...);
```

При одновременных запросах от пользователей разных компаний — риск положить сервер по памяти.

## Решение: Lazy-кэширование

Кэшируем результат агрегации чарта при первом запросе, отдаём из кэша всем пользователям компании.

## Архитектура

```
┌─────────────────────────────────────────────────────────────┐
│                         Request                             │
│  User from Company A → Report "Deals" → Chart by Complex    │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      Check Cache                            │
│  Key: chart:{companyId}:{reportId}:{filtersHash}            │
└─────────────────────────────────────────────────────────────┘
                    │                    │
              HIT ▼                MISS ▼
┌──────────────────────┐    ┌──────────────────────────────┐
│  Return from cache   │    │  Build chart (slow)          │
│  (instant)           │    │  - Load data                 │
│                      │    │  - Group by relation         │
│                      │    │  - Aggregate                 │
│                      │    │  - Store to cache            │
└──────────────────────┘    └──────────────────────────────┘
```

## Ключ кэширования

```php
$cacheKey = implode(':', [
    'chart',
    $companyId,           // Данные изолированы по компании
    $reportId,            // Разные отчёты = разный кэш
    $filtersHash,         // md5(json_encode($filters))
]);
```

### Переиспользование между отчётами (опционально)

Если несколько отчётов используют одинаковый чарт (модель + xField + yField + aggregation):

```php
$cacheKey = implode(':', [
    'chart',
    $companyId,
    $primaryModel,        // EstateDeals
    $xField,              // estateSells.estateHouses.geoCityComplex.geo_complex_name
    $yField,              // deal_sum
    $aggregation,         // sum
    $filtersHash,
]);
```

## TTL по типу отчёта

```php
$ttl = match ($report->type ?? 'default') {
    'analytics'   => 86400,   // 24 часа — аналитика не требует свежести
    'registry'    => 14400,   // 4 часа — менеджеры сверяются в течение дня
    'finance'     => 3600,    // 1 час — бухгалтерия нужна свежая
    'operational' => null,    // Без кэша — реальное время
    'default'     => 14400,   // 4 часа по умолчанию
};
```

## Реализация

```php
class ReportDataService
{
    protected function buildChart(Builder $baseQuery): ?array
    {
        $chartConfig = $this->config['chart'] ?? null;

        if (!$chartConfig) {
            return null;
        }

        // Проверяем кэш
        $cacheKey = $this->getChartCacheKey($chartConfig);
        $ttl = $this->getChartTtl();

        if ($ttl && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Строим чарт
        $chart = $this->buildChartData($baseQuery, $chartConfig);

        // Сохраняем в кэш
        if ($ttl) {
            Cache::put($cacheKey, $chart, $ttl);
        }

        return $chart;
    }

    protected function getChartCacheKey(array $chartConfig): string
    {
        $companyId = $this->connectionService->getCompanyId();
        $reportId = $this->report->id;
        $filtersHash = md5(json_encode($this->appliedFilters));

        return "chart:{$companyId}:{$reportId}:{$filtersHash}";
    }

    protected function getChartTtl(): ?int
    {
        return $this->config['cache_ttl'] ?? 14400;
    }
}
```

## Конфиг отчёта

```php
[
    'title' => 'Реестр сделок',
    'cache_ttl' => 14400, // 4 часа, null = без кэша
    'chart' => [
        'type' => 'bar',
        'x' => 'estateSells.estateHouses.geoCityComplex.geo_complex_name',
        'y' => 'deal_sum',
        'aggregation' => 'sum',
    ],
    // ...
]
```

## Инвалидация кэша

### Варианты:

1. **По времени (TTL)** — автоматически истекает
2. **Ручной сброс** — кнопка в админке "Обновить данные"
3. **По событию** — при добавлении/изменении данных (сложнее)

```php
// Ручной сброс
Cache::forget("chart:{$companyId}:{$reportId}:*");

// По событию (если есть hooks в MacroData)
Event::listen(DealUpdated::class, fn($e) => Cache::forget("chart:{$e->companyId}:*"));
```

## Для operational отчётов

Без кэша, но с оптимизациями:

1. **Ограничение по времени** — только записи за сегодня/неделю
2. **Без чартов по relations** — только прямые поля (SQL GROUP BY)
3. **Cursor** — streaming, минимальная RAM

```php
// Cursor для больших данных
$aggregates = [];
foreach ($query->select('id', 'deal_sum', 'complex_id')->cursor() as $row) {
    $label = $row->complex_id;
    $aggregates[$label] = ($aggregates[$label] ?? 0) + $row->deal_sum;
}
```

## Итог

| Тип отчёта | Кэш | Чарты по relations | RAM |
|------------|-----|-------------------|-----|
| Analytics | 24ч | Да | Первый запрос |
| Registry | 4ч | Да | Первый запрос |
| Finance | 1ч | Да | Первый запрос |
| Operational | Нет | Нет | Минимально (cursor/paginate) |

---

## Фильтрация по вычисляемым полям (expression)

### Проблема

Поля с `expression` (например, `to_pay = deal_sum - finances_income`) не участвуют в фильтрации:

```php
// buildAvailableFilters() - пропускаем вычисляемые поля
if (isset($column['expression'])) {
    continue; // Нет колонки в БД → нельзя фильтровать на уровне SQL
}
```

### Почему нельзя сейчас

Фильтрация работает **до** загрузки данных (на уровне Eloquent/SQL):

```php
$query->where('deal_sum', '>', 1000000); // SQL WHERE
$items = $query->paginate(50);           // Только потом загружаем
```

Вычисляемые поля существуют только **после** загрузки:

```php
$row['to_pay'] = $row['deal_sum'] - $row['finances_income']; // В PHP
```

### Решение с кэшированием

Когда реализуем кэширование чартов, сможем добавить фильтрацию по expression:

1. При первом запросе — загружаем все данные в RAM
2. Вычисляем expression для каждой записи
3. Фильтруем в памяти
4. Кэшируем результат

```php
// После реализации кэша
if (Cache::has($cacheKey)) {
    return Cache::get($cacheKey);
}

// Загружаем и вычисляем
$items = $query->with($relations)->get();
$items->each(fn($item) => $item->to_pay = $item->deal_sum - $item->finances_income);

// Фильтруем в памяти
$filtered = $items->filter(fn($item) => $item->to_pay > 1000000);

// Кэшируем
Cache::put($cacheKey, $filtered, $ttl);
```

### Trade-off

| Подход | Плюсы | Минусы |
|--------|-------|--------|
| Без фильтрации по expression | Быстро, SQL-level | Нельзя фильтровать по to_pay |
| Фильтрация в памяти | Можно фильтровать по expression | Жрёт RAM, медленнее |
| Кэш + фильтрация | Лучшее из двух миров | Сложнее, данные могут устареть |

### Рекомендация

Пока не реализуем кэширование — не фильтруем по expression. После реализации кэша — добавить опционально в конфиг отчёта:

```php
'columns' => [
    ['field' => 'to_pay', 'expression' => 'deal_sum - finances_income', 'filterable' => true, 'cache_required' => true],
]
```
