<template>
  <div class="profile-page">
    <PageHeader :title="t('profile.title')" icon="pi pi-user" />

    <div class="profile-page__body">
      <div class="row g-0 h-100">
        <!-- Tab sidebar -->
        <div class="col-auto">
          <nav class="profile-tabs">
            <button
              v-for="tab in visibleTabs"
              :key="tab"
              class="profile-tabs__item"
              :class="{ 'profile-tabs__item--active': activeTab === tab }"
              @click="setTab(tab)"
            >
              <i :class="['profile-tabs__icon', tabIcon(tab)]" />
              <span>{{ t(`profile.tabs.${tab}`) }}</span>
            </button>
          </nav>
        </div>

        <!-- Tab content -->
        <div class="col">
          <div class="profile-content">
            <!-- Profile tab -->
            <template v-if="activeTab === 'profile'">
              <h2 class="profile-content__heading">{{ t('profile.tabs.profile') }}</h2>
              <div v-if="user" class="row g-4">
                <div class="col-md-6">
                  <div class="profile-field">
                    <label class="profile-field__label">{{ t('profile.fields.full_name') }}</label>
                    <InputText :model-value="user.full_name" disabled class="w-100" />
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="profile-field">
                    <label class="profile-field__label">{{ t('profile.fields.email') }}</label>
                    <InputText :model-value="user.email" disabled class="w-100" />
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="profile-field">
                    <label class="profile-field__label">{{ t('profile.fields.role') }}</label>
                    <InputText :model-value="t(`roles.${user.role}`, user.role)" disabled class="w-100" />
                  </div>
                </div>
              </div>
            </template>

            <!-- Security tab -->
            <template v-if="activeTab === 'security'">
              <h2 class="profile-content__heading">{{ t('profile.security.title') }}</h2>

              <!-- 2FA section -->
              <div class="profile-section">
                <h3 class="profile-section__title">{{ t('profile.security.totp_section') }}</h3>

                <!-- 2FA enabled -->
                <div v-if="user?.totp_enabled && !isTotpSetupStarted && !showBackupCodes">
                  <Tag severity="success" :value="t('profile.security.totp_enabled')" class="mb-3" />
                  <p class="text-muted">{{ t('profile.security.totp_enabled') }}</p>
                </div>

                <!-- 2FA not enabled — offer setup -->
                <div v-if="!user?.totp_enabled && !isTotpSetupStarted && !showBackupCodes">
                  <Tag severity="secondary" :value="t('profile.security.totp_disabled')" class="mb-3" />
                  <p class="mb-3">{{ t('profile.security.totp_disabled') }}</p>
                  <Button
                    :label="t('profile.security.enable_totp')"
                    :loading="isSettingUpTotp"
                    @click="startTotpSetup"
                  />
                </div>

                <!-- Setup QR step -->
                <div v-if="isTotpSetupStarted && !showBackupCodes">
                  <h4 class="mb-3">{{ t('profile.security.totp_setup_title') }}</h4>
                  <p class="mb-3">{{ t('profile.security.totp_scan_qr') }}</p>

                  <!-- QR code placeholder (library qrcode.vue needed) -->
                  <div class="totp-qr-placeholder mb-4">
                    <p class="totp-qr-placeholder__text">{{ totpSetupSecret }}</p>
                    <p class="text-muted" style="font-size: 12px; word-break: break-all;">{{ totpSetupUri }}</p>
                  </div>

                  <p class="mb-3">{{ t('profile.security.totp_enter_code') }}</p>

                  <div class="d-flex gap-3 align-items-start">
                    <div class="profile-field" style="max-width: 200px">
                      <InputText
                        v-model="totpSetupCode"
                        placeholder="000000"
                        inputmode="numeric"
                        maxlength="6"
                        :invalid="!!totpSetupError"
                        class="w-100"
                      />
                      <small v-if="totpSetupError" class="login-field__error">{{ totpSetupError }}</small>
                    </div>
                    <Button
                      :label="t('profile.security.totp_verify')"
                      :loading="isSettingUpTotp"
                      @click="verifyTotpSetup"
                    />
                    <Button
                      :label="t('common.cancel')"
                      severity="secondary"
                      outlined
                      @click="cancelTotpSetup"
                    />
                  </div>
                </div>

                <!-- Backup codes -->
                <div v-if="showBackupCodes">
                  <Message severity="success" :closable="false" class="mb-4">
                    {{ t('profile.security.totp_setup_success') }}
                  </Message>
                  <h4 class="mb-2">{{ t('profile.security.totp_backup_codes') }}</h4>
                  <p class="mb-3 text-muted">{{ t('profile.security.totp_backup_hint') }}</p>
                  <div class="totp-backup-codes">
                    <code v-for="code in backupCodes" :key="code" class="totp-backup-code">
                      {{ code }}
                    </code>
                  </div>
                </div>
              </div>
            </template>

            <!-- Telegram tab -->
            <template v-if="activeTab === 'telegram'">
              <h2 class="profile-content__heading">{{ t('profile.telegram.title') }}</h2>
              <div class="profile-section">
                <!-- Not linked -->
                <div v-if="!telegramLinked" class="telegram-block">
                  <div class="telegram-block__icon-row">
                    <i class="pi pi-telegram telegram-block__icon" />
                    <div>
                      <p class="telegram-block__status">{{ t('profile.telegram.notLinked') }}</p>
                      <p class="telegram-block__desc">{{ t('profile.telegram.description') }}</p>
                    </div>
                  </div>
                  <Button
                    icon="pi pi-link"
                    :label="t('profile.telegram.linkBtn')"
                    :loading="telegramLinking"
                    class="mt-3"
                    @click="linkTelegram"
                  />
                </div>
                <!-- Linked -->
                <div v-else class="telegram-block">
                  <div class="telegram-block__icon-row">
                    <i class="pi pi-telegram telegram-block__icon telegram-block__icon--success" />
                    <div>
                      <p class="telegram-block__status telegram-block__status--success">
                        {{ t('profile.telegram.linked') }}
                        <span v-if="telegramUsername" class="telegram-block__username">
                          @{{ telegramUsername }}
                        </span>
                      </p>
                    </div>
                  </div>
                  <Button
                    icon="pi pi-unlink"
                    :label="t('profile.telegram.unlinkBtn')"
                    severity="danger"
                    outlined
                    :loading="telegramUnlinking"
                    class="mt-3"
                    @click="unlinkTelegram"
                  />
                </div>
              </div>
            </template>

            <!-- Appearance tab: theme + nav mode -->
            <template v-if="activeTab === 'appearance'">
              <h2 class="profile-content__heading">{{ t('layout.appearance') }}</h2>

              <!-- Theme section -->
              <div class="profile-section">
                <h3 class="profile-section__title">{{ t('account.theme') }}</h3>
                <SelectButton
                  v-model="currentTheme"
                  :options="themeOptions"
                  option-label="label"
                  option-value="value"
                  :pt="{ root: { class: 'theme-selectbtn' } }"
                />
              </div>

              <!-- Nav mode section -->
              <div class="profile-section">
                <h3 class="profile-section__title">{{ t('layout.navMode') }}</h3>
                <div class="nav-mode-cards">
                  <button
                    v-for="mode in navModeOptions"
                    :key="mode.value"
                    class="nav-mode-card"
                    :class="{ 'nav-mode-card--active': currentNavMode === mode.value }"
                    @click="setNavMode(mode.value)"
                  >
                    <i :class="['nav-mode-card__icon', mode.icon]" />
                    <span class="nav-mode-card__label">{{ mode.label }}</span>
                    <span v-if="mode.hint" class="nav-mode-card__hint">{{ mode.hint }}</span>
                    <i v-if="currentNavMode === mode.value" class="pi pi-check nav-mode-card__check" />
                  </button>
                </div>
                <p v-if="currentNavMode === 'orbit'" class="appearance-hint">
                  <i class="pi pi-info-circle" />
                  {{ t('layout.navModeOrbitHint') }}
                </p>
              </div>
            </template>

            <!-- Quick actions tab -->
            <template v-if="activeTab === 'quickActions'">
              <h2 class="profile-content__heading">{{ t('quickActions.title') }}</h2>
              <div class="profile-section">
                <h3 class="profile-section__title">{{ t('quickActions.sectionTitle') }}</h3>
                <p class="text-muted mb-3">{{ t('quickActions.sectionHint') }}</p>

                <!-- Current selection preview -->
                <div v-if="currentQuickActions.length > 0" class="quick-actions-preview mb-4">
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
            </template>

            <!-- System tab (admin only) -->
            <template v-if="activeTab === 'system'">
              <h2 class="profile-content__heading">{{ t('system.reset.tab_title') }}</h2>

              <div class="profile-section">
                <h3 class="profile-section__title">{{ t('system.reset.section_title') }}</h3>
                <p class="text-muted mb-3">{{ t('system.reset.section_hint') }}</p>

                <div class="system-reset-trigger">
                  <div class="system-reset-trigger__info">
                    <i class="pi pi-exclamation-triangle system-reset-trigger__icon" aria-hidden="true" />
                    <div>
                      <p class="system-reset-trigger__label">{{ t('system.reset.action_label') }}</p>
                      <p class="system-reset-trigger__desc">{{ t('system.reset.action_desc') }}</p>
                    </div>
                  </div>
                  <Button
                    :label="t('system.reset.open_dialog_btn')"
                    severity="danger"
                    outlined
                    icon="pi pi-refresh"
                    @click="systemReset.openDialog()"
                  />
                </div>
              </div>
            </template>

            <!-- Coming soon tabs -->
            <template v-if="['notifications', 'locale', 'calendar', 'signature', 'segments'].includes(activeTab)">
              <h2 class="profile-content__heading">{{ t(`profile.tabs.${activeTab}`) }}</h2>

              <!-- Locale switcher -->
              <div v-if="activeTab === 'locale'">
                <p class="mb-3">{{ t('profile.locale.title') }}</p>
                <div class="d-flex gap-3">
                  <Button
                    :label="t('profile.locale.ru')"
                    :severity="currentLocale === 'ru' ? 'primary' : 'secondary'"
                    outlined
                    @click="setLocale('ru')"
                  />
                  <Button
                    :label="t('profile.locale.en')"
                    :severity="currentLocale === 'en' ? 'primary' : 'secondary'"
                    outlined
                    @click="setLocale('en')"
                  />
                </div>
              </div>

              <div v-else class="coming-soon-block">
                <i class="pi pi-clock coming-soon-block__icon" />
                <p>{{ t('profile.coming_soon') }}</p>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick actions picker dialog (portal) -->
  <QuickActionsPickerDialog v-model:visible="pickerVisible" />

  <!-- System reset dialog (admin only, portal) -->
  <SystemResetDialog
    v-if="isAdmin"
    :visible="systemReset.dialogVisible.value"
    :confirm-input="systemReset.confirmInput.value"
    :is-confirmed="systemReset.isConfirmed.value"
    :is-pending="systemReset.isPending.value"
    :RESET_CONFIRM_PHRASE="systemReset.RESET_CONFIRM_PHRASE"
    @update:visible="(v) => { if (!v) systemReset.closeDialog() }"
    @update:confirm-input="(v) => { systemReset.confirmInput.value = v }"
    @confirm="systemReset.executeReset()"
    @cancel="systemReset.closeDialog()"
  />
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import SelectButton from 'primevue/selectbutton'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import { useLayoutStore } from '@/stores/layout'
import { useThemeStore } from '@/stores/theme'
import { localeManager } from '@/application/locale'
import { getI18nLocale, type AvailableLocales } from '@/plugins/i18n'
import type { NavMode } from '@/stores/layout'
import { useProfilePage, type ProfileTab } from './composables/useProfilePage'
import QuickActionsPickerDialog from './components/QuickActionsPickerDialog.vue'
import SystemResetDialog from './components/SystemResetDialog.vue'
import { useUserStore } from '@/stores/user'
import { resolveQuickActions } from '@/shared/nav/quickActionRegistry'
import { useSystemReset } from './composables/useSystemReset'

