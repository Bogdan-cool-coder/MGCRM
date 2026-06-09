import type { WritableComputedRef } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/locales/en.json'
import ru from '@/locales/ru.json'

export const AVAILABLE_LOCALES = ['en', 'ru'] as const
export type AvailableLocales = (typeof AVAILABLE_LOCALES)[number]
export const DEFAULT_LOCALE: AvailableLocales = 'ru'

type MessageSchema = typeof en
type AppLocaleRef = WritableComputedRef<AvailableLocales>

declare module 'vue-i18n' {
  export interface DefineLocaleMessage extends MessageSchema {}
}

export const i18n = createI18n({
  legacy: false,
  locale: DEFAULT_LOCALE,
  fallbackLocale: 'en',
  missing: (locale, key) => {
    if (import.meta.env.DEV) {
      console.warn(`[i18n] Missing translation: ${locale}.${key}`)
    }
    return key
  },
  messages: {
    en,
    ru,
  },
  pluralRules: {
    ru: (choice: number) => {
      const mod10 = choice % 10
      const mod100 = choice % 100
      if (mod100 >= 11 && mod100 <= 19) return 2
      if (mod10 === 1) return 0
      if (mod10 >= 2 && mod10 <= 4) return 1
      return 2
    },
  },
})

const appLocale = i18n.global.locale as AppLocaleRef

export function getI18nLocale(): AvailableLocales {
  const value = appLocale.value
  return isValidLocale(value) ? value : DEFAULT_LOCALE
}

export function setI18nLocale(locale: AvailableLocales) {
  appLocale.value = locale
}

export function getBrowserLocale(): AvailableLocales | null {
  if (typeof navigator !== 'undefined') {
    const browserLang = navigator.language.toLowerCase()
    if (browserLang.startsWith('ru')) {
      return 'ru'
    }
    if (browserLang.startsWith('en')) {
      return 'en'
    }
  }
  return null
}

export function isValidLocale(locale: string): locale is AvailableLocales {
  return AVAILABLE_LOCALES.includes(locale as AvailableLocales)
}
