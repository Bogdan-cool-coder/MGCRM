<template>
  <aside class="app-sidebar" :class="{ 'app-sidebar--collapsed': collapsed }">
    <!-- Logo area + toggle button -->
    <div class="app-sidebar__logo-area">
      <!-- Expanded: full logo -->
      <div v-if="!collapsed" class="app-sidebar__logo-wrap">
        <img
          src="/logo.svg"
          alt="MACRO Global CRM"
          class="app-sidebar__logo-full"
          height="28"
        />
      </div>

      <!-- Collapsed: «MG» lettermark — hover shows chevron to expand -->
      <button
        v-else
        v-tooltip.right="t('common.expand')"
        class="app-sidebar__logo-mark"
        :aria-label="t('common.expand')"
        @click="$emit('toggle')"
      >
        <span class="app-sidebar__logo-mark-text">MG</span>
        <span class="app-sidebar__logo-mark-chevron">
          <i class="pi pi-chevron-right" />
        </span>
      </button>

      <!-- Collapse toggle — only visible when expanded -->
      <button
        v-if="!collapsed"
        class="app-sidebar__toggle"
        :title="t('common.collapse')"
        @click="$emit('toggle')"
      >
        <i class="pi pi-chevron-left" />
      </button>
    </div>

    <!-- Navigation -->
    <nav class="app-sidebar__nav" aria-label="Основная навигация">
      <!-- Skeleton: рендерится пока userStore не загрузил пользователя -->
      <ul v-if="!isNavReady" class="app-sidebar__nav-list" role="list" aria-hidden="true">
        <li
          v-for="n in 5"
          :key="n"
          class="app-sidebar__nav-item"
        >
          <div class="app-sidebar__nav-skeleton" />
        </li>
      </ul>

      <ul v-else class="app-sidebar__nav-list" role="list">
        <li
          v-for="item in visibleNavItems"
          :key="item.key"
          class="app-sidebar__nav-item"
        >
          <router-link
            :to="item.route"
            class="app-sidebar__nav-link"
            active-class="app-sidebar__nav-link--active"
            :aria-label="t(item.labelKey)"
            :title="collapsed ? t(item.labelKey) : undefined"
            @mouseenter="prefetch(item.route)"
            @focus="prefetch(item.route)"
          >
            <i :class="['app-sidebar__nav-icon', item.icon]" />
            <span v-if="!collapsed" class="app-sidebar__nav-label">
              {{ t(item.labelKey) }}
            </span>

            <!-- Badge (expanded mode) -->
            <template v-if="!collapsed && item.badge">
              <span
                v-if="getBadgeCount(item.badge.source) > 0"
                :class="[
                  'app-sidebar__nav-badge',
                  { 'app-sidebar__nav-badge--danger': item.badge.variant === 'danger' },
                ]"
              >
                {{ getBadgeCount(item.badge.source) }}
              </span>
            </template>

            <!-- Badge dot (collapsed mode) -->
            <template v-if="collapsed && item.badge">
              <span
                v-if="getBadgeCount(item.badge.source) > 0"
                :class="[
                  'app-sidebar__nav-dot',
                  { 'app-sidebar__nav-dot--danger': item.badge.variant === 'danger' },
                ]"
              />
            </template>
          </router-link>
        </li>

        <!-- Admin-only section with hairline divider -->
        <template v-if="isAdminOrDirector && visibleAdminNavItems.length">
          <li class="app-sidebar__nav-divider" role="separator">
            <hr class="app-sidebar__admin-divider" />
          </li>
          <li
            v-for="item in visibleAdminNavItems"
            :key="item.key"
            class="app-sidebar__nav-item"
          >
            <router-link
              :to="item.route"
              class="app-sidebar__nav-link"
              active-class="app-sidebar__nav-link--active"
              :aria-label="t(item.labelKey)"
              :title="collapsed ? t(item.labelKey) : undefined"
              @mouseenter="prefetch(item.route)"
              @focus="prefetch(item.route)"
            >
              <i :class="['app-sidebar__nav-icon', item.icon]" />
              <span v-if="!collapsed" class="app-sidebar__nav-label">
                {{ t(item.labelKey) }}
              </span>
            </router-link>
          </li>
        </template>
      </ul>
    </nav>

    <!-- Footer: user card → opens AccountMenu -->
    <div class="app-sidebar__footer">
      <!-- Expanded footer -->
      <button
        v-if="!collapsed"
        class="app-sidebar__user"
        type="button"
        @click="toggleAccountMenu"
      >
        <div class="app-sidebar__avatar">
          <img
            v-if="userStore.getAvatarPath"
            :src="userStore.getAvatarPath"
            :alt="userStore.getUserName"
          />
          <span v-else class="app-sidebar__avatar-initials">{{ initials }}</span>
        </div>
        <div class="app-sidebar__user-info">
          <span class="app-sidebar__user-name">{{ userStore.getUserName }}</span>
          <span class="app-sidebar__user-role">{{ roleLabel }}</span>
        </div>
        <i class="pi pi-ellipsis-h app-sidebar__user-menu-icon" />
      </button>

      <!-- Collapsed footer: avatar only -->
      <button
        v-else
        v-tooltip.right="userStore.getUserName"
        class="app-sidebar__user app-sidebar__user--collapsed"
        type="button"
        :aria-label="userStore.getUserName"
        @click="toggleAccountMenu"
      >
        <div class="app-sidebar__avatar">
          <img
            v-if="userStore.getAvatarPath"
            :src="userStore.getAvatarPath"
            :alt="userStore.getUserName"
          />
          <span v-else class="app-sidebar__avatar-initials">{{ initials }}</span>
        </div>
      </button>
    </div>

    <!-- AccountMenu popover (attached to footer) -->
    <AccountMenu ref="accountMenuRef" />
  </aside>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUserStore } from '@/stores/user'
