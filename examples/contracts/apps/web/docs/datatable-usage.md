# DataTable&lt;T&gt; — руководство по использованию

Файл компонента: `apps/web/src/components/ui/DataTable.tsx`

## Быстрый старт

```tsx
import { DataTable } from "@/components/ui/DataTable";
import type { DataTableColumn } from "@/components/ui/DataTable";

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
}

const columns: DataTableColumn<User>[] = [
  { key: "name",  header: "Имя",       skeletonWidth: "70%" },
  { key: "email", header: "Email",      skeletonWidth: "80%" },
  { key: "role",  header: "Роль",       width: "8rem", align: "center" },
];

// rows === undefined → skeleton; rows.length === 0 → EmptyState
<DataTable
  columns={columns}
  rows={users}             // SWR data (undefined = loading)
  getRowKey={(u) => u.id}
  ariaLabel="Список пользователей"
/>
```

---

## Пропсы

| Prop | Тип | Default | Описание |
|---|---|---|---|
| `columns` | `DataTableColumn<T>[]` | — | Описание колонок |
| `rows` | `T[] \| undefined` | — | `undefined` → skeleton, `[]` → EmptyState |
| `getRowKey` | `(row: T) => string \| number` | — | Уникальный ключ строки |
| `onRowClick` | `(row: T) => void` | — | Делает строку кликабельной (cursor-pointer, role=button, Enter/Space) |
| `rowActions` | `(row: T) => ReactNode` | — | Контент колонки действий (виден при hover) |
| `stickyHeader` | `boolean` | `true` | Sticky thead |
| `density` | `"comfortable" \| "compact"` | `"comfortable"` | Плотность строк |
| `maxHeight` | `string` | `"70vh"` | Максимальная высота scroll-контейнера |
| `ariaLabel` | `string` | — | `aria-label` для table |
| `emptyIcon` | `string` | `"bi-table"` | Bootstrap Icons класс для EmptyState |
| `emptyTitle` | `string` | `"Нет данных"` | Заголовок EmptyState |
| `emptyText` | `string` | — | Подпись EmptyState |
| `emptyCta` | `ReactNode` | — | CTA-кнопка EmptyState |
| `isError` | `boolean` | `false` | Показывает inline-ошибку вместо данных |
| `errorText` | `string` | `"Не удалось загрузить данные"` | Текст ошибки |
| `footer` | `(totalCols: number) => ReactNode` | — | Содержимое `<tfoot>` (total-bar) |
| `sortKey` | `string` | — | Текущая сортировочная колонка |
| `sortDir` | `"asc" \| "desc"` | — | Направление сортировки |
| `onSort` | `(key: string) => void` | — | Вызывается при клике на sortable-заголовок |
| `skeletonRows` | `number` | `6` | Кол-во skeleton-строк при загрузке |

### DataTableColumn&lt;T&gt;

| Поле | Тип | Описание |
|---|---|---|
| `key` | `string` | Уникальный ключ (также используется как fallback для `row[key]`) |
| `header` | `ReactNode` | Заголовок th |
| `align` | `"left" \| "right" \| "center"` | Выравнивание (right автоматически добавляет `tabular-nums`) |
| `width` | `string` | CSS width (напр. `"8rem"`, `"15%"`) |
| `className` | `string` | Доп. класс для td |
| `render` | `(row: T) => ReactNode` | Кастомный рендер ячейки |
| `skeletonWidth` | `string` | Ширина skeleton-блока (CSS, default `"60%"`) |
| `sortable` | `boolean` | Добавляет sort-иконку и `aria-sort` |

---

## Примеры

### Registry-паттерн: row-actions + внешние фильтры

