# Forms 2.0 — примеры использования примитивов

> MARATHON-DESIGN-V2 · A3 · Sprint 2026-06-04
> Компоненты живут в `apps/web/src/components/ui/`.

---

## Combobox

Select с поиском на Radix Popover. Используй вместо нативного `<select>` когда
список длинный (справочники, пользователи, контрагенты, продукты).

### API

| Prop | Тип | По умолч. | Описание |
|---|---|---|---|
| `label` | `string` | — | Подпись поля (`<label>`). |
| `value` | `V \| null \| undefined` | — | Выбранное значение. |
| `onChange` | `(value: V \| null) => void` | — | Вызывается при выборе. |
| `options` | `ComboboxOption<V>[]` | — | Список опций (`value`, `label`, `hint?`, `disabled?`). |
| `placeholder` | `string` | `"Выберите значение…"` | Когда ничего не выбрано. |
| `searchPlaceholder` | `string` | `"Поиск…"` | Placeholder поля поиска. |
| `required` | `boolean` | `false` | Обязательное поле. |
| `disabled` | `boolean` | `false` | Блокировка. |
| `isLoading` | `boolean` | `false` | Показать спиннер (async). |
| `clearable` | `boolean` | `false` | Кнопка × для сброса. |
| `hint` | `React.ReactNode` | — | Подсказка под полем. |
| `className` | `string` | — | Доп. класс на корневой div. |
| `ariaLabel` | `string` | — | Если `label` не передан. |

```tsx
// Простой справочник
const [categoryId, setCategoryId] = useState<number | null>(null);

<Combobox
  label="Категория"
  value={categoryId}
  onChange={setCategoryId}
  options={categories.map(c => ({ value: c.id, label: c.name }))}
  required
  clearable
  hint="Выберите категорию продукта"
/>
```

```tsx
// С подсказкой (hint в опции) + async loading
<Combobox<string>
  label="Контрагент"
  value={counterpartyId}
  onChange={setCounterpartyId}
  options={counterparties.map(c => ({
    value: c.id,
    label: c.name,
    hint: c.inn,         // ИНН справа
  }))}
  isLoading={isLoadingCounterparties}
  clearable
  searchPlaceholder="Поиск по имени или ИНН…"
/>
```

---

## DatePicker

Выбор даты через Radix Popover + inline-календарь (date-fns RU).
Значение в ISO: `"YYYY-MM-DD"` или `null`.

### API

| Prop | Тип | По умолч. | Описание |
|---|---|---|---|
| `label` | `string` | — | Подпись поля. |
| `value` | `string \| null \| undefined` | — | ISO `"YYYY-MM-DD"`. |
| `onChange` | `(iso: string \| null) => void` | — | Вызывается при выборе. |
| `placeholder` | `string` | `"Выберите дату"` | — |
| `required` | `boolean` | `false` | Обязательное поле. |
| `disabled` | `boolean` | `false` | Блокировка. |
| `clearable` | `boolean` | `false` | Кнопка × очистки. |
| `minDate` | `string` | — | ISO нижняя граница. |
| `maxDate` | `string` | — | ISO верхняя граница. |
| `hint` | `React.ReactNode` | — | Подсказка под полем. |
| `className` | `string` | — | Доп. класс. |

```tsx
// Дата в форме договора
const [signedAt, setSignedAt] = useState<string | null>(null);

<DatePicker
  label="Дата подписания"
  value={signedAt}
  onChange={setSignedAt}
  required
  clearable
  maxDate={new Date().toISOString().split("T")[0]}  // не будущее
/>
```

```tsx
// Диапазон (начало / конец)
<div className="grid grid-cols-2 gap-4">
  <DatePicker
    label="Дата начала"
    value={startDate}
    onChange={setStartDate}
    maxDate={endDate ?? undefined}
  />
  <DatePicker
    label="Дата окончания"
    value={endDate}
    onChange={setEndDate}
    minDate={startDate ?? undefined}
  />
</div>
```

> Если нужен формат ДД.ММ.ГГГГ (старый DateField) — конвертируй через
> `isoToRu` / `ruToIso` из `@/lib/dates`.

---

## FloatingInput / FloatingTextarea

Инпут с анимированным floating-label. Лейбл «всплывает» при фокусе или
наличии значения. Базируется на стилях `.input` + focus-glow токенах.
Поддерживает trailing-иконку.

### FloatingInput API

Extends стандартные `React.InputHTMLAttributes<HTMLInputElement>`, плюс:

