<template>
  <div class="profile-page">
    <PageHeader :title="t('profile.title')" icon="pi pi-user" />

    <div class="profile-page__body">
      <div class="row g-0 h-100">
        <!-- Tab sidebar -->
        <div class="col-auto">
          <nav class="profile-tabs">
            <button
              v-for="tab in tabs"
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

            <!-- Coming soon tabs -->
            <template v-if="['notifications', 'locale', 'theme', 'calendar', 'signature', 'segments'].includes(activeTab)">
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

              <!-- Theme switcher -->
              <div v-else-if="activeTab === 'theme'">
                <p class="mb-3">{{ t('profile.theme.title') }}</p>
                <div class="d-flex gap-3">
                  <Button
                    :label="t('profile.theme.light')"
                    :severity="!layoutStore.isDarkMode ? 'primary' : 'secondary'"
                    outlined
                    @click="layoutStore.setDarkMode(false)"
                  />
                  <Button
                    :label="t('profile.theme.dark')"
                    :severity="layoutStore.isDarkMode ? 'primary' : 'secondary'"
                    outlined
                    @click="layoutStore.setDarkMode(true)"
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
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import { useLayoutStore } from '@/stores/layout'
import { localeManager } from '@/application/locale'
import { getI18nLocale, type AvailableLocales } from '@/plugins/i18n'
import { useProfilePage, type ProfileTab } from './composables/useProfilePage'

const { t } = useI18n()
const layoutStore = useLayoutStore()

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
  theme: 'pi pi-palette',
  calendar: 'pi pi-calendar',
  signature: 'pi pi-pen-to-square',
  segments: 'pi pi-tag',
  telegram: 'pi pi-telegram',
}

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
