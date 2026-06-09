"use client";

const OAUTH_STEPS = [
  {
    title: "Регистрация OAuth App",
    content:
      'Зарегистрируй своё приложение в разделе OAuth-приложения (/admin/oauth/clients). Получи Client ID и Client Secret.',
  },
  {
    title: "Redirect пользователя на авторизацию",
    content: (
      <span>
        Перенаправь пользователя на{" "}
        <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">
          /oauth/authorize?client_id=…&redirect_uri=…&response_type=code&scope=read:leads
        </code>
      </span>
    ),
  },
  {
    title: "Получение code и обмен на access_token",
    content: (
      <span>
        После согласия пользователя получишь{" "}
        <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">code</code>{" "}
        в redirect_uri. Обменяй его на токен через POST{" "}
        <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">/oauth/token</code>{" "}
        с{" "}
        <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">grant_type=authorization_code</code>.
      </span>
    ),
  },
  {
    title: "Использование access_token в запросах",
    content: (
      <span>
        Добавляй заголовок{" "}
        <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">
          Authorization: Bearer &lt;access_token&gt;
        </code>{" "}
        к каждому API-запросу. Токен действует 1 час.
      </span>
    ),
  },
  {
    title: "Обновление и отзыв токена",
    content: (
      <span>
        Используй refresh_token через POST{" "}
        <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">/oauth/token</code>{" "}
        с{" "}
        <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">grant_type=refresh_token</code>{" "}
        для получения нового access_token. Для отзыва — POST{" "}
        <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">/oauth/revoke</code>.
      </span>
    ),
  },
];

export function OAuthGuideSection() {
  return (
    <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-6">
      <h2 className="text-h4 mb-2">OAuth 2.0 для партнёров</h2>
      <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
        Используй Authorization Code Flow, чтобы пользователи MACRO CRM могли авторизовывать
        твоё приложение без передачи пароля.
      </p>
      <div className="space-y-2">
        {OAUTH_STEPS.map((step, idx) => (
          <details key={idx} className="border border-gray-200 dark:border-gray-700 rounded-xl">
            <summary className="cursor-pointer px-4 py-3 flex items-center gap-3 text-sm font-medium text-gray-900 dark:text-gray-100 select-none hover:bg-gray-50 dark:hover:bg-gray-800 rounded-xl transition-colors">
              <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary text-white text-xs font-bold shrink-0">
                {idx + 1}
              </span>
              {step.title}
              <i className="bi bi-chevron-down ml-auto text-gray-400" />
            </summary>
            <div className="px-4 pb-4 pt-2 text-sm text-gray-700 dark:text-gray-300 leading-relaxed border-t border-gray-100 dark:border-gray-700">
              {step.content}
            </div>
          </details>
        ))}
      </div>
    </div>
  );
}
