# Session flow

Этот документ описывает, как в приложении устроена сессия: где хранится состояние, как оно инициализируется, как выбирается company scope и что происходит после мутаций, которые могут изменить пользователя или компании.

## Короткая модель

Сессия в приложении состоит не из одного объекта. Это связка нескольких слоев:

- `userStore` хранит auth token и текущего пользователя.
- `companiesStore` хранит список компаний и активную компанию.
- `sessionCoordinator` синхронизирует пользователя, компании, locale и active company.
- `userSessionService` выполняет auth/user операции и обновляет `userStore`.
- `Services` содержит низкоуровневые API-сервисы: `authService`, `userService`, `companyService` и остальные.
- `sessionStateService` содержит функции полного или частичного сброса session-related состояния.
- `useSessionMutation` оборачивает обычную мутацию и запускает session refresh effects после успеха.

```text
main.ts
  |-- createApplicationServices
  |     |-- Services
  |     |     |-- authService
  |     |     |-- userService
  |     |     `-- companyService
  |     |-- UserSessionService
  |     |     |-- authService
  |     |     |-- userService
  |     |     `-- userStore
  |     |-- SessionCoordinator
  |     |     |-- UserSessionService
  |     |     |-- userStore
  |     |     |-- CompanySelectionService
  |     |     `-- localeManager
  |     |-- CompanySelectionService
  |     |     |-- companyService
  |     |     |-- companiesStore
  |     |     `-- userStore
  |     `-- UnauthorizedHandler
  |-- configureAxiosMiddleware
  |     `-- onUnauthorized -> UnauthorizedHandler
  |-- configureLocaleCoordinator
  `-- bootstrapApp

UnauthorizedHandler
  `-- resetAuthenticatedSessionState
        |-- userStore
        `-- companiesStore
```

## Что считается состоянием сессии

### `userStore`

Файл: `front/src/stores/user.ts`

Состояние:

- `token: string | null`
- `currentUser: User | null`

Persist:

- сохраняется только `token`;
- `currentUser` после перезагрузки надо заново получить с backend.

Важные getters:

- `getAuthCredential` возвращает token;
- `getIsAuthenticated` проверяет наличие token;
- `getUser` возвращает валидного текущего пользователя или `null`;
- `getUserRole` используется для правил доступа к компаниям;
- `getAvailableCompanyIds` берется из `currentUser.company_accesses`.

Важный нюанс: пользователь считается authenticated на уровне `userStore.getIsAuthenticated`, если есть token. Но полноценная hydrated session есть только когда есть и token, и `currentUser`.

```ts
hasAuthenticatedSession({
  token: authToken,
  currentUser: userStore.getUser,
})
```

### `companiesStore`

Файл: `front/src/stores/companies.ts`

Состояние:

- `companies: Company[]`
- `activeCompanyId: number | null`

Persist:

- сохраняется только `activeCompanyId`;
- список компаний после перезагрузки надо заново получить с backend.

Store отвечает за нормализацию active company:

- если активная компания больше недоступна, выбирается другая доступная;
- если доступных компаний нет, `activeCompanyId` становится `null`;
- если передан `preferredId` и он доступен, выбирается он.

```text
setCompanies(companies, options)
  |
  v
companies = companies
  |
  v
reconcileActiveCompany(...)
  |
  v
normalizeActiveCompany(...)
  |
  v
activeCompanyId
```

### Runtime state координатора

Файл: `front/src/application/session/sessionCoordinator.ts`

Это неперсистентное состояние, которое лежит в `WeakMap<Pinia, SessionRuntimeState>`.

```ts
type SingleFlight<T> = (_task: () => Promise<T>) => Promise<T>

