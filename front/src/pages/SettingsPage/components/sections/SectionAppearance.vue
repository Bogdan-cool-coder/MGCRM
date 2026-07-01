<template>
  <div class="section-appearance">
    <!-- Theme section -->
    <div class="profile-section">
      <h3 class="profile-section__title">{{ t('account.theme') }}</h3>
      <SelectButton
        v-model="themeDraft"
        :options="themeOptions"
        option-label="label"
        option-value="value"
        :pt="{ root: { class: 'theme-selectbtn' } }"
        @change="onThemeChange"
      />
    </div>

    <!-- Nav mode section -->
    <div class="profile-section">
      <h3 class="profile-section__title">{{ t('layout.navMode') }}</h3>
      <div class="nav-mode-cards">
        <button
          v-for="mode in navModeOptions"
          :key="mode.value"
          type="button"
          class="nav-mode-card"
          :class="{ 'nav-mode-card--active': navModeDraft === mode.value }"
          @click="onNavModeChange(mode.value)"
        >
          <i :class="['nav-mode-card__icon', mode.icon]" />
          <span class="nav-mode-card__label">{{ mode.label }}</span>
          <span v-if="mode.hint" class="nav-mode-card__hint">{{ mode.hint }}</span>
          <i v-if="navModeDraft === mode.value" class="pi pi-check nav-mode-card__check" />
        </button>
      </div>
    </div>

    <!-- Quick actions section -->
    <div class="profile-section">
      <h3 class="profile-section__title">{{ t('quickActions.sectionTitle') }}</h3>

      <!-- Orbit hint -->
      <Message
        v-if="navModeDraft === 'orbit'"
        severity="info"
        :closable="false"
        class="mb-3"
      >
        {{ t('settings.appearance.quickActionsOrbitHint') }}
      </Message>
      <p v-else class="appearance-hint mb-3">
        <i class="pi pi-info-circle" />
        {{ t('settings.appearance.quickActionsNonOrbitHint') }}
      </p>

      <!-- Preview chips -->
      <div v-if="currentQuickActions.length > 0" class="quick-actions-preview mb-3">
        <div
          v-for="action in currentQuickActions"
          :key="action.key"
          class="quick-actions-preview__item"
        >
          <i :class="[action.icon, 'quick-actions-preview__icon']" aria-hidden="true" />
          <span class="quick-actions-preview__label">{{ t(action.labelKey) }}</span>
        </div>
      </div>
      <p v-else class="text-muted mb-3">{{ t('quickActions.noneSelected') }}</p>

      <Button
        icon="pi pi-cog"
        :label="t('quickActions.configure')"
        severity="secondary"
        outlined
        @click="pickerVisible = true"
      />
    </div>

    <!-- Save bar -->
    <div v-if="isDirty" class="settings-save-bar">
      <Button
        icon="pi pi-times"
        :label="t('settings.discard')"
        severity="secondary"
        text
        @click="discard"
      />
      <Button
        icon="pi pi-check"
        :label="t('settings.save')"
        @click="save"
      />
    </div>
  </div>

  <!-- Quick actions picker: draftMode=true so it emits keys without persisting to API -->
  <QuickActionsPickerDialog
    v-model:visible="pickerVisible"
    :draft-mode="true"
    :draft-keys="quickActionsDraft"
    @update:draft="onQuickActionsDraftUpdate"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch, inject, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { useThemeStore } from '@/stores/theme'
import { useLayoutStore } from '@/stores/layout'
import { useUserStore } from '@/stores/user'
import { resolveQuickActions } from '@/shared/nav/quickActionRegistry'
import { profileApi } from '@/api/profile'
import { mapUser } from '@/entities/user'
import type { NavMode } from '@/stores/layout'
import { SETTINGS_MARK_DIRTY_KEY, SETTINGS_MARK_CLEAN_KEY } from '../../composables/useSettings'
import QuickActionsPickerDialog from '@/pages/ProfilePage/components/QuickActionsPickerDialog.vue'

