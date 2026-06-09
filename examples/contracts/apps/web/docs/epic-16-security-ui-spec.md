# ТЗ: Эпик 16 — Security UI (2FA + SSO + Rate Limiting)

**Версия:** 1.1  
**Дата:** 2026-06-02  
**Автор:** designer  
**Исполнитель:** frontend-specialist

---

## Cover

### Цель

Реализовать UI для трёх независимых security-фич:

1. **2FA (TOTP)** — пользователь подключает Google Authenticator / Яндекс.Ключ через 4-шаговый мастер, логин с 2FA получает промежуточную страницу `/auth/2fa`.
2. **SSO** — кнопки «Войти через Google» / «Войти через Yandex» на `/login`, секция «Подключённые аккаунты» в профиле.
3. **Rate Limit** — поле `rate_limit_per_hour` в модале создания/редактирования API токена + колонка в таблице.

### Контекст

- Существующие страницы затрагиваются: `/login`, `/profile`, `/admin/api-tokens`.
- Новые страницы: `/auth/2fa`, `/profile/security`.
- Профиль сейчас — одна страница `/profile/page.tsx`. Страница `/profile/security` создаётся как **соседняя** страница в той же директории `(app)/profile/`, ссылка на неё добавляется в навигацию внутри профиля (вкладки или боковое меню).
- Пересечение с Эпиком 21 (Профиль 2.0): `/profile/security` создаём сейчас. Эпик 21 будет расширять, не переписывать.
- `/auth/2fa` — страница вне `(app)/` layout (без Sidebar), аналогично `/login`.

---

## Процессы (иллюстрация)

### A. Setup 2FA (happy path)

```
Пользователь на /profile/security
        │
        ▼
[ Кнопка «Подключить 2FA» ]
        │
        ▼
[ Modal: Шаг 1 — «Скачайте приложение» ]
        │ Нажать «Далее»
        ▼
[ Modal: Шаг 2 — «Отсканируйте QR-код» ]
  ← POST /auth/2fa/setup → получаем otpauth URI + QR base64
        │ Нажать «Далее»
        ▼
[ Modal: Шаг 3 — «Введите код» ]
  6-значный input → POST /auth/2fa/verify-setup → backup codes
        │ Нажать «Далее» (код верный)
        ▼
[ Modal: Шаг 4 — «Сохраните резервные коды» ]
  Показ 8 backup кодов + «Скачать .txt»
        │ Нажать «Я сохранил коды, продолжить»
        ▼
[ Modal закрывается. Карточка 2FA обновляется → статус «2FA активирована» ]
```

### B. Логин с 2FA

```
/login — пользователь вводит email + пароль
        │
        ▼
POST /auth/login
        │
   ┌────┴────┐
   │ requires_2fa: false         │ requires_2fa: true
   ▼                             ▼
redirect /contracts        cookie temp_token (5 мин)
                                 │
                                 ▼
                          redirect /auth/2fa
                                 │
                    ┌────────────┴─────────────┐
                    │ Вводит TOTP-код           │ Вводит backup code
                    ▼                           ▼
              POST /auth/2fa/validate     POST /auth/2fa/validate
                    │                    (поле backup_code)
                    ▼
               access_token в cookie
                    │
                    ▼
             redirect /contracts
```

### C. SSO flow

```
/login — клик «Войти через Google»
        │
        ▼
window.location.href = '/api/auth/sso/google/start'
        │
        ▼
Backend: redirect на Google OAuth consent
        │
        ▼
Google callback → /api/auth/sso/google/callback
        │
   ┌────┴────┐
   │ hd == macroglobaltech.com    │ другой домен
   ▼                              ▼
access_token в cookie        redirect /login?sso_error=domain_not_allowed
redirect /contracts
```

### D. Link/Unlink SSO в профиле

```
/profile/security — секция «Подключённые аккаунты»
        │
        │ Кнопка «Подключить» (provider не привязан)
        ▼
window.location.href = '/api/auth/sso/{provider}/link?return=/profile/security'
        │ (Backend: OAuth flow → callback → создаёт UserSSOLink → redirect /profile/security)
        ▼
Страница обновляется, provider показан как «Подключён»

        │ Кнопка «Отключить» (provider привязан)
        ▼
Inline confirm: «Отключить Google?» + кнопки [Отмена] [Отключить]
        │ Подтверждение
        ▼
DELETE /auth/sso/{provider}/unlink
        │
   ┌────┴────────────────────┐
   │ у юзера есть пароль     │ пароля нет, это единственный provider
   ▼                         ▼
Success banner           Блокирующее предупреждение:
                         «Установите пароль сначала»
                         Кнопка «Перейти к смене пароля» → /profile
```

---

## Раздел 1 — `/profile/security` (новая страница)

### Где в коде

- Страница: `apps/web/src/app/(app)/profile/security/page.tsx`
- Компоненты:
  - `apps/web/src/components/Security/TwoFactorCard.tsx`
  - `apps/web/src/components/Security/TwoFactorSetupModal.tsx`
  - `apps/web/src/components/Security/TwoFactorConfirmModal.tsx`
  - `apps/web/src/components/Security/SsoAccountsCard.tsx`

### Wireframe

```
┌───────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Безопасность]                       │
│            ├───────────────────────────────────────────────────┤
│            │                                                   │
│            │  ┌─────────────────────────────────────────────┐  │
│            │  │ bi-shield-lock  Двухфакторная               │  │
│            │  │                аутентификация               │  │
│            │  │                                             │  │
│            │  │ [Описание]                                  │  │
│            │  │                                             │  │
│            │  │  [STATE A: без 2FA]                         │  │
│            │  │  [ btn-primary: Подключить 2FA ]            │  │
│            │  │                                             │  │
│            │  │  [STATE B: с 2FA]                           │  │
│            │  │  ✓ badge success «2FA активирована»         │  │
│            │  │  Подключена: 14 мая 2026                    │  │
│            │  │  [ btn-secondary: Сгенерировать коды ]      │  │
│            │  │  [ btn-ghost text-danger: Отключить 2FA ]   │  │
│            │  └─────────────────────────────────────────────┘  │
│            │                                                   │
│            │  ┌─────────────────────────────────────────────┐  │
│            │  │ bi-link-45deg  Подключённые аккаунты        │  │
│            │  │                                             │  │
│            │  │  [Google row]  [Yandex row]                 │  │
│            │  │  — иконка провайдера                        │  │
│            │  │  — email или «Не подключён»                 │  │
│            │  │  — дата / кнопка                            │  │
│            │  └─────────────────────────────────────────────┘  │
└───────────────────────────────────────────────────────────────┘
```

### Layout

