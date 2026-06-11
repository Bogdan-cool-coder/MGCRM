// pinia-plugin-persistedstate для persist хранилищ
// Используется только для: token (userStore), toolboxCollapsed (layoutStore)
import { createPersistedState } from 'pinia-plugin-persistedstate'

export const persistPlugin = createPersistedState({
  storage: localStorage,
})
