"use client";

/**
 * Combobox<V> — select с поиском на Radix Popover.
 *
 * Используй вместо нативного <select> когда список длинный (справочники,
 * пользователи, контрагенты, продукты).
 *
 * USAGE: см. apps/web/docs/forms-usage.md
 *
 * Generic по типу значения V (string | number | ...); опции type ComboboxOption<V>.
 * Поддержка async-загрузки через isLoading.
 * Полная a11y: aria-expanded, aria-activedescendant, Esc/Enter/↑↓ стрелки.
 */

import * as RadixPopover from "@radix-ui/react-popover";
import clsx from "clsx";
import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
} from "react";

// ─── Типы ──────────────────────────────────────────────────────────────────────

export interface ComboboxOption<V> {
  /** Машинное значение (строка/число/любой примитив). */
  value: V;
  /** Отображаемый текст в списке и кнопке-триггере. */
  label: string;
  /** Опциональный вспомогательный текст справа. */
  hint?: string;
  /** Сделать опцию недоступной для выбора. */
  disabled?: boolean;
}

interface ComboboxProps<V> {
  /** Подпись поля — выводится как <label>. */
  label?: string;
  /** Текущее выбранное значение. null / undefined = ничего не выбрано. */
  value: V | null | undefined;
  /** Вызывается при выборе опции. null — сброс (если clearable=true). */
  onChange: (value: V | null) => void;
  /** Список опций. */
  options: ComboboxOption<V>[];
  /** Placeholder когда ничего не выбрано. */
  placeholder?: string;
  /** Placeholder поля поиска внутри дропдауна. */
  searchPlaceholder?: string;
  /** Поле обязательно. */
  required?: boolean;
  /** Заблокировать весь контрол. */
  disabled?: boolean;
  /** Показать spinner загрузки (для async-вариантов). */
  isLoading?: boolean;
  /** Разрешить сбросить выбор (отображает кнопку ×). */
  clearable?: boolean;
  /** Подсказка под полем. */
  hint?: React.ReactNode;
  /** Дополнительные className на корневой div. */
  className?: string;
  /** aria-label для кнопки-триггера (если нет label). */
  ariaLabel?: string;
}

// ─── Компонент ────────────────────────────────────────────────────────────────

