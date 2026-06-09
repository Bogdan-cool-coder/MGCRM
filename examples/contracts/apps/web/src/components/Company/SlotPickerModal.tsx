"use client";

import { useMemo, useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { fetcher } from "@/lib/api";
import type { AvailableSlot } from "@/lib/types";

export interface SlotPickerModalProps {
  open: boolean;
  userId: number;
  userName: string;
  onClose: () => void;
  onSlotSelected: (start: string, end: string) => void;
}

const DAY_NAMES = ["Вс", "Пн", "Вт", "Ср", "Чт", "Пт", "Сб"];
const DAY_NAMES_FULL = ["Воскресенье", "Понедельник", "Вторник", "Среда", "Четверг", "Пятница", "Суббота"];

function getWeekStart(offset: number): Date {
  const today = new Date();
  const dow = today.getDay(); // 0=Sun
  const monday = new Date(today);
  monday.setDate(today.getDate() - (dow === 0 ? 6 : dow - 1) + offset * 7);
  monday.setHours(0, 0, 0, 0);
  return monday;
}

function toDateStr(d: Date): string {
  return d.toISOString().slice(0, 10);
}

function formatTime(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" });
}

function formatDayLabel(dateStr: string): string {
  const d = new Date(dateStr + "T00:00:00");
  const dayName = DAY_NAMES_FULL[d.getDay()];
  const formatted = d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
  return `${dayName} ${formatted}`;
}

function formatWeekLabel(start: Date): string {
  const end = new Date(start);
  end.setDate(start.getDate() + 6);
  const fmtS = start.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
  const fmtE = end.toLocaleDateString("ru-RU", { day: "numeric", month: "short", year: "numeric" });
  return `${fmtS} – ${fmtE}`;
}

type SlotsByDay = Record<string, AvailableSlot[]>;

export function SlotPickerModal({ open, userId, userName, onClose, onSlotSelected }: SlotPickerModalProps) {
  const [weekOffset, setWeekOffset] = useState(0);

  const weekStart = useMemo(() => getWeekStart(weekOffset), [weekOffset]);
  const weekEnd = useMemo(() => {
    const d = new Date(weekStart);
    d.setDate(weekStart.getDate() + 6);
    return d;
  }, [weekStart]);

  const from = toDateStr(weekStart);
  const to = toDateStr(weekEnd);

  const { data: slots, error, isLoading } = useSWR<AvailableSlot[]>(
    open ? `/users/${userId}/available-slots?date_from=${from}&date_to=${to}` : null,
    fetcher
  );

  // Generate 7 day strings for the week
  const weekDays = useMemo<string[]>(() => {
    return Array.from({ length: 7 }, (_, i) => {
      const d = new Date(weekStart);
      d.setDate(weekStart.getDate() + i);
      return toDateStr(d);
    });
  }, [weekStart]);

  // Group slots by day
  const slotsByDay = useMemo<SlotsByDay>(() => {
    if (!slots) return {};
    const map: SlotsByDay = {};
    for (const slot of slots) {
      const day = slot.start.slice(0, 10);
      if (!map[day]) map[day] = [];
      map[day].push(slot);
    }
    return map;
  }, [slots]);

  const hasAnySlot = slots && slots.length > 0;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Подобрать слот: ${userName}`}
      width="md"
      footer={
        <button className="btn-ghost" onClick={onClose}>Отмена</button>
      }
    >
      {/* Week navigation */}
      <div className="flex items-center justify-between mb-5">
        <button
          className="btn-ghost text-sm"
          onClick={() => setWeekOffset((w) => w - 1)}
        >
          <i className="bi bi-chevron-left" /> Пред. неделя
        </button>
        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
          {formatWeekLabel(weekStart)}
        </span>
        <button
          className="btn-ghost text-sm"
          onClick={() => setWeekOffset((w) => w + 1)}
        >
          Сл. неделя <i className="bi bi-chevron-right" />
        </button>
      </div>

      {error && (
        <div className="text-danger text-sm">Не удалось загрузить доступные слоты</div>
      )}

      {isLoading && (
        <div className="space-y-4">
          {[1, 2, 3].map((i) => (
            <div key={i}>
              <div className="animate-pulse h-4 w-32 bg-gray-100 dark:bg-gray-700 rounded mb-2" />
              <div className="flex gap-2 flex-wrap">
                {[1, 2, 3].map((j) => (
                  <div key={j} className="animate-pulse h-8 w-28 bg-gray-100 dark:bg-gray-700 rounded" />
                ))}
              </div>
            </div>
          ))}
        </div>
      )}

      {!isLoading && !error && (
        <>
          {!hasAnySlot ? (
            <div className="text-center py-10">
              <i className="bi bi-calendar-x text-3xl text-gray-300 block mb-3" />
              <div className="text-gray-600 dark:text-gray-400 mb-3">На этой неделе слотов нет</div>
              <button
                className="btn-ghost text-sm"
                onClick={() => setWeekOffset((w) => w + 1)}
              >
                Перейти на следующую неделю
              </button>
            </div>
          ) : (
            <div className="space-y-5">
              {weekDays.map((day) => {
                const daySlots = slotsByDay[day];
                const d = new Date(day + "T00:00:00");
                const isWeekend = d.getDay() === 0 || d.getDay() === 6;

                if (isWeekend && !daySlots?.length) {
                  return (
                    <div key={day}>
                      <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {formatDayLabel(day)}
                      </div>
                      <div className="text-sm text-gray-400">— нерабочий день</div>
                    </div>
                  );
                }

                if (!daySlots || daySlots.length === 0) {
                  // Check if there are any slots in the whole week to decide if "busy" or "no schedule"
                  return (
                    <div key={day}>
                      <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {formatDayLabel(day)}
                      </div>
                      <div className="text-sm text-gray-400">— слотов нет</div>
                    </div>
                  );
                }

                return (
                  <div key={day}>
                    <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      {formatDayLabel(day)}
                    </div>
                    <div className="flex flex-wrap gap-2">
                      {daySlots.map((slot) => (
                        <button
                          key={slot.start}
                          className="text-sm bg-primary/10 text-primary hover:bg-primary/20 border border-primary/30 rounded px-3 py-1.5 transition-colors"
                          onClick={() => {
                            onSlotSelected(slot.start, slot.end);
                            onClose();
                          }}
                        >
                          {formatTime(slot.start)}–{formatTime(slot.end)}
                        </button>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}
    </Modal>
  );
}
