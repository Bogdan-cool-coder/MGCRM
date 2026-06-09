"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { SsoButtons } from "@/components/Auth/SsoButtons";
import { BorderBeam } from "@/components/magicui/BorderBeam";
import { BlurFade } from "@/components/magicui/BlurFade";
import { DotPattern } from "@/components/magicui/DotPattern";
import { ShimmerButton } from "@/components/magicui/ShimmerButton";
import type { LoginResponse } from "@/lib/types";

// ──────────────────────────────────────────────────────────────────────────────
// Отдельный компонент для drift-блобов, чтобы не замусоривать JSX
// ──────────────────────────────────────────────────────────────────────────────
// blob-drift / blob-drift-2 / blob-drift-3 и @keyframes blob-drift живут в globals.css
function BrandBlobs() {
  return (
    <>
      {/* b1 */}
      <div
        aria-hidden="true"
        className="blob-drift absolute rounded-full blur-[60px] opacity-50 w-[420px] h-[420px] top-[-60px] left-[-40px]"
        style={{ background: "#2B4987" }}
      />
      {/* b2 */}
      <div
        aria-hidden="true"
        className="blob-drift blob-drift-2 absolute rounded-full blur-[60px] opacity-50 w-[360px] h-[360px] bottom-[-80px] right-[-30px]"
        style={{ background: "#3b6fd4" }}
      />
      {/* b3 */}
      <div
        aria-hidden="true"
        className="blob-drift blob-drift-3 absolute rounded-full blur-[60px] opacity-50 w-[300px] h-[300px] top-[40%] left-[45%]"
        style={{ background: "#1b3263" }}
      />
    </>
  );
}

// ──────────────────────────────────────────────────────────────────────────────
// Лого-знак для использования внутри страницы
// ──────────────────────────────────────────────────────────────────────────────
function LogoMark({ variant }: { variant: "light" | "dark" }) {
  if (variant === "light") {
    // Для брендовой панели — белый текст
    return (
      <div className="flex items-center gap-3">
        <div className="h-11 w-11 rounded-xl bg-white/10 backdrop-blur grid place-items-center font-extrabold text-lg text-white">
          M
        </div>
        <span className="text-lg font-bold tracking-tight text-white">MACRO CRM</span>
      </div>
    );
  }
  // Для мобильного — адаптируется под тему
  return (
    <div className="flex items-center gap-2">
      <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-primary-light to-primary grid place-items-center font-extrabold text-white text-base">
        M
      </div>
      <span className="text-lg font-bold text-primary dark:text-gray-100">MACRO CRM</span>
    </div>
  );
}

