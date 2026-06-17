import { setI18nLocale, getBrowserLocale, DEFAULT_LOCALE, type AvailableLocales } from '@/plugins/i18n'

const LOCALE_STORAGE_KEY = 'mgcrm_locale'

export const localeManager = {
  /**
   * Определить начальную локаль: localStorage → браузер → ru
   */
  getInitialLocale(): AvailableLocales {
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
    if (stored === 'ru' || stored === 'en') return stored
    return getBrowserLocale() ?? DEFAULT_LOCALE
  },

  setLocaleLocal(locale: AvailableLocales): void {
    setI18nLocale(locale)
    localStorage.setItem(LOCALE_STORAGE_KEY, locale)
    document.documentElement.setAttribute('lang', locale)
  },

  changeLocale(locale: AvailableLocales): void {
    this.setLocaleLocal(locale)
  },
}
