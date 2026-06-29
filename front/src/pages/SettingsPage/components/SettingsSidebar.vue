<template>
  <nav class="settings-sidebar" aria-label="Настройки навигация">
    <div
      v-for="group in visibleGroups"
      :key="group.key"
      class="settings-nav-group"
      :class="{ 'settings-nav-group--faded': group.allDisabled }"
    >
      <p class="settings-nav-group__label">{{ t(group.labelKey) }}</p>

      <button
        v-for="section in group.sections"
        :key="section.key"
        type="button"
        class="settings-nav-item"
        :class="{
          'settings-nav-item--active': activeSection === section.key && section.phase === 1,
          'settings-nav-item--disabled': section.phase !== 1,
        }"
        :disabled="section.phase !== 1"
        :aria-current="activeSection === section.key ? 'page' : undefined"
        @click="section.phase === 1 ? $emit('select', section.key) : undefined"
      >
        <i :class="[section.icon, 'settings-nav-item__icon']" aria-hidden="true" />
        <span class="settings-nav-item__label">{{ t(section.labelKey) }}</span>
        <Tag
          v-if="section.phase !== 1"
          :value="t('common.coming_soon')"
          severity="secondary"
          class="settings-nav-item__soon-tag"
        />
      </button>
    </div>
  </nav>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import { useUserStore } from '@/stores/user'

const { t } = useI18n()
const userStore = useUserStore()

defineProps<{
  activeSection: string
}>()

defineEmits<{
  select: [key: string]
}>()

interface SettingsSection {
  key: string
  labelKey: string
  icon: string
  phase: 1 | 2 | 3
  roles?: string[]
}

interface SettingsGroup {
  key: string
  labelKey: string
  adminOnly: boolean
  sections: SettingsSection[]
}

const GROUPS: SettingsGroup[] = [
  {
    key: 'account',
    labelKey: 'settings.groups.account',
    adminOnly: false,
    sections: [
      { key: 'profile',    labelKey: 'settings.sections.profile.title',    icon: 'pi pi-user',      phase: 1 },
      { key: 'security',   labelKey: 'settings.sections.security.title',   icon: 'pi pi-lock',      phase: 1 },
      { key: 'appearance', labelKey: 'settings.sections.appearance.title', icon: 'pi pi-sliders-h', phase: 1 },
      { key: 'language',   labelKey: 'settings.sections.language.title',   icon: 'pi pi-globe',     phase: 1 },
    ],
  },
  {
    key: 'integrations',
    labelKey: 'settings.groups.integrations',
    adminOnly: false,
    sections: [
      { key: 'channels', labelKey: 'settings.sections.channels.title', icon: 'pi pi-share-alt', phase: 1 },
    ],
  },
  {
    key: 'directories',
    labelKey: 'settings.groups.directories',
    adminOnly: true,
    sections: [
      { key: 'countries',       labelKey: 'settings.sections.countries.title',       icon: 'pi pi-globe',       phase: 1, roles: ['admin', 'director'] },
      { key: 'acq-channels',    labelKey: 'settings.sections.acq-channels.title',    icon: 'pi pi-megaphone',   phase: 1, roles: ['admin', 'director'] },
      { key: 'disc-reasons',    labelKey: 'settings.sections.disc-reasons.title',    icon: 'pi pi-ban',         phase: 1, roles: ['admin', 'director'] },
      { key: 'catalog',         labelKey: 'settings.sections.catalog.title',         icon: 'pi pi-box',         phase: 1, roles: ['admin', 'director'] },
      { key: 'exchange-rates',  labelKey: 'settings.sections.exchange-rates.title',  icon: 'pi pi-dollar',      phase: 1, roles: ['admin', 'director'] },
      { key: 'pipeline-stg',    labelKey: 'settings.sections.pipeline-stg.title',    icon: 'pi pi-sliders-h',   phase: 2, roles: ['admin', 'director'] },
      { key: 'doc-templates',   labelKey: 'settings.sections.doc-templates.title',   icon: 'pi pi-file-edit',   phase: 2, roles: ['admin', 'director'] },
      { key: 'tpl-variables',   labelKey: 'settings.sections.tpl-variables.title',   icon: 'pi pi-list',        phase: 2, roles: ['admin', 'director'] },
      { key: 'approval-routes', labelKey: 'settings.sections.approval-routes.title', icon: 'pi pi-sitemap',     phase: 2, roles: ['admin', 'director'] },
      { key: 'msg-templates',   labelKey: 'settings.sections.msg-templates.title',   icon: 'pi pi-envelope',    phase: 2, roles: ['admin', 'director'] },
    ],
  },
  {
    key: 'system',
    labelKey: 'settings.groups.system',
    adminOnly: true,
    sections: [
      { key: 'users',           labelKey: 'settings.sections.users.title',           icon: 'pi pi-users',       phase: 1, roles: ['admin', 'director'] },
      { key: 'access-control',  labelKey: 'settings.sections.access-control.title',  icon: 'pi pi-shield',      phase: 1, roles: ['admin', 'director'] },
      { key: 'automation-runs', labelKey: 'settings.sections.automation-runs.title', icon: 'pi pi-clock',       phase: 1, roles: ['admin', 'director'] },
      { key: 'system-reset',    labelKey: 'settings.sections.system-reset.title',    icon: 'pi pi-refresh',     phase: 1, roles: ['admin'] },
    ],
  },
]

