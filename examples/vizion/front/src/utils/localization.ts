import { getI18nLocale } from '@/plugins/i18n'

/**
 * Get localized text from a string or localized object
 * @param value - String or localized object (e.g., { ru: '...', en: '...' })
 * @param locale - Preferred locale (default: current app locale)
 * @returns Localized string or empty string if value is undefined
 */
export const getLocalizedText = (
  value: string | Record<string, string> | undefined,
  locale: string = getI18nLocale(),
): string => {
  if (!value) return ''
  if (typeof value === 'string') return value
  return value[locale] || value.en || Object.values(value)[0] || ''
}