type SessionRuntimeState = {
  flights: {
    initializeSession: SingleFlight<void>
    initializeAuthenticatedSession: SingleFlight<void>
    ensureCompanySelected: SingleFlight<number | null>
  }
  hydratedAuthToken: string | null
  hasInitializedAnonymousScope: boolean
}
```

Зачем оно нужно:

- `flights.initializeSession` не дает нескольким параллельным вызовам `initializeSession()` сделать одинаковую работу;
- `flights.initializeAuthenticatedSession` защищает authenticated initialization;
- `flights.ensureCompanySelected` защищает company scope guard;
- `hydratedAuthToken` запоминает token, для которого уже были загружены user + companies;
- `hasInitializedAnonymousScope` отличает уже подготовленную anonymous session.

`WeakMap` привязан к `Pinia`, поэтому runtime разделяется внутри одного приложения, но может быть сброшен для конкретного Pinia через `resetSessionCoordinatorRuntime(pinia)`.

## Application services и почему `userSessionService` не внутри `services`

`Services` - это низкоуровневые API-сервисы:

```ts
type Services = {
  authService: AuthService
  userService: UserService
  companyService: CompanyService
  reportService: ReportService
  chatService: ChatService
}
```

`UserSessionService` - application-level сервис. Он не просто вызывает API, а обновляет `userStore`:

- `login()` вызывает `authService.login()` и кладет token/user в store;
- `loginWithIframeToken()` делает то же для iframe token;
- `logout()` вызывает backend logout и всегда чистит локальную сессию;
- `refreshCurrentUser()` получает текущего пользователя и кладет его в store;
- `updateCurrentUserLocale()` обновляет locale пользователя и store.

Поэтому `sessionCoordinator` получает обе зависимости:

```ts
createSessionCoordinator({
  pinia,
  services,
  userSessionService,
})
```

И использует:

- `options.userSessionService.refreshCurrentUser()` для обновления пользователя;
- `CompanySelectionService` для загрузки компаний, очистки и нормализации active company.

## Bootstrap приложения

Основной вход: `front/src/main.ts` и `front/src/application/bootstrap/bootstrapApp.ts`.

Порядок в `main.ts`:

1. Создается Vue app и Pinia.
2. Подключается persist plugin.
3. Выставляется initial locale из `localeManager.getInitialLocale()`.
4. Создаются router, raw `services` и `applicationServices`.
5. Настраивается Axios middleware:
   - `getToken` берет token из `userStore`;
   - `onUnauthorized` вызывает `unauthorizedHandler`.
6. Настраивается locale coordinator.
7. Запускается `bootstrapApp(...)`.
8. Router и сервисы предоставляются через Vue provide.
9. После завершения bootstrap и readiness router приложение монтируется.

```text
main.ts
  |
  | createPinia + persist plugin
  v
Pinia stores

main.ts
  | createApplicationServices(pinia, router, services)
  v
applicationServices

main.ts
  | configureAxiosMiddleware(getToken, onUnauthorized)
  v
Axios middleware

main.ts
  | configureLocaleCoordinator(...)
  v
localeCoordinator

main.ts
  | bootstrapApp(...)
  v
bootstrapApp
  |
  `--> bootstrapPromise

main.ts
  | app.use(router)
  | provide services
  | await router.isReady()
  v
mount app
```

## Инициализация сессии

Публичный вход один:

- `initializeSession(options?)` - общий вход, который сам решает anonymous или authenticated path.

Authenticated branch остается внутренней деталью координатора. Внешний код не должен сам решать, какой тип сессии инициализировать.

### Общий путь `initializeSession`

```text
initializeSession()
  |
  v
runSingleFlight(flights.initializeSession)
  |
  v
userStore.getIsAuthenticated?
  |
  |-- no ------------------------------.
  |                                    |
  v                                    v
initializeAuthenticatedSession()   initializeAnonymousSession()
  |                                    |
  v                                    v
canReuseAuthenticatedSession?      localeManager.syncOnce(initialLocale)
  |                                    |
  |-- yes --> localeManager.syncOnce   v
  |                                    companiesStore.clear()
  |                                    |
  `-- no --> hydrateAuthenticatedSession()
             |                         hasInitializedAnonymousScope = true
             |-- refreshUser()
             `-- refreshCompanies()
                  |
                  v
             localeManager.syncOnce(initialLocale)
                  |
                  v
             hydratedAuthToken = authToken
```

