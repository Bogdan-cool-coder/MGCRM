<template>
  <div class="profile-page">
    <!-- ─── HUB MODE: card grid ─────────────────────────────────────────────── -->
    <template v-if="activeTab === 'hub'">
      <PageHeader
        :title="t('account.hubTitle')"
        icon="pi pi-user"
        :subtitle="t('account.hubSubtitle')"
      />

      <div class="profile-page__body profile-page__body--hub">
        <!-- Account group -->
        <div class="hub-group">
          <h2 class="hub-group__label">{{ t('account.groupAccount') }}</h2>
          <div class="row g-3">
            <div
              v-for="section in accountSections"
              :key="section.key"
              class="col-md-6 col-lg-4"
            >
              <button
                v-if="!section.disabled"
                class="settings-card settings-card--btn"
                type="button"
                @click="setTab(section.tab as ProfileTab)"
              >
                <div class="settings-card__icon-wrap">
                  <i :class="['settings-card__icon', section.icon]" />
                </div>
                <div class="settings-card__body">
                  <h3 class="settings-card__title">{{ t(section.titleKey) }}</h3>
                  <p v-if="section.descKey" class="settings-card__desc">{{ t(section.descKey) }}</p>
                </div>
                <i class="pi pi-chevron-right settings-card__arrow" />
              </button>

              <div v-else class="settings-card settings-card--disabled">
                <div class="settings-card__icon-wrap">
                  <i :class="['settings-card__icon', section.icon]" />
                </div>
                <div class="settings-card__body">
                  <h3 class="settings-card__title">
                    {{ t(section.titleKey) }}
                    <Tag
                      :value="t('common.coming_soon')"
                      severity="secondary"
                      class="settings-card__soon-tag"
                    />
                  </h3>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- System group (admin/director only) -->
        <div v-if="isAdminOrDirector" class="hub-group">
          <h2 class="hub-group__label">{{ t('account.groupSystem') }}</h2>
          <div class="row g-3">
            <div
              v-for="section in systemSections"
              :key="section.key"
              class="col-md-6 col-lg-4"
            >
              <router-link :to="section.route" class="settings-card" tabindex="0">
                <div class="settings-card__icon-wrap">
                  <i :class="['settings-card__icon', section.icon]" />
                </div>
                <div class="settings-card__body">
                  <h3 class="settings-card__title">{{ t(section.titleKey) }}</h3>
                  <p v-if="section.descKey" class="settings-card__desc">{{ t(section.descKey) }}</p>
                </div>
                <i class="pi pi-chevron-right settings-card__arrow" />
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ─── SECTION MODE: single tab content with back button ──────────────── -->
    <template v-else>
      <PageHeader :title="sectionTitle" :icon="sectionIcon">
        <template #actions>
          <Button
            icon="pi pi-arrow-left"
            :label="t('common.back')"
            severity="secondary"
            text
            class="profile-back-btn"
            @click="setTab('hub')"
          />
        </template>
      </PageHeader>

      <div class="profile-page__body">
        <div class="profile-content">
          <!-- Profile tab -->
          <template v-if="activeTab === 'profile'">
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

                <div class="totp-qr-placeholder mb-4">
                  <p class="totp-qr-placeholder__text">{{ totpSetupSecret }}</p>
                  <p class="text-muted totp-uri-text" style="word-break: break-all;">{{ totpSetupUri }}</p>
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
            <div class="profile-section">
              <h3 class="profile-section__title">{{ t('quickActions.sectionTitle') }}</h3>
              <p class="text-muted mb-3">{{ t('quickActions.sectionHint') }}</p>

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

          <!-- Locale tab -->
          <template v-if="activeTab === 'locale'">
            <div class="profile-section">
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
          </template>

          <!-- Coming soon tabs (notifications, calendar, signature, segments) -->
          <template v-if="['notifications', 'calendar', 'signature', 'segments'].includes(activeTab as string)">
            <div class="coming-soon-block">
              <i class="pi pi-clock coming-soon-block__icon" />
              <p>{{ t('profile.coming_soon') }}</p>
            </div>
          </template>
        </div>
      </div>
    </template>
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