- Использует `(app)/layout.tsx` (Sidebar + main).
- `PageHeader` с title «Безопасность» и description «Управление двухфакторной аутентификацией и внешними аккаунтами».
- Основная область: `p-8`, `max-w-3xl`, `space-y-6`.
- Две `card p-6` вертикально: сначала 2FA, потом SSO-аккаунты.
- **ProfileNav — через Sidebar:** добавить в компонент `Sidebar` подпункты для группы «Профиль». В Sidebar навигации создать раскрытую группу с тремя подпунктами:
  - `Личное` → `/profile`
  - `Безопасность` → `/profile/security`
  - `Уведомления` → `/profile/notifications` (ссылка-заглушка, страница в будущем эпике)
  - Активный подпункт выделяется `text-primary font-medium` (аналогично текущим активным пунктам Sidebar).
  - `ProfileNav` компонент как отдельный файл не создавать — вёрстка прямо в Sidebar.
  - Компонент `ProfileNav` из списка компонентов убрать.

### 1.1 Карточка «Двухфакторная аутентификация» (TwoFactorCard)

**State machine:**

| Состояние | Условие | UI |
|---|---|---|
| `disabled` | `user.totp_enabled === false` | Описание + кнопка «Подключить 2FA» |
| `enabled` | `user.totp_enabled === true` | Badge «2FA активирована» + дата + две кнопки |
| `loading` | SWR загружает | Skeleton: 2 строки `div.animate-pulse h-4 bg-gray-100 rounded` |
| `error` | SWR ошибка | Inline `text-danger text-sm`: «Не удалось загрузить статус 2FA» |

**State `disabled`:**

```
┌─────────────────────────────────────────────┐
│  i.bi-shield-lock text-2xl text-primary     │
│  h3: Двухфакторная аутентификация           │
│                                             │
│  p.text-sm.text-gray-600:                  │
│  «Защитите аккаунт дополнительным кодом    │
│  из приложения-аутентификатора             │
│  (Google Authenticator, Яндекс.Ключ и др.)»│
│                                             │
│  [ btn-primary: bi-shield-plus Подключить 2FA ] │
└─────────────────────────────────────────────┘
```

**State `enabled`:**

```
┌─────────────────────────────────────────────┐
│  i.bi-shield-check text-2xl text-success    │
│  h3: Двухфакторная аутентификация           │
│                                             │
│  span.badge bg-success/10 text-success:     │
│  bi-check-circle «2FA активирована»         │
│  p.text-sm.text-gray-500: «Подключена: <дата>»│
│                                             │
│  [ btn-secondary: bi-arrow-repeat Сгенерировать новые коды ] │
│  [ btn-ghost text-danger: bi-shield-x Отключить 2FA ]        │
└─────────────────────────────────────────────┘
```

**Tailwind детали:**

- Заголовок карточки: `flex items-start gap-3 mb-4`
- Иконка: `text-2xl` (Bootstrap Icons через `<i className="bi bi-shield-lock text-2xl text-primary" />`)
- Badge: `inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium bg-success/10 text-success`
- Дата подключения: `text-sm text-gray-500 mt-1 mb-4`
- Кнопки: `flex gap-3 flex-wrap mt-4`

**Data source:** `useMe()` из `@/lib/auth`. После setup/disable — `mutate()` для обновления.  
Дополнительно нужно поле `totp_enabled_at?: string | null` в типе `User` (требуется правка backend: добавить поле в `/auth/me` ответ).

### 1.2 TwoFactorSetupModal (4 шага)

Один `Modal` с prop `width="md"`, `title="Подключение двухфакторной аутентификации"`, `isDirty={step > 1}`.

**Шаги: state enum `SetupStep = 1 | 2 | 3 | 4`**

Прогресс-индикатор вверху тела модала:

```
[ 1: Приложение ] — [ 2: QR-код ] — [ 3: Проверка ] — [ 4: Резервные коды ]
```

Реализация: 4 `div` с номером и лейблом в строку. Активный шаг — `text-primary font-semibold`, разделители — `text-gray-300 mx-2 text-xs`. Не общий компонент Stepper (его нет), вёрстка прямо в модале.

```
┌───────────────────────────────────────────────────────┐
│ Подключение двухфакторной аутентификации         [X]  │
├───────────────────────────────────────────────────────┤
│                                                       │
│  1: Приложение  ›  2: QR-код  ›  3: Проверка  ›  4: Коды  │
│                                                       │
│  ┌ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┐     │
│  │              [Контент шага]                 │     │
│  └ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┘     │
│                                                       │
├───────────────────────────────────────────────────────┤
│  [ btn-ghost: Отмена ]       [ btn-secondary: Назад ] │
│                              [ btn-primary: Далее ]   │
└───────────────────────────────────────────────────────┘
```

**Footer кнопки по шагам:**

| Шаг | Левый | Правый |
|---|---|---|
| 1 | `btn-ghost` Отмена | `btn-primary` Далее |
| 2 | `btn-ghost` Отмена / `btn-secondary` Назад | `btn-primary` Далее |
| 3 | `btn-ghost` Отмена / `btn-secondary` Назад | `btn-primary` Проверить (loading: «Проверяем…») |
| 4 | — (кнопки Отмена/Назад убрать) | `btn-primary` Я сохранил коды, продолжить |

---

#### Шаг 1 — «Скачайте приложение»

```
┌─────────────────────────────────────────────────────┐
│  h4: Шаг 1. Установите приложение-аутентификатор    │
│                                                      │
│  p.text-sm.text-gray-600:                            │
│  «Для генерации кодов нужно приложение              │
│  на смартфоне. Выберите любое:»                     │
│                                                      │
│  ┌──────────────────────────────────────────┐       │
│  │ bi-phone  Google Authenticator           │       │
│  │           App Store | Google Play        │       │
│  ├──────────────────────────────────────────┤       │
│  │ bi-phone  Яндекс.Ключ                    │       │
│  │           App Store | Google Play        │       │
│  ├──────────────────────────────────────────┤       │
│  │ bi-phone  Microsoft Authenticator        │       │
│  │           App Store | Google Play        │       │
│  └──────────────────────────────────────────┘       │
│                                                      │
│  p.text-sm.text-gray-500:                            │
│  «Уже установлено? Жми Далее»                       │
└─────────────────────────────────────────────────────┘
```

- Список приложений: `div.space-y-2`. Каждый элемент: `div.flex items-start gap-3 p-3 rounded-lg border border-gray-200`.
- Иконка: `bi-phone text-xl text-gray-400`.
- Название: `text-sm font-medium text-gray-900`.
- Ссылки магазинов: `<a>` с `text-xs text-primary underline` в строку через `·`.
- На шаге 1 НЕ вызывается API — только контент.

**Ссылки магазинов (копи):**

| Приложение | App Store | Google Play |
|---|---|---|
| Google Authenticator | `https://apps.apple.com/app/google-authenticator/id388497605` | `https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2` |
| Яндекс.Ключ | `https://apps.apple.com/ru/app/id957324816` | `https://play.google.com/store/apps/details?id=ru.yandex.key` |
| Microsoft Authenticator | `https://apps.apple.com/app/microsoft-authenticator/id983156458` | `https://play.google.com/store/apps/details?id=com.azure.authenticator` |

---

#### Шаг 2 — «Отсканируйте QR-код»

При переходе на шаг 2 (нажали «Далее» с шага 1): вызвать `POST /auth/2fa/setup` и получить `{ qr_base64: string, manual_code: string, otpauth_uri: string }`.

Показывать **skeleton** пока запрос летит: `div.animate-pulse` квадрат 200x200 `bg-gray-100 rounded`.