### Anonymous session

Anonymous path выбирается, когда `shouldClearCompanyScope(userStore.getIsAuthenticated)` возвращает `true`, то есть когда нет token.

Действия:

- синхронизируется locale через `localeManager.syncOnce(initialLocale)`;
- очищается список компаний;
- `hydratedAuthToken` сбрасывается в `null`;
- `hasInitializedAnonymousScope` становится `true`.

Если anonymous scope уже был initialized, повторный вызов просто чистит компании и не выполняет лишнюю работу.

### Authenticated session

Authenticated path нужен, когда token есть.

Координатор сначала проверяет, можно ли переиспользовать уже hydrated session:

```ts
hasAuthenticatedSession({ token, currentUser }) &&
runtime.hydratedAuthToken === authToken &&
companiesStore.getCompanies.length > 0
```

Если да:

- backend не дергается;
- только выполняется `localeManager.syncOnce(initialLocale)`.

Если нет:

1. `refreshUser()`:
   - вызывает `userSessionService.refreshCurrentUser()`;
   - тот вызывает `services.userService.fetchCurrentUser()`;
   - результат кладется в `userStore.currentUser`.
2. `refreshCompanies()`:
   - вызывает `services.companyService.fetchCompanies()`;
   - кладет список в `companiesStore`;
   - передает allowed company ids и preferred active id.
3. `localeManager.syncOnce(initialLocale)` синхронизирует locale с текущим пользователем.
4. `runtime.hydratedAuthToken = authToken`.
5. `runtime.hasInitializedAnonymousScope = false`.

## Company scope

Company scope - это выбранная активная компания, с которой работают company-scoped страницы.

Основной вход:

```ts
sessionCoordinator.ensureCompanySelected()
```

Используется в `useCompanyScopedPage`.

Порядок:

1. Запускается single-flight `flights.ensureCompanySelected`.
2. Вызывается `initializeSession()`.
3. После инициализации вызывается `reconcileSession()`.
4. Возвращается `companiesStore.getActiveCompanyId`.

```text
company-scoped page
  |
  | mounted
  v
useCompanyScopedPage
  |
  | ensureCompanySelected()
  v
sessionCoordinator
  |
  | initializeSession()
  | reconcileActiveCompany(allowedIds, preferredId)
  v
companiesStore
  |
  | activeCompanyId
  v
sessionCoordinator
  |
  | companyId
  v
useCompanyScopedPage
  |
  | sync(companyId, true)
  v
createCompanySyncService
```

Allowed companies зависят от роли:

- для `superadmin` возвращается `undefined`, то есть используется список компаний как доступный набор;
- для остальных ролей используется `userStore.getAvailableCompanyIds`.

```ts
resolveAllowedCompanyIds({
  role: userStore.getUserRole,
  availableCompanyIds: userStore.getAvailableCompanyIds,
})
```

## Locale в рамках сессии

Locale живет отдельно от auth/session state, но синхронизируется во время session initialization.

При старте:

- initial locale берется из localStorage/browser fallback через `localeManager.getInitialLocale()`;
- затем `localeManager.setLocaleLocal(initialLocale)` выставляет его локально.

После загрузки пользователя:

- `localeManager.syncOnce(initialLocale)` смотрит `dependencies.getUserLocale()`;
- если у пользователя есть валидный locale и он отличается от текущего, locale меняется.

При полном сбросе auth session:

- `resetAuthenticatedSessionState()` вызывает `startNewLocaleSession()`;
- это сбрасывает внутренний locale session id и состояние текущего locale request.