// ─── Role guards ──────────────────────────────────────────────────────────────
const isAdmin = computed(() => userStore.getUserRole === 'admin')
const isAdminOrDirector = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

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

const pickerVisible = ref(false)

// ─── Hub: account sections ────────────────────────────────────────────────────
interface AccountSection {
  key: string
  tab: string
  icon: string
  titleKey: string
  descKey?: string
  disabled?: boolean
}

const accountSections: AccountSection[] = [
  { key: 'profile',      tab: 'profile',      icon: 'pi pi-user',           titleKey: 'account.sections.profile.title',      descKey: 'account.sections.profile.desc' },
  { key: 'security',     tab: 'security',     icon: 'pi pi-lock',           titleKey: 'account.sections.security.title',     descKey: 'account.sections.security.desc' },
  { key: 'appearance',   tab: 'appearance',   icon: 'pi pi-sliders-h',      titleKey: 'account.sections.appearance.title',   descKey: 'account.sections.appearance.desc' },
  { key: 'quickActions', tab: 'quickActions', icon: 'pi pi-bolt',           titleKey: 'account.sections.quickActions.title', descKey: 'account.sections.quickActions.desc' },
  { key: 'telegram',     tab: 'telegram',     icon: 'pi pi-telegram',       titleKey: 'account.sections.telegram.title',     descKey: 'account.sections.telegram.desc' },
  { key: 'locale',       tab: 'locale',       icon: 'pi pi-globe',          titleKey: 'account.sections.locale.title',       descKey: 'account.sections.locale.desc' },
  { key: 'notifications', tab: 'notifications', icon: 'pi pi-bell',         titleKey: 'account.sections.notifications.title', disabled: true },
  { key: 'calendar',     tab: 'calendar',     icon: 'pi pi-calendar',       titleKey: 'account.sections.calendar.title',     disabled: true },
  { key: 'signature',    tab: 'signature',    icon: 'pi pi-pen-to-square',  titleKey: 'account.sections.signature.title',    disabled: true },
  { key: 'segments',     tab: 'segments',     icon: 'pi pi-tag',            titleKey: 'account.sections.segments.title',     disabled: true },
]

// ─── Hub: system sections ─────────────────────────────────────────────────────
interface SystemSection {
  key: string
  route: string
  icon: string
  titleKey: string
  descKey?: string
}

const systemSections: SystemSection[] = [
  { key: 'users',                route: '/admin/users',                  icon: 'pi pi-users',       titleKey: 'settings.sections.users.title',               descKey: 'settings.sections.users.desc' },
  { key: 'pipeline',             route: '/settings/pipeline',            icon: 'pi pi-sliders-h',   titleKey: 'settings.sections.pipeline.title',            descKey: 'settings.sections.pipeline.desc' },
  { key: 'catalog',              route: '/admin/products',               icon: 'pi pi-box',         titleKey: 'settings.sections.catalog.title',             descKey: 'settings.sections.catalog.desc' },
  { key: 'exchangeRates',        route: '/admin/exchange-rates',         icon: 'pi pi-dollar',      titleKey: 'settings.sections.exchangeRates.title',       descKey: 'settings.sections.exchangeRates.desc' },
  { key: 'templates',            route: '/admin/templates',              icon: 'pi pi-file-edit',   titleKey: 'settings.sections.templates.title',           descKey: 'settings.sections.templates.desc' },
  { key: 'templateVariables',    route: '/admin/template-variables',     icon: 'pi pi-list',        titleKey: 'settings.sections.templateVariables.title',   descKey: 'settings.sections.templateVariables.desc' },
  { key: 'approvalRoutes',       route: '/admin/approval-routes',        icon: 'pi pi-sitemap',     titleKey: 'settings.sections.approvalRoutes.title',      descKey: 'settings.sections.approvalRoutes.desc' },
  { key: 'messageTemplates',     route: '/admin/message-templates',      icon: 'pi pi-envelope',    titleKey: 'settings.sections.messageTemplates.title',    descKey: 'settings.sections.messageTemplates.desc' },
  { key: 'automationRuns',       route: '/admin/automation-runs',        icon: 'pi pi-clock',       titleKey: 'settings.sections.automationRuns.title',      descKey: 'settings.sections.automationRuns.desc' },
  { key: 'acquisitionChannels',  route: '/admin/acquisition-channels',   icon: 'pi pi-filter',      titleKey: 'settings.sections.acquisitionChannels.title', descKey: 'settings.sections.acquisitionChannels.desc' },
  { key: 'disconnectReasons',    route: '/admin/disconnect-reasons',     icon: 'pi pi-ban',         titleKey: 'settings.sections.disconnectReasons.title',   descKey: 'settings.sections.disconnectReasons.desc' },
]