const { t } = useI18n()
const themeStore = useThemeStore()
const layoutStore = useLayoutStore()
const userStore = useUserStore()
const toast = useToast()

const markDirty = inject<() => void>(SETTINGS_MARK_DIRTY_KEY, () => {})
const markClean = inject<() => void>(SETTINGS_MARK_CLEAN_KEY, () => {})

// ─── Drafts (snapshots taken at section mount) ───────────────────────────────
const themeDraft = ref<'light' | 'dark'>(themeStore.theme)
const navModeDraft = ref<NavMode>(layoutStore.navMode)
const savedTheme = ref<'light' | 'dark'>(themeStore.theme)
const savedNavMode = ref<NavMode>(layoutStore.navMode)

// ─── Quick actions draft (BUG-7) ─────────────────────────────────────────────
// Dialog writes into this draft; persists only when section "Save" is clicked.
const quickActionsDraft = ref<string[]>([...userStore.getNavQuickActions])
const savedQuickActions = ref<string[]>([...userStore.getNavQuickActions])

onMounted(() => {
  // Take snapshot of all drafts
  savedTheme.value = themeStore.theme
  savedNavMode.value = layoutStore.navMode
  themeDraft.value = themeStore.theme
  navModeDraft.value = layoutStore.navMode
  quickActionsDraft.value = [...userStore.getNavQuickActions]
  savedQuickActions.value = [...userStore.getNavQuickActions]
})

const isDirty = computed(
  () =>
    themeDraft.value !== savedTheme.value ||
    navModeDraft.value !== savedNavMode.value ||
    JSON.stringify(quickActionsDraft.value) !== JSON.stringify(savedQuickActions.value),
)

watch(isDirty, (dirty) => {
  if (dirty) markDirty()
  else markClean()
})

function onThemeChange(event: { value: 'light' | 'dark' }) {
  themeDraft.value = event.value
  // Preview immediately
  themeStore.setTheme(event.value)
}

function onNavModeChange(mode: NavMode) {
  navModeDraft.value = mode
  // Preview immediately
  layoutStore.setNavMode(mode)
}

/** Receives draft keys from QuickActionsPickerDialog (draftMode=true) and closes the dialog. */
function onQuickActionsDraftUpdate(keys: string[]) {
  quickActionsDraft.value = keys
  // Close dialog explicitly here as well as in the dialog's own save() so the
  // dialog always closes regardless of which code path emits the event.
  pickerVisible.value = false
}

async function save() {
  // Persist theme + navMode in stores (Pinia persist → localStorage)
  savedTheme.value = themeDraft.value
  savedNavMode.value = navModeDraft.value

  // Persist quick actions to backend only if changed (BUG-7)
  const qaChanged =
    JSON.stringify(quickActionsDraft.value) !== JSON.stringify(savedQuickActions.value)
  if (qaChanged) {
    try {
      const response = await profileApi.updateProfile({ nav_quick_actions: quickActionsDraft.value })
      userStore.setCurrentUser(mapUser(response.data))
      savedQuickActions.value = [...quickActionsDraft.value]
    } catch {
      toast.add({
        severity: 'error',
        summary: t('errors.unknown', 'Ошибка сохранения'),
        life: 3000,
      })
      return
    }
  }

  markClean()
  toast.add({
    severity: 'success',
    summary: t('settings.appearance.saved'),
    life: 2500,
  })
}

function discard() {
  // Rollback all previews including quick actions
  themeStore.setTheme(savedTheme.value)
  layoutStore.setNavMode(savedNavMode.value)
  themeDraft.value = savedTheme.value
  navModeDraft.value = savedNavMode.value
  quickActionsDraft.value = [...savedQuickActions.value]
  markClean()
}

// Expose discard for parent dirty-guard "Leave" action
defineExpose({ discard })

