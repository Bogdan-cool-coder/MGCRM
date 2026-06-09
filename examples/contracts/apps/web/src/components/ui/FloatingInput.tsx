"use client";

/**
 * FloatingInput / FloatingTextarea — инпут/textarea с анимированным floating-label.
 *
 * Лейбл находится внутри поля как placeholder; при фокусе или наличии значения
 * «всплывает» наверх и уменьшается (CSS transition, CSS-only — без JS).
 * Базируется на классе .input + focus-glow токены. Dark mode.
 *
 * Поддерживает:
 *   - обычный <input> (однострочный)
 *   - <textarea> (multiline) через prop multiline
 *   - trailing-иконку (icon)
 *   - состояния: ошибка, required, disabled, readonly
 *   - prefers-reduced-motion (CSS переключается на мгновенный переход)
 *
 * USAGE: см. apps/web/docs/forms-usage.md
 */

import clsx from "clsx";
import { forwardRef, useId, useState } from "react";

// ─── Общие типы ────────────────────────────────────────────────────────────────

interface FloatingBaseProps {
  /** Текст лейбла (плавающего). */
  label: string;
  /** Сообщение об ошибке под полем. */
  error?: string;
  /** Вспомогательный текст под полем (не ошибка). */
  hint?: React.ReactNode;
  /** Иконка справа внутри поля (Bootstrap Icons класс: "bi-search"). */
  icon?: string;
  /** Показать метку обязательности (*). */
  required?: boolean;
  /** Доп. className на root-обёртку. */
  className?: string;
}

// ─── FloatingInput ────────────────────────────────────────────────────────────

export interface FloatingInputProps
  extends FloatingBaseProps,
    Omit<React.InputHTMLAttributes<HTMLInputElement>, "placeholder"> {
  /** Режим textarea отключён — используйте FloatingTextarea. */
  multiline?: false;
}

export const FloatingInput = forwardRef<HTMLInputElement, FloatingInputProps>(
  function FloatingInput(
    { label, error, hint, icon, required, className, ...inputProps },
    ref,
  ) {
    const id = useId();
    const [focused, setFocused] = useState(false);
    const hasValue = Boolean(inputProps.value ?? inputProps.defaultValue ?? "");
    const floated = focused || hasValue || Boolean(inputProps.readOnly && hasValue);

    return (
      <FloatingWrapper
        id={id}
        label={label}
        error={error}
        hint={hint}
        icon={icon}
        required={required}
        className={className}
        focused={focused}
        floated={floated}
        disabled={inputProps.disabled}
      >
        <input
          ref={ref}
          id={id}
          placeholder=" "
          required={required}
          aria-required={required}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
          onFocus={(e) => {
            setFocused(true);
            inputProps.onFocus?.(e);
          }}
          onBlur={(e) => {
            setFocused(false);
            inputProps.onBlur?.(e);
          }}
          {...inputProps}
          className={clsx(
            "peer w-full bg-transparent",
            "pt-5 pb-1.5 px-3",
            icon ? "pr-9" : "pr-3",
            "text-sm text-gray-800 dark:text-gray-100",
            "placeholder-transparent", // скрываем стандартный placeholder
            "focus:outline-none",
            "disabled:cursor-not-allowed",
          )}
        />
      </FloatingWrapper>
    );
  },
);

// ─── FloatingTextarea ─────────────────────────────────────────────────────────

export interface FloatingTextareaProps
  extends FloatingBaseProps,
    Omit<React.TextareaHTMLAttributes<HTMLTextAreaElement>, "placeholder"> {}

export const FloatingTextarea = forwardRef<
  HTMLTextAreaElement,
  FloatingTextareaProps