import { useActivityStore } from '@/stores/activityStore'
import { useApprovalsStore } from '@/stores/approvalsStore'
import { useOnboardingStore } from '@/stores/onboardingStore'
import { prototypeNavItems, adminNavItems } from '@/shared/nav/navItems'
import type { NavItemBadge } from '@/shared/nav/navItems'
import AccountMenu from './AccountMenu.vue'
import { useNavPrefetch } from '@/components/Orbita/composables/useNavPrefetch'

defineProps<{
  collapsed: boolean
}>()

defineEmits<{
  toggle: []
}>()

const { t } = useI18n()
const userStore = useUserStore()
const activityStore = useActivityStore()
const approvalsStore = useApprovalsStore()
const onboardingStore = useOnboardingStore()

const accountMenuRef = ref<InstanceType<typeof AccountMenu> | null>(null)

// ─── Nav ready: пользователь загружен (не null) ───────────────────────────────
// Пока getUser === null (bootstrap ещё загружает /me) — показываем скелетон.
const isNavReady = computed<boolean>(() => userStore.getUser !== null)

// ─── Nav items (prototype set) ────────────────────────────────────────────────
const visibleNavItems = computed(() => prototypeNavItems)

const isAdminOrDirector = computed<boolean>(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

const visibleAdminNavItems = computed(() => adminNavItems)

// ─── Badge counts ──────────────────────────────────────────────────────────────
function getBadgeCount(source: NavItemBadge['source']): number {
  switch (source) {
    case 'activityStore.myOpenCount':
      return activityStore.myOpenCount ?? 0
    case 'approvalsStore.pendingCount':
      return approvalsStore.pendingCount ?? 0
    case 'onboardingStore.overdueCount':
      return onboardingStore.overdueCount ?? 0
    default:
      return 0
  }
}

// ─── User info ────────────────────────────────────────────────────────────────
const initials = computed(() => {
  const name = userStore.getUserName
  if (!name) return '?'
  return name
    .split(' ')
    .slice(0, 2)
    .map((n) => n.charAt(0).toUpperCase())
    .join('')
})

const roleLabel = computed(() => {
  const role = userStore.getUserRole
  if (!role) return ''
  return t(`roles.${role}`, role)
})

// ─── Account menu ─────────────────────────────────────────────────────────────
function toggleAccountMenu(event: MouseEvent) {
  accountMenuRef.value?.toggle(event)
}

// ─── Prefetch on hover/focus ──────────────────────────────────────────────────
const { prefetch } = useNavPrefetch()

// ─── Init badge counts ────────────────────────────────────────────────────────
onMounted(() => {
  if (userStore.getUser) {
    void activityStore.fetchMyOpenCount()
    void approvalsStore.fetchPendingCount()
    void onboardingStore.fetchOverdueCount()
  }
})
</script>

<style lang="scss" scoped>
.app-sidebar {
  display: flex;
  flex-direction: column;
  background-color: $sidebar-bg;
  color: $sidebar-text-active;
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

// ─── Logo area ────────────────────────────────────────────────────────────────
.app-sidebar__logo-area {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  height: 60px;
  padding: 0 $space-4;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  position: relative;

  .app-sidebar--collapsed & {
    padding: 0;
    justify-content: center;
  }
}

.app-sidebar__logo-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  overflow: hidden;
}

.app-sidebar__logo-full {
  max-width: 160px;
  height: 28px;
  object-fit: contain;
  filter: brightness(0) invert(1);
  flex-shrink: 0;
}

// Collapsed lettermark button (MG → chevron on hover)
.app-sidebar__logo-mark {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: $radius-lg;
  background-color: rgba(255, 255, 255, 0.1);
  border: none;
  cursor: pointer;
  color: #ffffff;
  position: relative;
  overflow: hidden;
  transition: background-color var(--app-transition-fast);

  &:hover {
    background-color: rgba(255, 255, 255, 0.18);

    .app-sidebar__logo-mark-text {
      opacity: 0;
    }

    .app-sidebar__logo-mark-chevron {
      opacity: 1;
    }
  }

  &:focus-visible {
    outline: 2px solid rgba(255, 255, 255, 0.4);
    outline-offset: 2px;
  }
}

.app-sidebar__logo-mark-text {
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  letter-spacing: -0.02em;
  line-height: 1;
  transition: opacity var(--app-transition-fast);
}

.app-sidebar__logo-mark-chevron {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity var(--app-transition-fast);

  i {
    font-size: 14px;
  }
}

// Collapse toggle button (visible in expanded mode)
.app-sidebar__toggle {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background-color: $sidebar-bg;
  border: 2px solid rgba(255, 255, 255, 0.2);
  color: #ffffff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  flex-shrink: 0;
  margin-left: $space-2;
  transition: background-color var(--app-transition-fast);

  &:hover {
    background-color: $sidebar-hover-bg;
  }

  &:focus-visible {
    outline: 2px solid rgba(255, 255, 255, 0.4);
    outline-offset: 2px;
  }

  i {
    font-size: 10px;
  }
}

// ─── Navigation ───────────────────────────────────────────────────────────────
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

.app-sidebar__nav-item {
  // no extra margin — pill handles its own margin
}

// Pill nav link
.app-sidebar__nav-link {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 2px 8px;
  border-radius: 9px;
  padding: 8px 10px;
  color: $sidebar-text;
  text-decoration: none;
  position: relative;
  transition: background-color var(--app-transition-fast), color var(--app-transition-fast);
  white-space: nowrap;
  // overflow visible — позволяет ::before выступать за левый край таблетки до края сайдбара
  overflow: visible;
  min-height: 36px;

  &:hover {
    background-color: rgba(255, 255, 255, 0.05);
    color: $sidebar-text-active;
  }

  &:focus-visible {
    outline: 2px solid rgba(255, 255, 255, 0.4);
    outline-offset: 2px;
  }

  // Active state: pill highlight + left bar indicator (прижат к левому краю сайдбара)
  &--active {
    background-color: $sidebar-active-bg;
    color: $sidebar-text-active;

    &::before {
      content: '';
      position: absolute;
      // margin таблетки = 8px → бар уходит на -8px влево, прижимаясь к краю сайдбара
      left: -8px;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 18px;
      background: $sidebar-active-bar;
      border-radius: 0 3px 3px 0;
    }
  }

  // Collapsed: center icons
  .app-sidebar--collapsed & {
    justify-content: center;
    margin: 2px 8px;
    padding: 8px;
  }
}

.app-sidebar__nav-icon {
  font-size: 18px;
  flex-shrink: 0;
  width: 18px;
  text-align: center;
}

.app-sidebar__nav-label {
  font-size: 13px;
  font-weight: $font-weight-medium;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
}

// Badge (inline, expanded mode)
.app-sidebar__nav-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: 9px;
  background: #E8821E; // warning variant
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
  flex-shrink: 0;
  margin-left: auto;

  &--danger {
    background: #FF5A44;
  }
}