export function Combobox<V extends string | number>({
  label,
  value,
  onChange,
  options,
  placeholder = "Выберите значение…",
  searchPlaceholder = "Поиск…",
  required = false,
  disabled = false,
  isLoading = false,
  clearable = false,
  hint,
  className,
  ariaLabel,
}: ComboboxProps<V>) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [touched, setTouched] = useState(false);
  const [activeIndex, setActiveIndex] = useState<number>(-1);

  const id = useId();
  const triggerId = `combobox-trigger-${id}`;
  const listId = `combobox-list-${id}`;
  const searchRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  const selectedOption = useMemo(
    () => options.find((o) => o.value === value) ?? null,
    [options, value],
  );

  const filtered = useMemo(() => {
    if (!query.trim()) return options;
    const q = query.toLowerCase();
    return options.filter(
      (o) =>
        o.label.toLowerCase().includes(q) ||
        (o.hint?.toLowerCase().includes(q) ?? false),
    );
  }, [options, query]);

  // Сбрасываем activeIndex при изменении filtered
  useEffect(() => {
    setActiveIndex(-1);
  }, [query]);

  // Фокус на поиск при открытии
  useEffect(() => {
    if (open) {
      // Небольшая задержка — Radix анимирует появление
      const timer = setTimeout(() => searchRef.current?.focus(), 50);
      return () => clearTimeout(timer);
    } else {
      setQuery("");
      setActiveIndex(-1);
    }
  }, [open]);

  const handleSelect = useCallback(
    (option: ComboboxOption<V>) => {
      if (option.disabled) return;
      onChange(option.value);
      setOpen(false);
      setTouched(true);
    },
    [onChange],
  );

  const handleClear = useCallback(
    (e: React.MouseEvent) => {
      e.stopPropagation();
      onChange(null);
      setTouched(true);
    },
    [onChange],
  );

  // Прокрутка активного элемента в область видимости
  const scrollToActive = useCallback((idx: number) => {
    if (!listRef.current) return;
    const items = listRef.current.querySelectorAll<HTMLElement>("[data-option]");
    items[idx]?.scrollIntoView({ block: "nearest" });
  }, []);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      const enabledOptions = filtered.filter((o) => !o.disabled);
      const currentIdx = activeIndex >= 0 ? activeIndex : -1;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        const nextIdx = Math.min(currentIdx + 1, enabledOptions.length - 1);
        // Маппинг обратно в позицию filtered
        const filteredIdx = filtered.indexOf(enabledOptions[nextIdx]);
        setActiveIndex(filteredIdx);
        scrollToActive(filteredIdx);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        const prevIdx = Math.max(currentIdx - 1, 0);
        const filteredIdx = filtered.indexOf(enabledOptions[prevIdx]);
        setActiveIndex(filteredIdx);
        scrollToActive(filteredIdx);
      } else if (e.key === "Enter") {
        e.preventDefault();
        if (activeIndex >= 0 && activeIndex < filtered.length) {
          handleSelect(filtered[activeIndex]);
        } else if (filtered.length === 1 && !filtered[0].disabled) {
          handleSelect(filtered[0]);
        }
      } else if (e.key === "Escape") {
        e.preventDefault();
        setOpen(false);
      }
    },
    [activeIndex, filtered, handleSelect, scrollToActive],
  );

  const invalid = required && touched && (value === null || value === undefined || value === "");
  const activeOptionId =
    activeIndex >= 0 ? `${listId}-opt-${activeIndex}` : undefined;

  return (
    <div className={clsx("relative", className)}>
      {label && (
        <label htmlFor={triggerId} className="label">
          {label}
          {required && <span className="text-danger ml-0.5">*</span>}
        </label>
      )}

      <RadixPopover.Root open={open} onOpenChange={(next) => {
        if (disabled) return;
        if (!next) setTouched(true);
        setOpen(next);
      }}>
        <RadixPopover.Trigger asChild>
          <button
            id={triggerId}
            type="button"
            disabled={disabled}
            aria-expanded={open}
            aria-haspopup="listbox"
            aria-controls={listId}
            aria-activedescendant={activeOptionId}
            aria-label={ariaLabel}
            aria-required={required}
            aria-invalid={invalid}
            className={clsx(
              "input flex items-center justify-between text-left",
              "cursor-pointer select-none",
              invalid && "border-danger focus:border-danger focus:ring-danger/20",
              disabled && "cursor-not-allowed opacity-60",
            )}
          >
            <span
              className={clsx(
                "flex-1 truncate",
                !selectedOption && "text-gray-400 dark:text-gray-500",
              )}
            >
              {isLoading ? (
                <span className="text-gray-400">Загрузка…</span>
              ) : selectedOption ? (
                selectedOption.label
              ) : (
                placeholder
              )}
            </span>

            <span className="ml-2 flex shrink-0 items-center gap-1">
              {isLoading && (
                <SpinnerIcon />
              )}
              {clearable && selectedOption && !disabled && !isLoading && (
                <span
                  role="button"
                  tabIndex={-1}
                  aria-label="Очистить"
                  onClick={handleClear}
                  className="flex h-4 w-4 items-center justify-center rounded-full hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
                >
                  <i className="bi bi-x text-xs leading-none" />
                </span>
              )}
              <i
                className={clsx(
                  "bi bi-chevron-down text-xs text-gray-500 transition-transform duration-fast ease-standard",
                  open && "rotate-180",
                )}
              />
            </span>
          </button>
        </RadixPopover.Trigger>

        <RadixPopover.Portal>
          <RadixPopover.Content
            align="start"
            side="bottom"
            sideOffset={4}
            onOpenAutoFocus={(e) => e.preventDefault()}
            // Ширина = ширина триггера
            style={{ width: "var(--radix-popover-trigger-width)" }}
            className={clsx(
              "z-[45] rounded-lg border border-gray-200 bg-white shadow-elev-3",
              "dark:bg-gray-800 dark:border-gray-700",
              "data-[state=open]:popover-in data-[state=closed]:popover-out",
              "overflow-hidden",
            )}
          >
            {/* Поле поиска */}
            <div className="border-b border-gray-100 dark:border-gray-700 px-3 py-2">
              <div className="relative">
                <i className="bi bi-search absolute left-2 top-1/2 -translate-y-1/2 text-xs text-gray-400" />
                <input
                  ref={searchRef}
                  type="text"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder={searchPlaceholder}
                  aria-label="Поиск по списку"
                  aria-controls={listId}
                  aria-activedescendant={activeOptionId}
                  className={clsx(
                    "w-full rounded-md border border-gray-200 bg-gray-50",
                    "pl-7 pr-3 py-1.5 text-sm",
                    "focus:outline-none focus:border-primary focus:ring-2 focus:ring-primary/15",
                    "dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100",
                    "dark:placeholder-gray-400 dark:focus:border-primary-light",
                  )}
                />
              </div>
            </div>

            {/* Список опций */}
            <div
              id={listId}
              role="listbox"
              ref={listRef}
              className="max-h-64 overflow-y-auto py-1"
            >
              {isLoading ? (
                <LoadingRows />
              ) : filtered.length === 0 ? (
                <EmptyRow />
              ) : (
                filtered.map((option, idx) => (
                  <OptionRow
                    key={String(option.value)}
                    id={`${listId}-opt-${idx}`}
                    option={option}
                    isSelected={option.value === value}
                    isActive={idx === activeIndex}
                    onSelect={handleSelect}
                    onHover={() => setActiveIndex(idx)}
                  />
                ))
              )}
            </div>
          </RadixPopover.Content>
        </RadixPopover.Portal>
      </RadixPopover.Root>

      {invalid && (
        <p className="mt-1 text-xs text-danger">Обязательное поле</p>
      )}
      {hint && !invalid && (
        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{hint}</p>
      )}
    </div>
  );
}

