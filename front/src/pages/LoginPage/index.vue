<template>
  <div class="login-split">
    <!-- Left: brand panel -->
    <div class="login-brand" aria-hidden="true">
      <div class="login-brand__inner">
        <img
          src="/logo-light.svg"
          alt="MACRO Global"
          class="login-brand__logo"
        />
        <div class="login-brand__copy">
          <p class="login-brand__tagline">{{ t('auth.brand_panel.tagline') }}</p>
          <p class="login-brand__accent">{{ t('auth.brand_panel.accent') }}</p>
        </div>
      </div>
      <div class="login-brand__decor" aria-hidden="true" />
    </div>

    <!-- Right: form panel -->
    <div class="login-panel">
      <div class="login-panel__card">
        <!-- Logo (mobile only, inside form panel) -->
        <div class="login-panel__mobile-logo">
          <img src="/logo.svg" alt="MACRO Global" class="login-panel__mobile-logo-img" />
        </div>

        <!-- Reset notice -->
        <Message v-if="showResetNotice" severity="info" :closable="false" class="mb-3">
          {{ t('system.reset.relogin_notice') }}
        </Message>

        <!-- Step 1: Password form -->
        <template v-if="isAwaitingPassword">
          <h1 class="login-panel__title">{{ t('auth.login.title') }}</h1>

          <form class="login-form" @submit.prevent="handleLogin">
            <!-- Email -->
            <div class="login-field">
              <label class="login-field__label" for="login-email">
                {{ t('auth.login.email') }}
              </label>
              <InputText
                id="login-email"
                v-model="email"
                type="email"
                :placeholder="t('auth.login.email_placeholder')"
                :invalid="!!fieldErrors['email']"
                :disabled="isLoading"
                autocomplete="email"
                class="w-100"
              />
              <small v-if="fieldErrors['email']" class="login-field__error">
                {{ fieldErrors['email'] }}
              </small>
            </div>

            <!-- Password -->
            <div class="login-field">
              <label class="login-field__label" for="login-password">
                {{ t('auth.login.password') }}
              </label>
              <Password
                id="login-password"
                v-model="password"
                :placeholder="t('auth.login.password_placeholder')"
                :invalid="!!fieldErrors['password']"
                :disabled="isLoading"
                :feedback="false"
                toggle-mask
                autocomplete="current-password"
                class="w-100"
                input-class="w-100"
              />
              <small v-if="fieldErrors['password']" class="login-field__error">
                {{ fieldErrors['password'] }}
              </small>
            </div>

            <!-- General error -->
            <Message v-if="generalError" severity="error" :closable="false">
              {{ generalError }}
            </Message>

            <!-- Submit -->
            <Button
              type="submit"
              :label="isLoading ? t('auth.login.submitting') : t('auth.login.submit')"
              :loading="isLoading"
              :disabled="isLoading"
              class="w-100 login-form__submit"
            />
          </form>
        </template>

        <!-- Step 2: TOTP form -->
        <template v-if="isAwaitingTOTP">
          <h1 class="login-panel__title">{{ t('auth.two_factor.title') }}</h1>
          <p class="login-panel__subtitle">{{ t('auth.two_factor.subtitle') }}</p>

          <form class="login-form" @submit.prevent="handleTotpValidate">
            <!-- TOTP code -->
            <div v-if="!useBackupCode" class="login-field">
              <label class="login-field__label" for="totp-code">
                {{ t('auth.two_factor.code_label') }}
              </label>
              <InputText
                id="totp-code"
                v-model="totpCode"
                :placeholder="t('auth.two_factor.code_placeholder')"
                :invalid="!!fieldErrors['totp_code']"
                :disabled="isLoading"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                class="w-100"
              />
              <small v-if="fieldErrors['totp_code']" class="login-field__error">
                {{ fieldErrors['totp_code'] }}
              </small>
            </div>

            <!-- Backup code -->
            <div v-if="useBackupCode" class="login-field">
              <label class="login-field__label" for="backup-code">
                {{ t('auth.two_factor.backup_label') }}
              </label>
              <InputText
                id="backup-code"
                v-model="backupCode"
                :placeholder="t('auth.two_factor.backup_placeholder')"
                :invalid="!!fieldErrors['backup_code']"
                :disabled="isLoading"
                autocomplete="off"
                class="w-100"
              />
              <small v-if="fieldErrors['backup_code']" class="login-field__error">
                {{ fieldErrors['backup_code'] }}
              </small>
            </div>

            <!-- General error -->
            <Message v-if="generalError" severity="error" :closable="false">
              {{ generalError }}
            </Message>

            <!-- Submit -->
            <Button
              type="submit"
              :label="isLoading ? t('auth.two_factor.submitting') : t('auth.two_factor.submit')"
              :loading="isLoading"
              :disabled="isLoading"
              class="w-100 login-form__submit"
            />

            <!-- Toggle backup / back links -->
            <div class="login-form__links">
              <button
                type="button"
                class="login-link"
                :disabled="isLoading"
                @click="toggleBackupCode"
              >
                {{ useBackupCode ? t('auth.two_factor.use_totp') : t('auth.two_factor.use_backup') }}
              </button>
              <button
                type="button"
                class="login-link"
                :disabled="isLoading"
                @click="backToLogin"
              >
                {{ t('auth.two_factor.back_to_login') }}
              </button>
            </div>
          </form>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useLoginPage } from './composables/useLoginPage'