```text
main.ts initialLocale
  |
  v
setLocaleLocal(initialLocale)

refreshCurrentUser()
  |
  v
syncOnce(initialLocale)
  |
  v
user locale exists?
  |
  |-- no --> keep current locale
  |
  `-- yes --> valid and should sync?
                |
                |-- yes --> setLocale(user locale)
                |
                `-- no  --> keep current locale
```

## Сброс состояния

Файл: `front/src/application/session/sessionStateService.ts`

### `clearSessionState`

Чистит данные, завязанные на текущего пользователя, но не обязательно сам auth token.

Действия:

- сбрасывает runtime `sessionCoordinator`;
- очищает `currentUser`;
- очищает companies;
- очищает chats.

```text
clearSessionState()
  |-- resetSessionCoordinatorRuntime(pinia)
  |-- useUserStore(pinia).clearCurrentUser()
  |-- useCompaniesStore(pinia).clear()
  `-- useChatsStore(pinia).clear()
```

### `resetAuthenticatedSessionState`

Полный сброс auth session.

Действия:

- `startNewLocaleSession()`;
- опционально чистит iframe token;
- если есть Pinia, вызывает `userStore.clearSession()` и очищает token + current user;
- затем вызывает `clearSessionState()`.

```text
resetAuthenticatedSessionState(options)
  |-- startNewLocaleSession()
  |-- clearIframeToken?
  |     `-- yes -> iframeTokenStorage.clear()
  |-- resolvedPinia?
  |     `-- yes -> userStore.clearSession()
  `-- clearSessionState(options)
```

Где используется:

- при 401 через `unauthorizedHandler`;
- в bootstrap, если authenticated initialization вернула unauthorized;
- в router guard, если bootstrap завершился unauthorized;
- при logout из profile menu.

## Unauthorized flow

Axios middleware настроен так, что при unauthorized вызывает `applicationServices.unauthorizedHandler`.

```text
API request
  |
  | 401
  v
Axios middleware
  |
  | onUnauthorized()
  v
unauthorizedHandler
  |
  |-- already handling? -> stop
  |-- no auth token?    -> stop
  |
  | resetAuthenticatedSessionState(clearIframeToken = true)
  v
resetAuthenticatedSessionState
  |
  v
router.push('/login')
```

`handlingUnauthorized` защищает от нескольких одновременных редиректов на `/login`.

## Мутации с session effects

Для обычных async actions есть `useMutation`. Для действий, которые после успеха могут затронуть session/company scope, используется `useSessionMutation`.

```ts
const mutation = useSessionMutation<Result>()

await mutation.run(
  async () => {
    return await someApiCall()
  },
  {
    sync: 'user',
    affectsSession: (result) => result.affectsSession,
    refreshScopedData: loadPageData,
    onSuccess: showSuccess,
  },
)
```

`useSessionMutation` делает так:

1. Запускает исходную мутацию через `useMutation`.
2. Если мутация успешна, вызывает `runSessionMutationEffects`.
3. Если мутация упала, session effects не выполняются.
4. `onError` и `onFinally` работают как в обычной мутации.

```text
UI/composable
  |
  | run(mutation, hooks)
  v
useSessionMutation
  |
  | baseMutation.run(...)
  v
useMutation
  |
  | execute mutation()
  v
API/action
  |
  | result
  v
useMutation.onSuccess
  |
  | runSessionMutationEffects(result)
  v
runSessionMutationEffects
  |
  | refresh by sync mode
  v
sessionCoordinator
  |
  v
refreshScopedData()
  |
  v
onSuccess(result)
  |
  v
result returned to UI
```

### `sync: 'none'`

Ничего не обновляет в session coordinator.

Использовать, когда мутация не меняет:

- текущего пользователя;
- company access текущего пользователя;
- список компаний;
- активную компанию;
- данные, от которых зависит company scope.

Порядок:

```text
mutation success
  -> refreshScopedData?
  -> onSuccess?
```

### `sync: 'company'`

Вызывает:

```ts
sessionCoordinator.refreshAfterCompanyMutation()
```

Это:

