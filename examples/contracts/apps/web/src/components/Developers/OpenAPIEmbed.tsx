"use client";

import { useState } from "react";

export function OpenAPIEmbed() {
  const [error, setError] = useState(false);

  return (
    <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-0 overflow-hidden">
      <div className="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <h2 className="text-h4">Справочник API (OpenAPI)</h2>
        <a
          href="/api/docs"
          target="_blank"
          rel="noopener noreferrer"
          className="text-sm text-primary hover:underline flex items-center gap-1"
        >
          <i className="bi bi-box-arrow-up-right" />
          Открыть в новой вкладке
        </a>
      </div>
      {error ? (
        <div className="p-6 text-center">
          <i className="bi bi-exclamation-circle text-3xl text-warning mb-2 block" />
          <p className="text-sm text-gray-600 dark:text-gray-400 mb-3">
            Не удалось загрузить документацию API
          </p>
          <a
            href="/api/docs"
            target="_blank"
            rel="noopener noreferrer"
            className="btn-secondary"
          >
            <i className="bi bi-box-arrow-up-right" /> Открыть docs напрямую
          </a>
        </div>
      ) : (
        <iframe
          src="/api/docs"
          className="w-full border-none rounded-b-lg"
          style={{ height: "600px" }}
          title="MACRO CRM OpenAPI docs"
          onError={() => setError(true)}
        />
      )}
    </div>
  );
}
