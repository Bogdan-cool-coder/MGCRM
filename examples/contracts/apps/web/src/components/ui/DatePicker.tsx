"use client";

/**
 * DatePicker — выбор даты через Radix Popover + лёгкий inline-календарь.
 *
 * Значение хранится в ISO-формате "YYYY-MM-DD" или null.
 * Выводит дату в кратком русском формате (date-fns ru-locale).
 * Навигация: кнопки Пред./След. месяц, клик по дню.
 * Клавиатура: Tab → триггер, Enter/Space → открыть/закрыть.
 *
 * USAGE: см. apps/web/docs/forms-usage.md
 */

import * as RadixPopover from "@radix-ui/react-popover";
import clsx from "clsx";
import {
  addMonths,
  eachDayOfInterval,
  endOfMonth,
  format,
  getDay,
  isSameDay,
  isSameMonth,
  isToday,
  startOfMonth,
  subMonths,
} from "date-fns";
import { ru } from "date-fns/locale";
import { useCallback, useId, useMemo, useState } from "react";

// ─── Типы ──────────────────────────────────────────────────────────────────────

interface DatePickerProps {
  /** Подпись поля. */
  label?: string;
  /** Значение в ISO "YYYY-MM-DD" или null/undefined. */
  value: string | null | undefined;
  /** Вызывается при выборе. null — сброс. */
  onChange: (iso: string | null) => void;
  /** Placeholder в инпуте. */
  placeholder?: string;
  /** Поле обязательно. */
  required?: boolean;
  /** Заблокировать контрол. */
  disabled?: boolean;
  /** Разрешить очистку. */
  clearable?: boolean;
  /** Подсказка под полем. */
  hint?: React.ReactNode;
  /** min/max — ISO "YYYY-MM-DD". */
  minDate?: string;
  maxDate?: string;
  /** Доп. className на корневой div. */
  className?: string;
}

// Русские аббревиатуры дней недели — пн с понедельника
const WEEKDAYS = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];

// ─── Компонент ────────────────────────────────────────────────────────────────

