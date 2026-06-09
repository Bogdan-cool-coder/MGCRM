"use client";

import { useState } from "react";

interface FtmFieldValues {
  is_first_time_meeting: boolean;
  ftm_decision_maker_attended: boolean;
  ftm_presentation_shown: boolean;
  ftm_report_url: string;
  ftm_announced_tg: boolean;
}

interface Props {
  values: FtmFieldValues;
  onChange: (v: Partial<FtmFieldValues>) => void;
}

export function FtmFields({ values, onChange }: Props) {
  return (
    <div className="space-y-3">
      <label className="flex items-center gap-2 cursor-pointer">
        <input
          type="checkbox"
          className="rounded border-gray-300 text-primary focus:ring-primary"
          checked={values.is_first_time_meeting}
          onChange={(e) => onChange({ is_first_time_meeting: e.target.checked })}
        />
        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
          Это первая встреча с этим клиентом
        </span>
      </label>

      {values.is_first_time_meeting && (
        <div className="pl-6 border-l-2 border-primary/30 space-y-3 transition-all">
          <div className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
            Детали FTM
          </div>

          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              className="rounded border-gray-300 text-primary focus:ring-primary"
              checked={values.ftm_decision_maker_attended}
              onChange={(e) => onChange({ ftm_decision_maker_attended: e.target.checked })}
            />
            <span className="text-sm text-gray-700 dark:text-gray-300">
              Присутствовал ЛПР (лицо, принимающее решение)
            </span>
          </label>

          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              className="rounded border-gray-300 text-primary focus:ring-primary"
              checked={values.ftm_presentation_shown}
              onChange={(e) => onChange({ ftm_presentation_shown: e.target.checked })}
            />
            <span className="text-sm text-gray-700 dark:text-gray-300">
              Показана презентация системы
            </span>
          </label>

          <div>
            <label className="label">Ссылка на отчёт о встрече</label>
            <input
              type="url"
              className="input"
              placeholder="https://..."
              value={values.ftm_report_url}
              onChange={(e) => onChange({ ftm_report_url: e.target.value })}
            />
          </div>

          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              className="rounded border-gray-300 text-primary focus:ring-primary"
              checked={values.ftm_announced_tg}
              onChange={(e) => onChange({ ftm_announced_tg: e.target.checked })}
            />
            <span className="text-sm text-gray-700 dark:text-gray-300">
              Объявлено в Telegram (MACRO Global Sales)
            </span>
          </label>
        </div>
      )}
    </div>
  );
}

export type { FtmFieldValues };
