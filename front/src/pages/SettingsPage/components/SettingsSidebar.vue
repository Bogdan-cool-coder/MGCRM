<template>
  <nav class="settings-sidebar" aria-label="Настройки навигация">
    <!-- Search filter (hi-fi TZ §2) -->
    <div class="settings-sidebar__search">
      <IconField>
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="search"
          :placeholder="t('settings.nav.searchPlaceholder')"
          class="settings-sidebar__search-input"
        />
      </IconField>
    </div>

    <!-- Empty state when the search matches nothing -->
    <p v-if="isSearchEmpty" class="settings-sidebar__empty">
      {{ t('settings.nav.searchEmpty') }}
    </p>

    <div
      v-for="group in filteredGroups"
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
          'settings-nav-item--active': isSectionActive(section) && section.phase === 1 && !section.linkOut,
          'settings-nav-item--disabled': section.phase !== 1,
          'settings-nav-item--danger': section.danger && !(isSectionActive(section) && section.phase === 1 && !section.linkOut),
        }"
        :disabled="section.phase !== 1"
        :aria-current="activeSection === section.key ? 'page' : undefined"
        @click="onSectionClick(section)"
      >
        <i :class="[section.icon, 'settings-nav-item__icon']" aria-hidden="true" />
        <span class="settings-nav-item__label">{{ t(section.labelKey) }}</span>
        <span
          v-if="sectionMeta(section) !== null"
          class="settings-nav-item__meta"
        >{{ sectionMeta(section) }}</span>
        <i v-if="section.linkOut && section.phase === 1" class="pi pi-external-link settings-nav-item__link-icon" aria-hidden="true" />
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
import { computed, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import { useUserStore } from '@/stores/user'
import { adminUsersApi } from '@/api/adminUsers'
import { isProfileSection, isDirectoriesSection } from '../composables/useSettings'

const { t } = useI18n()
const userStore = useUserStore()

const props = defineProps<{
  activeSection: string
}>()

// ─── Search filter ────────────────────────────────────────────────────────────
const search = ref('')

// ─── Users meta counter (active-user count next to «Пользователи») ────────────
const activeUsersCount = ref<number | null>(null)

const emit = defineEmits<{
  select: [key: string]
  /** linkOut-навигация: родитель проверяет dirty перед router.push */
  linkOut: [path: string]
}>()

interface SettingsSection {
  key: string
  labelKey: string
  icon: string
  phase: 1 | 2 | 3
  /** Per-item role restriction. If absent — inherits group.adminOnly logic */
  roles?: string[]
  /** If set — item navigates to this route instead of switching section panel */
  linkOut?: string
  /** Danger item — red text when at rest (e.g. «Сброс системы») */
  danger?: boolean
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
      // ОВ-3 (Ф5): 4 пункта схлопнуты в один «Профиль»; активен при любом PROFILE_TAB_KEY
      { key: 'profile', labelKey: 'settings.sections.profile.title', icon: 'pi pi-user', phase: 1 },
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
    adminOnly: false, // group visibility now driven by per-item role filter below
    // Гэп-1: 11 отдельных справочников свёрнуты в ЕДИНЫЙ пункт «Справочники»
    // (открывает SectionDirectories с таб-стрипом). Роли — объединение всех
    // per-tab ролей: пункт виден, если пользователю доступен хотя бы один таб.
    sections: [
      { key: 'directories',     labelKey: 'settings.sections.directories.title',     icon: 'pi pi-folder-open', phase: 1, roles: ['admin', 'director', 'lawyer', 'manager'] },
      // link-out: navigates to standalone PipelineSettingsPage instead of embedding
      { key: 'pipeline-stg',    labelKey: 'settings.sections.pipeline-stg.title',    icon: 'pi pi-sliders-h',   phase: 1, roles: ['admin', 'director'], linkOut: '/settings/pipeline' },
    ],
  },
  {
    key: 'sales',
    labelKey: 'settings.groups.sales',
    adminOnly: false, // per-item role filter (admin/director)
    sections: [
      { key: 'motivation-builder', labelKey: 'settings.sections.motivation-builder.title', icon: 'pi pi-chart-line', phase: 1, roles: ['admin', 'director'] },
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
      { key: 'system-reset',    labelKey: 'settings.sections.system-reset.title',    icon: 'pi pi-refresh',     phase: 1, roles: ['admin'], danger: true },
    ],
  },
]

const userRole = computed(() => userStore.getUserRole ?? '')
const isAdminOrDirector = computed(() => userRole.value === 'admin' || userRole.value === 'director')
const isAdmin = computed(() => userRole.value === 'admin')

/** Check if a section is accessible for the current user role */
function isSectionVisible(s: SettingsSection): boolean {
  if (!s.roles) return true
  // admin-only (not director): only show to admin
  if (s.roles.includes('admin') && !s.roles.includes('director') && !s.roles.includes('lawyer') && !s.roles.includes('manager')) {
    return isAdmin.value
  }
  return s.roles.includes(userRole.value)
}