>(function FloatingTextarea(
  { label, error, hint, icon, required, className, ...textareaProps },
  ref,
) {
  const id = useId();
  const [focused, setFocused] = useState(false);
  const hasValue = Boolean(textareaProps.value ?? textareaProps.defaultValue ?? "");
  const floated = focused || hasValue;

  return (
    <FloatingWrapper
      id={id}
      label={label}
      error={error}
      hint={hint}
      icon={icon}
      required={required}
      className={className}
      focused={focused}
      floated={floated}
      disabled={textareaProps.disabled}
      isTextarea
    >
      <textarea
        ref={ref}
        id={id}
        placeholder=" "
        required={required}
        aria-required={required}
        aria-invalid={Boolean(error)}
        aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
        onFocus={(e) => {
          setFocused(true);
          textareaProps.onFocus?.(e);
        }}
        onBlur={(e) => {
          setFocused(false);
          textareaProps.onBlur?.(e);
        }}
        {...textareaProps}
        className={clsx(
          "peer w-full min-h-[100px] resize-y bg-transparent",
          "pt-5 pb-1.5 px-3",
          icon ? "pr-9" : "pr-3",
          "text-sm text-gray-800 dark:text-gray-100",
          "placeholder-transparent",
          "focus:outline-none",
          "disabled:cursor-not-allowed",
        )}
      />
    </FloatingWrapper>
  );
});

// ─── FloatingWrapper — общая обёртка поля ────────────────────────────────────

interface FloatingWrapperProps {
  id: string;
  label: string;
  error?: string;
  hint?: React.ReactNode;
  icon?: string;
  required?: boolean;
  className?: string;
  focused: boolean;
  floated: boolean;
  disabled?: boolean;
  isTextarea?: boolean;
  children: React.ReactNode;
}

function FloatingWrapper({
  id,
  label,
  error,
  hint,
  icon,
  required,
  className,
  focused,
  floated,
  disabled,
  isTextarea,
  children,
}: FloatingWrapperProps) {
  return (
    <div className={clsx("relative", className)}>
      {/* Поле с бордером */}
      <div
        className={clsx(
          // Базовые стили (повторяем .input без px/py)
          "relative rounded-md border bg-white",
          "transition-[border-color,box-shadow] duration-base ease-standard",
          // Состояния бордера
          error
            ? "border-danger"
            : focused
              ? "border-primary ring-4 ring-primary/15"
              : "border-gray-300",
          // Dark mode
          "dark:bg-gray-700",
          error
            ? "dark:border-danger"
            : focused
              ? "dark:border-primary-light dark:ring-primary-light/20"
              : "dark:border-gray-600",
          // Disabled
          disabled && "opacity-60 bg-gray-50 dark:bg-gray-800 cursor-not-allowed",
        )}
      >
        {children}

        {/* Floating label */}
        <label
          htmlFor={id}
          className={clsx(
            // Позиционирование
            "pointer-events-none absolute left-3 select-none",
            "transition-all duration-base ease-standard",
            // Состояния: обычный (в поле) и floated (наверху)
            floated
              ? "top-1.5 text-[10px] font-medium leading-none"
              : isTextarea
                ? "top-3.5 text-sm"
                : "top-1/2 -translate-y-1/2 text-sm",
            // Цвет
            error
              ? "text-danger"
              : focused
                ? "text-primary dark:text-primary-light"
                : floated
                  ? "text-gray-500 dark:text-gray-400"
                  : "text-gray-400 dark:text-gray-500",
          )}
        >
          {label}
          {required && (
            <span
              className={clsx("ml-0.5", error ? "text-danger" : "text-danger")}
              aria-hidden="true"
            >
              *
            </span>
          )}
        </label>

        {/* Trailing icon */}
        {icon && (
          <span
            className={clsx(
              "pointer-events-none absolute right-3",
              isTextarea ? "top-4" : "top-1/2 -translate-y-1/2",
              "text-base leading-none",
              error ? "text-danger" : "text-gray-400 dark:text-gray-500",
            )}
            aria-hidden="true"
          >
            <i className={clsx("bi", icon)} />
          </span>
        )}
      </div>

      {/* Error / hint */}
      {error && (
        <p
          id={`${id}-error`}
          role="alert"
          className="mt-1 text-xs text-danger"
        >
          {error}
        </p>
      )}
      {hint && !error && (
        <p id={`${id}-hint`} className="mt-1 text-xs text-gray-500 dark:text-gray-400">
          {hint}
        </p>
      )}
    </div>
  );
}