1. Загружает список компаний заново.
2. Кладет их в `companiesStore`.
3. Нормализует active company.
4. Возвращает текущий `activeCompanyId`.

Использовать, когда изменилась компания или список компаний, например:

- создание компании;
- обновление настроек компании;
- удаление компании.

```text
mutation success
  |
  v
refreshAfterCompanyMutation()
  |
  v
companyService.fetchCompanies()
  |
  v
companiesStore.setCompanies(...)
  |
  v
reconcile activeCompanyId
  |
  v
refreshScopedData?
  |
  v
onSuccess?
```

### `sync: 'user'`

Вызывает:

```ts
sessionCoordinator.refreshAfterUserMutation({ affectsSession })
```

`affectsSession` может быть:

- `boolean`;
- функцией от результата мутации.

Если `affectsSession === false`:

- пользователь заново не загружается;
- компании заново не загружаются;
- выполняется только `reconcileSession()`;
- это дешево и подходит для мутаций чужого пользователя, которые не меняют текущую сессию.

Если `affectsSession === true`:

- заново грузится текущий пользователь;
- заново грузятся компании;
- active company нормализуется на основе новых прав.

```text
mutation success
  |
  v
resolve affectsSession
  |
  v
affectsSession?
  |
  |-- no --> reconcileSession()
  |            |
  |            v
  |        refreshScopedData?
  |
  `-- yes -> hydrateAuthenticatedSession()
              |
              |-- refreshCurrentUser()
              `-- fetchCompanies() + companiesStore.setCompanies(...)
                   |
                   v
              refreshScopedData?

refreshScopedData?
  |
  v
onSuccess?
```

Использовать `sync: 'user'` для мутаций пользователей и прав доступа.

Пример логики:

- редактируем текущего пользователя: `affectsSession = true`;
- редактируем другого пользователя: `affectsSession = false`;
- создаем нового пользователя: обычно `false`;
- удаляем пользователя, который является текущим или влияет на текущие доступы: `true`.

## Порядок post-success effects

Важный порядок внутри `runSessionMutationEffects`:

1. Сначала session sync:
   - `company` или `user`;
   - либо ничего при `none`.
2. Потом `refreshScopedData`.
3. Потом пользовательский `onSuccess`.

Это значит, что `refreshScopedData` и `onSuccess` видят уже обновленные `userStore` / `companiesStore` / `activeCompanyId`.

```text
session sync
  -> refreshScopedData
  -> onSuccess
```

## Single-flight защита

`runSingleFlight` предотвращает дублирующие параллельные запросы:

```ts
if (state.inFlight) {
  return state.inFlight
}

state.inFlight = task().finally(() => {
  state.inFlight = null
})
```

Если несколько компонентов одновременно вызовут `ensureCompanySelected()` или `initializeSession()`, они получат один и тот же Promise.

Это защищает от:

- дублирующего `fetchCurrentUser`;
- дублирующего `fetchCompanies`;
- гонок при нормализации active company;
- лишних locale sync операций.

## Полный authenticated startup flow

```text
main.ts
  |
  | bootstrapApp(initialLocale)
  v
bootstrapApp
  |
  | parse redirect / token / iframe token
  v
iframe token exists and no auth token?
  |
  |-- yes -> userSessionService.loginWithIframeToken(token)
  |             |
  |             v
  |          userStore.setAuthSession(token, user)
  |
  v
sessionCoordinator.initializeSession(initialLocale)
  |
  v
coordinator chooses anonymous/authenticated branch
  |
  |-- anonymous -> clear company scope
  |
  `-- authenticated -> userSessionService.refreshCurrentUser()
                       |
                       v
                     userService.fetchCurrentUser()
                       |
                       v
                     userStore.setCurrentUser(User)
                       |
                       v
                     companyService.fetchCompanies()
                       |
                       v
                     companiesStore.setCompanies(companies, allowedIds, preferredId)
                       |
                       v
                     localeManager.syncOnce(initialLocale)
                       |
                       v
                     hydratedAuthToken = token
                       |
                       v
                     router redirect if authenticated

