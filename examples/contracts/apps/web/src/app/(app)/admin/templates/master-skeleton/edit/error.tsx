"use client";

import Link from "next/link";
import { useEffect } from "react";

/**
 * Route-level error boundary для страницы редактора шаблона. Без него Next.js
 * показывает generic «Application error» — здесь мы рендерим реальное сообщение
 * и stack-trace, чтобы при падении было ясно что чинить.
 */
export default function MasterSkeletonEditError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error("MasterSkeletonEditError:", error);
  }, [error]);

  return (
    <div className="p-8 max-w-2xl mx-auto space-y-4">
      <h1 className="text-h3 text-danger">Ошибка редактора шаблона</h1>
      <div className="bg-danger/10 p-4 rounded text-sm space-y-2">
        <div>
          <span className="font-semibold">Сообщение:</span> {error.message || "(нет текста)"}
        </div>
        {error.digest && (
          <div>
            <span className="font-semibold">Digest:</span> <code>{error.digest}</code>
          </div>
        )}
        {error.stack && (
          <details>
            <summary className="cursor-pointer">Stack</summary>
            <pre className="text-xs mt-2 whitespace-pre-wrap break-words">{error.stack}</pre>
          </details>
        )}
      </div>
      <div className="flex gap-2">
        <button onClick={reset} className="btn-primary">
          Попробовать снова
        </button>
        <Link href="/admin/templates" className="btn-secondary">
          К списку шаблонов
        </Link>
      </div>
    </div>
  );
}
