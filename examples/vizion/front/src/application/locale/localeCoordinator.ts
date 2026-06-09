import { getI18nLocale, i18n, isValidLocale, type AvailableLocales } from '@/plugins/i18n'
import { notificationCenter } from '@/application/notificationCenter'

const LOCALE_STORAGE_KEY = 'vizion_locale'

interface LocaleCoordinatorDependencies {
  isAuthenticated: () => boolean
  getUserLocale: () => AvailableLocales | null
  updateCurrentUserLocale: (_locale: AvailableLocales) => Promise<void>
}

const defaultDependencies: LocaleCoordinatorDependencies = {
  isAuthenticated: () => false,
  getUserLocale: () => null,
  updateCurrentUserLocale: async () => {},
}

let dependencies: LocaleCoordinatorDependencies = defaultDependencies

export const configureLocaleCoordinator = (
  nextDependencies: LocaleCoordinatorDependencies,
): void => {
  dependencies = nextDependencies
}

let currentRequestId: string = crypto.randomUUID()

const setCurrentRequestId = (id: string): void => {
  currentRequestId = id
}

let isChangingLocale = false

export const startNewLocaleSession = (): void => {
  currentRequestId = crypto.randomUUID()
  isChangingLocale = false
}

const safeSetLocalStorage = (key: string, value: string): void => {
  try {
    localStorage.setItem(key, value)
  } catch {
    if (import.meta.env.DEV) {
      console.warn(`[localeCoordinator] Failed to write localStorage (key: ${key})`)
    }
  }
}

export const setLocale = (locale: AvailableLocales): void => {
  if (!isValidLocale(locale)) {
    if (import.meta.env.DEV) {
      console.warn(`[localeCoordinator] Invalid locale: ${locale}`)
    }
    return
  }

  if (getI18nLocale() === locale) {
    return
  }

  i18n.global.locale.value = locale
  safeSetLocalStorage(LOCALE_STORAGE_KEY, locale)
}

export const changeLocale = async (locale: AvailableLocales): Promise<void> => {
  if (!isValidLocale(locale)) {
    if (import.meta.env.DEV) {
      console.warn(`[localeCoordinator] Invalid locale: ${locale}`)
    }
    return
  }

  if (getI18nLocale() === locale) {
    return
  }

  const requestId = crypto.randomUUID()
  setCurrentRequestId(requestId)
  isChangingLocale = true

  setLocale(locale)

  if (!dependencies.isAuthenticated()) {
    isChangingLocale = false
    return
  }

  try {
    await dependencies.updateCurrentUserLocale(locale)

    if (requestId !== currentRequestId && import.meta.env.DEV) {
      console.debug(`[localeCoordinator] Discarded stale locale update (request ${requestId})`)
    }
  } catch (error) {
    // Do not roll back the visible locale on backend failure — the user
    // explicitly chose this locale and a phantom revert to the previous one
    // ("я нажал EN — на мгновение стало EN — и тут же вернулось в RU") is more
    // confusing than a backend sync failure they cannot see. localStorage
    // already persisted the choice, so the locale will be respected on the
    // next page load too; backend sync will retry naturally next time the
    // user toggles or on session refresh.
    if (import.meta.env.DEV) {
      console.error('[localeCoordinator] Failed to sync locale to backend', error)
    }
    notificationCenter.warn(i18n.global.t('errors.localeSyncFailed'))
  } finally {
    isChangingLocale = false
  }
}

export const resolveInitialLocale = (): AvailableLocales => {
  if (typeof window !== 'undefined' && window.localStorage) {
    try {
      const saved = localStorage.getItem(LOCALE_STORAGE_KEY)
      if (saved && isValidLocale(saved)) {
        return saved as AvailableLocales
      }
    } catch {
    }
  }

  if (typeof navigator !== 'undefined') {
    const lang = navigator.language?.split('-')[0]?.toLowerCase()
    if (lang === 'ru') {
      return 'ru'
    }
    if (lang === 'en') {
      return 'en'
    }
  }

  return 'ru'
}

export const syncOnce = (initialLocale?: AvailableLocales): void => {
  const userLocale = dependencies.getUserLocale()

  if (!userLocale || !isValidLocale(userLocale)) {
    return
  }

  const current = getI18nLocale()

  if (userLocale === current || (initialLocale && userLocale === initialLocale) || isChangingLocale) {
    return
  }

  setLocale(userLocale)
}

export type { AvailableLocales }
