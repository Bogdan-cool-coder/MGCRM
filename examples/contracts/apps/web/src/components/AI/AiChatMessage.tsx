"use client";

import type { AIChatMessage } from "@/lib/types";
import ReactMarkdown from "react-markdown";

interface Props {
  message: AIChatMessage;
}

function relativeTime(d: string) {
  const diff = Date.now() - new Date(d).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return "только что";
  if (mins < 60) return `${mins} мин назад`;
  return new Date(d).toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" });
}

export function AiChatMessage({ message }: Props) {
  const isUser = message.role === "user";

  return (
    <div className={`flex ${isUser ? "justify-end" : "justify-start"}`}>
      <div className="max-w-[80%]">
        <div
          className={
            isUser
              ? "bg-primary text-white rounded-2xl rounded-br-none px-4 py-2 text-sm"
              : "bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white rounded-2xl rounded-bl-none px-4 py-2 text-sm"
          }
        >
          {isUser ? (
            message.content
          ) : (
            /* Markdown-рендер для ответов ассистента */
            <ReactMarkdown
              components={{
                /* Убираем внешний <p> margin для первого/последнего абзаца */
                p: ({ children }) => <p className="mb-1 last:mb-0">{children}</p>,
                strong: ({ children }) => <strong className="font-semibold">{children}</strong>,
                ul: ({ children }) => <ul className="list-disc list-inside space-y-0.5 my-1">{children}</ul>,
                ol: ({ children }) => <ol className="list-decimal list-inside space-y-0.5 my-1">{children}</ol>,
                li: ({ children }) => <li className="text-sm">{children}</li>,
                code: ({ children }) => (
                  <code className="bg-black/10 dark:bg-white/10 rounded px-1 py-0.5 text-xs font-mono">
                    {children}
                  </code>
                ),
              }}
            >
              {message.content}
            </ReactMarkdown>
          )}
        </div>

        {/* Tool calls */}
        {message.tool_calls && message.tool_calls.length > 0 && (
          <div className="mt-1 text-xs text-gray-500 flex items-center gap-1">
            <i className="bi bi-check-circle text-success" />
            <span>Задача создана</span>
          </div>
        )}

        <div className={`text-xs text-gray-400 mt-1 ${isUser ? "text-right" : ""}`}>
          {relativeTime(message.created_at)}
        </div>
      </div>
    </div>
  );
}

/**
 * Typing-индикатор v2: три точки с stagger-задержкой.
 * motion-safe:animate-bounce — уважает prefers-reduced-motion.
 * sr-only текст для a11y.
 */
export function AiStreamingDots() {
  return (
    <div className="flex justify-start">
      <div
        className={[
          "flex items-center gap-1.5",
          "bg-gray-100 dark:bg-gray-800",
          "rounded-2xl rounded-bl-none",
          "px-4 py-3",
        ].join(" ")}
      >
        {[0, 1, 2].map((i) => (
          <span
            key={i}
            aria-hidden="true"
            className="w-1.5 h-1.5 rounded-full bg-gray-400 dark:bg-gray-500 motion-safe:animate-bounce"
            style={{ animationDelay: `${i * 120}ms`, animationDuration: "0.9s" }}
          />
        ))}
        <span className="sr-only">AI отвечает…</span>
      </div>
    </div>
  );
}
