"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { AiChatMessage, AiStreamingDots } from "./AiChatMessage";
import { AiActionCard } from "./AiActionCard";
import { AiQuickChips } from "./AiQuickChips";
import { BlurFade } from "@/components/magicui/BlurFade";
import { EmptyState } from "@/components/EmptyState";
import type {
  AIChatMessage,
  AIAssistantHistoryItem,
  AIAssistantMessageResponse,
  AIAssistantConfirmResponse,
} from "@/lib/types";

interface Props {
  open: boolean;
  onClose: () => void;
}

const STORAGE_KEY = "crm-ai-assistant-messages";

/** Маршруты детальных страниц сущностей (для навигации после создания). */
const ENTITY_ROUTES: Record<string, (id: number) => string> = {
  task: (id) => `/tasks/${id}`,
  deal: (id) => `/deals/${id}`,
  contract: (id) => `/contracts/${id}`,
};

function extractDetail(detail: unknown): string | null {
  if (typeof detail === "string") return detail;
  if (detail && typeof detail === "object" && "detail" in detail) {
    const d = (detail as { detail: unknown }).detail;
    if (typeof d === "string") return d;
  }
  return null;
}

export function AiAssistantDrawer({ open, onClose }: Props) {
  const router = useRouter();
  const [messages, setMessages] = useState<AIChatMessage[]>([]);
  const [input, setInput] = useState("");
  const [busy, setBusy] = useState(false);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const scrollToBottom = useCallback(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, []);

  useEffect(() => {
    if (open) {
      const stored = sessionStorage.getItem(STORAGE_KEY);
      if (stored) {
        try {
          setMessages(JSON.parse(stored) as AIChatMessage[]);
        } catch {
          /* ignore corrupt cache */
        }
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    scrollToBottom();
  }, [messages, busy, scrollToBottom]);

  const persist = useCallback((next: AIChatMessage[]) => {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    } catch {
      /* ignore quota */
    }
  }, []);

  function handleNewSession() {
    setMessages([]);
    setError(null);
    sessionStorage.removeItem(STORAGE_KEY);
  }

  /** Маппинг сохранённых сообщений в anthropic-native history. */
  function buildHistory(msgs: AIChatMessage[]): AIAssistantHistoryItem[] {
    return msgs
      .filter((m) => m.content.trim().length > 0)
      .map((m) => ({ role: m.role, content: m.content }));
  }

  async function sendMessage(text: string) {
    const trimmed = text.trim();
    if (!trimmed || busy) return;
    setInput("");
    setError(null);

    // Сбросить авто-высоту textarea
    if (textareaRef.current) {
      textareaRef.current.style.height = "auto";
    }

    const userMsg: AIChatMessage = {
      id: Date.now(),
      role: "user",
      content: trimmed,
      created_at: new Date().toISOString(),
    };

    // История из предыдущих ходов (до текущего сообщения).
    const history = buildHistory(messages);

    const withUser = [...messages, userMsg];
    setMessages(withUser);
    persist(withUser);
    setBusy(true);

    try {
      const res = await api<AIAssistantMessageResponse>("/api/ai/assistant/message", {
        method: "POST",
        body: { message: trimmed, history },
      });

      const assistantMsg: AIChatMessage = {
        id: Date.now() + 1,
        role: "assistant",
        content: res.assistant_text ?? "",
        created_at: new Date().toISOString(),
        proposed_action: res.proposed_action ?? null,
        action_status: res.proposed_action ? "pending" : null,
      };

      const next = [...withUser, assistantMsg];
      setMessages(next);
      persist(next);
    } catch (e) {
      if (e instanceof ApiError && e.status === 503) {
        setError(
          extractDetail(e.detail) ??
            "AI-ассистент недоступен (не настроен ANTHROPIC_API_KEY).",
        );
      } else {
        setError("Не удалось получить ответ. Попробуй ещё раз.");
      }
    } finally {
      setBusy(false);
    }
  }

  async function confirmAction(msg: AIChatMessage) {
    if (!msg.proposed_action || confirmingId !== null) return;
    setConfirmingId(msg.id);
    setError(null);

    try {
      const res = await api<AIAssistantConfirmResponse>("/api/ai/assistant/confirm", {
        method: "POST",
        body: { type: msg.proposed_action.type, args: msg.proposed_action.args },
      });

      // Определяем рабочую ссылку: бэкенд даёт link, но подстрахуемся
      // от отсутствующих маршрутов через известный реестр.
      const routeBuilder = ENTITY_ROUTES[res.entity_type];
      const link = routeBuilder ? routeBuilder(res.entity_id) : res.link;

      const resultMsg: AIChatMessage = {
        id: Date.now(),
        role: "assistant",
        content: res.message,
        created_at: new Date().toISOString(),
        link,
      };

      setMessages((prev) => {
        const updated = prev.map((m) =>
          m.id === msg.id ? { ...m, action_status: "confirmed" as const } : m,
        );
        const next = [...updated, resultMsg];
        persist(next);
        return next;
      });
    } catch (e) {
      if (e instanceof ApiError && e.status === 503) {
        setError(
          extractDetail(e.detail) ??
            "AI-ассистент недоступен (не настроен ANTHROPIC_API_KEY).",
        );
      } else {
        setError(extractDetail(e instanceof ApiError ? e.detail : null) ?? "Не удалось создать. Попробуй ещё раз.");
      }
    } finally {
      setConfirmingId(null);
    }
  }

  function cancelAction(msg: AIChatMessage) {
    setMessages((prev) => {
      const next = prev.map((m) =>
        m.id === msg.id ? { ...m, action_status: "cancelled" as const } : m,
      );
      persist(next);
      return next;
    });
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      sendMessage(input);
    }
  }

  function handleNavigate(link: string) {
    router.push(link);
    onClose();
  }

  /** Авто-рост textarea при вводе */
  function handleTextareaInput(e: React.FormEvent<HTMLTextAreaElement>) {
    const t = e.currentTarget;
    t.style.height = "auto";
    t.style.height = `${t.scrollHeight}px`;
  }

  return (
    <>
      {/* Overlay */}
      {open && <div className="fixed inset-0 bg-black/20 z-30" onClick={onClose} />}

      {/* Drawer */}
      <div
        className={[
          "fixed right-0 top-0 bottom-0 w-96 max-w-full z-40 flex flex-col",
          "bg-white dark:bg-gray-900",
          "border-l border-gray-200/60 dark:border-gray-700",
          "shadow-elev-4",
          "transition-transform duration-300",
          open ? "translate-x-0" : "translate-x-full",
        ].join(" ")}
      >
        {/* Декоративный градиент сверху — aria-hidden, чисто визуальный */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute top-0 left-0 right-0 h-32 bg-gradient-to-b from-primary/[0.04] to-transparent dark:from-primary/[0.08] z-0"
        />

        {/* Header */}
        <div className="relative z-10 flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700 sticky top-0 bg-white/90 dark:bg-gray-900/90 backdrop-blur-sm">
          <div className="flex items-center gap-2">
            {/* Иконка с пульсирующим ореолом — только motion-safe */}
            <span className="relative inline-flex items-center justify-center">
              <i className="bi bi-stars text-primary-light relative z-10" />
              <span
                aria-hidden="true"
                className="absolute inset-0 rounded-full opacity-20 bg-primary-light motion-safe:animate-ping motion-reduce:hidden"
              />
            </span>
            <span className="text-sm font-semibold">AI-ассистент</span>
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={handleNewSession}
              className="btn-ghost text-xs"
              title="Новый чат"
              disabled={busy}
            >
              <i className="bi bi-plus-lg mr-1" />
              Новый чат
            </button>
            <button onClick={onClose} className="btn-ghost p-1">
              <i className="bi bi-x-lg" />
            </button>
          </div>
        </div>

        {/* Messages */}
        <div className="relative z-10 flex-1 overflow-y-auto p-4 space-y-3">
          {/* Empty state чата */}
          {messages.length === 0 && !busy && (
            <EmptyState
              icon="bi-stars"
              title="Привет! Я AI-ассистент"
              description="Могу создать задачу, сделку или договор — просто опиши, что нужно"
            />
          )}

          {messages.map((msg, idx) => {
            /* Stagger-задержка: cap 300ms при большом количестве сообщений.
               Новые сообщения (idx близок к length-1) получают ~0 задержки из-за cap. */
            const delay = Math.min(idx * 0.04, 0.3);

            // Карточка действия вместо обычного пузыря, если есть proposed_action.
            if (msg.proposed_action && msg.action_status) {
              return (
                <BlurFade key={msg.id} delay={delay} duration={0.18}>
                  <div className="space-y-2">
                    {msg.content.trim() && <AiChatMessage message={msg} />}
                    <AiActionCard
                      action={msg.proposed_action}
                      status={msg.action_status}
                      confirming={confirmingId === msg.id}
                      onConfirm={() => confirmAction(msg)}
                      onCancel={() => cancelAction(msg)}
                    />
                  </div>
                </BlurFade>
              );
            }
            // Сообщение с ссылкой на созданную сущность — добавляем кнопку перехода.
            return (
              <BlurFade key={msg.id} delay={delay} duration={0.18}>
                <div className="space-y-1">
                  <AiChatMessage message={msg} />
                  {msg.link && (
                    <div className="flex justify-start">
                      <button
                        onClick={() => handleNavigate(msg.link as string)}
                        className="btn-secondary text-xs ml-1"
                      >
                        <i className="bi bi-arrow-right-short mr-1" />
                        Перейти
                      </button>
                    </div>
                  )}
                </div>
              </BlurFade>
            );
          })}

          {busy && <AiStreamingDots />}

          <div ref={messagesEndRef} />
        </div>

        {/* Error banner */}
        {error && (
          <div className="relative z-10 mx-4 mb-2 rounded-lg bg-danger/10 border border-danger/30 px-3 py-2 text-xs text-danger flex items-start gap-2">
            <i className="bi bi-exclamation-triangle-fill mt-0.5" />
            <span className="flex-1">{error}</span>
            <button onClick={() => setError(null)} className="text-danger/70 hover:text-danger">
              <i className="bi bi-x-lg" />
            </button>
          </div>
        )}

        {/* Quick chips — только при пустом чате */}
        {messages.length === 0 && !busy && <AiQuickChips onSelect={sendMessage} />}

        {/* Input-зона: floating-стиль */}
        <div className="relative z-10 border-t border-gray-200/80 dark:border-gray-700 p-4">
          <div
            className={[
              "flex gap-2 items-end",
              "bg-gray-50 dark:bg-gray-800",
              "rounded-xl border border-gray-200 dark:border-gray-700",
              "px-3 py-2",
              "focus-within:border-primary/50 focus-within:ring-1 focus-within:ring-primary/20",
              "transition-all duration-150",
            ].join(" ")}
          >
            <textarea
              ref={textareaRef}
              className={[
                "flex-1 resize-none text-sm bg-transparent",
                "border-0 outline-none focus:ring-0",
                "placeholder:text-gray-400 dark:text-gray-100",
                "min-h-[1.5rem] max-h-40 overflow-y-auto",
              ].join(" ")}
              rows={1}
              placeholder="Опиши задачу, сделку или договор…"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onInput={handleTextareaInput}
              onKeyDown={handleKeyDown}
              disabled={busy}
            />
            <button
              onClick={() => sendMessage(input)}
              className="btn-primary shrink-0 rounded-lg px-3 py-1.5"
              disabled={!input.trim() || busy}
            >
              <i className="bi bi-send text-sm" />
            </button>
          </div>
        </div>
      </div>
    </>
  );
}
