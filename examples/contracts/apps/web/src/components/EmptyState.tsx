"use client";

import { LordIcon } from "@/components/ui/LordIcon";
import type { LordIconProps } from "@/components/ui/LordIcon";

interface EmptyStateProps {
  /** Bootstrap-иконка (bi-*), используется если lordIcon не передан */
  icon: string;
  title: string;
  description?: string;
  /** Алиас для description — обратная совместимость */
  text?: string;
  cta?: React.ReactNode;
  /** Дополнительный className на корневом div */
  className?: string;
  /**
   * Опциональная Lordicon-иконка. Если передана — заменяет Bootstrap bi-иконку.
   * Пример: <EmptyState lordIcon={{ icon: trashJson, trigger: "loop", size: 64 }} ... />
   */
  lordIcon?: Pick<LordIconProps, "icon" | "trigger" | "size" | "colors">;
}

export function EmptyState({ icon, title, description, text, cta, lordIcon, className }: EmptyStateProps) {
  const desc = description ?? text;
  return (
    <div className={["flex flex-col items-center justify-center py-10 text-center", className].filter(Boolean).join(" ")}>
      {lordIcon ? (
        <div className="mb-3 opacity-60">
          <LordIcon
            icon={lordIcon.icon}
            trigger={lordIcon.trigger ?? "loop"}
            size={lordIcon.size ?? 64}
            colors={lordIcon.colors}
            fallbackIcon={icon}
          />
        </div>
      ) : (
        <i className={`bi ${icon} text-4xl text-gray-300 mb-3`} />
      )}
      <p className="text-sm font-medium text-gray-500">{title}</p>
      {desc && <p className="text-xs text-gray-400 mt-1 max-w-xs">{desc}</p>}
      {cta && <div className="mt-4">{cta}</div>}
    </div>
  );
}