```
┌─────────────────────────────────────────────────────┐
│  h4: Шаг 2. Отсканируйте QR-код                     │
│                                                      │
│  p.text-sm.text-gray-600:                            │
│  «Откройте приложение → нажмите «+» → «Сканировать  │
│  QR-код» — наведите камеру на код ниже.»            │
│                                                      │
│  ┌──────────────────────────────────────────┐       │
│  │   [img: QR base64, 200x200, mx-auto]     │       │
│  └──────────────────────────────────────────┘       │
│                                                      │
│  «Не можете отсканировать?» (link, toggle)           │
│  ↓ (если раскрыто)                                   │
│  «Введите код вручную:»                             │
│  div.font-mono.text-sm.bg-gray-50.rounded.p-3.      │
│  select-all.tracking-widest: XXXX XXXX XXXX …       │
│  [ bi-clipboard Скопировать ] (btn-ghost text-xs)   │
└─────────────────────────────────────────────────────┘
```

- QR: `<img src={`data:image/png;base64,${qr_base64}`} alt="QR-код для 2FA" className="w-48 h-48 mx-auto border border-gray-200 rounded" />`.
- Раздел «вручную»: `div.mt-3 space-y-2`, скрыт по умолчанию, раскрывается по клику на ссылку.
- Manual code в `<span className="font-mono text-sm bg-gray-50 rounded p-3 select-all tracking-widest block">`.
- Кнопка «Скопировать» использует `navigator.clipboard.writeText(manual_code)` → после нажатия текст кнопки меняется на «Скопировано» на 2 секунды.
- Если `POST /auth/2fa/setup` вернул ошибку — inline `text-danger text-sm` + кнопка «Попробовать снова».

---

#### Шаг 3 — «Введите код»

```
┌─────────────────────────────────────────────────────┐
│  h4: Шаг 3. Подтвердите настройку                   │
│                                                      │
│  p.text-sm.text-gray-600:                            │
│  «Введите 6-значный код из приложения,              │
│  чтобы убедиться что всё настроено верно.»          │
│                                                      │
│  label.label: Код из приложения *                   │
│  input.input type=text inputmode=numeric             │
│    maxLength=6 placeholder="000000"                  │
│    autoFocus pattern=[0-9]{6}                        │
│                                                      │
│  [error inline: text-danger text-sm]                 │
└─────────────────────────────────────────────────────┘
```

- При нажатии «Проверить»: `POST /auth/2fa/verify-setup` с `{ totp_code: "123456" }`.
- Response success: `{ backup_codes: string[] }` — сохранить в state, перейти на шаг 4.
- Response error (422/400): показать inline `text-danger`: «Неверный код. Проверь приложение и попробуй снова.»
- Input: очищать при ошибке (`setCode("")` + `autoFocus`).
- `loading` state кнопки: `disabled + «Проверяем…»`.

---

#### Шаг 4 — «Сохраните резервные коды»

```
┌─────────────────────────────────────────────────────┐
│  h4: Шаг 4. Резервные коды восстановления           │
│                                                      │
│  div.rounded-md.bg-danger/10.text-danger.px-3.py-2  │
│  .text-sm.flex.gap-2:                               │
│  bi-exclamation-triangle                            │
│  «Эти коды показываются только один раз.            │
│  Сохрани их в безопасном месте.                     │
│  Каждый код можно использовать только один раз.»   │
│                                                      │
│  div.grid.grid-cols-2.gap-2.my-4:                   │
│  8 ячеек — каждая code:                             │
│  span.font-mono.text-sm.bg-gray-50.rounded.         │
│  px-3.py-1.5.text-center.select-all                 │
│                                                      │
│  [ btn-secondary: bi-download Скачать .txt ]         │
└─────────────────────────────────────────────────────┘
```

- Backup codes: `string[]` из шага 3, 8 штук. Формат — **8-значные hex strings** (`a1b2c3d4`), генерируются backend через `secrets.token_hex(4)`.
- Grid: `grid grid-cols-2 gap-2`.
- Каждый код: `<span className="font-mono text-sm bg-gray-50 rounded px-3 py-1.5 text-center select-all block">`. Пример отображения: `a1b2c3d4`.
- Кнопка «Скачать .txt»: генерирует Blob с кодами по одному на строку (plain text), `a.download="macro-crm-backup-codes.txt"` → click.
- Footer шага 4: только `btn-primary` «Я сохранил коды, продолжить» (кнопки «Отмена» и «Назад» убрать). При нажатии — закрыть Modal, вызвать `mutate()` для обновления `useMe`, затем `router.push("/profile/security?2fa=enabled")`.
- Страница `/profile/security` при маунте читает `?2fa=enabled` → показывает success-banner `div.rounded-md.bg-success/10.text-success.px-3.py-2.text-sm.flex.gap-2` с `bi-check-circle` и текстом «Двухфакторная аутентификация подключена». Баннер автоисчезает через 5 секунд. URL очищается через `window.history.replaceState({}, "", "/profile/security")`.

---

### 1.2a Диаграмма состояний TwoFactorSetupModal

```
                  ┌─────────────────────────────────────────────────────┐
                  │          TwoFactorSetupModal                        │
                  │                                                     │
    [open]        │  step=1: INSTALL_APP                                │
   ──────────►    │  ┌──────────────────────────┐                       │
                  │  │ Шаг 1. Установите         │                       │
                  │  │ приложение-аутентификатор │                       │
                  │  │ (список: GA / Яндекс.Ключ │                       │
                  │  │  / Microsoft Authenticator│                       │
                  │  │  с ссылками на магазины)  │                       │
                  │  │                           │                       │
                  │  │ [Отмена] [Далее ─────────►]── нажать «Далее»     │
                  │  └──────────────────────────┘   → POST /auth/2fa/setup
                  │                                                     │
                  │  step=2: SCAN_QR                                    │
                  │  ┌──────────────────────────┐                       │
                  │  │ Loading: animate-pulse    │ ← пока POST летит    │
                  │  │ QR base64 img 200x200     │ ← ответ получен      │
                  │  │ toggle «Ввести вручную»   │                       │
                  │  │ manual_code (monospace)   │                       │
                  │  │ «Скопировать» → 2 сек     │                       │
                  │  │                           │                       │
                  │  │ [Отмена][Назад] [Далее ──►]── нажать «Далее»     │
                  │  │                           │   (без API-вызова)   │
                  │  │ [Попробовать снова] ◄──── │ ← POST вернул ошибку │
                  │  └──────────────────────────┘                       │
                  │            │ «Назад»                                │
                  │            ▼ (вернуться на step=1)                  │
                  │                                                     │
                  │  step=3: VERIFY_CODE                                │
                  │  ┌──────────────────────────┐                       │
                  │  │ input 6 цифр autoFocus   │                       │
                  │  │ [Отмена][Назад]           │                       │
                  │  │ [Проверить] → «Проверяем…»│── POST /auth/2fa/verify-setup
                  │  │                           │                       │
                  │  │ ошибка 422/400:           │                       │
                  │  │ inline text-danger,       │                       │
                  │  │ input очищается + focus   │◄── неверный код       │
                  │  └──────────────────────────┘                       │
                  │            │ верный код                             │
                  │            │ backup_codes[] в state                 │
                  │            ▼                                        │
                  │  step=4: SAVE_CODES                                 │
                  │  ┌──────────────────────────┐                       │
                  │  │ Предупреждение danger    │                       │
                  │  │ Grid 2x4 hex кодов       │                       │
                  │  │ [Скачать .txt]            │                       │
                  │  │                           │                       │
                  │  │ [Я сохранил коды ────────►]── закрыть Modal      │
                  │  │                           │   mutate() useMe      │
                  │  │                           │   router.push(        │
                  │  │                           │   "/profile/security  │
                  │  │                           │    ?2fa=enabled")     │
                  │  └──────────────────────────┘                       │
                  │                                                     │
   [X / Отмена]   │   isDirty={step > 1}: нажатие [X] на шагах 2-4    │
   ─────────────► │   не вызывает confirm, просто закрывает Modal       │
                  │   (TOTP secret уже создан, но не активирован)       │
                  └─────────────────────────────────────────────────────┘
```