const { t } = useI18n()
const layoutStore = useLayoutStore()
const themeStore = useThemeStore()
const userStore = useUserStore()

// ─── Admin guard ──────────────────────────────────────────────────────────────
const isAdmin = computed(() => userStore.getUserRole === 'admin')

// ─── System reset (admin only) ───────────────────────────────────────────────
const systemReset = useSystemReset()

// ─── Quick actions ────────────────────────────────────────────────────────────
const currentQuickActions = computed(() =>
  resolveQuickActions(userStore.getNavQuickActions),
)

// ─── Appearance: theme ────────────────────────────────────────────────────────
const currentTheme = computed<'light' | 'dark'>({
  get: () => themeStore.theme,
  set: (value) => themeStore.setTheme(value),
})

const themeOptions = computed(() => [
  { label: t('account.themeLight'), value: 'light' },
  { label: t('account.themeDark'), value: 'dark' },
])

// ─── Appearance: nav mode ─────────────────────────────────────────────────────
const currentNavMode = computed(() => layoutStore.navMode)

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

function setNavMode(mode: NavMode) {
  layoutStore.setNavMode(mode)
}

const currentLocale = computed(() => getI18nLocale())

function setLocale(locale: AvailableLocales) {
  localeManager.changeLocale(locale)
}

const {
  activeTab,
  setTab,
  tabs,
  user,
  totpSetupSecret,
  totpSetupUri,
  totpSetupCode,
  totpSetupError,
  backupCodes,
  showBackupCodes,
  isSettingUpTotp,
  isTotpSetupStarted,
  startTotpSetup,
  verifyTotpSetup,
  cancelTotpSetup,
  telegramLinked,
  telegramUsername,
  telegramLinking,
  telegramUnlinking,
  linkTelegram,
  unlinkTelegram,
} = useProfilePage()

