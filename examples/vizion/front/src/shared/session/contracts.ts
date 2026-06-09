export type SessionOptions = {
  initialLocale?: import('@/plugins/i18n').AvailableLocales
}

export type SessionMutationOptions = {
  affectsSession?: boolean
}

export type SessionMutationSync = 'none' | 'company' | 'user'
