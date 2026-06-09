// Общая логика очистки событий Sentry от секретов и PII.
// Используется во всех трёх средах (client / server / edge), чтобы правила
// редактирования были одинаковы и менялись в одном месте.
//
// Принципы:
//  - НИКОГДА не отправляем cookies, Authorization и любые auth-заголовки.
//  - Вырезаем значения у ключей, похожих на секреты (password/token/secret/...).
//  - Не прикрепляем email/phone/name пользователя (sendDefaultPii: false и так
//    это выключает, но дополнительно подчищаем user вручную на всякий случай).
//
// Без зависимости от типов SDK на уровне сигнатуры: beforeSend в Sentry получает
// event и должен вернуть event | null. Работаем с unknown + narrowing, без any.

const REDACTED = "[Filtered]";

// Заголовки, которые удаляем целиком (lower-case сравнение).
const HEADER_DENYLIST = new Set([
  "cookie",
  "set-cookie",
  "authorization",
  "proxy-authorization",
  "x-api-key",
  "x-auth-token",
  "x-csrf-token",
  "x-xsrf-token",
]);

// Подстроки в имени ключа → значение редактируем (data / extra / contexts).
const KEY_DENY_SUBSTRINGS = [
  "password",
  "passwd",
  "token",
  "secret",
  "api_key",
  "apikey",
  "anthropic",
  "jwt",
  "totp",
  "dsn",
  "authorization",
  "cookie",
  "access_token",
  "refresh_token",
  "private_key",
  "client_secret",
];

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function keyLooksSensitive(key: string): boolean {
  const k = key.toLowerCase();
  return KEY_DENY_SUBSTRINGS.some((needle) => k.includes(needle));
}

// Рекурсивно редактирует значения у "секретных" ключей. Глубину ограничиваем,
// чтобы не уйти в цикл на самоссылающихся структурах.
function redactDeep(value: unknown, depth: number): unknown {
  if (depth > 6) return value;
  if (Array.isArray(value)) {
    return value.map((item) => redactDeep(item, depth + 1));
  }
  if (isPlainObject(value)) {
    const out: Record<string, unknown> = {};
    for (const [key, val] of Object.entries(value)) {
      out[key] = keyLooksSensitive(key) ? REDACTED : redactDeep(val, depth + 1);
    }
    return out;
  }
  return value;
}

function scrubHeaders(headers: unknown): unknown {
  if (!isPlainObject(headers)) return headers;
  const out: Record<string, unknown> = {};
  for (const [key, val] of Object.entries(headers)) {
    if (HEADER_DENYLIST.has(key.toLowerCase())) {
      out[key] = REDACTED;
    } else if (keyLooksSensitive(key)) {
      out[key] = REDACTED;
    } else {
      out[key] = val;
    }
  }
  return out;
}

// Чистит request-секцию события: убирает cookies/headers/data секреты.
function scrubRequest(request: Record<string, unknown>): void {
  if ("cookies" in request) request.cookies = REDACTED;
  if ("headers" in request) request.headers = scrubHeaders(request.headers);
  if ("data" in request) request.data = redactDeep(request.data, 0);
  // query_string может нести token=... — режем целиком, безопаснее.
  if ("query_string" in request && typeof request.query_string === "string") {
    request.query_string = REDACTED;
  }
}

// beforeSend: принимает событие Sentry (типизируем как unknown-record),
// возвращает его же после очистки. Никогда не роняет отправку из-за ошибки
// сериализации — оборачиваем в try/catch и в крайнем случае дропаем событие.
export function scrubEvent<T>(event: T): T {
  try {
    if (!isPlainObject(event)) return event;
    // Работаем через локальную ссылку типа Record, чтобы доступ к полям не
    // упирался в ограничения на индексирование generic-параметра T.
    const ev: Record<string, unknown> = event;

    // 1. Не отправляем PII пользователя (email/phone/username/ip).
    //    Числовой id оставляем как тег при наличии — он не PII.
    if (isPlainObject(ev.user)) {
      const id = ev.user.id;
      const keepId =
        typeof id === "number" || (typeof id === "string" && /^\d+$/.test(id));
      ev.user = keepId ? { id } : {};
    }

    // 2. request: cookies/headers/data/query.
    if (isPlainObject(ev.request)) {
      scrubRequest(ev.request);
    }

    // 3. extra / contexts — редактируем секретные ключи.
    if (isPlainObject(ev.extra)) {
      ev.extra = redactDeep(ev.extra, 0) as Record<string, unknown>;
    }
    if (isPlainObject(ev.contexts)) {
      ev.contexts = redactDeep(ev.contexts, 0) as Record<string, unknown>;
    }

    return event;
  } catch {
    // Если очистка по какой-то причине упала — лучше не слать событие вовсе,
    // чем рискнуть утечкой несочищенных данных.
    return null as unknown as T;
  }
}
