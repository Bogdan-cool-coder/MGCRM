"use client";

/**
 * DateTimePicker — выбор даты + времени через Radix Popover.
 *
 * Значение хранится в формате "YYYY-MM-DDTHH:mm" (datetime-local compatible).
 * Radix Popover рендерится в Portal — не ограничен overflow модалки/дравера.
 * Collision-aware: автоматически меняет сторону при нехватке места.
 *
 * USAGE:
 *   <DateTimePicker
 *     value={form.due_at}            // "2026-06-10T14:30" или ""
 *     onChange={(v) => setField("due_at", v)}
 *     placeholder="Выберите дедлайн"
 *   />
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

interface DateTimePickerProps {
  /** Значение в формате "YYYY-MM-DDTHH:mm" или "" */
  value: string;
  /** Вызывается при выборе даты/времени. Возвращает "YYYY-MM-DDTHH:mm" */
  onChange: (val: string) => void;
  /** Placeholder в кнопке-триггере */
  placeholder?: string;
  /** Заблокировать контрол */
  disabled?: boolean;
  /** Доп. className на корневой div */
  className?: string;
}

// Русские аббревиатуры дней недели — с понедельника
const WEEKDAYS = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];

// ─── Хелперы ──────────────────────────────────────────────────────────────────

/** Разобрать "YYYY-MM-DDTHH:mm" → { dateIso, hours, minutes } */
function parseValue(val: string): { dateIso: string; hours: number; minutes: number } | null {
  if (!val) return null;
  const [datePart, timePart] = val.split("T");
  if (!datePart) return null;
  const [hStr, mStr] = (timePart ?? "00:00").split(":");
  const hours = parseInt(hStr ?? "0", 10);
  const minutes = parseInt(mStr ?? "0", 10);
  if (isNaN(hours) || isNaN(minutes)) return null;
  return { dateIso: datePart, hours, minutes };
}

/** Собрать "YYYY-MM-DDTHH:mm" из частей */
function buildValue(dateIso: string, hours: number, minutes: number): string {
  const hh = String(hours).padStart(2, "0");
  const mm = String(minutes).padStart(2, "0");
  return `${dateIso}T${hh}:${mm}`;
}

// ─── Компонент ────────────────────────────────────────────────────────────────

