import {
  changeLocale,
  configureLocaleCoordinator,
  resolveInitialLocale,
  setLocale,
  startNewLocaleSession,
  syncOnce,
  type AvailableLocales,
} from './localeCoordinator'

export interface LocaleManager {
  setLocaleLocal: (_locale: AvailableLocales) => void
  changeLocaleAndSync: (_locale: AvailableLocales) => Promise<void>
  getInitialLocale: () => AvailableLocales
  syncOnce: (_initialLocale?: AvailableLocales) => void
}

export const localeManager: LocaleManager = {
  setLocaleLocal: (locale) => setLocale(locale),
  changeLocaleAndSync: (locale) => changeLocale(locale),
  getInitialLocale: () => resolveInitialLocale(),
  syncOnce: (initialLocale) => syncOnce(initialLocale),
}

export {
  changeLocale,
  configureLocaleCoordinator,
  resolveInitialLocale,
  setLocale,
  startNewLocaleSession,
  syncOnce,
  type AvailableLocales,
}
