<template>
  <div class="section-security">
    <div class="profile-section">
      <h3 class="profile-section__title">{{ t('profile.security.totp_section') }}</h3>

      <!-- 2FA enabled -->
      <div v-if="user?.totp_enabled && !isTotpSetupStarted && !showBackupCodes">
        <Tag severity="success" :value="t('profile.security.totp_enabled')" class="mb-3" />

        <!-- Manage actions -->
        <div v-if="!totpManageAction" class="d-flex gap-2 flex-wrap">
          <Button
            :label="t('profile.security.regenerate_codes')"
            icon="pi pi-refresh"
            severity="secondary"
            outlined
            size="small"
            @click="startTotpManage('regenerate')"
          />
          <Button
            :label="t('profile.security.disable_totp')"
            icon="pi pi-shield"
            severity="danger"
            outlined
            size="small"
            @click="startTotpManage('disable')"
          />
        </div>

        <!-- Confirm with TOTP code -->
        <div v-else class="totp-manage-confirm mt-3">
          <p class="mb-2">
            {{
              totpManageAction === 'disable'
                ? t('profile.security.disable_confirm_hint')
                : t('profile.security.regenerate_confirm_hint')
            }}
          </p>
          <div class="d-flex gap-3 align-items-start">
            <div style="max-width: 200px">
              <InputText
                v-model="totpManageCode"
                placeholder="000000"
                inputmode="numeric"
                maxlength="6"
                :invalid="!!totpManageError"
                class="w-100"
              />
              <small v-if="totpManageError" class="login-field__error">{{ totpManageError }}</small>
            </div>
            <Button
              :label="t('common.confirm')"
              :severity="totpManageAction === 'disable' ? 'danger' : undefined"
              :loading="isManagingTotp"
              @click="totpManageAction === 'disable' ? confirmDisableTotp() : confirmRegenerateCodes()"
            />
            <Button
              :label="t('common.cancel')"
              severity="secondary"
              outlined
              @click="cancelTotpManage"
            />
          </div>
        </div>
      </div>

      <!-- 2FA not enabled -->
      <div v-if="!user?.totp_enabled && !isTotpSetupStarted && !showBackupCodes">
        <Tag severity="secondary" :value="t('profile.security.totp_disabled')" class="mb-3" />
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

        <!-- QR code block: always white background so camera can read it in both themes -->
        <div class="totp-qr-block mb-4">
          <div class="totp-qr-canvas-wrapper">
            <canvas ref="qrCanvas" class="totp-qr-canvas" />
          </div>

          <!-- Fallback: manual secret entry -->
          <div class="totp-qr-manual mt-3">
            <p class="totp-qr-manual__label">{{ t('profile.security.totp_manual_key') }}</p>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <code class="totp-qr-secret">{{ totpSetupSecret }}</code>
              <Button
                :label="keyCopied ? t('profile.security.totp_key_copied') : t('profile.security.totp_copy_key')"
                :icon="keyCopied ? 'pi pi-check' : 'pi pi-copy'"
                severity="secondary"
                outlined
                size="small"
                @click="copySecret"
              />
            </div>
          </div>
        </div>

        <p class="mb-3">{{ t('profile.security.totp_enter_code') }}</p>

        <div class="d-flex gap-3 align-items-start">
          <div style="max-width: 200px">
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

    <!-- Смена пароля — отделена от 2FA-блока горизонтальным разделителем -->
    <hr class="security-divider" />
    <div class="profile-section">
      <h3 class="profile-section__title">{{ t('settings.security.password.sectionTitle') }}</h3>
      <ChangePasswordForm />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import QRCode from 'qrcode'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useProfilePage } from '@/pages/ProfilePage/composables/useProfilePage'
import ChangePasswordForm from './profile/ChangePasswordForm.vue'

const { t } = useI18n()

// Receives the full profile composable return so refs remain mutable
const props = defineProps<{
  profile: ReturnType<typeof useProfilePage>
}>()