// ──────────────────────────────────────────────────────────────────────────────
// Страница Login
// ──────────────────────────────────────────────────────────────────────────────
export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ssoError, setSsoError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const ssoErr = params.get("sso_error");
    if (ssoErr) {
      setSsoError(ssoErr);
      window.history.replaceState({}, "", "/login");
    }
  }, []);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const res = await api<LoginResponse>("/auth/login", {
        method: "POST",
        body: { email, password },
      });
      if ("requires_2fa" in res && res.requires_2fa) {
        router.push("/auth/2fa");
      } else {
        router.push("/contracts");
      }
    } catch (err) {
      if (err instanceof ApiError) {
        const detail = (err.detail as { detail?: string })?.detail;
        setError(detail ?? "Не удалось войти");
      } else {
        setError("Ошибка соединения");
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-screen grid lg:grid-cols-2 bg-gray-100 dark:bg-[#0B1220]">

      {/* ── ЛЕВАЯ БРЕНДОВАЯ ПАНЕЛЬ ─────────────────────────────────────────── */}
      <div
        className="hidden lg:flex flex-col justify-between p-12 relative overflow-hidden"
        style={{ background: "#101d3a" }}
      >
        {/* Dot pattern — декоративный слой */}
        <DotPattern />

        {/* Дрейфующие блобы */}
        <BrandBlobs />

        {/* Лого-шапка */}
        <div className="relative z-10">
          <LogoMark variant="light" />
        </div>

        {/* Основной контент */}
        <div className="relative z-10 max-w-md">
          <h2 className="text-3xl font-bold leading-tight text-white">
            Единая система продаж, договоров и финансов MACRO Global
          </h2>
          <p className="mt-4 text-white/70 text-sm">
            Воронки, реестр клиентов, документооборот, аналитика и финучёт — в одном окне.
          </p>

          {/* Статистика */}
          <div className="mt-8 flex items-center gap-6 text-white/80">
            <div>
              <div className="text-2xl font-bold">121+</div>
              <div className="text-xs text-white/50">контрагентов</div>
            </div>
            <div className="h-8 w-px bg-white/15" aria-hidden="true" />
            <div>
              <div className="text-2xl font-bold">128</div>
              <div className="text-xs text-white/50">подписок</div>
            </div>
            <div className="h-8 w-px bg-white/15" aria-hidden="true" />
            <div>
              <div className="text-2xl font-bold">14</div>
              <div className="text-xs text-white/50">этапов воронки</div>
            </div>
          </div>
        </div>

        {/* Копирайт */}
        <p className="relative z-10 text-xs text-white/40">
          © MACRO Global Technologies
        </p>
      </div>

      {/* ── ПРАВАЯ ПАНЕЛЬ (ФОРМА) ─────────────────────────────────────────── */}
      <div className="flex items-center justify-center p-6 min-h-screen lg:min-h-0">
        <div className="w-full max-w-md">

          {/* Лого — только mobile */}
          <BlurFade className="lg:hidden mb-8 flex justify-center">
            <LogoMark variant="dark" />
          </BlurFade>

          {/* Карточка с BorderBeam */}
          <div className="relative rounded-2xl bg-white dark:bg-gray-800/80 dark:backdrop-blur border border-gray-200 dark:border-white/10 shadow-elev-4 p-8">
            <BorderBeam borderRadius="1rem" size={1.5} duration={6} />

            {/* Заголовок */}
            <BlurFade delay={0}>
              <h1 className="text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                Вход в систему
              </h1>
              <p className="text-gray-500 dark:text-gray-400 text-sm mt-1 mb-6">
                Рады видеть снова
              </p>
            </BlurFade>

            <form onSubmit={onSubmit} className="space-y-4">

              {/* Поле Email */}
              <BlurFade delay={0.06}>
                <div>
                  <label
                    htmlFor="email"
                    className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 block"
                  >
                    Email
                  </label>
                  <div className="relative">
                    <i
                      className="bi bi-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"
                      aria-hidden="true"
                    />
                    <input
                      id="email"
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      autoComplete="email"
                      required
                      className={[
                        "w-full rounded-xl border bg-white dark:bg-gray-900/50",
                        "pl-10 pr-3 py-2.5 text-[15px] outline-none",
                        "transition-[border-color,box-shadow] duration-base ease-standard",
                        "text-gray-900 dark:text-gray-100",
                        "placeholder-gray-400 dark:placeholder-gray-500",
                        error
                          ? "border-danger focus:border-danger focus:ring-4 focus:ring-danger/15"
                          : "border-gray-300 dark:border-white/10 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15",
                      ].join(" ")}
                    />
                  </div>
                </div>
              </BlurFade>

              {/* Поле Пароль */}
              <BlurFade delay={0.12}>
                <div>
                  <label
                    htmlFor="password"
                    className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 block"
                  >
                    Пароль
                  </label>
                  <div className="relative">
                    <i
                      className="bi bi-lock absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"
                      aria-hidden="true"
                    />
                    <input
                      id="password"
                      type={showPassword ? "text" : "password"}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      autoComplete="current-password"
                      required
                      className={[
                        "w-full rounded-xl border bg-white dark:bg-gray-900/50",
                        "pl-10 pr-10 py-2.5 text-[15px] outline-none",
                        "transition-[border-color,box-shadow] duration-base ease-standard",
                        "text-gray-900 dark:text-gray-100",
                        error
                          ? "border-danger focus:border-danger focus:ring-4 focus:ring-danger/15"
                          : "border-gray-300 dark:border-white/10 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15",
                      ].join(" ")}
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((v) => !v)}
                      aria-label={showPassword ? "Скрыть пароль" : "Показать пароль"}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-light"
                    >
                      <i
                        className={`bi ${showPassword ? "bi-eye-slash" : "bi-eye"}`}
                        aria-hidden="true"
                      />
                    </button>
                  </div>
                </div>
              </BlurFade>

              {/* Ошибка под полями */}
              {error && (
                <div role="alert" aria-live="polite" className="text-danger-600 dark:text-danger-400 text-sm flex items-center gap-1.5">
                  <i className="bi bi-exclamation-circle shrink-0" aria-hidden="true" />
                  {error}
                </div>
              )}

              {/* Запомнить меня + Забыли пароль */}
              <BlurFade delay={0.18}>
                <div className="flex items-center justify-between text-sm">
                  <label className="inline-flex items-center gap-2 text-gray-600 dark:text-gray-400 cursor-pointer select-none">
                    <input
                      type="checkbox"
                      checked={rememberMe}
                      onChange={(e) => setRememberMe(e.target.checked)}
                      className="rounded border-gray-300 dark:border-gray-600 text-primary-light focus:ring-primary-light dark:bg-gray-700"
                    />
                    Запомнить меня
                  </label>
                  {/* Сброс пароля пока не реализован — ссылка скрыта */}
                </div>
              </BlurFade>

              {/* Кнопка Войти */}
              <BlurFade delay={0.18}>
                <ShimmerButton
                  type="submit"
                  loading={loading}
                  loadingText="Входим…"
                >
                  Войти
                </ShimmerButton>
              </BlurFade>
            </form>

            {/* Разделитель «или» */}
            <BlurFade delay={0.24}>
              <div className="relative my-6">
                <div className="absolute inset-0 flex items-center">
                  <div className="w-full border-t border-gray-200 dark:border-white/10" />
                </div>
                <div className="relative flex justify-center text-xs uppercase tracking-wide">
                  <span className="bg-white dark:bg-gray-800 px-3 text-gray-400">или</span>
                </div>
              </div>

              {/* SSO-кнопки */}
              <SsoButtons ssoError={ssoError} />
            </BlurFade>
          </div>

          {/* Футер страницы */}
          <BlurFade delay={0.24}>
            <p className="text-center text-xs text-gray-400 mt-6">
              Защищено 2FA · MACRO Global Technologies
            </p>
          </BlurFade>

        </div>
      </div>
    </div>
  );
}