// ─── Nav mode options ────────────────────────────────────────────────────────
const navModeOptions = computed(() => [
  {
    value: 'sidebar' as NavMode,
    icon: 'pi pi-objects-column',
    label: t('layout.navModeSidebar'),
    hint: null,
  },
  {
    value: 'orbit' as NavMode,
    icon: 'pi pi-circle-fill',
    label: t('layout.navModeOrbit'),
    hint: t('layout.navModeOrbitHint'),
  },
])

// ─── Theme options ────────────────────────────────────────────────────────────
const themeOptions = computed(() => [
  { label: t('account.themeLight'), value: 'light' },
  { label: t('account.themeDark'), value: 'dark' },
])

// ─── Quick actions ────────────────────────────────────────────────────────────
const pickerVisible = ref(false)
/** Preview chips — shown from draft so user sees pending changes before saving */
const currentQuickActions = computed(() =>
  resolveQuickActions(quickActionsDraft.value),
)
</script>

<style lang="scss" scoped>
.section-appearance {
  padding: $space-6;
}

.profile-section {
  margin-bottom: $space-6;
}

.profile-section__title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-4;
  padding-bottom: $space-2;
  border-bottom: 1px solid $surface-200;

  .app-dark & {
    color: var(--p-surface-900);
    border-bottom-color: var(--p-surface-700);
  }
}

.text-muted {
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Nav mode cards
.nav-mode-cards {
  display: flex;
  gap: $space-3;
  flex-wrap: wrap;
}

.nav-mode-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: $space-2;
  padding: $space-4;
  width: 200px;
  background: $surface-card;
  border: 2px solid $surface-200;
  border-radius: $radius-md;
  cursor: pointer;
  text-align: left;
  transition: border-color $transition-fast, background-color $transition-fast;

  .app-dark & {
    // BUG-2: surface-800 in dark = #F1F2F3 (light); use surface-100
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }

  &:hover {
    border-color: $primary;
    background: rgba($primary, 0.03);
  }

  &--active {
    border-color: $primary;
    background: rgba($primary, 0.06);
  }

  &__icon {
    font-size: $font-size-2xl;
    color: $primary;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-900;

    .app-dark & {
      color: var(--p-surface-50);
    }
  }

  &__hint {
    font-size: $font-size-xs;
    color: $surface-500;
    line-height: 1.4;
  }

  &__check {
    position: absolute;
    top: $space-2;
    right: $space-2;
    font-size: $font-size-sm;
    color: $primary;
  }
}

.appearance-hint {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
  margin-top: $space-2;
  font-size: $font-size-sm;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }

  i {
    flex-shrink: 0;
    margin-top: 2px;
  }
}

// Quick actions preview
.quick-actions-preview {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
}

.quick-actions-preview__item {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  font-size: $font-size-sm;

  .app-dark & {
    // BUG-2: surface-800 in dark = #F1F2F3; use surface-100
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

.quick-actions-preview__icon {
  color: $primary;
  font-size: $font-size-md;
}

.quick-actions-preview__label {
  font-weight: $font-weight-medium;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-50);
  }
}

// Theme SelectButton dark override — scoped under .section-appearance so Vue
// can attach the scope attribute correctly
.section-appearance {
  .app-dark & :deep(.theme-selectbtn .p-togglebutton.p-togglebutton-checked) {
    background: var(--p-primary-color);
    color: var(--p-primary-contrast-color);
    border-color: var(--p-primary-color);
  }
}

// Save bar
.settings-save-bar {
  display: flex;
  gap: $space-2;
  justify-content: flex-end;
  padding: $space-4 $space-6;
  border-top: 1px solid $surface-200;
  background: $surface-card;
  margin: $space-4 calc(-1 * $space-6) 0;
  position: sticky;
  bottom: 0;
  z-index: 1;

  .app-dark & {
    // BUG-2: surface-800 in dark = #F1F2F3 (light); use surface-100
    background: var(--p-surface-100);
    border-top-color: var(--p-surface-200);
  }
}
</style>