const TAB_ICONS: Record<ProfileTab, string> = {
  profile: 'pi pi-user',
  security: 'pi pi-lock',
  notifications: 'pi pi-bell',
  locale: 'pi pi-globe',
  calendar: 'pi pi-calendar',
  signature: 'pi pi-pen-to-square',
  segments: 'pi pi-tag',
  telegram: 'pi pi-telegram',
  appearance: 'pi pi-sliders-h',
  quickActions: 'pi pi-bolt',
  system: 'pi pi-cog',
}

// Filter tabs: system tab is admin-only
const visibleTabs = computed(() =>
  tabs.filter((tab) => tab !== 'system' || isAdmin.value),
)

const pickerVisible = ref(false)

function tabIcon(tab: ProfileTab): string {
  return TAB_ICONS[tab] ?? 'pi pi-circle'
}
</script>

<style lang="scss" scoped>
.profile-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.profile-page__body {
  flex: 1;
  overflow: hidden;
}

// Tab sidebar
.profile-tabs {
  width: 200px;
  height: 100%;
  background-color: $surface-card;
  border-right: 1px solid $surface-200;
  padding: $space-2 0;
  overflow-y: auto;
}

.profile-tabs__item {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-2 $space-4;
  background: none;
  border: none;
  text-align: left;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-700;
  transition: background-color var(--app-transition-fast), color var(--app-transition-fast);

  &:hover {
    background-color: $surface-100;
    color: $surface-900;
  }

  &--active {
    background-color: $primary-100;
    color: $primary-900;
    font-weight: $font-weight-medium;
  }
}

