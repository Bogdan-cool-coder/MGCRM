"use client";

import { useState } from "react";
import Link from "next/link";

export function QuickStartSection() {
  const [copied, setCopied] = useState(false);

  const token = "<YOUR_API_TOKEN>";
  const curlExample = `curl -H "Authorization: Bearer ${token}" \\
  https://contracts.macroglobal.tech/api/leads`;

  function handleCopy() {
    navigator.clipboard.writeText(curlExample).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => {});
  }

  return (
    <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-6">
      <h2 className="text-h4 mb-4">Быстрый старт</h2>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <ol className="list-decimal pl-5 space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <li>
              <Link
                href="/admin/api-tokens"
                className="text-primary hover:underline font-medium inline-flex items-center gap-1"
              >
                <i className="bi bi-key-fill" />
                Получи API-токен в разделе API Токены
              </Link>
            </li>
            <li>
              Добавь заголовок{" "}
              <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">
                Authorization: Bearer &lt;токен&gt;
              </code>{" "}
              к каждому запросу
            </li>
            <li>
              Базовый URL:{" "}
              <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">
                https://contracts.macroglobal.tech/api
              </code>
            </li>
          </ol>
        </div>
        <div>
          <div className="text-xs text-gray-500 dark:text-gray-400 mb-2 font-medium">Пример запроса</div>
          <div className="relative">
            <pre className="bg-gray-900 text-green-400 rounded-lg p-4 text-sm font-mono overflow-x-auto whitespace-pre-wrap">
              {curlExample}
            </pre>
            <button
              onClick={handleCopy}
              className="btn-ghost text-xs absolute top-2 right-2 text-green-400 hover:text-green-300"
            >
              <i className={`bi ${copied ? "bi-check-lg" : "bi-clipboard"}`} />
              {copied ? " Скопировано" : " Копировать"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
