"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Logo } from "@/components/Logo";
import { api, ApiError } from "@/lib/api";
import { BorderBeam } from "@/components/magicui/BorderBeam";
import { BlurFade } from "@/components/magicui/BlurFade";
import { DotPattern } from "@/components/magicui/DotPattern";

type Mode = "totp" | "backup";

export default function TwoFactorPage() {
  const router = useRouter();
  const [mode, setMode] = useState<Mode>("totp");
  const [code, setCode] = useState("");
  const [backupCode, setBackupCode] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [attempts, setAttempts] = useState(0);
  const locked = attempts >= 5;
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    inputRef.current?.focus();
  }, [mode]);

  // Авто-submit при вводе 6 цифр в TOTP режиме
  useEffect(() => {
    if (mode === "totp" && code.length === 6 && !loading && !locked) {
      void handleSubmit("totp");
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code]);

  async function handleSubmit(submitMode?: Mode) {
    const currentMode = submitMode ?? mode;
    setError(null);

    if (currentMode === "backup") {
      if (!/^[a-f0-9]{8}$/.test(backupCode)) {
        setError("Резервный код: 8 символов, только цифры и буквы a–f");
        return;
      }
    }

    setLoading(true);
    try {
      const body = currentMode === "totp"
        ? { totp_code: code }
        : { backup_code: backupCode };
      await api("/auth/2fa/validate", { method: "POST", body });
      router.push("/contracts");
    } catch (err) {
      if (err instanceof ApiError && err.status === 429) {
        setAttempts(5);
        setError("Слишком много неверных попыток. Подожди немного или обратись к администратору.");
      } else {
        setAttempts((prev) => prev + 1);
        setError("Неверный код. Попробуй ещё раз.");
      }
      setCode("");
      setBackupCode("");
      setTimeout(() => inputRef.current?.focus(), 50);
    } finally {
      setLoading(false);
    }
  }

  function switchMode(newMode: Mode) {
    setMode(newMode);
    setCode("");
    setBackupCode("");
    setError(null);
    setAttempts(0);
  }

  return (
    <div className="min-h-screen grid lg:grid-cols-2 bg-gray-100 dark:bg-[#0B1220]">

      {/* ── ЛЕВАЯ БРЕНДОВАЯ ПАНЕЛЬ ──────────────────────────────────────── */}
      <div
        className="hidden lg:flex flex-col justify-between p-12 relative overflow-hidden"
        style={{ background: "#101d3a" }}
      >
        <DotPattern />
        {/* Дрейфующие блобы */}
        <div aria-hidden="true" className="blob-drift absolute rounded-full blur-[60px] opacity-50 w-[420px] h-[420px] top-[-60px] left-[-40px]" style={{ background: "#2B4987" }} />
        <div aria-hidden="true" className="blob-drift blob-drift-2 absolute rounded-full blur-[60px] opacity-50 w-[360px] h-[360px] bottom-[-80px] right-[-30px]" style={{ background: "#3b6fd4" }} />

        <div className="relative z-10 flex items-center gap-3">
          <div className="h-11 w-11 rounded-xl bg-white/10 backdrop-blur grid place-items-center font-extrabold text-lg text-white">M</div>
          <span className="text-lg font-bold tracking-tight text-white">MACRO CRM</span>
        </div>

        <div className="relative z-10 max-w-md">
          <div className="flex items-center gap-3 mb-4">
            <div className="h-10 w-10 rounded-full bg-white/10 grid place-items-center">
              <i className="bi bi-shield-check text-white text-lg" aria-hidden="true" />
            </div>
            <h2 className="text-2xl font-bold text-white">Двойная защита</h2>
          </div>
          <p className="text-white/70 text-sm leading-relaxed">
            Дополнительный шаг проверки защищает ваш аккаунт, даже если пароль скомпрометирован.
            Используйте приложение-аутентификатор (Google Authenticator, Authy) или резервный код.
          </p>
        </div>

        <p className="relative z-10 text-xs text-white/40">© MACRO Global Technologies</p>
      </div>

      {/* ── ПРАВАЯ ПАНЕЛЬ (ФОРМА) ────────────────────────────────────────── */}
      <div className="flex items-center justify-center p-6 min-h-screen lg:min-h-0">
        <div className="w-full max-w-md">

          {/* Лого — только mobile */}
          <BlurFade className="lg:hidden mb-8 flex justify-center">
            <div className="flex items-center gap-2">
              <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-primary-light to-primary grid place-items-center font-extrabold text-white text-base">M</div>
              <span className="text-lg font-bold text-primary dark:text-gray-100">MACRO CRM</span>
            </div>
          </BlurFade>

          {/* Карточка с BorderBeam */}
          <div className="relative rounded-2xl bg-white dark:bg-gray-800/80 dark:backdrop-blur border border-gray-200 dark:border-white/10 shadow-elev-4 p-8">
            <BorderBeam borderRadius="1rem" size={1.5} duration={8} />

            <BlurFade delay={0}>
              <div className="flex items-center gap-3 mb-2">
                <div className="h-9 w-9 rounded-lg bg-primary/10 dark:bg-primary-light/10 grid place-items-center shrink-0">
                  <i className="bi bi-shield-lock text-primary dark:text-primary-light text-base" aria-hidden="true" />
                </div>
                <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                  Двухфакторная аутентификация
                </h1>
              </div>
              <p className="text-sm text-gray-500 dark:text-gray-400 mb-6 ml-[calc(36px+12px)]">
                Введите код из приложения-аутентификатора
              </p>
            </BlurFade>

            {/* Вкладки */}
            <BlurFade delay={0.06}>
              <div className="flex border-b border-gray-200 dark:border-white/10 mb-5">
                <button
                  type="button"
                  onClick={() => switchMode("totp")}
                  className={`pb-2.5 px-1 text-sm transition-colors ${
                    mode === "totp"
                      ? "border-b-2 border-primary dark:border-primary-light text-primary dark:text-primary-light font-medium"
                      : "text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                  }`}
                >
                  Код из приложения
                </button>
                <button
                  type="button"
                  onClick={() => switchMode("backup")}
                  className={`pb-2.5 px-1 ml-4 text-sm transition-colors ${
                    mode === "backup"
                      ? "border-b-2 border-primary dark:border-primary-light text-primary dark:text-primary-light font-medium"
                      : "text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                  }`}
                >
                  Резервный код
                </button>
              </div>
            </BlurFade>

            {locked ? (
              <BlurFade delay={0.1}>
                <div className="flex items-start gap-2.5 rounded-xl bg-warning-50 dark:bg-yellow-500/10 text-warning-800 dark:text-yellow-400 border border-warning-200 dark:border-yellow-500/20 px-4 py-3 text-sm mb-4">
                  <i className="bi bi-clock mt-0.5 shrink-0" aria-hidden="true" />
                  <span>Слишком много неверных попыток. Подожди немного или обратись к администратору.</span>
                </div>
              </BlurFade>
            ) : (
              <>
                <BlurFade delay={0.1}>
                  {mode === "totp" ? (
                    <div className="mb-5">
                      <input
                        ref={inputRef}
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        maxLength={6}
                        autoComplete="one-time-code"
                        placeholder="000000"
                        className={[
                          "w-full rounded-xl border bg-white dark:bg-gray-900/50",
                          "px-4 py-3 text-2xl tracking-[0.5em] font-mono text-center outline-none",
                          "transition-[border-color,box-shadow] duration-150",
                          "text-gray-900 dark:text-gray-100 placeholder-gray-300 dark:placeholder-gray-600",
                          error
                            ? "border-danger focus:border-danger focus:ring-4 focus:ring-danger/15"
                            : "border-gray-300 dark:border-white/10 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15",
                        ].join(" ")}
                        value={code}
                        onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
                        disabled={loading}
                        autoFocus
                      />
                    </div>
                  ) : (
                    <div className="mb-5">
                      <input
                        ref={inputRef}
                        type="text"
                        inputMode="text"
                        maxLength={8}
                        pattern="[a-f0-9]{8}"
                        placeholder="a1b2c3d4"
                        className={[
                          "w-full rounded-xl border bg-white dark:bg-gray-900/50",
                          "px-4 py-3 text-xl tracking-widest font-mono lowercase text-center outline-none",
                          "transition-[border-color,box-shadow] duration-150",
                          "text-gray-900 dark:text-gray-100 placeholder-gray-300 dark:placeholder-gray-600",
                          error
                            ? "border-danger focus:border-danger focus:ring-4 focus:ring-danger/15"
                            : "border-gray-300 dark:border-white/10 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15",
                        ].join(" ")}
                        value={backupCode}
                        onChange={(e) => setBackupCode(e.target.value.toLowerCase())}
                        disabled={loading}
                        autoFocus
                      />
                    </div>
                  )}
                </BlurFade>

                {error && (
                  <div role="alert" aria-live="polite" className="flex items-center gap-1.5 text-danger text-sm mb-4">
                    <i className="bi bi-exclamation-circle shrink-0" aria-hidden="true" />
                    {error}
                  </div>
                )}

                <BlurFade delay={0.16}>
                  <button
                    type="button"
                    className="btn-primary w-full justify-center"
                    onClick={() => void handleSubmit()}
                    disabled={
                      loading ||
                      (mode === "totp" && code.length !== 6) ||
                      (mode === "backup" && backupCode.length !== 8)
                    }
                  >
                    {loading ? (
                      <>
                        <i className="bi bi-arrow-clockwise animate-spin mr-1.5" aria-hidden="true" />
                        Входим…
                      </>
                    ) : (
                      "Войти"
                    )}
                  </button>
                </BlurFade>
              </>
            )}

            <div className="mt-5 text-center">
              <a
                href="/login"
                className="text-sm text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-light transition-colors"
                onClick={(e) => {
                  e.preventDefault();
                  router.push("/login");
                }}
              >
                <i className="bi bi-arrow-left mr-1" aria-hidden="true" />
                Назад к входу
              </a>
            </div>
          </div>

          <BlurFade delay={0.2}>
            <p className="text-center text-xs text-gray-400 mt-5">
              Защищено 2FA · MACRO Global Technologies
            </p>
          </BlurFade>

        </div>
      </div>
    </div>
  );
}
