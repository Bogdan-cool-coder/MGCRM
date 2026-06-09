"use client";

import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { AiAssistantDrawer } from "./AiAssistantDrawer";
import { BorderBeam } from "@/components/magicui/BorderBeam";

interface Props {
  /** Если true — форсированно открыть drawer (из внешнего события crm:open-ai). */
  forceOpen?: boolean;
  /** Колбэк сброса forceOpen после открытия */
  onForceOpenChange?: (v: boolean) => void;
}

export function AiAssistantButton({ forceOpen, onForceOpenChange }: Props) {
  const [open, setOpen] = useState(false);
  // SSR-guard: portal рендерится только после mount на клиенте
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  useEffect(() => {
    if (forceOpen) {
      setOpen(true);
      onForceOpenChange?.(false);
    }
  }, [forceOpen, onForceOpenChange]);

  // До mount (SSR / hydration) не рендерим portal-содержимое
  if (!mounted) return null;

  return createPortal(
    <>
      {/*
        Внешняя обёртка: fixed-позиционирование FAB в правом нижнем углу.
        НЕ содержит relative — иначе relative перебивает fixed в некоторых
        stacking context (transform/contain на предках в layout).
        BorderBeam и overflow-hidden — только на внутреннем <button>.
      */}
      <div className="fixed bottom-6 right-6 z-50">
        <button
          onClick={() => setOpen(!open)}
          className={[
            "relative overflow-hidden",
            "w-14 h-14 rounded-full",
            "bg-gradient-to-br from-primary to-primary-light",
            "text-white shadow-elev-3",
            "transition-all duration-200",
            /* scale только при motion-safe: уважаем prefers-reduced-motion */
            "motion-safe:hover:scale-110 hover:shadow-elev-4",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50",
            "flex items-center justify-center",
          ].join(" ")}
          title="AI-ассистент"
          aria-label="Открыть AI-ассистент"
          aria-expanded={open}
        >
          {open ? (
            /* При открытом drawer — статичный крестик */
            <i className="bi bi-x-lg text-xl relative z-10" />
          ) : (
            /* В idle: bi-stars иконка */
            <i className="bi bi-stars text-xl relative z-10" />
          )}

          {/* BorderBeam: только в idle (не open). Медленный (4s) idle-pulse по периметру. */}
          {!open && (
            <BorderBeam
              size={1.5}
              duration={4}
              colorFrom="rgba(255,255,255,0.6)"
              colorTo="rgba(156,194,255,0.4)"
              borderRadius="50%"
              zIndex={1}
            />
          )}
        </button>
      </div>

      <AiAssistantDrawer open={open} onClose={() => setOpen(false)} />
    </>,
    document.body,
  );
}