.profile-tabs__icon {
  width: 16px;
  text-align: center;
  flex-shrink: 0;
}

// Content area
.profile-content {
  padding: $space-6;
  overflow-y: auto;
  height: 100%;
}

.profile-content__heading {
  font-size: $font-size-xl;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-6;
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
}

.profile-field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.profile-field__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-900;
}

// TOTP setup
.totp-qr-placeholder {
  padding: $space-4;
  background-color: $surface-100;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  display: inline-block;
  font-family: monospace;
}

.totp-qr-placeholder__text {
  font-size: $font-size-md;
  font-weight: $font-weight-bold;
  letter-spacing: 0.1em;
  color: $surface-900;
  margin: 0 0 $space-2;
}

.totp-backup-codes {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: $space-2;
}

.totp-backup-code {
  display: block;
  padding: $space-2 $space-3;
  background-color: $surface-100;
  border: 1px solid $surface-200;
  border-radius: $radius-sm;
  font-family: monospace;
  font-size: $font-size-sm;
  color: $surface-900;
  text-align: center;
}

.login-field__error {
  font-size: $font-size-xs;
  color: $red-700;
}

.text-muted {
  color: $surface-500;
}

// Telegram
.telegram-block {
  max-width: 480px;

  &__icon-row {
    display: flex;
    align-items: flex-start;
    gap: $space-3;
  }

  &__icon {
    font-size: 28px;
    color: var(--p-text-muted-color);
    margin-top: 2px;
    flex-shrink: 0;

    &--success {
      color: var(--p-green-500);
    }
  }

  &__status {
    font-size: $font-size-md;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin: 0 0 $space-1;
  }

  &__status--success {
    color: var(--p-green-600);
  }

  &__username {
    color: var(--p-primary-color);
    font-weight: $font-weight-normal;
    margin-left: $space-1;
  }

  &__desc {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
    margin: 0;
  }
}

// Appearance tab
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
  transition:
    border-color $transition-fast,
    background-color $transition-fast;

  &:hover {
    border-color: $primary;
    background: rgba($primary, 0.03);
  }

  &--active {
    border-color: $primary;
    background: rgba($primary, 0.06);
  }

  &__icon {
    font-size: 24px;
    color: $primary;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-900;
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
    font-size: 14px;
    color: $primary;
  }
}

.appearance-hint {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
  margin-top: $space-3;
  font-size: $font-size-sm;
  color: $surface-500;

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
}

.quick-actions-preview__icon {
  color: $primary;
  font-size: 1rem;
}

.quick-actions-preview__label {
  font-weight: $font-weight-medium;
  color: $surface-900;
}

// System reset trigger card
.system-reset-trigger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-4;
  padding: $space-4;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  background: $surface-card;

  &__info {
    display: flex;
    align-items: flex-start;
    gap: $space-3;
    flex: 1;
    min-width: 0;
  }

  &__icon {
    font-size: 1.25rem;
    color: var(--p-red-500);
    flex-shrink: 0;
    margin-top: 2px;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-900;
    margin: 0 0 $space-1;
  }

  &__desc {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
  }
}

// Theme SelectButton dark override
:global(.app-dark) :deep(.theme-selectbtn .p-togglebutton.p-togglebutton-checked) {
  background: var(--p-primary-color);
  color: var(--p-primary-contrast-color);
  border-color: var(--p-primary-color);
}

// Coming soon
.coming-soon-block {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  color: $surface-500;

  p {
    margin: 0;
    font-size: $font-size-md;
  }
}

.coming-soon-block__icon {
  font-size: 48px;
  opacity: 0.4;
}
</style>