```tsx
const columns: DataTableColumn<RegistryRow>[] = [
  {
    key: "counterparty_name",
    header: "Клиент",
    skeletonWidth: "70%",
    render: (r) => (
      <Link href={`/counterparties/${r.counterparty_id}`} className="font-medium text-primary hover:underline">
        {r.counterparty_name}
      </Link>
    ),
  },
  {
    key: "fee_actual",
    header: "Абонентка",
    align: "right",
    skeletonWidth: "50%",
    render: (r) => r.fee_actual != null ? `${r.fee_actual.toLocaleString("ru-RU")} ${r.fee_currency ?? ""}` : "—",
  },
];

<DataTable
  columns={columns}
  rows={rows}
  getRowKey={(r) => r.id}
  density="compact"
  rowActions={(r) => (
    <Link href={`/counterparties/${r.counterparty_id}`} className="btn-ghost p-1 text-gray-400 hover:text-primary">
      <i className="bi bi-box-arrow-up-right text-xs" />
    </Link>
  )}
  emptyIcon="bi-people"
  emptyTitle="Нет клиентов по фильтру"
  emptyText="Попробуй изменить фильтры или сбросить сегмент"
/>
```

Фильтры — снаружи компонента (через `rows` prop уже отфильтрованные).

---

### Finance-паттерн: total-bar через footer

```tsx
const BALANCE_COLS: DataTableColumn<BalanceRow>[] = [
  { key: "account_name", header: "Счёт",    skeletonWidth: "55%" },
  { key: "currency",     header: "Валюта",   width: "6rem",  skeletonWidth: "40%" },
  { key: "amount",       header: "Остаток",  align: "right", skeletonWidth: "45%",
    render: (r) => <span className="font-semibold tabular-nums">{formatAmount(r.amount)} {r.currency}</span> },
  { key: "amount_func",  header: "В базе",   align: "right", skeletonWidth: "40%",
    render: (r) => <span className="tabular-nums text-gray-500">{formatAmount(r.amount_func)} RUB</span> },
];

<DataTable
  columns={BALANCE_COLS}
  rows={balances}
  getRowKey={(b) => b.money_account_id}
  onRowClick={(b) => router.push(`/finance/accounts/${b.money_account_id}`)}
  footer={(totalCols) => (
    <tr className="border-t-2 border-gray-200 dark:border-gray-600 bg-gradient-to-r from-gray-50 to-white dark:from-gray-800/80 dark:to-gray-900">
      <td colSpan={totalCols - 1} className="px-4 py-3 text-sm font-semibold">
        Итого в RUB:
      </td>
      <td className="px-4 py-3 text-right">
        <span className="tabular-nums font-bold text-base">{formatAmount(total)}</span>
        <span className="text-xs text-gray-400 ml-1">RUB</span>
      </td>
    </tr>
  )}
  emptyIcon="bi-wallet2"
  emptyTitle="Нет счетов"
/>
```

---

### Sortable-заголовки

```tsx
const [sortKey, setSortKey] = useState("name");
const [sortDir, setSortDir] = useState<"asc" | "desc">("asc");

function handleSort(key: string) {
  if (key === sortKey) {
    setSortDir((d) => d === "asc" ? "desc" : "asc");
  } else {
    setSortKey(key);
    setSortDir("asc");
  }
}

const sorted = useMemo(
  () => [...(items ?? [])].sort((a, b) => {
    const v = String(a[sortKey as keyof typeof a]).localeCompare(String(b[sortKey as keyof typeof b]));
    return sortDir === "asc" ? v : -v;
  }),
  [items, sortKey, sortDir]
);

<DataTable
  columns={[
    { key: "name",  header: "Название", sortable: true },
    { key: "count", header: "Кол-во",   sortable: true, align: "right" },
  ]}
  rows={sorted}
  getRowKey={(r) => r.id}
  sortKey={sortKey}
  sortDir={sortDir}
  onSort={handleSort}
/>
```

---

## Поведение состояний

| `rows` | `isError` | Отображение |
|---|---|---|
| `undefined` | any | Skeleton (`skeletonRows` строк) |
| `[]` | `false` | EmptyState |
| `T[]` (length > 0) | `false` | Таблица с blur-fade анимацией + footer (если задан) |
| any | `true` | Inline-ошибка |

---

## CSS

Компонент добавляет классы `.dt-table-wrap` и `.dt-thead-shadow` (определены в `globals.css`).
Scroll-детект: JS устанавливает `.scrolled` на обёртку → тень переходит из тонкой линии в `shadow-elev-1`.

Связанные CSS-классы (уже существуют, не трогаем): `.registry-thead-shadow`, `.fin-thead-shadow`.

## prefers-reduced-motion

Глобальное правило в `globals.css` автоматически гасит `blur-fade` анимацию и `transition` теней до `0.01ms`.