export function DateTimePicker({
  value,
  onChange,
  placeholder = "Выберите дату и время",
  disabled = false,
  className,
}: DateTimePickerProps) {
  const [open, setOpen] = useState(false);
  const id = useId();
  const triggerId = `datetimepicker-${id}`;

  // Разбор текущего значения
  const parsed = useMemo(() => parseValue(value), [value]);

  // Вид месяца в календаре
  const [viewDate, setViewDate] = useState<Date>(() => {
    if (parsed) {
      const d = new Date(parsed.dateIso);
      if (!isNaN(d.getTime())) return d;
    }
    return new Date();
  });

  // Локальные состояния часов/минут (синхронизируются с value, но редактируются локально)
  const [localHours, setLocalHours] = useState<number>(() => parsed?.hours ?? 9);
  const [localMinutes, setLocalMinutes] = useState<number>(() => parsed?.minutes ?? 0);

  const selectedDate = useMemo((): Date | null => {
    if (!parsed) return null;
    const d = new Date(parsed.dateIso);
    return isNaN(d.getTime()) ? null : d;
  }, [parsed]);

  // Текст для триггера
  const displayValue = useMemo(() => {
    if (!parsed || !selectedDate) return null;
    const datePart = format(selectedDate, "d MMMM yyyy", { locale: ru });
    const hh = String(parsed.hours).padStart(2, "0");
    const mm = String(parsed.minutes).padStart(2, "0");
    return `${datePart}, ${hh}:${mm}`;
  }, [parsed, selectedDate]);

  // Сетка дней
  const calendarDays = useMemo((): Array<Date | null> => {
    const firstDay = startOfMonth(viewDate);
    const lastDay = endOfMonth(viewDate);
    const allDays = eachDayOfInterval({ start: firstDay, end: lastDay });
    const startWeekday = (getDay(firstDay) + 6) % 7;
    const padding: null[] = Array(startWeekday).fill(null);
    return [...padding, ...allDays];
  }, [viewDate]);

  const prevMonth = useCallback(() => setViewDate((d) => subMonths(d, 1)), []);

  const monthLabel = format(viewDate, "LLLL yyyy", { locale: ru });

  // Выбор дня — сохраняет время из localHours/localMinutes
  const handleSelectDay = useCallback(
    (day: Date) => {
      const dateIso = format(day, "yyyy-MM-dd");
      onChange(buildValue(dateIso, localHours, localMinutes));
      // Не закрываем — пользователь должен ещё выбрать время
    },
    [onChange, localHours, localMinutes],
  );

  // Изменение часов
  const handleHoursChange = useCallback(
    (raw: string) => {
      const h = Math.max(0, Math.min(23, parseInt(raw, 10) || 0));
      setLocalHours(h);
      if (parsed) {
        onChange(buildValue(parsed.dateIso, h, localMinutes));
      }
    },
    [parsed, localMinutes, onChange],
  );

  // Изменение минут
  const handleMinutesChange = useCallback(
    (raw: string) => {
      const m = Math.max(0, Math.min(59, parseInt(raw, 10) || 0));
      setLocalMinutes(m);
      if (parsed) {
        onChange(buildValue(parsed.dateIso, localHours, m));
      }
    },
    [parsed, localHours, onChange],
  );

  // Preset-кнопки времени
  const timePresets = [
    { label: "09:00", h: 9, m: 0 },
    { label: "12:00", h: 12, m: 0 },
    { label: "15:00", h: 15, m: 0 },
    { label: "18:00", h: 18, m: 0 },
  ];

  const handlePreset = useCallback(
    (h: number, m: number) => {
      setLocalHours(h);
      setLocalMinutes(m);
      if (parsed) {
        onChange(buildValue(parsed.dateIso, h, m));
      }
    },
    [parsed, onChange],
  );

  // Сегодня
  const handleToday = useCallback(() => {
    const now = new Date();
    const dateIso = format(now, "yyyy-MM-dd");
    const h = localHours;
    const m = localMinutes;
    onChange(buildValue(dateIso, h, m));
    setViewDate(now);
  }, [localHours, localMinutes, onChange]);

  // Очистить
  const handleClear = useCallback(
    (e: React.MouseEvent) => {
      e.stopPropagation();
      onChange("");
    },
    [onChange],
  );

  const handleOpenChange = useCallback(
    (next: boolean) => {
      if (disabled) return;
      if (next && selectedDate) {
        setViewDate(selectedDate);
        setLocalHours(parsed?.hours ?? 9);
        setLocalMinutes(parsed?.minutes ?? 0);
      }
      setOpen(next);
    },
    [disabled, selectedDate, parsed],
  );

  return (
    <div className={clsx("relative", className)}>
      <RadixPopover.Root open={open} onOpenChange={handleOpenChange}>
        <RadixPopover.Trigger asChild>
          <button
            id={triggerId}
            type="button"
            disabled={disabled}
            aria-haspopup="dialog"
            aria-expanded={open}
            className={clsx(
              "input flex items-center justify-between text-left",
              "cursor-pointer select-none",
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
              {displayValue && !disabled && (
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
              <i className="bi bi-calendar3-event text-gray-400 text-sm" />
            </span>
          </button>
        </RadixPopover.Trigger>

        <RadixPopover.Portal>
          <RadixPopover.Content
            align="start"
            side="bottom"
            sideOffset={4}
            avoidCollisions
            collisionPadding={12}
            onOpenAutoFocus={(e) => e.preventDefault()}
            role="dialog"
            aria-label="Выбор даты и времени"
            className={clsx(
              "z-[9999] w-72 rounded-xl border border-gray-200 bg-white p-4 shadow-elev-3",
              "dark:bg-gray-800 dark:border-gray-700",
              "data-[state=open]:popover-in data-[state=closed]:popover-out",
              "focus:outline-none",
            )}
          >
            {/* Навигация по месяцам */}
            <div className="mb-3 flex items-center justify-between">
              <button
                type="button"
                onClick={prevMonth}
                aria-label="Предыдущий месяц"
                className={clsx(
                  "flex h-7 w-7 items-center justify-center rounded-md",
                  "text-gray-500 hover:bg-gray-100 hover:text-gray-700",
                  "dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200",
                  "transition-colors",
                )}
              >
                <i className="bi bi-chevron-left text-xs" />
              </button>

              <span className="text-sm font-semibold capitalize text-gray-800 dark:text-gray-100 tabular-nums">
                {monthLabel}
              </span>

              <button
                type="button"
                onClick={() => setViewDate((d) => addMonths(d, 1))}
                aria-label="Следующий месяц"
                className={clsx(
                  "flex h-7 w-7 items-center justify-center rounded-md",
                  "text-gray-500 hover:bg-gray-100 hover:text-gray-700",
                  "dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200",
                  "transition-colors",
                )}
              >
                <i className="bi bi-chevron-right text-xs" />
              </button>
            </div>

            {/* Дни недели */}
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

                return (
                  <button
                    key={day.toISOString()}
                    type="button"
                    aria-selected={isSelected}
                    aria-label={format(day, "d MMMM yyyy", { locale: ru })}
                    onClick={() => handleSelectDay(day)}
                    className={clsx(
                      "flex h-8 w-full items-center justify-center rounded-md text-sm tabular-nums transition-colors",
                      isCurrentMonth
                        ? "text-gray-700 dark:text-gray-300"
                        : "text-gray-300 dark:text-gray-600",
                      isSelected &&
                        "bg-primary text-white font-medium hover:bg-primary-light dark:bg-primary-light dark:hover:bg-primary",
                      isCurrentDay &&
                        !isSelected &&
                        "font-semibold text-primary dark:text-primary-light ring-1 ring-inset ring-primary/30 dark:ring-primary-light/40",
                      !isSelected &&
                        "hover:bg-gray-100 dark:hover:bg-gray-700 active:bg-gray-200 dark:active:bg-gray-600",
                    )}
                  >
                    {format(day, "d")}
                  </button>
                );
              })}
            </div>

            {/* Разделитель */}
            <div className="my-3 border-t border-gray-100 dark:border-gray-700" />

            {/* Выбор времени */}
            <div className="space-y-2">
              <p className="text-xs font-medium text-gray-500 dark:text-gray-400">Время</p>

              {/* Инпуты часов:минут */}
              <div className="flex items-center gap-1.5">
                <input
                  type="number"
                  min={0}
                  max={23}
                  value={String(localHours).padStart(2, "0")}
                  onChange={(e) => handleHoursChange(e.target.value)}
                  className={clsx(
                    "w-12 rounded-md border border-gray-200 dark:border-gray-600",
                    "bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100",
                    "px-2 py-1 text-sm text-center tabular-nums",
                    "focus:outline-none focus:ring-2 focus:ring-primary/30",
                  )}
                  aria-label="Часы"
                />
                <span className="text-gray-500 dark:text-gray-400 font-medium">:</span>
                <input
                  type="number"
                  min={0}
                  max={59}
                  step={5}
                  value={String(localMinutes).padStart(2, "0")}
                  onChange={(e) => handleMinutesChange(e.target.value)}
                  className={clsx(
                    "w-12 rounded-md border border-gray-200 dark:border-gray-600",
                    "bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100",
                    "px-2 py-1 text-sm text-center tabular-nums",
                    "focus:outline-none focus:ring-2 focus:ring-primary/30",
                  )}
                  aria-label="Минуты"
                />
              </div>

              {/* Пресеты времени */}
              <div className="flex flex-wrap gap-1">
                {timePresets.map((p) => {
                  const active =
                    parsed !== null && localHours === p.h && localMinutes === p.m;
                  return (
                    <button
                      key={p.label}
                      type="button"
                      onClick={() => handlePreset(p.h, p.m)}
                      className={clsx(
                        "rounded px-2 py-0.5 text-xs transition-colors",
                        active
                          ? "bg-primary text-white"
                          : "bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600",
                      )}
                    >
                      {p.label}
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Кнопки «Сегодня» и «Готово» */}
            <div className="mt-3 border-t border-gray-100 dark:border-gray-700 pt-2 flex gap-2">
              <button
                type="button"
                onClick={handleToday}
                className={clsx(
                  "flex-1 rounded-md py-1.5 text-sm text-center",
                  "text-primary dark:text-primary-light",
                  "hover:bg-primary/8 dark:hover:bg-primary-light/10",
                  "transition-colors",
                )}
              >
                Сегодня
              </button>
              {selectedDate && (
                <button
                  type="button"
                  onClick={() => setOpen(false)}
                  className={clsx(
                    "flex-1 rounded-md py-1.5 text-sm text-center",
                    "bg-primary text-white hover:bg-primary-light",
                    "transition-colors",
                  )}
                >
                  Готово
                </button>
              )}
            </div>
          </RadixPopover.Content>
        </RadixPopover.Portal>
      </RadixPopover.Root>
    </div>
  );
}