// Dot indicator (collapsed mode)
.app-sidebar__nav-dot {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #E8821E;
  flex-shrink: 0;

  &--danger {
    background: #FF5A44;
  }
}

// Admin section divider
.app-sidebar__nav-divider {
  list-style: none;
}

.app-sidebar__admin-divider {
  margin: 8px 16px;
  border: none;
  border-top: 1px solid $sidebar-divider;
}

// ─── Footer ───────────────────────────────────────────────────────────────────
.app-sidebar__footer {
  flex-shrink: 0;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  padding: $space-2;
}

.app-sidebar__user {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2;
  border-radius: $radius-md;
  background: transparent;
  border: none;
  cursor: pointer;
  color: $sidebar-text;
  width: 100%;
  text-align: left;
  transition: background-color var(--app-transition-fast), color var(--app-transition-fast);
  overflow: hidden;

  &:hover {
    background-color: rgba(255, 255, 255, 0.05);
    color: $sidebar-text-active;
  }

  &:focus-visible {
    outline: 2px solid rgba(255, 255, 255, 0.4);
    outline-offset: 2px;
  }

  &--collapsed {
    justify-content: center;
    padding: $space-2;
    gap: 0;
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
}

.app-sidebar__avatar-initials {
  color: #ffffff;
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  line-height: 1;
}

.app-sidebar__user-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
  flex: 1;
}

.app-sidebar__user-name {
  font-size: 14px;
  font-weight: $font-weight-medium;
  color: $sidebar-text-active;
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

.app-sidebar__user-menu-icon {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.4);
  flex-shrink: 0;
  margin-left: auto;
}

// ─── Nav skeleton ─────────────────────────────────────────────────────────────
@keyframes sidebar-skeleton-pulse {
  0%, 100% { opacity: 0.18; }
  50%       { opacity: 0.32; }
}

.app-sidebar__nav-skeleton {
  margin: 2px 8px;
  height: 36px;
  border-radius: 9px;
  background: rgba(255, 255, 255, 0.2);
  animation: sidebar-skeleton-pulse 1.4s ease-in-out infinite;

  .app-sidebar--collapsed & {
    // В свёрнутом виде — квадрат ~36px
    margin: 2px 8px;
    width: calc(var(--app-sidebar-rail-width) - 16px);
  }
}
</style>