const visibleGroups = computed(() =>
  GROUPS
    .map((g) => ({
      ...g,
      sections: g.sections.filter((s) => {
        // For groups with adminOnly=true, first check admin/director gate
        if (g.adminOnly && !isAdminOrDirector.value) return false
        return isSectionVisible(s)
      }),
    }))
    // Hide groups with no visible sections
    .filter((g) => g.sections.length > 0)
    .map((g) => ({
      ...g,
      allDisabled: g.sections.every((s) => s.phase !== 1),
    })),
)

/** Groups after applying the search filter (matches translated label substring). */
const filteredGroups = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return visibleGroups.value
  return visibleGroups.value
    .map((g) => ({
      ...g,
      sections: g.sections.filter((s) => t(s.labelKey).toLowerCase().includes(q)),
    }))
    .filter((g) => g.sections.length > 0)
})

/** True when a non-empty search yields no matching items. */
const isSearchEmpty = computed(
  () => !!search.value.trim() && filteredGroups.value.length === 0,
)

/** Optional meta counter shown right of a section label (e.g. active users). */
function sectionMeta(section: SettingsSection): number | string | null {
  if (section.key === 'users' && activeUsersCount.value !== null) {
    return activeUsersCount.value
  }
  return null
}

onMounted(async () => {
  // Fetch active-user count once for the «Пользователи» badge — admin/director only,
  // since only they see the item. Best-effort: silently skip on failure.
  if (!isAdminOrDirector.value) return
  try {
    const res = await adminUsersApi.getUsers({ is_active: true, per_page: 1 })
    activeUsersCount.value = res.meta.total
  } catch {
    activeUsersCount.value = null
  }
})

/**
 * Пункт «Профиль» активен при любом из PROFILE_TAB_KEYS
 * (profile / security / appearance / language).
 */
function isSectionActive(section: SettingsSection): boolean {
  if (section.key === 'profile') {
    return isProfileSection(props.activeSection)
  }
  // Гэп-1: единый пункт «Справочники» активен при любом directory/document-ключе
  // (activeSection хранит конкретный таб, напр. countries / msg-templates).
  if (section.key === 'directories') {
    return isDirectoriesSection(props.activeSection)
  }
  return props.activeSection === section.key
}

function onSectionClick(section: SettingsSection) {
  if (section.phase !== 1) return
  if (section.linkOut) {
    // Делегируем родителю — он проверит dirty-state перед router.push,
    // чтобы не полагаться на async onBeforeRouteLeave тайминг Vue Router.
    emit('linkOut', section.linkOut)
    return
  }
  emit('select', section.key)
}
</script>

<style lang="scss" scoped>
.settings-sidebar {
  padding: $space-3 0;
}

// ─── Search field ─────────────────────────────────────────────────────────────
.settings-sidebar__search {
  padding: $space-2 $space-3 $space-1;

  :deep(.p-iconfield),
  :deep(.p-inputtext) {
    width: 100%;
  }
}

.settings-sidebar__search-input {
  height: 36px;
  font-size: $font-size-sm;
}

.settings-sidebar__empty {
  padding: $space-3 $space-4;
  margin: 0;
  font-size: $font-size-xs;
  color: $surface-500;

  // Inverted dark scale: muted text must use a HIGH surface step (600+) to stay
  // readable on the dark sidebar (#111E38). surface-400 = #616263 → too dark.
  .app-dark & {
    color: var(--p-surface-600);
  }
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

  // Inverted dark scale: group headers need surface-600+ to read on dark sidebar.
  .app-dark & {
    color: var(--p-surface-600);
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
  // Inverted dark scale: nav text must use HIGH surface steps on the dark
  // sidebar. surface-300 (#27395C-ish) fails AA; surface-700 reads clearly and
  // hover surface-900 (near-white) is the brightest step.
  .app-dark & {
    color: var(--p-surface-700);

    &:hover:not(.settings-nav-item--disabled) {
      background: var(--mg-surface-hover);
      color: var(--p-surface-900);
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

  // Danger item (e.g. «Сброс системы») — красный текст в состоянии покоя.
  // При active применяется навы-инверт из &--active (danger-класс не ставится).
  &--danger {
    color: var(--p-red-500);

    &:hover:not(.settings-nav-item--disabled) {
      // slightly stronger red on hover; background inherits shared hover token
      color: var(--p-red-600);
    }

    // Inverted dark scale: red lightens in dark → red-400 reads on dark sidebar.
    .app-dark & {
      color: var(--p-red-400);

      &:hover:not(.settings-nav-item--disabled) {
        color: var(--p-red-300);
      }
    }
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

  &__meta {
    font-size: $font-size-2xs;
    font-weight: $font-weight-bold;
    color: $surface-500;
    flex-shrink: 0;

    // Inverted dark scale: meta count needs surface-600+ to read on dark sidebar.
    .app-dark & {
      color: var(--p-surface-600);
    }
  }

  &__link-icon {
    font-size: $font-size-xs;
    color: inherit;
    opacity: 0.5;
    flex-shrink: 0;
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
