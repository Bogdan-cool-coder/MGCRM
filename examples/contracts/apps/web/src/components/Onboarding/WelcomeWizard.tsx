"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { createPortal } from "react-dom";
import type { CrmExperienceLevel } from "@/lib/types";
import { api } from "@/lib/api";
import { useMe } from "@/lib/auth";

type WizardStep = "welcome" | "profile" | "experience" | null;

interface ProfileForm {
  full_name: string;
  phone: string;
}

export function WelcomeWizard() {
  const router = useRouter();
  const { user, mutate: mutateMe } = useMe();
  const [step, setStep] = useState<WizardStep>(null);
  const [profile, setProfile] = useState<ProfileForm>({ full_name: "", phone: "" });
  const [experience, setExperience] = useState<CrmExperienceLevel>("none");
  const [submitting, setSubmitting] = useState(false);
  const [mounted, setMounted] = useState(false);

  useEffect(() => { setMounted(true); }, []);

  useEffect(() => {
    if (!user) return;
    // Показываем wizard если is_onboarding_wizard_shown === false
    if (user.is_onboarding_wizard_shown === false) {
      setStep("welcome");
      setProfile({ full_name: user.full_name ?? "", phone: user.phone ?? "" });
    }
  }, [user]);

  async function handleStartLearning() {
    setSubmitting(true);
    try {
      // Если нет full_name или phone — переходим на шаг 2
      if (!user?.full_name || !user?.phone) {
        setStep("profile");
        return;
      }
      // Иначе сразу шаг 3 (опыт)
      setStep("experience");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDismiss() {
    try {
      await api("/users/me/onboarding-wizard", {
        method: "PATCH",
        body: { dismissed: true },
      });
      await mutateMe();
    } catch {
      /* игнорируем */
    }
    setStep(null);
  }

  async function handleSaveProfile() {
    if (!profile.full_name.trim()) return;
    setSubmitting(true);
    try {
      await api("/users/me", {
        method: "PATCH",
        body: { full_name: profile.full_name.trim(), phone: profile.phone.trim() || null },
      });
      await mutateMe();
      setStep("experience");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleSaveExperience() {
    setSubmitting(true);
    try {
      await api("/users/me/onboarding-wizard", {
        method: "PATCH",
        body: {
          crm_experience_level: experience,
          dismissed: false,
        },
      });
      await mutateMe();
      setStep(null);
      router.push("/onboarding");
    } finally {
      setSubmitting(false);
    }
  }

  if (!mounted || !step) return null;

  const overlay = (
    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-2xl max-w-md w-full p-8">
        {step === "welcome" && (
          <div className="text-center">
            <i className="bi bi-mortarboard-fill text-primary text-5xl" />
            <h2 className="text-2xl font-bold mt-4 mb-2">Добро пожаловать в MACRO CRM!</h2>
            <p className="text-gray-600 mb-6">
              Чтобы быстро влиться в работу, пройди короткое обучение. Это займёт около часа.
            </p>
            <button
              className="btn-primary w-full mb-3 justify-center"
              onClick={handleStartLearning}
              disabled={submitting}
            >
              Начать обучение →
            </button>
            <button
              className="btn-ghost w-full justify-center text-gray-500"
              onClick={handleDismiss}
            >
              Пропустить сейчас
            </button>
            <p className="text-xs text-gray-400 mt-3">
              Ты всегда найдёшь курсы в разделе «Обучение»
            </p>
          </div>
        )}

        {step === "profile" && (
          <div>
            <button
              type="button"
              className="btn-ghost text-xs mb-3 flex items-center gap-1"
              onClick={() => setStep("welcome")}
            >
              <i className="bi bi-arrow-left" />
              Назад
            </button>
            <h2 className="text-xl font-bold mb-1">Заполни профиль</h2>
            <p className="text-sm text-gray-500 mb-5">Это поможет нам персонализировать обучение</p>

            <div className="space-y-3">
              <div>
                <label className="label">Полное имя *</label>
                <input
                  className="input"
                  value={profile.full_name}
                  onChange={(e) => setProfile((p) => ({ ...p, full_name: e.target.value }))}
                  placeholder="Иван Иванов"
                />
              </div>
              <div>
                <label className="label">Телефон</label>
                <input
                  className="input"
                  value={profile.phone}
                  onChange={(e) => setProfile((p) => ({ ...p, phone: e.target.value }))}
                  placeholder="+7 900 000-00-00"
                />
              </div>
            </div>

            <button
              className="btn-primary w-full mt-5 justify-center"
              onClick={handleSaveProfile}
              disabled={submitting || !profile.full_name.trim()}
            >
              {submitting ? "Сохранение…" : "Далее →"}
            </button>
          </div>
        )}

        {step === "experience" && (
          <div>
            <h2 className="text-xl font-bold mb-1">Опыт с CRM</h2>
            <p className="text-sm text-gray-500 mb-5">
              Поможет подобрать подходящий темп обучения
            </p>

            <div className="space-y-3">
              {([
                { value: "none" as const,     label: "Никогда не работал с CRM",  desc: "Это моя первая CRM" },
                { value: "basic" as const,    label: "Базовый опыт",              desc: "Работал, но знаком поверхностно" },
                { value: "advanced" as const, label: "Продвинутый пользователь",  desc: "CRM — мой основной инструмент" },
              ]).map((opt) => (
                <label key={opt.value} className="flex items-start gap-3 cursor-pointer">
                  <input
                    type="radio"
                    name="experience"
                    value={opt.value}
                    checked={experience === opt.value}
                    onChange={() => setExperience(opt.value)}
                    className="mt-0.5"
                  />
                  <div>
                    <div className="text-sm font-medium">{opt.label}</div>
                    <div className="text-xs text-gray-500">{opt.desc}</div>
                  </div>
                </label>
              ))}
            </div>

            <button
              className="btn-primary w-full mt-6 justify-center"
              onClick={handleSaveExperience}
              disabled={submitting}
            >
              {submitting ? "Сохранение…" : "Перейти к обучению →"}
            </button>
            <button
              type="button"
              className="btn-ghost w-full mt-2 justify-center text-gray-500"
              onClick={handleDismiss}
            >
              Пропустить
            </button>
          </div>
        )}
      </div>
    </div>
  );

  return createPortal(overlay, document.body);
}
