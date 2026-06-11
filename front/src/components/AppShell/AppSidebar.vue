<template>
  <aside class="app-sidebar" :class="{ 'app-sidebar--collapsed': collapsed }">
    <!-- Logo area -->
    <div class="app-sidebar__logo">
      <AppLogo :collapsed="collapsed" />
    </div>

    <!-- Toggle collapse button -->
    <button
      class="app-sidebar__toggle"
      :title="collapsed ? t('common.expand') : t('common.collapse')"
      @click="$emit('toggle')"
    >
      <i :class="collapsed ? 'pi pi-angle-right' : 'pi pi-angle-left'" />
    </button>

    <!-- Navigation -->
    <nav class="app-sidebar__nav">
      <ul class="app-sidebar__nav-list">
        <li
          v-for="item in navItems"
          :key="item.name"
          class="app-sidebar__nav-item"
        >
          <router-link
            :to="item.to"
            class="app-sidebar__nav-link"
            active-class="app-sidebar__nav-link--active"
            :title="collapsed ? t(item.labelKey) : undefined"
          >
            <i :class="['app-sidebar__nav-icon', item.icon]" />
            <span v-if="!collapsed" class="app-sidebar__nav-label">
              {{ t(item.labelKey) }}
            </span>
          </router-link>
        </li>
      </ul>
    </nav>

    <!-- User info at bottom -->
    <div class="app-sidebar__footer">
      <router-link
        to="/profile"
        class="app-sidebar__user"
        :title="collapsed ? userStore.getUserName : undefined"
      >
        <div class="app-sidebar__avatar">
          <img
            v-if="userStore.getAvatarPath"
            :src="userStore.getAvatarPath"
            :alt="userStore.getUserName"
          />
          <i v-else class="pi pi-user" />
        </div>
        <div v-if="!collapsed" class="app-sidebar__user-info">
          <span class="app-sidebar__user-name">{{ userStore.getUserName }}</span>
          <span class="app-sidebar__user-role">{{ roleLabel }}</span>
        </div>
      </router-link>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppLogo from './AppLogo.vue'
import { useUserStore } from '@/stores/user'

defineProps<{
  collapsed: boolean
}>()

defineEmits<{
  toggle: []
}>()

const { t } = useI18n()
const userStore = useUserStore()

const navItems = [
  { name: 'dashboard', to: '/dashboard', icon: 'pi pi-home', labelKey: 'nav.dashboard' },
  { name: 'contacts', to: '/contacts', icon: 'pi pi-users', labelKey: 'nav.contacts' },
  { name: 'companies', to: '/companies', icon: 'pi pi-building', labelKey: 'nav.companies' },
  { name: 'deals', to: '/deals', icon: 'pi pi-chart-bar', labelKey: 'nav.deals' },
  { name: 'products', to: '/admin/products', icon: 'pi pi-box', labelKey: 'nav.catalog' },
  { name: 'documents', to: '/documents', icon: 'pi pi-file', labelKey: 'nav.documents' },
  { name: 'tasks', to: '/tasks', icon: 'pi pi-check-square', labelKey: 'nav.tasks' },
  { name: 'finance', to: '/finance', icon: 'pi pi-wallet', labelKey: 'nav.finance' },
  { name: 'team', to: '/team', icon: 'pi pi-sitemap', labelKey: 'nav.team' },
  { name: 'analytics', to: '/analytics', icon: 'pi pi-chart-line', labelKey: 'nav.analytics' },
  { name: 'settings', to: '/settings', icon: 'pi pi-cog', labelKey: 'nav.settings' },
]

const roleLabel = computed(() => {
  const role = userStore.getUserRole
  if (!role) return ''
  return t(`roles.${role}`, role)
})
</script>

<style lang="scss" scoped>
.app-sidebar {
  display: flex;
  flex-direction: column;
  background-color: #172747; // brand-primary — фиксированный (sidebar не меняет цвет в dark mode)
  color: #ffffff;
  width: var(--app-sidebar-width);
  height: 100%;
  flex-shrink: 0;
  overflow: hidden;
  transition: width var(--app-transition-normal);
  position: relative;

  &--collapsed {
    width: var(--app-sidebar-rail-width);
  }
}

.app-sidebar__logo {
  flex-shrink: 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.app-sidebar__toggle {
  position: absolute;
  top: calc(var(--app-header-height) + 12px);
  right: -12px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background-color: #172747;
  border: 2px solid rgba(255, 255, 255, 0.2);
  color: #ffffff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  z-index: 10;
  transition: background-color var(--app-transition-fast);

  &:hover {
    background-color: #0e172b;
  }

  i {
    font-size: 10px;
  }
}

.app-sidebar__nav {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: $space-2 0;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

.app-sidebar__nav-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.app-sidebar__nav-link {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-4;
  color: rgba(255, 255, 255, 0.75);
  text-decoration: none;
  transition: background-color var(--app-transition-fast), color var(--app-transition-fast);
  white-space: nowrap;
  overflow: hidden;
  min-height: 40px;

  &:hover {
    background-color: #0e172b;
    color: #ffffff;
  }

  &--active {
    background-color: #2b4987;
    color: #ffffff;
  }
}

.app-sidebar__nav-icon {
  font-size: $font-size-md;
  flex-shrink: 0;
  width: 20px;
  text-align: center;
}

.app-sidebar__nav-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  overflow: hidden;
  text-overflow: ellipsis;
}

// Footer / user section
.app-sidebar__footer {
  flex-shrink: 0;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  padding: $space-2;
}

.app-sidebar__user {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2;
  border-radius: $radius-md;
  text-decoration: none;
  color: rgba(255, 255, 255, 0.75);
  transition: background-color var(--app-transition-fast);
  overflow: hidden;

  &:hover {
    background-color: #0e172b;
    color: #ffffff;
  }
}

.app-sidebar__avatar {
  width: 32px;
  height: 32px;
  border-radius: $radius-xl;
  background-color: rgba(255, 255, 255, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  i {
    font-size: $font-size-sm;
    color: rgba(255, 255, 255, 0.75);
  }
}

.app-sidebar__user-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
}

.app-sidebar__user-name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: #ffffff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-sidebar__user-role {
  font-size: $font-size-xs;
  color: rgba(255, 255, 255, 0.55);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