// ─── Вспомогательные подкомпоненты ────────────────────────────────────────────

interface OptionRowProps<V> {
  id: string;
  option: ComboboxOption<V>;
  isSelected: boolean;
  isActive: boolean;
  onSelect: (option: ComboboxOption<V>) => void;
  onHover: () => void;
}

function OptionRow<V extends string | number>({
  id,
  option,
  isSelected,
  isActive,
  onSelect,
  onHover,
}: OptionRowProps<V>) {
  return (
    <div
      id={id}
      role="option"
      aria-selected={isSelected}
      aria-disabled={option.disabled}
      data-option
      onMouseDown={(e) => {
        // mouseDown чтобы не потерять фокус раньше click
        e.preventDefault();
        if (!option.disabled) onSelect(option);
      }}
      onMouseEnter={onHover}
      className={clsx(
        "flex cursor-pointer select-none items-center justify-between px-3 py-2 text-sm",
        "transition-colors duration-fast",
        isActive && !isSelected && "bg-gray-50 dark:bg-gray-700/60",
        isSelected
          ? "bg-primary/8 text-primary dark:bg-primary-light/15 dark:text-primary-light font-medium"
          : "text-gray-700 dark:text-gray-300",
        option.disabled && "pointer-events-none opacity-40",
      )}
    >
      <span className="flex-1 truncate">{option.label}</span>
      <span className="ml-3 flex shrink-0 items-center gap-2">
        {option.hint && (
          <span className="text-xs text-gray-400 dark:text-gray-500">{option.hint}</span>
        )}
        {isSelected && (
          <i className="bi bi-check2 text-primary dark:text-primary-light" />
        )}
      </span>
    </div>
  );
}

function EmptyRow() {
  return (
    <div className="flex flex-col items-center gap-1.5 px-3 py-6 text-center">
      <i className="bi bi-search text-xl text-gray-300 dark:text-gray-600" />
      <span className="text-sm text-gray-400 dark:text-gray-500">Ничего не найдено</span>
    </div>
  );
}

function LoadingRows() {
  return (
    <div className="space-y-1 px-2 py-2">
      {[65, 80, 55].map((w) => (
        <div
          key={w}
          className="h-8 animate-pulse rounded-md bg-gray-100 dark:bg-gray-700"
          style={{ width: `${w}%` }}
        />
      ))}
    </div>
  );
}

function SpinnerIcon() {
  return (
    <svg
      className="h-3.5 w-3.5 animate-spin text-gray-400"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <circle
        className="opacity-25"
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        strokeWidth="4"
      />
      <path
        className="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
      />
    </svg>
  );
}