const { t } = useI18n()
const route = useRoute()

const showResetNotice = computed(() => route.query['reason'] === 'reset')

const {
  email,
  password,
  totpCode,
  backupCode,
  useBackupCode,
  fieldErrors,
  generalError,
  isLoading,
  isAwaitingPassword,
  isAwaitingTOTP,
  handleLogin,
  handleTotpValidate,
  backToLogin,
  toggleBackupCode,
} = useLoginPage()
</script>

<style lang="scss" scoped>
// ─── Split layout ─────────────────────────────────────────────────────────────
.login-split {
  display: flex;
  min-height: 100vh;
  width: 100%;
}

// ─── Brand panel (left, dark) ─────────────────────────────────────────────────
.login-brand {
  // Always dark, brand-invariant — does NOT change with light/dark theme.
  background-color: $brand-header-bg;
  flex: 0 0 44%;
  max-width: 44%;
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: $space-8;

  // Decorative radial glow in bottom-right corner
  &::after {
    content: '';
    position: absolute;
    inset: 0;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background:
      radial-gradient(ellipse 70% 60% at 90% 110%, rgba(255, 255, 255, 0.04) 0%, transparent 70%),
      radial-gradient(ellipse 50% 40% at 10% -10%, rgba(255, 255, 255, 0.03) 0%, transparent 60%); // brand header overlay — static decorative gradient on navy panel
    pointer-events: none;
  }
}

.login-brand__inner {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  gap: $space-8;
}

.login-brand__logo {
  // SVG has viewBox="0 0 281 88" — display at ~240px wide keeps it readable
  height: 56px;
  width: auto;
  object-fit: contain;
  // Logo is white on dark, filter not needed since we have a dedicated light variant
}

.login-brand__copy {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.login-brand__tagline {
  margin: 0;
  font-size: $font-size-2xl;
  font-weight: $font-weight-semibold;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.95); // brand header overlay — static text on navy panel
  line-height: $line-height-tight;
  letter-spacing: -0.01em;
}

.login-brand__accent {
  margin: 0;
  font-size: $font-size-2xl;
  font-weight: $font-weight-semibold;
  // Subtle lighter accent vs. the primary tagline line
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.55); // brand header overlay — static text on navy panel
  line-height: $line-height-tight;
  letter-spacing: -0.01em;
}