**Ключевые переходы:**

| Откуда | Куда | Триггер | Side effect |
|---|---|---|---|
| `INSTALL_APP` | `SCAN_QR` | «Далее» | `POST /auth/2fa/setup` → сохранить `{ qr_base64, manual_code }` в state |
| `SCAN_QR` | `INSTALL_APP` | «Назад» | очистить QR state (при повторном шаге 2 снова вызовется POST) |
| `SCAN_QR` | `VERIFY_CODE` | «Далее» | без API |
| `VERIFY_CODE` | `SCAN_QR` | «Назад» | очистить поле кода |
| `VERIFY_CODE` | `SAVE_CODES` | «Проверить» + 200 OK | `backup_codes[]` в state |
| `VERIFY_CODE` | `VERIFY_CODE` | «Проверить» + 422 | inline error, input очистить |
| `SAVE_CODES` | (closed) | «Я сохранил коды» | `mutate()` + `router.push("/profile/security?2fa=enabled")` |
| Любой шаг | (closed) | «Отмена» / «X» | Modal закрывается, TOTP не активирован |

---

### 1.3 TwoFactorConfirmModal (для «Отключить 2FA» и «Сгенерировать коды»)

Отдельный небольшой Modal `width="sm"` для действий, требующих подтверждения TOTP.

**Props:** `mode: "disable" | "new-backup-codes"`, `onSuccess: (backupCodes?: string[]) => void`.

```
┌──────────────────────────────────────────────────────┐
│ [disable]  Отключить 2FA                         [X] │
│ [codes]    Сгенерировать резервные коды           [X] │
├──────────────────────────────────────────────────────┤
│                                                      │
│  p.text-sm.text-gray-600:                            │
│  [disable] «Для подтверждения введи текущий         │
│            код из приложения-аутентификатора.»      │
│  [codes]   «Введи код из приложения.               │
│             Текущие коды станут недействительными.» │
│                                                      │
│  label.label: Код из приложения *                   │
│  input.input type=text inputmode=numeric             │
│    maxLength=6 placeholder="000000" autoFocus        │
│                                                      │
│  p.text-sm.text-gray-500: «Нет телефона?            │
│  <a href> Используй резервный код</a>»              │
│  ↓ (если раскрыто) — input для резервного кода      │
│  input.input type=text maxLength=8                  │
│    placeholder="a1b2c3d4" pattern="[a-f0-9]{8}"    │
│    className="font-mono tracking-widest"            │
│                                                      │
│  [error inline: text-danger text-sm]                 │
│                                                      │
├──────────────────────────────────────────────────────┤
│  [ btn-ghost: Отмена ]                               │
│  [disable] [ btn-secondary text-danger: Отключить ]  │
│  [codes]   [ btn-primary: Подтвердить ]              │
└──────────────────────────────────────────────────────┘
```

- `mode="disable"` → `POST /auth/2fa/disable` с `{ totp_code }` или `{ backup_code }` → success → `mutate()`.
- `mode="new-backup-codes"` → `POST /auth/2fa/regenerate-backup-codes` с `{ totp_code }` → response `{ backup_codes: string[] }` → передать в `onSuccess(backupCodes)`.
- После `new-backup-codes` — показать коды так же, как шаг 4 (можно переиспользовать sub-компонент `BackupCodesDisplay`).

---

### 1.4 Карточка «Подключённые аккаунты» (SsoAccountsCard)

**Data source:** `GET /auth/sso/links` → `SSOLink[]`.

```
interface SSOLink {
  provider: "google" | "yandex";
  provider_email: string;
  linked_at: string;
}
```

Требуется правка backend: эндпоинт `GET /auth/sso/links` возвращает список подключённых провайдеров текущего пользователя.

```
┌───────────────────────────────────────────────────────┐
│  i.bi-link-45deg text-xl text-primary                 │
│  h3: Подключённые аккаунты                            │
│  p.text-sm.text-gray-600: «Используй для быстрого    │
│  входа — без ввода пароля.»                          │
│                                                       │
│  ┌──────────────────────────────────────────────┐    │
│  │  [Google logo svg, 20px]  Google             │    │
│  │  [LINKED]  user@macroglobaltech.com  14 мая  │    │
│  │  [ btn-ghost text-danger: Отключить ]        │    │
│  │  [NOT LINKED]  «Не подключён»                │    │
│  │  [ btn-secondary: Подключить ]               │    │
│  ├──────────────────────────────────────────────┤    │
│  │  [Yandex logo svg, 20px]  Yandex             │    │
│  │  … (аналогично)                              │    │
│  └──────────────────────────────────────────────┘    │
│                                                       │
│  [ПРЕДУПРЕЖДЕНИЕ — если нет пароля и последний SSO]  │
│  div.rounded-md.bg-warning/10.text-warning.p-3.text-sm│
│  bi-exclamation-triangle «Это единственный способ   │
│  входа. Если отключишь — не сможешь войти.          │
│  Сначала установи пароль.»                           │
│  [ btn-secondary: Перейти к смене пароля ]           │
└───────────────────────────────────────────────────────┘
```

**Иконки провайдеров:** нет в Bootstrap Icons → использовать SVG-логотипы инлайн (простые, не зависимости). Помести в `components/Security/ProviderIcon.tsx` — компонент принимает `provider: "google" | "yandex"` и рендерит SVG 20x20.

**Строка провайдера (ProviderRow):**

| Состояние | UI |
|---|---|
| loading | `div.animate-pulse h-5 bg-gray-100 rounded w-48` |
| linked | Иконка + название + email (`text-sm text-gray-600`) + дата (`text-xs text-gray-400`) + `btn-ghost text-danger text-xs` «Отключить» |
| not_linked | Иконка + название + `text-sm text-gray-400 italic` «Не подключён» + `btn-secondary text-xs` «Подключить» |

**Inline confirm отключения:** не Modal, а inline expand под строкой:

```
div.mt-2.p-3.bg-gray-50.rounded-md.border.border-gray-200 (animated reveal)
  p.text-sm: «Отключить Google? Для входа придётся использовать пароль.»
  div.flex.gap-2.mt-2:
    [ btn-ghost text-xs: Отмена ]
    [ btn-ghost text-danger text-xs: bi-x-circle Да, отключить ]
```

**Предупреждение «только SSO»:** показывается если `user.has_password === false` И `links.length === 1`. Требуется правка backend: поле `has_password: boolean` в `/auth/me` ответе.

**SWR:** `useSWR<SSOLink[]>("/auth/sso/links", fetcher)`.

**После Link:** `window.location.href = '/api/auth/sso/{provider}/link?return=/profile/security'`. Backend после OAuth делает redirect обратно с `?linked=1` или `?error=...`. Страница читает search params при маунте:

```
// в useEffect при маунте:
const params = new URLSearchParams(window.location.search)
if (params.get("linked")) {
  // показать success banner
  mutate() // обновить SWR
  // убрать ?linked из URL: window.history.replaceState(...)
}
if (params.get("sso_error")) {
  // показать error banner
}
```

**Banner (inline, не toast):** `div.rounded-md.px-3.py-2.text-sm` + `bg-success/10 text-success` или `bg-danger/10 text-danger` под заголовком карточки. Автоисчезание через 5 секунд (`setTimeout → setBanner(null)`).

---

### Тексты раздела 1 (RU)

**Страница:**
- Заголовок: `Безопасность`
- Description PageHeader: `Управление двухфакторной аутентификацией и внешними аккаунтами`
- Навигационный пункт: `Безопасность` (рядом с «Профиль»)

**Карточка 2FA — disabled state:**
- Заголовок: `Двухфакторная аутентификация`
- Описание: `Защитите аккаунт дополнительным кодом из приложения-аутентификатора (Google Authenticator, Яндекс.Ключ и др.)`
- Кнопка: `Подключить 2FA`

**Карточка 2FA — enabled state:**
- Badge: `2FA активирована`
- Дата: `Подключена: {дата}`
- Кнопка генерации: `Сгенерировать новые резервные коды`
- Кнопка отключения: `Отключить 2FA`

**Setup Modal:**
- Заголовок модала: `Подключение двухфакторной аутентификации`
- Шаги: `Приложение` / `QR-код` / `Проверка` / `Резервные коды`
- Шаг 1, h4: `Шаг 1. Установите приложение-аутентификатор`
- Шаг 1, описание: `Для генерации кодов нужно приложение на смартфоне. Выберите любое:`
- Шаг 1, подсказка: `Уже установлено? Жми Далее`
- Шаг 2, h4: `Шаг 2. Отсканируйте QR-код`
- Шаг 2, описание: `Откройте приложение → нажмите «+» → «Сканировать QR-код» — наведите камеру на код ниже.`
- Шаг 2, toggle: `Не можете отсканировать?`
- Шаг 2, manual label: `Введите код вручную:`
- Шаг 2, copy btn: `Скопировать` / `Скопировано`
- Шаг 3, h4: `Шаг 3. Подтвердите настройку`
- Шаг 3, описание: `Введите 6-значный код из приложения, чтобы убедиться что всё настроено верно.`
- Шаг 3, label: `Код из приложения *`
- Шаг 3, placeholder: `000000`
- Шаг 3, error: `Неверный код. Проверь приложение и попробуй снова.`
- Шаг 4, h4: `Шаг 4. Резервные коды восстановления`
- Шаг 4, предупреждение: `Эти коды показываются только один раз. Сохрани их в безопасном месте. Каждый код можно использовать только один раз.`
- Шаг 4, скачать: `Скачать .txt`
- Шаг 4, кнопка finish: `Я сохранил коды, продолжить`
- Footer universal: `Отмена` / `Назад` / `Далее` / `Проверить` / `Проверяем…`

**Success banner (после завершения Setup):**
- Текст: `Двухфакторная аутентификация подключена`
- (Появляется на `/profile/security` при `?2fa=enabled`, исчезает через 5 сек)

**Confirm Modal — disable:**
- Заголовок: `Отключить 2FA`
- Описание: `Для подтверждения введи текущий код из приложения-аутентификатора.`
- Ссылка запасного: `Нет телефона? Используй резервный код`
- Кнопка подтверждения: `Отключить`
- Error: `Неверный код. Попробуй ещё раз.`

**Confirm Modal — new backup codes:**
- Заголовок: `Сгенерировать резервные коды`
- Описание: `Введи код из приложения. Текущие резервные коды станут недействительными.`
- Кнопка: `Подтвердить`

**Карточка SSO:**
- Заголовок: `Подключённые аккаунты`
- Описание: `Используй для быстрого входа — без ввода пароля.`
- Статус не подключён: `Не подключён`
- Кнопка подключить: `Подключить`
- Кнопка отключить: `Отключить`
- Inline confirm: `Отключить Google? Для входа придётся использовать пароль.`
- Inline confirm Yandex: `Отключить Yandex? Для входа придётся использовать пароль.`
- Inline confirm кнопка: `Да, отключить`
- Дата: `Подключён: {дата}`
- Предупреждение единственный способ: `Это единственный способ входа. Если отключишь — не сможешь войти. Сначала установи пароль.`
- Кнопка установить пароль: `Перейти к смене пароля`
- Success banner linked: `Аккаунт подключён`
- Error banner domain: `Этот домен не разрешён. Только @macroglobaltech.com аккаунты могут войти через Google.`
- Error banner generic: `Не удалось подключить аккаунт. Попробуй ещё раз.`

---

## Раздел 2 — `/auth/2fa` (страница второго шага логина)

### Где в коде

- Страница: `apps/web/src/app/auth/2fa/page.tsx`
- Вне `(app)/` layout — без Sidebar, как `/login`.

### Wireframe

```
┌──────────────────────────────────────────┐
│                                          │
│           [Logo]                         │
│                                          │
│  ┌──────────────────────────────────┐   │
│  │  bi-shield-lock  text-xl         │   │
│  │  h2: Двухфакторная               │   │
│  │      аутентификация              │   │
│  │  p: «Введите код из приложения-  │   │
│  │  аутентификатора»               │   │
│  │                                  │   │
│  │  [TAB: Код из приложения]        │   │
│  │  [TAB: Резервный код]            │   │
│  │                                  │   │
│  │  [STATE: totp tab]               │   │
│  │  input 6-значный, autoFocus      │   │
│  │  [error inline]                  │   │
│  │  [btn-primary w-full: Войти]     │   │
│  │                                  │   │
│  │  [STATE: backup tab]             │   │
│  │  input 8-значный                 │   │
│  │  [error inline]                  │   │
│  │  [btn-primary w-full: Войти]     │   │
│  │                                  │   │
│  │  [блокировка после 5 попыток]   │   │
│  │  div.bg-warning/10.text-warning  │   │
│  │  bi-clock «Слишком много попыток.│   │
│  │  Подожди или проверь почту.»    │   │
│  │                                  │   │
│  │  <a href="/login">               │   │
│  │    ← Назад к входу              │   │
│  │  </a>                            │   │
│  └──────────────────────────────────┘  │
│                                          │
└──────────────────────────────────────────┘
```

