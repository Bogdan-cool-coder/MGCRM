"use client";

/**
 * AiTeaserWidget — градиентная карточка AI-ассистента.
 * Кнопка диспатчит кастомный event `crm:open-ai` — layout
 * (app/layout.tsx) слушает его и открывает AiAssistantButton.
 * Аналогично тому, как работает crm:open-search.
 */
export function AiTeaserWidget() {
  function handleOpen() {
    window.dispatchEvent(new CustomEvent("crm:open-ai"));
  }

  return (
    <div
      className="lift rounded-2xl p-6 text-white relative overflow-hidden"
      style={{ background: "linear-gradient(135deg, #172747, #2B4987 60%, #3b6fd4)" }}
    >
      {/* Декоративный блоб */}
      <div
        className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 blur-xl pointer-events-none"
        aria-hidden="true"
      />

      <div className="flex items-center gap-2 mb-3 relative">
        <span className="h-8 w-8 grid place-items-center rounded-lg bg-white/15 backdrop-blur shrink-0">
          <i className="bi bi-stars text-sm" aria-hidden="true" />
        </span>
        <h3 className="font-semibold">AI-ассистент</h3>
      </div>

      <p className="text-sm text-white/80 relative">
        Спрашивай голосом или текстом — создавай сделки, задачи и договоры в диалоге.
      </p>

      <button
        type="button"
        onClick={handleOpen}
        className="mt-4 w-full rounded-xl bg-white/15 hover:bg-white/25 backdrop-blur py-2 text-sm font-medium transition inline-flex items-center justify-center gap-2 relative focus-visible:ring-2 focus-visible:ring-white/60"
      >
        <i className="bi bi-chat-dots" aria-hidden="true" />
        Открыть ассистента
      </button>
    </div>
  );
}
