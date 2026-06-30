import { ref, onMounted, provide, computed } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useUserStore } from '@/stores/user'

export const SETTINGS_MARK_DIRTY_KEY = 'settingsMarkDirty'
export const SETTINGS_MARK_CLEAN_KEY = 'settingsMarkClean'

/** Разделы Ф1 (аккаунт + интеграции) */
const ACCOUNT_KEYS = ['profile', 'security', 'appearance', 'language', 'channels'] as const

/** Разделы Ф2 — Справочники (admin/director only) */
export const DIRECTORIES_KEYS = [
  'countries',
  'acq-channels',
  'disc-reasons',
  'catalog',
  'exchange-rates',
] as const

/** Разделы Ф3 — Система (admin/director; system-reset — только admin) */
export const SYSTEM_KEYS = [
  'users',
  'access-control',
  'automation-runs',
  'system-reset',
] as const

/** Ключи системы, доступные только admin (не director) */
const ADMIN_ONLY_KEYS = ['system-reset'] as const

/** Все валидные ключи разделов (Ф1 + Ф2 + Ф3 активные) */
const VALID_KEYS = [...ACCOUNT_KEYS, ...DIRECTORIES_KEYS, ...SYSTEM_KEYS] as const
type ValidKey = (typeof VALID_KEYS)[number]

/**
 * Возвращает колбэки для управления диалогом «Несохранённые изменения» из шелла.
 *
 * Паттерн: Promise-based guard. При необходимости показа диалога шелл устанавливает
 * `dialogVisible = true` и ждёт единственного промиса `pendingResolve`.
 * Пользователь жмёт «Покинуть» → resolve(true); «Остаться» → resolve(false).
 * После разрешения промис сбрасывается — повторный показ исключён.
 */
export interface DirtyGuardControls {
  /** isDirty — признак несохранённых изменений в текущей форм-секции */
  readonly isDirty: boolean
  /** показать диалог; resolve(true) = уйти, resolve(false) = остаться */
  readonly showDialog: () => Promise<boolean>
}

export function useSettings() {
  const route = useRoute()
  const router = useRouter()
  const userStore = useUserStore()

  const activeSection = ref<string>('profile')

  // ─── Dirty state ─────────────────────────────────────────────────────────────
  // Истинное dirty-состояние: дочерние формы вызывают markDirty/markClean,
  // которые теперь — реальные сеттеры, а не no-op.
  const isDirty = ref(false)

  function markDirty() {
    isDirty.value = true
  }

  function markClean() {
    isDirty.value = false
  }

  // Provide to all child section components
  provide(SETTINGS_MARK_DIRTY_KEY, markDirty)
  provide(SETTINGS_MARK_CLEAN_KEY, markClean)

  // ─── Dialog state (shared с index.vue через return) ───────────────────────────
  // index.vue монтирует <UnsavedChangesDialog v-model:visible="dialogVisible">
  // и слушает @leave/@stay — это единственный экземпляр диалога.
  const dialogVisible = ref(false)

  // Текущий ожидающий промис; null = диалог не открыт, guard не активен.
  let pendingResolve: ((confirmed: boolean) => void) | null = null

  /** Открыть диалог и вернуть промис. Гарантируется единственный resolve. */
  function askUserToConfirmLeave(): Promise<boolean> {
    return new Promise<boolean>((resolve) => {
      // Перезаписываем pendingResolve — если по какой-то причине предыдущий
      // промис завис (не должно случаться при корректном использовании),
      // отменяем его (resolve false = остаться) и стартуем новый.
      if (pendingResolve) {
        const old = pendingResolve
        pendingResolve = null
        old(false)
      }
      pendingResolve = resolve
      dialogVisible.value = true
    })
  }

  /** Вызывается из @leave диалога. Dirty и диалог сбрасываются ДО resolve,
   *  чтобы router.replace внутри setSection() не мог повторно тригернуть guard
   *  с открытым dialogVisible. */
  function onDialogLeave() {
    isDirty.value = false
    dialogVisible.value = false // закрыть до resolve — re-trigger guard не покажет phantom
    const resolve = pendingResolve
    pendingResolve = null
    resolve?.(true)
  }

  /** Вызывается из @stay диалога. Диалог закрывается явно до resolve,
   *  не полагаясь на тайминг @hide PrimeVue Dialog. */
  function onDialogStay() {
    dialogVisible.value = false
    const resolve = pendingResolve
    pendingResolve = null
    resolve?.(false)
  }

  // ─── Role checks ──────────────────────────────────────────────────────────────
  const isAdminOrDirector = computed(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'director'
  })

  const isAdmin = computed(() => userStore.getUserRole === 'admin')

  function resolveSection(key: string | undefined): string {
    if (!key) return 'profile'
    if ((DIRECTORIES_KEYS as readonly string[]).includes(key) && !isAdminOrDirector.value) {
      return 'profile'
    }
    if ((SYSTEM_KEYS as readonly string[]).includes(key) && !isAdminOrDirector.value) {
      return 'profile'
    }
    if ((ADMIN_ONLY_KEYS as readonly string[]).includes(key) && !isAdmin.value) {
      return 'profile'
    }
    if ((VALID_KEYS as readonly string[]).includes(key as ValidKey)) return key
    return 'profile'
  }

  onMounted(() => {
    const fromQuery = route.query['section'] as string | undefined
    activeSection.value = resolveSection(fromQuery)
  })

  /**
   * Переключить раздел. Если текущий раздел dirty — сначала спросить пользователя.
   * При «Остаться» навигация отменяется; при «Покинуть» — выполняется.
   */
  async function setSection(key: string) {
    if (key === activeSection.value) return

    if (isDirty.value) {
      const confirmed = await askUserToConfirmLeave()
      if (!confirmed) return
      // isDirty уже false (onDialogLeave сбросил)
    }

    activeSection.value = key
    void router.replace({ path: '/settings', query: { section: key } })
  }

  /**
   * onBeforeRouteLeave — guard для ухода со страницы /settings целиком.
   * Смена раздела внутри страницы перехватывается setSection() выше,
   * router.replace() не триггерит onBeforeRouteLeave (та же страница).
   */
  onBeforeRouteLeave(async () => {
    if (!isDirty.value) return true

    const confirmed = await askUserToConfirmLeave()
    // isDirty уже false при confirmed=true (onDialogLeave сбросил)
    return confirmed
  })

  return {
    activeSection,
    setSection,
    isAdminOrDirector,
    isAdmin,
    // Dirty guard — пробрасываем в index.vue для монтирования диалога
    isDirty,
    dialogVisible,
    onDialogLeave,
    onDialogStay,
  }
}
