"use client";

import { useState } from "react";

type TabKey = "python" | "node" | "curl";

const EXAMPLES: Record<TabKey, { label: string; code: string }> = {
  python: {
    label: "Python",
    code: `import httpx

BASE_URL = "https://contracts.macroglobal.tech/api"
TOKEN = "your_api_token_here"

headers = {
    "Authorization": f"Bearer {TOKEN}",
}

# Получить список лидов
response = httpx.get(
    f"{BASE_URL}/leads",
    headers=headers,
    params={"limit": 20, "offset": 0},
)
response.raise_for_status()
leads = response.json()

# Создать лид
new_lead = httpx.post(
    f"{BASE_URL}/leads",
    headers=headers,
    json={
        "name": "Тестовый лид",
        "contact_email": "test@example.com",
        "source": "api",
    }
)
print(new_lead.json())`,
  },
  node: {
    label: "Node.js",
    code: `const BASE_URL = "https://contracts.macroglobal.tech/api";
const TOKEN = "your_api_token_here";

const headers = {
  "Authorization": \`Bearer \${TOKEN}\`,
  "Content-Type": "application/json",
};

// Получить список лидов
async function getLeads() {
  const res = await fetch(\`\${BASE_URL}/leads?limit=20\`, {
    headers,
  });
  if (!res.ok) throw new Error(\`HTTP \${res.status}\`);
  return res.json();
}

// Создать лид
async function createLead() {
  const res = await fetch(\`\${BASE_URL}/leads\`, {
    method: "POST",
    headers,
    body: JSON.stringify({
      name: "Тестовый лид",
      contact_email: "test@example.com",
      source: "api",
    }),
  });
  if (!res.ok) throw new Error(\`HTTP \${res.status}\`);
  return res.json();
}

getLeads().then(console.log);`,
  },
  curl: {
    label: "curl",
    code: `# Получить список лидов
curl -H "Authorization: Bearer your_api_token" \\
  "https://contracts.macroglobal.tech/api/leads?limit=20"

# Создать лид
curl -X POST \\
  -H "Authorization: Bearer your_api_token" \\
  -H "Content-Type: application/json" \\
  -d '{"name":"Тестовый лид","source":"api"}' \\
  "https://contracts.macroglobal.tech/api/leads"

# Получить список сделок
curl -H "Authorization: Bearer your_api_token" \\
  "https://contracts.macroglobal.tech/api/deals"`,
  },
};

export function CodeTabs() {
  const [activeTab, setActiveTab] = useState<TabKey>("python");
  const [copied, setCopied] = useState(false);

  const current = EXAMPLES[activeTab];

  function handleCopy() {
    navigator.clipboard.writeText(current.code).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => {});
  }

  return (
    <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-6">
      <h2 className="text-h4 mb-4">Примеры кода</h2>
      <div className="flex gap-1 border-b border-gray-200 dark:border-gray-700 mb-4">
        {(Object.keys(EXAMPLES) as TabKey[]).map((key) => (
          <button
            key={key}
            onClick={() => setActiveTab(key)}
            className={
              "px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors " +
              (activeTab === key
                ? "border-primary text-primary"
                : "border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100")
            }
          >
            {EXAMPLES[key].label}
          </button>
        ))}
      </div>
      <div className="relative">
        <pre className="bg-gray-900 text-green-400 rounded-lg p-4 text-sm font-mono overflow-x-auto whitespace-pre">
          {current.code}
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
  );
}
