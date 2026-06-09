"use client";

import { GoogleIcon, YandexIcon } from "./SsoIcons";

interface Props {
  ssoError?: string | null;
}

export function SsoButtons({ ssoError }: Props) {
  return (
    <div>
      <div className="grid grid-cols-2 gap-3">
        <button
          type="button"
          onClick={() => { window.location.href = "/api/auth/sso/google/start"; }}
          className="rounded-xl border border-gray-300 dark:border-white/10 py-2.5 text-sm font-medium hover:bg-gray-50 dark:hover:bg-white/5 transition inline-flex items-center justify-center gap-2 focus-visible:ring-2 focus-visible:ring-primary-light focus-visible:outline-none"
        >
          <GoogleIcon />
          Войти через Google
        </button>
        <button
          type="button"
          onClick={() => { window.location.href = "/api/auth/sso/yandex/start"; }}
          className="rounded-xl border border-gray-300 dark:border-white/10 py-2.5 text-sm font-medium hover:bg-gray-50 dark:hover:bg-white/5 transition inline-flex items-center justify-center gap-2 focus-visible:ring-2 focus-visible:ring-primary-light focus-visible:outline-none"
        >
          <YandexIcon />
          Войти через Yandex
        </button>
      </div>

      {ssoError && (
        <div
          role="alert"
          className="flex items-start gap-2 rounded-xl bg-danger-50 dark:bg-danger-500/10 text-danger-600 dark:text-danger-400 px-3 py-2 text-sm mt-3 border border-danger-100 dark:border-danger-500/20"
        >
          <i className="bi bi-exclamation-triangle mt-0.5 shrink-0" aria-hidden="true" />
          <span>
            {ssoError === "domain_not_allowed"
              ? "Вход разрешён только для аккаунтов @macroglobaltech.com"
              : "Не удалось войти через внешний аккаунт. Попробуй ещё раз."}
          </span>
        </div>
      )}
    </div>
  );
}