// Convenience aliases
const user = props.profile.user
const totpSetupSecret = props.profile.totpSetupSecret
const totpSetupUri = props.profile.totpSetupUri
const totpSetupCode = props.profile.totpSetupCode
const totpSetupError = props.profile.totpSetupError
const backupCodes = props.profile.backupCodes
const showBackupCodes = props.profile.showBackupCodes
const isSettingUpTotp = props.profile.isSettingUpTotp
const isTotpSetupStarted = props.profile.isTotpSetupStarted
const totpManageAction = props.profile.totpManageAction
const totpManageCode = props.profile.totpManageCode
const totpManageError = props.profile.totpManageError
const isManagingTotp = props.profile.isManagingTotp
const startTotpSetup = props.profile.startTotpSetup
const verifyTotpSetup = props.profile.verifyTotpSetup
const cancelTotpSetup = props.profile.cancelTotpSetup
const startTotpManage = props.profile.startTotpManage
const cancelTotpManage = props.profile.cancelTotpManage
const confirmDisableTotp = props.profile.confirmDisableTotp
const confirmRegenerateCodes = props.profile.confirmRegenerateCodes

// QR canvas ref
const qrCanvas = ref<HTMLCanvasElement | null>(null)

// Key copied state for copy button feedback
const keyCopied = ref(false)
let keyCopiedTimer: ReturnType<typeof setTimeout> | null = null

async function renderQr(uri: string): Promise<void> {
  await nextTick()
  if (!qrCanvas.value || !uri) return
  await QRCode.toCanvas(qrCanvas.value, uri, {
    width: 200,
    margin: 2,
    color: {
      dark: '#000000',  // dark modules always black
      light: '#ffffff', // white background always — camera requires this
    },
  })
}

// Watch: when setup starts and URI becomes available, render QR
watch(
  () => totpSetupUri.value,
  (uri) => {
    if (uri) {
      renderQr(uri)
    }
  },
  { immediate: true },
)

// Also re-render when isTotpSetupStarted flips to true (canvas mounts)
watch(
  () => isTotpSetupStarted.value,
  (started) => {
    if (started && totpSetupUri.value) {
      renderQr(totpSetupUri.value)
    }
  },
)

async function copySecret(): Promise<void> {
  const secret = totpSetupSecret.value
  if (!secret) return
  try {
    await navigator.clipboard.writeText(secret)
    keyCopied.value = true
    if (keyCopiedTimer !== null) clearTimeout(keyCopiedTimer)
    keyCopiedTimer = setTimeout(() => {
      keyCopied.value = false
    }, 2000)
  } catch {
    // silently ignore — clipboard may be blocked in some contexts
  }
}
</script>

<style lang="scss" scoped>
.section-security {
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

.security-divider {
  border: none;
  border-top: 1px solid $surface-200;
  margin: $space-6 0;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}

.text-muted {
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.login-field__error {
  font-size: $font-size-xs;
  color: $red-700;

  .app-dark & {
    color: var(--p-red-400);
  }
}

.totp-manage-confirm {
  padding: $space-4;
  background: $surface-50;
  border: 1px solid $surface-200;
  border-radius: $radius-md;

  .app-dark & {
    background: var(--p-surface-50);
    border-color: var(--p-surface-200);
  }
}

// QR container: neutral surface card wrapper
.totp-qr-block {
  display: inline-block;
  padding: $space-4;
  background-color: $surface-50;
  border: 1px solid $surface-200;
  border-radius: $radius-md;

  .app-dark & {
    background-color: var(--p-surface-50);
    border-color: var(--p-surface-200);
  }
}

// The canvas wrapper provides a mandatory white background so the QR code
// remains scannable regardless of app theme. Camera sensors require dark
// modules on a white (not theme-surface) background — this is a technical
// constraint, not a design choice, hence the literal white value.
.totp-qr-canvas-wrapper {
  display: inline-block;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: #ffffff;
  padding: $space-2;
  border-radius: $radius-sm;
  line-height: 0; // remove inline gap below canvas
}

.totp-qr-canvas {
  display: block;
}

// Manual fallback section
.totp-qr-manual {
  &__label {
    font-size: $font-size-sm;
    color: $surface-600;
    margin: 0 0 $space-2;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }
}

.totp-qr-secret {
  display: inline-block;
  padding: $space-1 $space-3;
  background-color: $surface-100;
  border: 1px solid $surface-200;
  border-radius: $radius-sm;
  font-family: $font-family-mono;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  letter-spacing: 0.08em;
  color: $surface-900;

  .app-dark & {
    background-color: var(--p-surface-100);
    border-color: var(--p-surface-200);
    color: var(--p-surface-900);
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

  .app-dark & {
    background-color: var(--p-surface-100);
    border-color: var(--p-surface-200);
    color: var(--p-surface-50);
  }
}
</style>