### Layout

- `min-h-screen flex items-center justify-center bg-gray-100 p-4` — идентично `/login`.
- Контейнер: `w-full max-w-md`.
- Логотип: `<Logo />` из `@/components/Logo`, `mb-8 flex justify-center`.
- Карточка: `card p-8`.

### State machine

| Состояние | Условие | UI |
|---|---|---|
| `totp` | Начальный режим | 6-значный input, autoFocus |
| `backup` | Переключился на вкладку «Резервный код» | 8-значный input, другой placeholder |
| `loading` | Запрос летит | `disabled` input + кнопка «Входим…» |
| `error` | Неверный код (< 5 попыток) | Inline `text-danger text-sm`, input очищается + autoFocus |
| `locked` | 5+ неверных попыток (backend 429) | Блок предупреждения, input скрыт, кнопка «Войти» disabled |
| `success` | Код принят | `router.push("/contracts")` |

### Переключение режимов (TOTP / Backup)

Две «вкладки» — не настоящий Tab-компонент, а два `button` рядом в `div.flex.border-b.border-gray-200.mb-4`:

```
[Код из приложения]  |  [Резервный код]
```

Активная вкладка: `border-b-2 border-primary text-primary font-medium text-sm pb-2 px-1`.
Неактивная: `text-gray-500 text-sm pb-2 px-1 hover:text-gray-700`.

При переключении: очистить поле, очистить ошибку, reset счётчик попыток в локальном стейте.

### Input TOTP (6-значный)

```
<input
  type="text"
  inputMode="numeric"
  pattern="[0-9]*"
  maxLength={6}
  autoComplete="one-time-code"
  placeholder="000000"
  className="input text-center text-2xl tracking-[0.5em] font-mono"
/>
```

**Auto-submit:** при длине === 6 символов автоматически вызывать submit (без нажатия кнопки). Показывать кнопку «Войти» серой/disabled пока < 6 цифр.

### Input Backup Code (8-значный hex)

```
<input
  type="text"
  inputMode="text"
  maxLength={8}
  pattern="[a-f0-9]{8}"
  placeholder="a1b2c3d4"
  className="input text-center text-xl tracking-widest font-mono lowercase"
/>
```

- Формат: hex строка `[a-f0-9]{8}`, пример `a1b2c3d4`.
- `inputMode="text"` (не numeric — есть буквы a-f).
- `lowercase` — приводить ввод к нижнему регистру на `onChange` (`value.toLowerCase()`).
- Нет auto-submit — нужно нажать «Войти» вручную.
- Клиентская pre-валидация перед submit: если `!/^[a-f0-9]{8}$/.test(code)` → inline error «Резервный код: 8 символов, только цифры и буквы a–f» (не дёргать API).

### API

- `POST /auth/2fa/validate` с `{ totp_code?: string, backup_code?: string }` и cookie `temp_token`.
- Response success: устанавливает `access_token` cookie → `router.push("/contracts")`.
- Response 401: неверный код → error state.
- Response 429: превышен лимит (5 попыток) → `locked` state.
- При redirect на `/auth/2fa` URL содержит параметр или нет — если `temp_token` cookie отсутствует (пользователь зашёл напрямую) → `router.replace("/login")`.

### Тексты раздела 2 (RU)

- Заголовок карточки: `Двухфакторная аутентификация`
- Описание: `Введите код из приложения-аутентификатора`
- Вкладка 1: `Код из приложения`
- Вкладка 2: `Резервный код`
- Placeholder TOTP: `000000`
- Placeholder backup: `a1b2c3d4`
- Кнопка: `Войти`
- Loading кнопка: `Входим…`
- Error неверный код: `Неверный код. Попробуй ещё раз.`
- Error backup формат: `Резервный код: 8 символов, только цифры и буквы a–f`
- Error locked: `Слишком много неверных попыток. Подожди немного или обратись к администратору.`
- Ссылка назад: `← Назад к входу`

---

## Раздел 3 — `/login` (дополнение SSO кнопок)

### Где в коде

- Страница (существующая): `apps/web/src/app/login/page.tsx`
- Новый компонент: `apps/web/src/components/Auth/SsoButtons.tsx`

### Wireframe (изменение)

```
┌──────────────────────────────────────────┐
│  [Logo]                                  │
│                                          │
│  ┌──────────────────────────────────┐   │
│  │  Вход в систему                  │   │
│  │  MACRO Global                    │   │
│  │                                  │   │
│  │  [Email input]                   │   │
│  │  [Password input]                │   │
│  │  [error]                         │   │
│  │  [btn-primary: Войти]            │   │
│  │                                  │   │  ← добавить ниже формы
│  │  ──────  или  ──────             │   │
│  │                                  │   │
│  │  [Google btn]  [Yandex btn]      │   │
│  └──────────────────────────────────┘  │
│                                          │
└──────────────────────────────────────────┘
```

### Разделитель

```html
<div className="relative my-5">
  <div className="absolute inset-0 flex items-center">
    <div className="w-full border-t border-gray-200" />
  </div>
  <div className="relative flex justify-center text-xs uppercase tracking-wide">
    <span className="bg-white px-3 text-gray-400">или</span>
  </div>
</div>
```

### SsoButtons компонент

```
interface SsoButtonsProps {
  ssoError?: string | null; // из URL search params
}
```

Два компонента кнопок в строку: `div.flex.gap-3`.

**Кнопка Google:**

```
<button
  type="button"
  onClick={() => { window.location.href = '/api/auth/sso/google/start'; }}
  className="btn-secondary flex-1 justify-center gap-2"
>
  <GoogleIcon />   {/* inline SVG, 18x18 */}
  Войти через Google
</button>
```

**Кнопка Yandex:**

```
<button
  type="button"
  onClick={() => { window.location.href = '/api/auth/sso/yandex/start'; }}
  className="btn-secondary flex-1 justify-center gap-2"
>
  <YandexIcon />   {/* inline SVG, 18x18 */}
  Войти через Yandex
</button>
```

- Оба `btn-secondary`, равная ширина (`flex-1`).
- SVG иконки: статичные, 18x18, помести в `components/Auth/SsoIcons.tsx`.
- Google SVG — стандартный цветной G логотип (4 пути, официальные цвета).
- Yandex SVG — красный Y логотип.
- `type="button"` — чтобы не сабмитить форму логина.

**Обработка SSO ошибок на /login:**

Backend делает redirect на `/login?sso_error=domain_not_allowed` (или `?sso_error=oauth_failed`). Страница при маунте читает param:

```typescript
// В LoginPage useEffect при маунте:
const params = new URLSearchParams(window.location.search);
const ssoErr = params.get("sso_error");
if (ssoErr) {
  setSsoError(ssoErr);
  window.history.replaceState({}, "", "/login");
}
```

Ошибка показывается **отдельным блоком под SSO-кнопками** (не в существующем `{error}` формы пароля) — это badge с иконкой:

