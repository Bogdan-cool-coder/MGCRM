"use client";

import { useState, useRef, useCallback } from "react";

interface Props {
  value: string[];
  onChange: (tags: string[]) => void;
  placeholder?: string;
  className?: string;
}

/**
 * Ввод/удаление тегов (чипы).
 * Добавление: Enter или запятая.
 * Удаление: кнопка × на чипе или Backspace на пустом поле.
 */
export function TagInput({ value, onChange, placeholder = "Добавить тег…" }: Props) {
  const [input, setInput] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);

  const addTag = useCallback((raw: string) => {
    const tag = raw.trim().replace(/,$/, "").trim();
    if (!tag || value.includes(tag)) return;
    onChange([...value, tag]);
    setInput("");
  }, [value, onChange]);

  const removeTag = useCallback((tag: string) => {
    onChange(value.filter((t) => t !== tag));
  }, [value, onChange]);

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Enter" || e.key === ",") {
      e.preventDefault();
      addTag(input);
    } else if (e.key === "Backspace" && input === "" && value.length > 0) {
      removeTag(value[value.length - 1]);
    }
  }

  function handleBlur() {
    if (input.trim()) addTag(input);
  }

  return (
    <div
      className="input min-h-[38px] flex flex-wrap gap-1.5 cursor-text"
      onClick={() => inputRef.current?.focus()}
    >
      {value.map((tag) => (
        <span
          key={tag}
          className="inline-flex items-center gap-1 text-xs bg-primary/10 dark:bg-primary/20 text-primary rounded px-2 py-0.5"
        >
          {tag}
          <button
            type="button"
            onClick={(e) => { e.stopPropagation(); removeTag(tag); }}
            className="hover:text-danger leading-none"
            aria-label={`Удалить тег ${tag}`}
          >
            <i className="bi bi-x text-[11px]" />
          </button>
        </span>
      ))}
      <input
        ref={inputRef}
        value={input}
        onChange={(e) => setInput(e.target.value)}
        onKeyDown={handleKeyDown}
        onBlur={handleBlur}
        placeholder={value.length === 0 ? placeholder : ""}
        className="flex-1 min-w-[100px] bg-transparent outline-none text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400"
      />
    </div>
  );
}