export function DatePicker({
  label,
  value,
  onChange,
  placeholder = "Выберите дату",
  required = false,
  disabled = false,
  clearable = false,
  hint,
  minDate,
  maxDate,
  className,
}: DatePickerProps) {
  const [open, setOpen] = useState(false);
  const [touched, setTouched] = useState(false);

  const id = useId();
  const triggerId = `datepicker-${id}`;

  // Текущий «видовой» месяц — по умолчанию месяц выбранного значения или сейчас
  const initialViewDate = useMemo(() => {
    if (value) {
      const d = new Date(value);
      if (!isNaN(d.getTime())) return d;
    }
    return new Date();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const [viewDate, setViewDate] = useState<Date>(initialViewDate);

  const selectedDate = useMemo((): Date | null => {
    if (!value) return null;
    const d = new Date(value);
    return isNaN(d.getTime()) ? null : d;
  }, [value]);

  const minDateObj = useMemo((): Date | null => {
    if (!minDate) return null;
    const d = new Date(minDate);
    return isNaN(d.getTime()) ? null : d;
  }, [minDate]);

  const maxDateObj = useMemo((): Date | null => {
    if (!maxDate) return null;
    const d = new Date(maxDate);
    return isNaN(d.getTime()) ? null : d;
  }, [maxDate]);

  // Форматированный текст для кнопки-триггера
  const displayValue = useMemo(() => {
    if (!selectedDate) return null;
    return format(selectedDate, "d MMMM yyyy", { locale: ru });
  }, [selectedDate]);

  const handleSelect = useCallback(
    (day: Date) => {
      onChange(format(day, "yyyy-MM-dd"));
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

  const prevMonth = useCallback(() => {
    setViewDate((d) => subMonths(d, 1));
  }, []);

  const nextMonth = useCallback(() => {
    setViewDate((d) => addMonths(d, 1));
  }, []);

  // Заголовок месяца: «июнь 2026»
  const monthLabel = format(viewDate, "LLLL yyyy", { locale: ru });

  // Построить сетку дней для текущего вида
  const calendarDays = useMemo((): Array<Date | null> => {
    const firstDay = startOfMonth(viewDate);
    const lastDay = endOfMonth(viewDate);
    const allDays = eachDayOfInterval({ start: firstDay, end: lastDay });

    // getDay(): 0=вс, 1=пн … 6=сб → нам нужно пн=0, вс=6
    const startWeekday = (getDay(firstDay) + 6) % 7; // offset пустых ячеек
    const padding: null[] = Array(startWeekday).fill(null);
    return [...padding, ...allDays];
  }, [viewDate]);

  // Проверить, является ли день недоступным по min/max
  const isDayDisabled = useCallback(
    (day: Date): boolean => {
      if (minDateObj && day < minDateObj) return true;
      if (maxDateObj && day > maxDateObj) return true;
      return false;
    },
    [minDateObj, maxDateObj],
  );

  const invalid = required && touched && !value;

  return (
    <div className={clsx("relative", className)}>
      {label && (
        <label htmlFor={triggerId} className="label">
          {label}
          {required && <span className="text-danger ml-0.5">*</span>}
        </label>
      )}

      <RadixPopover.Root
        open={open}
        onOpenChange={(next) => {
          if (disabled) return;
          if (!next) setTouched(true);
          // Синхронизировать вид с выбранным значением при открытии
          if (next && selectedDate) setViewDate(selectedDate);
          setOpen(next);
        }}
      >
        <RadixPopover.Trigger asChild>
          <button
            id={triggerId}
            type="button"
            disabled={disabled}
            aria-haspopup="dialog"
            aria-expanded={open}
            aria-invalid={invalid}
            aria-required={required}
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
                !displayValue && "text-gray-400 dark:text-gray-500",
              )}
            >
              {displayValue ?? placeholder}
            </span>

            <span className="ml-2 flex shrink-0 items-center gap-1.5">
              {clearable && displayValue && !disabled && (
                <span
                  role="button"
                  tabIndex={-1}
                  aria-label="Очистить дату"
                  onClick={handleClear}
                  className="flex h-4 w-4 items-center justify-center rounded-full hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
                >
                  <i className="bi bi-x text-xs leading-none" />
                </span>
              )}
              <i className="bi bi-calendar3 text-gray-400 text-sm" />
            </span>
          </button>
        </RadixPopover.Trigger>

        <RadixPopover.Portal>
          <RadixPopover.Content
            align="start"
            side="bottom"
            sideOffset={4}
            onOpenAutoFocus={(e) => e.preventDefault()}
            role="dialog"
            aria-label="Выбор даты"
            className={clsx(
              "z-[45] w-72 rounded-xl border border-gray-200 bg-white p-4 shadow-elev-3",
              "dark:bg-gray-800 dark:border-gray-700",
              "data-[state=open]:popover-in data-[state=closed]:popover-out",
              "focus:outline-none",
            )}
          >
            {/* Заголовок: навигация по месяцам */}
            <div className="mb-3 flex items-center justify-between">
              <button
                type="button"
                onClick={prevMonth}
                aria-label="Предыдущий месяц"
                className={clsx(
                  "flex h-7 w-7 items-center justify-center rounded-md",
                  "text-gray-500 hover:bg-gray-100 hover:text-gray-700",
                  "dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200",
                  "transition-colors duration-fast",
                )}
              >
                <i className="bi bi-chevron-left text-xs" />
              </button>

              <span className="text-sm font-semibold capitalize text-gray-800 dark:text-gray-100 tabular-nums">
                {monthLabel}
              </span>

              <button
                type="button"
                onClick={nextMonth}
                aria-label="Следующий месяц"
                className={clsx(
                  "flex h-7 w-7 items-center justify-center rounded-md",
                  "text-gray-500 hover:bg-gray-100 hover:text-gray-700",
                  "dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200",
                  "transition-colors duration-fast",
                )}
              >
                <i className="bi bi-chevron-right text-xs" />
              </button>
            </div>

            {/* Заголовки дней недели */}
            <div className="mb-1 grid grid-cols-7 gap-0.5">
              {WEEKDAYS.map((wd) => (
                <div
                  key={wd}
                  className="flex h-7 items-center justify-center text-xs font-medium text-gray-400 dark:text-gray-500"
                >
                  {wd}
                </div>
              ))}
            </div>

            {/* Сетка дней */}
            <div className="grid grid-cols-7 gap-0.5">
              {calendarDays.map((day, idx) => {
                if (day === null) {
                  return <div key={`empty-${idx}`} aria-hidden="true" />;
                }

                const isSelected = selectedDate ? isSameDay(day, selectedDate) : false;
                const isCurrentDay = isToday(day);
                const isCurrentMonth = isSameMonth(day, viewDate);
                const dayDisabled = isDayDisabled(day);

                return (
                  <button
                    key={day.toISOString()}
                    type="button"
                    disabled={dayDisabled}
                    aria-selected={isSelected}
                    aria-label={format(day, "d MMMM yyyy", { locale: ru })}
                    onClick={() => handleSelect(day)}
                    className={clsx(
                      "flex h-8 w-full items-center justify-center rounded-md text-sm tabular-nums",
                      "transition-colors duration-fast",
                      // Дни текущего месяца
                      isCurrentMonth
                        ? "text-gray-700 dark:text-gray-300"
                        : "text-gray-300 dark:text-gray-600",
                      // Выбранный день
                      isSelected &&
                        "bg-primary text-white font-medium hover:bg-primary-light dark:bg-primary-light dark:hover:bg-primary",
                      // Сегодня (не выбранный)
                      isCurrentDay &&
                        !isSelected &&
                        "font-semibold text-primary dark:text-primary-light ring-1 ring-inset ring-primary/30 dark:ring-primary-light/40",
                      // Hover/active (не выбранный, не disabled)
                      !isSelected &&
                        !dayDisabled &&
                        "hover:bg-gray-100 dark:hover:bg-gray-700 active:bg-gray-200 dark:active:bg-gray-600",
                      // Disabled
                      dayDisabled && "cursor-not-allowed opacity-30",
                    )}
                  >
                    {format(day, "d")}
                  </button>
                );
              })}
            </div>

            {/* Кнопка «Сегодня» */}
            <div className="mt-3 border-t border-gray-100 dark:border-gray-700 pt-2">
              <button
                type="button"
                onClick={() => handleSelect(new Date())}
                disabled={isDayDisabled(new Date())}
                className={clsx(
                  "w-full rounded-md py-1.5 text-sm text-center",
                  "text-primary dark:text-primary-light",
                  "hover:bg-primary/8 dark:hover:bg-primary-light/10",
                  "transition-colors duration-fast",
                  "disabled:cursor-not-allowed disabled:opacity-40",
                )}
              >
                Сегодня
              </button>
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
