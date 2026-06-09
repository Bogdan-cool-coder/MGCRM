import { useI18n } from 'vue-i18n'
import type { AvailableLocales } from '@/plugins/i18n'
import globalEn from '@/locales/en.json'
import globalRu from '@/locales/ru.json'

type LocalMessageSchema = Record<string, unknown>
type LocalMessages = Record<AvailableLocales, LocalMessageSchema>

export const useLocalI18n = (messages: LocalMessages) => {
  const mergedMessages = {
    en: {
      ...globalEn,
      ...messages.en,
    },
    ru: {
      ...globalRu,
      ...messages.ru,
    },
  }

  return useI18n({
    useScope: 'local',
    inheritLocale: true,
    messages: mergedMessages,
  } as unknown as Parameters<typeof useI18n>[0])
}