const isAdminOrDirector = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

const isAdmin = computed(() => userStore.getUserRole === 'admin')

const visibleGroups = computed(() =>
  GROUPS
    .filter((g) => !g.adminOnly || isAdminOrDirector.value)
    .map((g) => ({
      ...g,
      // Filter sections by role: sections with roles: ['admin'] are hidden from director
      sections: g.sections.filter((s) => {
        if (!s.roles) return true
        if (s.roles.includes('admin') && !s.roles.includes('director')) {
          // admin-only section: only show to admin
          return isAdmin.value
        }
        // admin+director section: show to both
        return isAdminOrDirector.value
      }),
    }))
    .map((g) => ({
      ...g,
      allDisabled: g.sections.every((s) => s.phase !== 1),
    })),
)
</script>

<style lang="scss" scoped>
.settings-sidebar {
  padding: $space-3 0;
}

.settings-nav-group {
  margin-bottom: $space-2;

  &--faded {
    opacity: 0.6;
  }
}

.settings-nav-group__label {
  // BUG-5: $font-size-xs renders 10.5px at 14px root (0.75rem × 14 = 10.5) — below 12px min.
  // $font-size-sm renders 12.25px (0.875rem × 14 = 12.25) — passes ≥12px requirement.
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: $surface-400;
  padding: $space-4 $space-4 $space-1;
  margin: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.settings-nav-item {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-4;
  border-radius: $radius-md;
  margin: 2px $space-2;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);
  min-height: 36px;
  text-decoration: none;
  border: none;
  background: transparent;
  width: calc(100% - $space-4);
  text-align: left;

  &:hover:not(&--disabled) {
    background: var(--mg-surface-hover);
    color: $surface-900;
  }

  // BUG-4 fix: general dark override MUST appear BEFORE &--active in source so
  // the compiled rule .app-dark .settings-nav-item comes before
  // .app-dark .settings-nav-item--active in the CSS output.
  // Both selectors have equal specificity (0,2,0); the LATER rule wins.
  // Active dark override (.app-dark .settings-nav-item--active) defined inside
  // &--active below will therefore appear AFTER this rule → wins correctly.
  .app-dark & {
    color: var(--p-surface-300);

    &:hover:not(.settings-nav-item--disabled) {
      background: var(--mg-surface-hover);
      color: var(--p-surface-100);
    }
  }

  &--active {
    background: var(--p-primary-50);
    color: $primary-900;
    font-weight: $font-weight-semibold;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: inset 3px 0 0 $primary-900;

    // BUG-4: dark active — compiled AFTER the general .app-dark .settings-nav-item
    // rule above → same specificity (0,2,0) but later in CSS → wins.
    .app-dark & {
      background: var(--p-primary-950);
      color: var(--p-primary-200);
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      box-shadow: inset 3px 0 0 var(--p-primary-200);
    }
  }

  &--disabled {
    opacity: 0.5;
    cursor: default;
    pointer-events: none;
  }

  &__icon {
    font-size: $font-size-sm;
    color: inherit;
    opacity: 0.7;
    flex-shrink: 0;
  }

  &__label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__soon-tag {
    font-size: $font-size-2xs;
    flex-shrink: 0;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    padding: 2px $space-2;
    line-height: 1;
  }
}
</style>
