/**
 * Утилиты для визуального конструктора воронок (Эпик 23).
 */
import type { Automation } from "@/lib/types";
import { ACTION_LABELS } from "@/lib/automationConfig";
import { TRIGGER_LABELS } from "@/lib/automationConfig";

/**
 * Формирует краткое резюме действия автоматизации для отображения в канвасе.
 */
export function getActionSummary(automation: Automation): string {
  const cfg = automation.action_config;
  switch (automation.action_kind) {
    case "tg_notify": {
      const recipient = cfg.recipient;
      if (typeof recipient === "object" && recipient !== null && "kind" in recipient) {
        const r = recipient as { kind: string; value?: unknown };
        if (r.kind === "owner") return "TG: Владельцу";
        if (r.kind === "user" && r.value) return `TG: пользователь #${String(r.value)}`;
        if (r.kind === "chat_id") return `TG: чат`;
      }
      return "TG: уведомление";
    }
    case "create_task": {
      const title = cfg.title;
      return typeof title === "string" && title.trim() ? title : "(без заголовка)";
    }
    case "set_field": {
      const field = cfg.field;
      const value = cfg.value;
      if (typeof field === "string" && value !== undefined) {
        return `${field}: ${String(value)}`;
      }
      return typeof field === "string" ? field : "—";
    }
    case "change_owner": {
      const rule = cfg.rule;
      return typeof rule === "string" ? rule : "по правилу";
    }
    case "webhook": {
      const url = cfg.url;
      if (typeof url === "string") {
        try {
          return new URL(url).hostname;
        } catch {
          return url.slice(0, 30);
        }
      }
      return "webhook";
    }
    case "email": {
      const subject = cfg.subject;
      return typeof subject === "string" ? subject : "письмо";
    }
    case "start_sequence": {
      const sid = cfg.sequence_id;
      return sid !== undefined ? `последовательность #${String(sid)}` : "последовательность";
    }
    case "generate_document": {
      const code = cfg.template_code;
      return typeof code === "string" ? code : "документ";
    }
    case "set_tags": {
      const tags = cfg.tags;
      if (Array.isArray(tags)) return tags.slice(0, 3).join(", ");
      return "теги";
    }
    case "complete_tasks": {
      const scope = cfg.scope;
      return scope === "all" ? "все задачи" : "задачи по типу";
    }
    case "change_stage": {
      const sid = cfg.stage_id;
      return sid !== undefined ? `этап #${String(sid)}` : "смена этапа";
    }
    case "create_deal": {
      const pid = cfg.pipeline_id;
      return pid !== undefined ? `воронка #${String(pid)}` : "новая сделка";
    }
    default:
      return ACTION_LABELS[automation.action_kind] ?? automation.action_kind;
  }
}

/**
 * Формирует label триггера для отображения в карточке.
 */
export function getTriggerLabel(automation: Automation): string {
  return TRIGGER_LABELS[automation.trigger_kind] ?? automation.trigger_kind;
}

/**
 * Фильтрует автоматизации для конкретного этапа.
 */
export function getStageAutomations(automations: Automation[], stageId: number): Automation[] {
  return automations.filter((a) => a.stage_id === stageId);
}

/** Список новых action_kind, которые ещё не поддержаны backend */
export const NEW_ACTION_KINDS: string[] = ["set_tags", "complete_tasks", "change_stage", "create_deal"];

export function isNewActionKind(kind: string): boolean {
  return NEW_ACTION_KINDS.includes(kind);
}
