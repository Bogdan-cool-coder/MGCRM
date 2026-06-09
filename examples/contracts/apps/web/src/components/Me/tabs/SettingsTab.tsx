"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { useTheme } from "@/contexts/ThemeContext";
import type { ThemePreference } from "@/lib/theme";

const CURRENCIES = ["RUB", "USD", "EUR", "KZT", "UZS", "AED"];

export function SettingsTab() {
  const { theme, setTheme } = useTheme();

  // Password change
  const [oldPassword, setOldPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [pwdSubmitting, setPwdSubmitting] = useState(false);
  const [pwdError, setPwdError] = useState<string | null>(null);
  const [pwdSuccess, setPwdSuccess] = useState(false);

  // Currency preferences
  const [salaryCurrency, setSalaryCurrency] = useState("UZS");
  const [displayCurrencies, setDisplayCurrencies] = useState<string[]>(["RUB", "KZT"]);
  const [prefSubmitting, setPrefSubmitting] = useState(false);
  const [prefError, setPrefError] = useState<string | null>(null);
  const [prefSuccess, setPrefSuccess] = useState(false);

  async function handlePasswordSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!oldPassword || !newPassword) return;
    setPwdSubmitting(true);
    setPwdError(null);
    setPwdSuccess(false);
    try {
      await api("/me/password", {
        method: "PATCH",
        body: { old_password: oldPassword, new_password: newPassword },
      });
      setPwdSuccess(true);
      setOldPassword("");
      setNewPassword("");
    } catch {
      setPwdError("Не удалось сменить пароль. Проверьте текущий пароль.");
    } finally {
      setPwdSubmitting(false);
    }
  }

  function toggleCurrency(cur: string) {
    setDisplayCurrencies((prev) =>
      prev.includes(cur) ? prev.filter((c) => c !== cur) : [...prev, cur],
    );
  }

  async function handlePrefsSubmit(e: React.FormEvent) {
    e.preventDefault();
    setPrefSubmitting(true);
    setPrefError(null);
    setPrefSuccess(false);
    try {
      await api("/me/preferences", {
        method: "PATCH",
        body: { salary_currency: salaryCurrency, display_currencies: displayCurrencies },
      });
      setPrefSuccess(true);
    } catch {
      setPrefError("Не удалось сохранить настройки.");
    } finally {
      setPrefSubmitting(false);
    }
  }

  return (
    <div className="space-y-6 max-w-xl">
      {/* Безопасность */}
      <div className="card p-5">
        <h3 className="text-h5 mb-4">Безопасность</h3>
        <form onSubmit={handlePasswordSubmit} className="space-y-3">
          <div>
            <label className="label">Текущий пароль</label>
            <input
              type="password"
              className="input"
              value={oldPassword}
              onChange={(e) => setOldPassword(e.target.value)}
              autoComplete="current-password"
            />
          </div>
          <div>
            <label className="label">Новый пароль</label>
            <input
              type="password"
              className="input"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              autoComplete="new-password"
            />
          </div>
          {pwdError && <p className="text-sm text-danger">{pwdError}</p>}
          {pwdSuccess && <p className="text-sm text-success">Пароль успешно изменён</p>}
          <button type="submit" className="btn-primary" disabled={pwdSubmitting}>
            {pwdSubmitting ? "Сохранение..." : "Сменить пароль"}
          </button>
        </form>
      </div>

      {/* Интерфейс */}
      <div className="card p-5">
        <h3 className="text-h5 mb-4">Интерфейс</h3>
        <div>
          <label className="label">Тема</label>
          <select
            className="input"
            value={theme}
            onChange={(e) => setTheme(e.target.value as ThemePreference)}
          >
            <option value="light">Светлая</option>
            <option value="dark">Тёмная</option>
            <option value="system">Системная</option>
          </select>
        </div>
      </div>

      {/* Валюты */}
      <div className="card p-5">
        <h3 className="text-h5 mb-4">Валюты для конвертации</h3>
        <form onSubmit={handlePrefsSubmit} className="space-y-4">
          <div>
            <label className="label">Основная валюта зарплаты</label>
            <select
              className="input"
              value={salaryCurrency}
              onChange={(e) => setSalaryCurrency(e.target.value)}
            >
              {CURRENCIES.map((c) => (
                <option key={c} value={c}>{c}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label mb-2">Показывать конвертацию в</label>
            <div className="flex flex-wrap gap-3">
              {CURRENCIES.map((c) => (
                <label key={c} className="flex items-center gap-2 cursor-pointer text-sm">
                  <input
                    type="checkbox"
                    className="rounded border-gray-300 text-primary focus:ring-primary"
                    checked={displayCurrencies.includes(c)}
                    onChange={() => toggleCurrency(c)}
                  />
                  {c}
                </label>
              ))}
            </div>
          </div>
          {prefError && <p className="text-sm text-danger">{prefError}</p>}
          {prefSuccess && <p className="text-sm text-success">Настройки сохранены</p>}
          <button type="submit" className="btn-primary" disabled={prefSubmitting}>
            {prefSubmitting ? "Сохранение..." : "Сохранить настройки"}
          </button>
        </form>
      </div>
    </div>
  );
}