```tsx
{ssoError && (
  <div className="flex items-start gap-2 rounded-md bg-danger/10 text-danger px-3 py-2 text-sm mt-3">
    <i className="bi bi-exclamation-triangle mt-0.5 shrink-0" />
    <span>
      {ssoError === "domain_not_allowed"
        ? "Вход разрешён только для аккаунтов @macroglobaltech.com"
        : "Не удалось войти через внешний аккаунт. Попробуй ещё раз."}
    </span>
  </div>
)}
```

State: `const [ssoError, setSsoError] = useState<string | null>(null)` — отдельный от `error` (ошибки формы email/пароль).

### Тексты раздела 3 (RU)

- Разделитель: `или`
- Кнопка Google: `Войти через Google`
- Кнопка Yandex: `Войти через Yandex`
- SSO Error badge (`bi-exclamation-triangle bg-danger/10 text-danger`), `domain_not_allowed`: `Вход разрешён только для аккаунтов @macroglobaltech.com`
- SSO Error badge, `oauth_failed` (и любая другая ошибка): `Не удалось войти через внешний аккаунт. Попробуй ещё раз.`

---

## Раздел 4 — `/admin/api-tokens` (Rate Limit поле)

### Где в коде

- Страница (существующая): `apps/web/src/app/(app)/admin/api-tokens/page.tsx` — без изменений.
- Компонент (существующий, изменить): `apps/web/src/components/ApiTokens/CreateApiTokenModal.tsx`
- Компонент (существующий, изменить): `apps/web/src/components/ApiTokens/ApiTokensTable.tsx`

### 4.1 CreateApiTokenModal — новое поле

Добавить после существующего поля «Действует до» новый блок:

```
┌──────────────────────────────────────────────────────┐
│  label.label: Лимит запросов в час                   │
│  div.flex.items-center.gap-2:                        │
│    input.input type=number min=10 max=100000         │
│      value=1000 className="w-40"                     │
│    span.text-sm.text-gray-500: «запросов/час»        │
│                                                      │
│  p.text-xs.text-gray-400.mt-1:                       │
│  «Запросы, превышающие лимит, получают              │
│  429 Too Many Requests. Защита от случайных          │
│  циклов в интеграциях.»                             │
└──────────────────────────────────────────────────────┘
```

**State:**

```typescript
const [rateLimitPerHour, setRateLimitPerHour] = useState<number>(1000);
```

**Валидация при submit:**

```typescript
if (rateLimitPerHour < 10 || rateLimitPerHour > 100000) {
  setError("Лимит должен быть от 10 до 100 000 запросов в час");
  return;
}
```

**В теле запроса** `POST /api-tokens`:

```typescript
body: {
  name: name.trim(),
  scopes,
  expires_at: expiresAt || undefined,
  rate_limit_per_hour: rateLimitPerHour,   // добавить
}
```

Требуется правка backend: добавить поле `rate_limit_per_hour: int` (default 1000) в `APITokenCreate` Pydantic-схему и `APIToken` ответ.

### 4.2 ApiTokensTable — новая колонка

Добавить колонку **«Лимит/час»** между «Истекает» и «Статус»:

```
<th className="px-4 py-3 text-left">Лимит/час</th>
```

В строке:

```tsx
<td className="px-4 py-3 text-gray-600 whitespace-nowrap">
  {t.rate_limit_per_hour.toLocaleString("ru-RU")}
</td>
```

- Значение: `t.rate_limit_per_hour` — число, форматировать с разделителями (`1 000`, `100 000`).
- Если backend старого токена не имеет поля — показывать `1 000` (default).

### 4.3 Обновление типа APIToken

В `apps/web/src/lib/types.ts` добавить поле:

```typescript
export interface APIToken {
  // ... существующие поля ...
  rate_limit_per_hour: number;   // добавить
}
```

### Interactions раздела 4

| Элемент | Действие | Результат |
|---|---|---|
| Input «Лимит/час» | blur, значение < 10 | Inline error под полем |
| Input «Лимит/час» | blur, значение > 100 000 | Inline error под полем |
| Кнопка «Создать» | click | Отправить `rate_limit_per_hour` в теле запроса |
| Колонка «Лимит/час» | — | Показывает текущее значение форматированным числом |

### Тексты раздела 4 (RU)

- Label: `Лимит запросов в час`
- Единица: `запросов/час`
- Help text: `Запросы, превышающие лимит, получают 429 Too Many Requests. Защита от случайных циклов в интеграциях.`
- Заголовок колонки: `Лимит/час`
- Error валидации: `Лимит должен быть от 10 до 100 000 запросов в час`

---

## Список новых/изменённых компонентов

| Компонент | Статус | Путь |
|---|---|---|
| `TwoFactorCard` | новый | `components/Security/TwoFactorCard.tsx` |
| `TwoFactorSetupModal` | новый | `components/Security/TwoFactorSetupModal.tsx` |
| `TwoFactorConfirmModal` | новый | `components/Security/TwoFactorConfirmModal.tsx` |
| `BackupCodesDisplay` | новый (sub) | `components/Security/BackupCodesDisplay.tsx` |
| `SsoAccountsCard` | новый | `components/Security/SsoAccountsCard.tsx` |
| `ProviderIcon` | новый | `components/Security/ProviderIcon.tsx` |
| `SsoButtons` | новый | `components/Auth/SsoButtons.tsx` |
| `SsoIcons` | новый | `components/Auth/SsoIcons.tsx` |
| `Sidebar` | изменить | `components/Sidebar.tsx` — добавить подпункты «Личное» / «Безопасность» / «Уведомления» в группу «Профиль» |
| `apps/web/src/app/(app)/profile/security/page.tsx` | новый | — |
| `apps/web/src/app/auth/2fa/page.tsx` | новый | — |
| `apps/web/src/app/login/page.tsx` | изменить | добавить SSO блок + sso_error handling |
| `components/ApiTokens/CreateApiTokenModal.tsx` | изменить | добавить rate_limit_per_hour поле |
| `components/ApiTokens/ApiTokensTable.tsx` | изменить | добавить колонку Лимит/час |
| `apps/web/src/lib/types.ts` | изменить | `APIToken.rate_limit_per_hour`, `User.totp_enabled`, `User.totp_enabled_at`, `User.has_password` |

---

## Interactions — сводная таблица