// ─── Section mode: header title + icon ───────────────────────────────────────
const TAB_ICONS: Record<ProfileTab | 'hub', string> = {
  hub:           'pi pi-th-large',
  profile:       'pi pi-user',
  security:      'pi pi-lock',
  notifications: 'pi pi-bell',
  locale:        'pi pi-globe',
  calendar:      'pi pi-calendar',
  signature:     'pi pi-pen-to-square',
  segments:      'pi pi-tag',
  telegram:      'pi pi-telegram',
  appearance:    'pi pi-sliders-h',
  quickActions:  'pi pi-bolt',
  system:        'pi pi-cog',
}

const TAB_TITLE_KEYS: Record<ProfileTab | 'hub', string> = {
  hub:           'account.hubTitle',
  profile:       'account.sections.profile.title',
  security:      'account.sections.security.title',
  notifications: 'account.sections.notifications.title',
  locale:        'account.sections.locale.title',
  calendar:      'account.sections.calendar.title',
  signature:     'account.sections.signature.title',
  segments:      'account.sections.segments.title',
  telegram:      'account.sections.telegram.title',
  appearance:    'account.sections.appearance.title',
  quickActions:  'account.sections.quickActions.title',
  system:        'system.reset.tab_title',
}

const sectionTitle = computed(() => t(TAB_TITLE_KEYS[activeTab.value as ProfileTab | 'hub'] ?? 'account.hubTitle'))
const sectionIcon = computed(() => TAB_ICONS[activeTab.value as ProfileTab | 'hub'] ?? 'pi pi-user')
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
  overflow-y: auto;

  &--hub {
    padding: $space-6;
  }
}

// ─── Back button ─────────────────────────────────────────────────────────────
.profile-back-btn {
  flex-shrink: 0;
}

// ─── Hub groups ───────────────────────────────────────────────────────────────
.hub-group {
  margin-bottom: $space-8;

  &:last-child {
    margin-bottom: 0;
  }
}

.hub-group__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin: 0 0 $space-3;
  padding-bottom: $space-2;
  border-bottom: 1px solid $surface-200;

  :global(.app-dark) & {
    color: var(--p-surface-400);
    border-bottom-color: var(--p-surface-700);
  }
}

// ─── Settings card ────────────────────────────────────────────────────────────
.settings-card {
  display: flex;
  align-items: center;
  gap: $space-4;
  padding: $space-4 $space-5;
  background-color: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  text-decoration: none;
  color: inherit;
  transition:
    border-color var(--app-transition-fast),
    box-shadow var(--app-transition-fast),
    background-color var(--app-transition-fast);
  cursor: pointer;
  height: 100%;
  min-height: 80px;

  :global(.app-dark) & {
    background-color: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }

  &:hover:not(.settings-card--disabled) {
    border-color: var(--p-primary-300);
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: $shadow-card-hover;
  }

  &:focus-visible {
    outline: 2px solid var(--p-primary-500);
    outline-offset: 2px;
  }

  // Button variant reset
  &--btn {
    width: 100%;
    text-align: left;
    background-color: $surface-card;
    border: 1px solid $surface-200;

    :global(.app-dark) & {
      background-color: var(--p-surface-800);
      border-color: var(--p-surface-700);
    }
  }

  // Disabled (coming soon)
  &--disabled {
    opacity: 0.55;
    cursor: not-allowed;
    pointer-events: none;
  }
}