.login-brand__decor {
  // Subtle large circle in the corner for visual depth
  position: absolute;
  bottom: -120px;
  right: -120px;
  width: 380px;
  height: 380px;
  border-radius: $radius-circle;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  border: 1px solid rgba(255, 255, 255, 0.06); // brand header overlay — static decorative border on navy panel
  pointer-events: none;

  &::before {
    content: '';
    position: absolute;
    inset: 40px;
    border-radius: $radius-circle;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    border: 1px solid rgba(255, 255, 255, 0.04); // brand header overlay — static decorative border on navy panel
  }
}

// ─── Form panel (right, adaptive) ────────────────────────────────────────────
.login-panel {
  flex: 1 1 56%;
  background-color: $surface-100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: $space-8 $space-4;

  :global(.app-dark) & {
    background-color: var(--p-surface-900);
  }
}

.login-panel__card {
  width: 100%;
  max-width: 420px;
  background-color: $surface-card;
  border-radius: $radius-lg;
  box-shadow: var(--app-shadow-lg);
  padding: $space-8;
  display: flex;
  flex-direction: column;
  gap: $space-5;

  :global(.app-dark) & {
    background-color: var(--p-surface-800);
    border: 1px solid var(--p-surface-700);
    box-shadow: $shadow-elevated;
  }
}

// Mobile-only logo (hidden on desktop where brand panel is visible)
.login-panel__mobile-logo {
  display: none;
  justify-content: center;
  margin-bottom: $space-2;
}

.login-panel__mobile-logo-img {
  height: 36px;
  width: auto;
}

.login-panel__title {
  font-size: $font-size-2xl;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: $line-height-tight;
}

.login-panel__subtitle {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
}

// ─── Form ─────────────────────────────────────────────────────────────────────
.login-form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.login-form__submit {
  margin-top: $space-2;
}

.login-form__links {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
}

// ─── Field ────────────────────────────────────────────────────────────────────
.login-field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.login-field__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-900;
}

.login-field__error {
  font-size: $font-size-xs;
  color: $red-700;
}

// ─── Link-style button ────────────────────────────────────────────────────────
.login-link {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $primary-color;
  text-decoration: underline;
  transition: color 0.15s ease;

  &:hover {
    color: $primary-600;
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
}

// ─── Responsive ───────────────────────────────────────────────────────────────
// md breakpoint: 768px (Bootstrap grid align)
@media (max-width: 767.98px) {
  .login-split {
    flex-direction: column;
  }

  // Brand panel becomes a compact top banner
  .login-brand {
    flex: none;
    max-width: 100%;
    width: 100%;
    padding: $space-5 $space-4;
    min-height: 120px;

    // Simplify decorative ring on mobile
    &::after {
      display: none;
    }
  }

  .login-brand__logo {
    height: 36px;
  }

  .login-brand__inner {
    flex-direction: row;
    align-items: center;
    gap: $space-4;
  }

  .login-brand__copy {
    gap: $space-1;
  }

  .login-brand__tagline,
  .login-brand__accent {
    font-size: $font-size-base;
  }

  .login-brand__decor {
    display: none;
  }

  // Form panel: full width, no separate logo needed (brand panel visible above)
  .login-panel {
    flex: none;
    width: 100%;
    padding: $space-6 $space-4 $space-8;
    // Align card to top so it doesn't float oddly on short screens
    align-items: flex-start;
  }

  .login-panel__card {
    box-shadow: none;
    border-radius: $radius-md;
    padding: $space-6 $space-5;
  }
}

// xs breakpoint: very narrow — below 480px, hide brand panel entirely
// and show inline mobile logo instead
@media (max-width: 479.98px) {
  .login-brand {
    display: none;
  }

  .login-panel__mobile-logo {
    display: flex;
  }

  .login-panel {
    align-items: center;
    min-height: 100vh;
    background-color: $surface-100;
  }

  .login-panel__card {
    box-shadow: var(--app-shadow-lg);
  }
}
</style>