| Prop | Тип | Описание |
|---|---|---|
| `label` | `string` | Текст floating-label (обязателен). |
| `error` | `string` | Сообщение ошибки под полем. |
| `hint` | `React.ReactNode` | Подсказка (не ошибка). |
| `icon` | `string` | Bootstrap Icons класс trailing-иконки (без `bi-` — пример: `"bi-search"`). |
| `required` | `boolean` | Метка * в лейбле + `aria-required`. |
| `className` | `string` | Доп. класс на обёртку. |

### FloatingTextarea API

Те же пропы + стандартные `React.TextareaHTMLAttributes<HTMLTextAreaElement>`.
Высота min 100px, resize вертикальный.

```tsx
// Контролируемый инпут
<FloatingInput
  label="Название сделки"
  value={title}
  onChange={e => setTitle(e.target.value)}
  required
  error={!title ? "Обязательное поле" : undefined}
  icon="bi-briefcase"
/>
```

```tsx
// Сумма с валютой-иконкой
<FloatingInput
  label="Сумма договора"
  type="number"
  value={amount}
  onChange={e => setAmount(e.target.value)}
  icon="bi-currency-dollar"
  hint="В рублях без НДС"
/>
```

```tsx
// Textarea с floating-label
<FloatingTextarea
  label="Комментарий"
  value={comment}
  onChange={e => setComment(e.target.value)}
  rows={4}
  hint="До 500 символов"
/>
```

> `FloatingInput` и `FloatingTextarea` принимают `ref` через `forwardRef`.

---

## Dropzone

Зона drag&drop загрузки файлов. Чистый UI — без fetch/axios.
Сетевую загрузку делает вызывающий код через `onFiles`.

### API

| Prop | Тип | По умолч. | Описание |
|---|---|---|---|
| `onFiles` | `(files: File[]) => void` | — | Вызывается с новыми файлами. |
| `files` | `DropzoneFile[]` | `[]` | Список отображаемых файлов (`key`, `file`). |
| `onRemove` | `(key: string) => void` | — | Удаление файла из списка. |
| `fileProgress` | `Record<string, number>` | `{}` | Прогресс 0–100 по key. |
| `accept` | `string` | — | MIME или расширения: `"image/*"`, `".pdf,.docx"`. |
| `multiple` | `boolean` | `false` | Несколько файлов. |
| `disabled` | `boolean` | `false` | Блокировка. |
| `error` | `string` | — | Ошибка под зоной. |
| `hint` | `React.ReactNode` | — | Подсказка. |
| `label` | `string` | `"Перетащите файлы сюда"` | Основной текст в зоне. |
| `description` | `string` | авто по accept | Вспомогательный текст. |
| `className` | `string` | — | Доп. класс. |

```tsx
// Загрузка договора (PDF/DOCX)
const [files, setFiles] = useState<DropzoneFile[]>([]);
const [progress, setProgress] = useState<Record<string, number>>({});

const handleFiles = async (newFiles: File[]) => {
  const entries = newFiles.map(f => ({ key: crypto.randomUUID(), file: f }));
  setFiles(prev => [...prev, ...entries]);

  for (const { key, file } of entries) {
    const formData = new FormData();
    formData.append("file", file);
    // xhr для progress, или fetch без progress:
    setProgress(p => ({ ...p, [key]: 50 }));
    await api("/api/attachments", { method: "POST", body: formData });
    setProgress(p => ({ ...p, [key]: 100 }));
  }
};

<Dropzone
  onFiles={handleFiles}
  files={files}
  onRemove={key => setFiles(f => f.filter(x => x.key !== key))}
  fileProgress={progress}
  accept=".pdf,.doc,.docx"
  multiple
  label="Прикрепите документы"
  description="PDF, DOC, DOCX до 20 МБ"
/>
```

```tsx
// Загрузка аватара (одиночный, image only)
<Dropzone
  onFiles={([f]) => uploadAvatar(f)}
  accept="image/*"
  label="Фото профиля"
  description="PNG, JPG, WEBP до 5 МБ"
/>
```

---

## Импорт

```tsx
import { Combobox, type ComboboxOption } from "@/components/ui/Combobox";
import { DatePicker }   from "@/components/ui/DatePicker";
import { FloatingInput, FloatingTextarea } from "@/components/ui/FloatingInput";
import { Dropzone, type DropzoneFile }    from "@/components/ui/Dropzone";
```