.settings-card__icon-wrap {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  border-radius: $radius-md;
  background-color: var(--p-primary-50);
  display: flex;
  align-items: center;
  justify-content: center;

  :global(.app-dark) & {
    background-color: rgba($primary-900, 0.3);
  }
}

.settings-card__icon {
  font-size: $font-size-xl;
  color: var(--p-primary-600);

  :global(.app-dark) & {
    color: var(--p-primary-300);
  }
}

.settings-card__body {
  flex: 1;
  min-width: 0;
}

.settings-card__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: flex;
  align-items: center;
  gap: $space-2;

  :global(.app-dark) & {
    color: var(--p-surface-50);
  }
}

.settings-card__desc {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  line-height: $line-height-normal;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;

  :global(.app-dark) & {
    color: var(--p-surface-400);
  }
}

.settings-card__arrow {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
  transition: color var(--app-transition-fast), transform var(--app-transition-fast);

  .settings-card:hover & {
    color: var(--p-primary-500);
    transform: translateX(2px);
  }
}

.settings-card__soon-tag {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

// ─── Content area ─────────────────────────────────────────────────────────────
.profile-content {
  padding: $space-6;
  overflow-y: auto;
  height: 100%;
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

  :global(.app-dark) & {
    color: var(--p-surface-50);
    border-bottom-color: var(--p-surface-700);
  }
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

  :global(.app-dark) & {
    color: var(--p-surface-200);
  }
}

// TOTP setup
.totp-qr-placeholder {
  padding: $space-4;
  background-color: $surface-100;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  display: inline-block;
  font-family: $font-family-mono;

  :global(.app-dark) & {
    background-color: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }
}

.totp-uri-text {
  font-size: $font-size-xs;
}

.totp-qr-placeholder__text {
  font-size: $font-size-md;
  font-weight: $font-weight-bold;
  letter-spacing: 0.1em;
  color: $surface-900;
  margin: 0 0 $space-2;

  :global(.app-dark) & {
    color: var(--p-surface-50);
  }
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
  font-family: $font-family-mono;
  font-size: $font-size-sm;
  color: $surface-900;
  text-align: center;

  :global(.app-dark) & {
    background-color: var(--p-surface-800);
    border-color: var(--p-surface-700);
    color: var(--p-surface-50);
  }
}

.login-field__error {
  font-size: $font-size-xs;
  color: $red-700;
}

.text-muted {
  color: $surface-500;

  :global(.app-dark) & {
    color: var(--p-surface-400);
  }
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
    font-size: $font-size-3xl;
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

  :global(.app-dark) & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-700);
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

    :global(.app-dark) & {
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

  :global(.app-dark) & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }
}

.quick-actions-preview__icon {
  color: $primary;
  font-size: $font-size-md;
}

.quick-actions-preview__label {
  font-weight: $font-weight-medium;
  color: $surface-900;

  :global(.app-dark) & {
    color: var(--p-surface-50);
  }
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

  :global(.app-dark) & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }

  &__info {
    display: flex;
    align-items: flex-start;
    gap: $space-3;
    flex: 1;
    min-width: 0;
  }

  &__icon {
    font-size: $font-size-xl;
    color: var(--p-red-500);
    flex-shrink: 0;
    margin-top: 2px;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-900;
    margin: 0 0 $space-1;

    :global(.app-dark) & {
      color: var(--p-surface-50);
    }
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
  font-size: $font-size-icon-2xl;
  opacity: 0.4;
}
</style>
