"use client";

import { useRef, useState, useCallback } from "react";
import { useMe } from "@/lib/auth";
import { api, ApiError } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";

const MAX_SIZE_BYTES = 500 * 1024;
const ALLOWED_TYPES = ["image/png", "image/jpeg"];

export function SignaturePanel() {
  const { user, mutate } = useMe();
  const { toast } = useToast();
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const [uploading, setUploading] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  const [cacheBust, setCacheBust] = useState(Date.now());

  const signatureUrl = user?.signature_image_url
    ? `${user.signature_image_url}?v=${cacheBust}`
    : null;

  const handleFile = useCallback(
    async (file: File) => {
      if (!ALLOWED_TYPES.includes(file.type)) {
        toast.error("Допустимы только PNG и JPG.");
        return;
      }
      if (file.size > MAX_SIZE_BYTES) {
        toast.error("Файл слишком большой. Максимум 500 КБ.");
        return;
      }
      setUploading(true);
      try {
        const form = new FormData();
        form.append("file", file);
        const res = await fetch("/api/users/me/signature", {
          method: "POST",
          body: form,
          credentials: "same-origin",
        });
        if (!res.ok) {
          const text = await res.text();
          try {
            toast.error(String(JSON.parse(text).detail ?? text));
          } catch {
            toast.error(text);
          }
          return;
        }
        await mutate();
        setCacheBust(Date.now());
        toast.success("Подпись загружена");
      } finally {
        setUploading(false);
      }
    },
    [mutate, toast],
  );

  async function deleteSignature() {
    setUploading(true);
    try {
      await api("/users/me/signature", { method: "DELETE" });
      await mutate();
      setCacheBust(Date.now());
      toast.success("Подпись удалена");
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка";
      toast.error(detail);
    } finally {
      setUploading(false);
    }
  }

  function handleDragOver(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(true);
  }

  function handleDragLeave() {
    setDragOver(false);
  }

  function handleDrop(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files[0];
    if (file) void handleFile(file);
  }

  if (!user) {
    return <div className="p-8 text-gray-500 dark:text-gray-400 animate-pulse">Загрузка…</div>;
  }

  return (
    <div className="p-6 max-w-2xl">
      <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-6">
        <div>
          <h2 className="text-h4 dark:text-gray-100 mb-1">Подпись изображением</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Подпись будет использоваться в PDF-договорах.
          </p>
        </div>

        {signatureUrl ? (
          <div className="space-y-4">
            <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-white dark:bg-gray-900 inline-block">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={signatureUrl}
                alt="Ваша подпись"
                className="max-h-[100px] object-contain"
              />
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                className="btn-secondary"
                disabled={uploading}
                onClick={() => fileInputRef.current?.click()}
              >
                <i className="bi bi-upload" /> Заменить
              </button>
              <button
                type="button"
                className="btn-ghost text-danger"
                disabled={uploading}
                onClick={() => void deleteSignature()}
              >
                <i className="bi bi-trash" /> Удалить подпись
              </button>
            </div>
          </div>
        ) : (
          <div
            className={
              "border-2 border-dashed rounded-lg p-8 text-center transition-colors cursor-pointer " +
              (dragOver
                ? "border-primary bg-primary/5 dark:bg-primary/10"
                : "border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500")
            }
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
            onClick={() => fileInputRef.current?.click()}
          >
            <i className="bi bi-pen text-3xl text-gray-300 dark:text-gray-600 block mb-3" />
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">
              Загрузи PNG или JPG подписи (прозрачный фон)
            </p>
            <p className="text-sm text-gray-500 dark:text-gray-500 mb-4">
              или перетащи файл сюда
            </p>
            <button
              type="button"
              className="btn-secondary"
              disabled={uploading}
              onClick={(e) => { e.stopPropagation(); fileInputRef.current?.click(); }}
            >
              <i className="bi bi-upload" /> Выбрать файл
            </button>
          </div>
        )}

        <input
          ref={fileInputRef}
          type="file"
          accept="image/png,image/jpeg"
          className="hidden"
          onChange={(e) => {
            const f = e.target.files?.[0];
            if (f) void handleFile(f);
            e.currentTarget.value = "";
          }}
        />

        <div className="text-xs text-gray-500 dark:text-gray-400 space-y-0.5">
          <p>PNG, JPG — до 500 КБ. Оптимальный размер: 400×150 px.</p>
          <p>PNG с прозрачным фоном предпочтительнее.</p>
          <p>Используется при генерации PDF-договоров.</p>
        </div>
      </div>
    </div>
  );
}
