<template>
  <div class="app-topbar">
    <div class="app-topbar__spacer" />
    <div class="app-topbar__actions">
      <!-- Dark mode toggle -->
      <button
        class="app-topbar__btn"
        :title="layoutStore.isDarkMode ? t('profile.theme.light') : t('profile.theme.dark')"
        @click="layoutStore.toggleDarkMode()"
      >
        <i :class="layoutStore.isDarkMode ? 'pi pi-sun' : 'pi pi-moon'" />
      </button>

      <!-- Locale switcher -->
      <button
        class="app-topbar__btn"
        :title="currentLocale === 'ru' ? 'Switch to English' : 'Переключить на Русский'"
        @click="toggleLocale"
      >
        <span class="app-topbar__locale">{{ currentLocale.toUpperCase() }}</span>
      </button>

      <!-- Profile link -->
      <router-link to="/profile" class="app-topbar__btn" :title="t('nav.profile')">
        <i class="pi pi-user" />
      </router-link>

      <!-- Logout -->
      <button class="app-topbar__btn app-topbar__btn--danger" :title="t('nav.logout')" @click="handleLogout">
        <i class="pi pi-sign-out" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useLayoutStore } from '@/stores/layout'
import { useUserStore } from '@/stores/user'
import { localeManager } from '@/application/locale'
import { authApi } from '@/api/auth'
import { getI18nLocale } from '@/plugins/i18n'
import type { AvailableLocales } from '@/plugins/i18n'

const { t } = useI18n()
const router = useRouter()
const layoutStore = useLayoutStore()
const userStore = useUserStore()

const currentLocale = computed(() => getI18nLocale())

function toggleLocale() {
  const next: AvailableLocales = currentLocale.value === 'ru' ? 'en' : 'ru'
  localeManager.changeLocale(next)
}

async function handleLogout() {
  try {
    await authApi.logout()
  } finally {
    userStore.clearAuthenticatedUserState()
    router.push('/login')
  }
}
</script>

<style lang="scss" scoped>
.app-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: var(--app-header-height);
  padding: 0 $space-4;
  background-color: $surface-card;
  border-bottom: 1px solid $surface-200;
  flex-shrink: 0;
}

.app-topbar__spacer {
  flex: 1;
}

.app-topbar__actions {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.app-topbar__btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  background: transparent;
  border-radius: $radius-md;
  cursor: pointer;
  color: $surface-600;
  transition: background-color var(--app-transition-fast), color var(--app-transition-fast);
  text-decoration: none;

  &:hover {
    background-color: $surface-200;
    color: $surface-900;
  }

  &--danger:hover {
    background-color: var(--app-red-50);
    color: $red-500;
  }

  i {
    font-size: $font-size-md;
  }
}

.app-topbar__locale {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  letter-spacing: 0.05em;
}
</style>