| Элемент | Действие | Результат |
|---|---|---|
| Кнопка «Подключить 2FA» | click | Открыть `TwoFactorSetupModal` на шаге 1 |
| Кнопка «Далее» в модале (шаг 1) | click | Перейти на шаг 2, вызвать `POST /auth/2fa/setup` |
| Кнопка «Далее» (шаг 2) | click | Перейти на шаг 3 |
| Кнопка «Проверить» (шаг 3) | click | `POST /auth/2fa/verify-setup` → шаг 4 или error |
| Кнопка «Я сохранил коды» (шаг 4) | click | Закрыть Modal, `mutate()`, `router.push("/profile/security?2fa=enabled")` |
| Кнопка «Скачать .txt» (шаг 4) | click | Сгенерировать Blob, скачать файл |
| Кнопка «Скопировать» (шаг 2) | click | `clipboard.writeText()`, текст «Скопировано» на 2 сек |
| Кнопка «Отключить 2FA» | click | Открыть `TwoFactorConfirmModal` mode="disable" |
| Кнопка «Сгенерировать коды» | click | Открыть `TwoFactorConfirmModal` mode="new-backup-codes" |
| `TwoFactorConfirmModal` подтверждение | click | API call → success → close + mutate |
| Кнопка «Подключить» (SSO) | click | `window.location.href = '/api/auth/sso/{provider}/link?return=/profile/security'` |
| Кнопка «Отключить» (SSO) | click | Показать inline confirm |
| Inline confirm «Да, отключить» | click | `DELETE /auth/sso/{provider}/unlink` → mutate SWR |
| `/profile/security` — param `?linked=1` | mount | Success banner (success), mutate, replaceState |
| `/profile/security` — param `?2fa=enabled` | mount | Success banner `bi-check-circle bg-success/10 text-success` «Двухфакторная аутентификация подключена», replaceState, исчезает через 5 сек |
| `/profile/security` — param `?sso_error` | mount | Error banner, replaceState |
| `/auth/2fa` — input TOTP 6 цифр | auto | Авто-submit без кнопки |
| `/auth/2fa` — вкладка «Резервный код» | click | Переключить input, очистить ошибку |
| `/auth/2fa` — «Назад к входу» | click | `router.push("/login")` |
| `/login` — «Войти через Google» | click | `window.location.href = '/api/auth/sso/google/start'` |
| `/login` — «Войти через Yandex» | click | `window.location.href = '/api/auth/sso/yandex/start'` |
| `/login` — param `?sso_error=domain_not_allowed` | mount | Inline error, replaceState |
| API-токены — поле «Лимит/час» | change | Обновить локальный state |
| API-токены — кнопка «Создать» | click | Включить `rate_limit_per_hour` в запрос |

---

## States (loading / empty / error) по разделам

### /profile/security

| Блок | Loading | Empty/initial | Error |
|---|---|---|---|
| TwoFactorCard | 2 строки `div.animate-pulse h-4 bg-gray-100 rounded` | State `disabled` (кнопка «Подключить 2FA») | `text-danger text-sm` «Не удалось загрузить статус 2FA» |
| TwoFactorSetupModal шаг 2 (QR) | `div.animate-pulse w-48 h-48 bg-gray-100 rounded mx-auto` | — | Inline `text-danger` + кнопка «Попробовать снова» |
| TwoFactorSetupModal шаг 3 (verify) | Кнопка `disabled` «Проверяем…` | — | Inline `text-danger` под input |
| SsoAccountsCard | Skeleton строки провайдера | State `not_linked` | Inline `text-danger` под карточкой |
| TwoFactorConfirmModal | Кнопка `disabled` «Выполняем…» | — | Inline `text-danger` под input |

### /auth/2fa

| Блок | Loading | Error (< 5 попыток) | Locked (5+ попыток) |
|---|---|---|---|
| Форма | Input + кнопка `disabled`, «Входим…» | `text-danger text-sm` под input, input очищается | `div.bg-warning/10 text-warning` предупреждение, input+кнопка disabled |

### /admin/api-tokens (новое поле)

| Блок | Validation error |
|---|---|
| Поле «Лимит/час» | `text-danger text-xs mt-1` под input |

---

## Связь с backend — API контракты

### Новые эндпоинты (требуются)

| Метод | Путь | Назначение |
|---|---|---|
| `POST` | `/auth/2fa/setup` | Генерирует TOTP secret, возвращает QR + manual code |
| `POST` | `/auth/2fa/verify-setup` | Верифицирует первый TOTP, активирует 2FA, возвращает backup codes |
| `POST` | `/auth/2fa/disable` | Отключает 2FA (требует totp_code или backup_code) |
| `POST` | `/auth/2fa/validate` | Второй шаг логина (принимает temp_token cookie + totp_code) |
| `POST` | `/auth/2fa/regenerate-backup-codes` | Генерирует новые backup codes (требует totp_code) |
| `GET` | `/auth/sso/links` | Список подключённых SSO провайдеров текущего пользователя |
| `GET` | `/auth/sso/{provider}/start` | Редирект на OAuth consent screen |
| `GET` | `/auth/sso/{provider}/callback` | OAuth callback, выдаёт access_token |
| `GET` | `/auth/sso/{provider}/link` | Link SSO к существующему аккаунту (с ?return=) |
| `DELETE` | `/auth/sso/{provider}/unlink` | Отвязать провайдера |

### Response shapes

**`POST /auth/2fa/setup`** → `200 OK`:
```json
{
  "qr_base64": "iVBORw0KGgo...",
  "manual_code": "JBSWY3DPEHPK3PXP",
  "otpauth_uri": "otpauth://totp/MACRO%20CRM:user@example.com?secret=JBSWY3DPEHPK3PXP&issuer=MACRO%20CRM"
}
```

**`POST /auth/2fa/verify-setup`** body: `{ "totp_code": "123456" }`  
→ `200 OK`:
```json
{
  "backup_codes": ["12345678", "23456789", "34567890", "45678901", "56789012", "67890123", "78901234", "89012345"]
}
```
→ `422` при неверном коде.

**`POST /auth/2fa/validate`** body: `{ "totp_code": "123456" }` или `{ "backup_code": "12345678" }`  
→ `200`: устанавливает `access_token` cookie.  
→ `401`: неверный код.  
→ `429`: rate limit (5 попыток / 15 мин).

**`POST /auth/2fa/disable`** body: `{ "totp_code": "123456" }` или `{ "backup_code": "12345678" }`  
→ `200 OK`.

**`POST /auth/2fa/regenerate-backup-codes`** body: `{ "totp_code": "123456" }`  
→ `200 OK`: `{ "backup_codes": [...] }`.

**`GET /auth/sso/links`** → `200 OK`:
```json
[
  {
    "provider": "google",
    "provider_email": "user@macroglobaltech.com",
    "linked_at": "2026-05-14T10:30:00Z"
  }
]
```

**`/auth/me` — расширение** (требуется правка backend):
```json
{
  "totp_enabled": true,
  "totp_enabled_at": "2026-05-14T10:30:00Z",
  "has_password": true
}
```

**`APIToken` — расширение** (требуется правка backend):
```json
{
  "rate_limit_per_hour": 1000
}
```

Все запросы через `api()` / `fetcher()` из `@/lib/api` с `credentials: "same-origin"`.

---

## Адаптивность

Desktop-first. Mobile — TBD (Эпик 10).

Исключения для текущего эпика:
- `/auth/2fa` — уже mobile-дружелюбна (центрированная карточка, как `/login`).
- QR-код на шаге 2: на узком экране `w-48 h-48` достаточно.
- SSO кнопки на `/login`: `flex gap-3`, на узком может переноситься — ок.

---

## Версия

| Версия | Дата | Изменение |
|---|---|---|
| 1.0 | 2026-06-02 | Первая версия ТЗ |
| 1.1 | 2026-06-02 | Финализация: backup codes hex-формат, ProfileNav → Sidebar-подпункты, SSO error badge, textarea→single input, auto-redirect `?2fa=enabled`, диаграмма состояний 2FA Modal |
