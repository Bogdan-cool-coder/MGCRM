"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { SSOLink } from "@/lib/types";
import { ProviderIcon } from "./ProviderIcon";

interface ProviderRowProps {
  provider: "google" | "yandex";
  link: SSOLink | undefined;
  loading: boolean;
  onUnlinkSuccess: () => void;
  onLinkClick: () => void;
}

function ProviderRow({ provider, link, loading, onUnlinkSuccess, onLinkClick }: ProviderRowProps) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [unlinking, setUnlinking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const providerLabel = provider === "google" ? "Google" : "Yandex";

  function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString("ru-RU", {
      day: "numeric",
      month: "long",
      year: "numeric",
    });
  }

  async function handleUnlink() {
    setUnlinking(true);
    setError(null);
    try {
      await api(`/auth/sso/${provider}/unlink`, { method: "DELETE" });
      setConfirmOpen(false);
      onUnlinkSuccess();
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setError("Установите пароль перед отключением единственного способа входа.");
      } else {
        setError("Не удалось отключить аккаунт.");
      }
      setConfirmOpen(false);
    } finally {
      setUnlinking(false);
    }
  }

  if (loading) {
    return <div className="animate-pulse h-5 bg-gray-100 rounded w-48" />;
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <ProviderIcon provider={provider} size={20} />
          <div>
            <span className="text-sm font-medium text-gray-900">{providerLabel}</span>
            {link ? (
              <div>
                <span className="text-sm text-gray-600">{link.provider_email}</span>
                <span className="text-xs text-gray-400 ml-2">
                  Подключён: {formatDate(link.linked_at)}
                </span>
              </div>
            ) : (
              <div className="text-sm text-gray-400 italic">Не подключён</div>
            )}
          </div>
        </div>
        {link ? (
          <button
            type="button"
            className="btn-ghost text-danger text-xs"
            onClick={() => setConfirmOpen(true)}
          >
            Отключить
          </button>
        ) : (
          <button
            type="button"
            className="btn-secondary text-xs"
            onClick={onLinkClick}
          >
            Подключить
          </button>
        )}
      </div>

      {error && (
        <p className="text-danger text-sm mt-2">{error}</p>
      )}

      {confirmOpen && (
        <div className="mt-2 p-3 bg-gray-50 rounded-md border border-gray-200">
          <p className="text-sm text-gray-700">
            Отключить {providerLabel}? Для входа придётся использовать пароль.
          </p>
          <div className="flex gap-2 mt-2">
            <button
              type="button"
              className="btn-ghost text-xs"
              onClick={() => setConfirmOpen(false)}
              disabled={unlinking}
            >
              Отмена
            </button>
            <button
              type="button"
              className="btn-ghost text-danger text-xs"
              onClick={handleUnlink}
              disabled={unlinking}
            >
              <i className="bi bi-x-circle mr-1" />
              {unlinking ? "Отключаем…" : "Да, отключить"}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

export function SsoAccountsCard() {
  const { user } = useMe();
  const { data: links, isLoading, error, mutate } = useSWR<SSOLink[]>("/auth/sso/links", fetcher);

  const [banner, setBanner] = useState<{ kind: "success" | "error"; text: string } | null>(null);

  // Обработка query params при маунте
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get("linked")) {
      setBanner({ kind: "success", text: "Аккаунт подключён" });
      void mutate();
      window.history.replaceState({}, "", "/profile?tab=security");
    }
    const ssoError = params.get("sso_error");
    if (ssoError) {
      const text = ssoError === "domain_not_allowed"
        ? "Этот домен не разрешён. Только @macroglobaltech.com аккаунты могут войти через Google."
        : "Не удалось подключить аккаунт. Попробуй ещё раз.";
      setBanner({ kind: "error", text });
      window.history.replaceState({}, "", "/profile?tab=security");
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Автоисчезание баннера
  useEffect(() => {
    if (!banner) return;
    const t = setTimeout(() => setBanner(null), 5000);
    return () => clearTimeout(t);
  }, [banner]);

  const hasPassword = user?.has_password ?? true;
  const onlyOneSso = !hasPassword && (links?.length ?? 0) === 1;

  function getLink(provider: "google" | "yandex"): SSOLink | undefined {
    return links?.find((l) => l.provider === provider);
  }

  return (
    <div className="card p-6">
      <div className="flex items-start gap-3 mb-4">
        <i className="bi bi-link-45deg text-xl text-primary" />
        <div>
          <h3 className="text-h4">Подключённые аккаунты</h3>
          <p className="text-sm text-gray-600 mt-1">
            Используй для быстрого входа — без ввода пароля.
          </p>
        </div>
      </div>

      {banner && (
        <div className={`flex items-start gap-2 rounded-md px-3 py-2 text-sm mb-4 ${
          banner.kind === "success" ? "bg-success/10 text-success" : "bg-danger/10 text-danger"
        }`}>
          <i className={`bi ${banner.kind === "success" ? "bi-check-circle" : "bi-exclamation-triangle"} mt-0.5 shrink-0`} />
          <span>{banner.text}</span>
        </div>
      )}

      {error && (
        <p className="text-danger text-sm mb-3">Не удалось загрузить подключённые аккаунты</p>
      )}

      <div className="divide-y divide-gray-100 space-y-3">
        {(["google", "yandex"] as const).map((provider) => (
          <div key={provider} className={provider !== "google" ? "pt-3" : ""}>
            <ProviderRow
              provider={provider}
              link={getLink(provider)}
              loading={isLoading}
              onUnlinkSuccess={() => void mutate()}
              onLinkClick={() => {
                window.location.href = `/api/auth/sso/${provider}/link?return=/profile?tab=security`;
              }}
            />
          </div>
        ))}
      </div>

      {onlyOneSso && (
        <div className="mt-4 flex items-start gap-2 rounded-md bg-yellow-50 border border-yellow-200 text-yellow-800 p-3 text-sm">
          <i className="bi bi-exclamation-triangle mt-0.5 shrink-0" />
          <div>
            <p>Это единственный способ входа. Если отключишь — не сможешь войти. Сначала установи пароль.</p>
            <a href="/profile" className="btn-secondary text-xs mt-2 inline-flex">
              Перейти к смене пароля
            </a>
          </div>
        </div>
      )}
    </div>
  );
}
