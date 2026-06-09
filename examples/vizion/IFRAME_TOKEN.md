# Iframe Token Auth

## Суть

Внешняя система встраивает Vizion через iframe по URL:
```
https://vizion.example.com?token=<iframe_token>
```

Фронтенд при загрузке видит `?token` в URL, обменивает его на Sanctum-токен и дальше работает как при обычном логине.

---

## Флоу для фронтенда

```
1. Пользователь открывает https://vizion.example.com?token=abc123...
2. Vue-приложение при mount проверяет URL на ?token
3. Если найден → POST /api/iframe-auth { token: "abc123..." }
4. Бэкенд возвращает { user, token } — тот же формат что при логине
5. Сохранить token в store (как при обычном логине)
6. Убрать ?token из URL (history.replaceState)
7. Работать дальше как обычно — все запросы с Authorization: Bearer <token>
```

---

## API

### 1. Iframe-авторизация (публичный)

```
POST /api/iframe-auth
Content-Type: application/json
Accept: application/json
```

**Request:**
```json
{
  "token": "abc123..."
}
```

**Response 200:**
```json
{
  "user": {
    "id": 1,
    "name": "Skorpyone",
    "email": "webkuznets@yandex.ru",
    "company_id": 1,
    "role": "superadmin",
    "locale": "ru",
    "company_accesses": [{"company_id": 1, "role": "superadmin"}],
    "company": {
      "id": 1,
      "name": "Vizion",
      "is_system": true,
      ...
    }
  },
  "token": "12|sxEjiYmJfsQNDEb0wq0T0qHJa6msv0EdxkKN9nq8ce561f82"
}
```

**Response 422 (неверный/пустой токен):**
```json
{
  "message": "Invalid email or password.",
  "errors": {
    "token": ["Invalid email or password."]
  }
}
```

### 2. Получить iframe-ссылку пользователя (для карточки)

```
GET /api/users/{id}/iframe-link
Authorization: Bearer <token>
```

**Response 200:**
```json
{
  "iframe_url": "https://vizion.example.com?token=abc123..."
}
```

**Response 200 (токена нет):**
```json
{
  "iframe_url": null
}
```

### 3. Пересоздать iframe-токен (старая ссылка перестанет работать)

```
POST /api/users/{id}/iframe-link/regenerate
Authorization: Bearer <token>
```

**Response 200:**
```json
{
  "iframe_url": "https://vizion.example.com?token=new_token_here..."
}
```

---

## Доступность по ролям

**Iframe-авторизация (`POST /api/iframe-auth`):**
- superadmin — запрещено
- admin, analyst, viewer — разрешено

**Управление ссылками (`iframe-link`, `regenerate`):**
- superadmin — для всех, кроме себя и других superadmin
- admin, analyst, viewer — нет доступа

---

## UI в карточке пользователя

Блок «Iframe-доступ» виден только superadmin, и только для пользователей с ролью admin/analyst/viewer:

```
┌─────────────────────────────────────────────────────┐
│ Iframe-доступ                                       │
│                                                     │
│ https://vizion.example.com?token=abc123...           │
│                                                     │
│ [Скопировать]  [Пересоздать]                        │
└─────────────────────────────────────────────────────┘
```

- **Скопировать** — GET `/api/users/{id}/iframe-link`, скопировать `iframe_url` в буфер обмена
- **Пересоздать** — POST `/api/users/{id}/iframe-link/regenerate`, перед этим подтвердить диалогом «Старая ссылка перестанет работать. Продолжить?»

---

## Токены для тестирования (сидеры)

| Пользователь | Email | iframe_token |
|-------------|-------|-------------|
| Skorpyone (superadmin) | webkuznets@yandex.ru | `seeder-skorpyone-fixed-token` |
| TTeqwwd (superadmin) | e.vetrov@macroglobaltech.com | `seeder-tteqwwd-fixed-token` |
| CIC Admin (admin) | admin@capitalinvest.kz | `seeder-cic-admin-fixed-token` |
| BOMI Admin (admin) | admin@bomi.uz | `seeder-bomi-admin-fixed-token` |