bootstrapApp
  |
  v
sessionCoordinator.reconcileSession()
```

## Полный logout / auth reset flow

При пользовательском logout обычно сначала вызывается `userSessionService.logout()`, затем локальный сброс/редирект на уровне UI.

Внутри `userSessionService.logout()`:

1. Пытается вызвать backend `authService.logout()`.
2. Игнорирует unauthorized logout error.
3. В `finally` всегда вызывает `userStore.clearSession()`.
4. Если была не-unauthorized ошибка, пробрасывает ее.

Для полного локального сброса используется `resetAuthenticatedSessionState`.

```text
logout action
  |
  v
authService.logout()
  |
  v
userStore.clearSession()
  |
  v
resetAuthenticatedSessionState()
  |
  |-- startNewLocaleSession()
  |-- clear iframe token optional
  `-- clearSessionState()
        |-- reset coordinator runtime
        |-- clear current user
        |-- clear companies
        `-- clear chats
```

## Инварианты

Файл: `front/src/shared/session/invariants.ts`

Основные правила:

- authenticated hydrated session требует token и current user;
- если пользователь не authenticated, company scope нужно очищать;
- `superadmin` не ограничивается `userStore.getAvailableCompanyIds`;
- active company всегда должна быть доступной;
- если active company стала недоступной, выбирается preferred или первая доступная;
- если доступных компаний нет, active company равна `null`.

## Практические правила для нового кода

Используй `useSessionMutation`, если после успешной мутации может понадобиться обновить session/company state.

Выбирай `sync` так:

- `none` - мутация не влияет на пользователя, права, компании и active company;
- `company` - изменилась компания или список компаний;
- `user` - изменился пользователь, права доступа или данные, из которых строится session scope.

Выставляй `affectsSession: true` только когда мутация действительно меняет текущую сессию:

- текущий пользователь;
- роль текущего пользователя;
- company accesses текущего пользователя;
- locale текущего пользователя;
- данные, из-за которых текущий active company может стать недоступным.

Не вызывай `services.userService.fetchCurrentUser()` прямо из UI, если цель - обновить состояние сессии. Для этого есть:

```ts
sessionCoordinator.refreshUser()
```

или, в большинстве mutation cases:

```ts
useSessionMutation(..., { sync: 'user', affectsSession: true })
```

Не меняй `activeCompanyId` вручную после загрузки компаний. Используй `companiesStore.setCompanies()` или `reconcileSession()`, потому что они применяют session invariants.

## Частые сценарии

### Страница требует активную компанию

```text
page mounted
  -> sessionCoordinator.ensureCompanySelected()
  -> initializeSession()
  -> reconcileSession()
  -> activeCompanyId
  -> load company-scoped data
```

### Обновили настройки компании

```text
save company settings
  -> API mutation
  -> sync: company
  -> reload companies
  -> normalize active company
  -> refresh scoped page data
  -> show success
```

### Обновили текущего пользователя

```text
save current user
  -> API mutation
  -> sync: user, affectsSession: true
  -> reload current user
  -> reload companies
  -> normalize active company by new access
  -> refresh scoped page data
```

### Обновили другого пользователя

```text
save another user
  -> API mutation
  -> sync: user, affectsSession: false
  -> reconcile current active company only
  -> refresh scoped page data
```

## Мини-глоссарий

- Auth session - наличие token и локального auth состояния.
- Hydrated session - token плюс загруженный `currentUser` и компании.
- Company scope - текущий `activeCompanyId`, валидный относительно доступных компаний.
- User-scoped state - данные, которые должны исчезнуть при смене/сбросе пользователя: current user, companies, chats, coordinator runtime.
- Session mutation - мутация, после которой может потребоваться синхронизировать user/company/session state.
- Single-flight - защита, при которой параллельные одинаковые операции используют один общий Promise.
