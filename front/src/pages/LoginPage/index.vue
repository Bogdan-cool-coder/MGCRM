<template>
  <div class="login-page d-flex flex-column align-items-center justify-content-center">
    <div class="login-card">
      <!-- Logo -->
      <div class="login-card__logo">
        <img src="/logo.svg" alt="MACRO Global CRM" height="36" />
      </div>

      <!-- Step 1: Password form -->
      <template v-if="isAwaitingPassword">
        <h1 class="login-card__title">{{ t('auth.login.title') }}</h1>

        <form class="login-card__form" @submit.prevent="handleLogin">
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
            class="w-100"
          />
        </form>
      </template>

      <!-- Step 2: TOTP form -->
      <template v-if="isAwaitingTOTP">
        <h1 class="login-card__title">{{ t('auth.two_factor.title') }}</h1>
        <p class="login-card__subtitle">{{ t('auth.two_factor.subtitle') }}</p>

        <form class="login-card__form" @submit.prevent="handleTotpValidate">
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
            class="w-100"
          />

          <!-- Toggle backup / back links -->
          <div class="login-card__links">
            <button type="button" class="login-link" :disabled="isLoading" @click="toggleBackupCode">
              {{ useBackupCode ? t('auth.two_factor.use_totp') : t('auth.two_factor.use_backup') }}
            </button>
            <button type="button" class="login-link" :disabled="isLoading" @click="backToLogin">
              {{ t('auth.two_factor.back_to_login') }}
            </button>
          </div>
        </form>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useLoginPage } from './composables/useLoginPage'

const { t } = useI18n()
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
.login-page {
  background-color: $surface-100;
  min-height: 100vh;
  width: 100%;
  padding: $space-4;
}

.login-card {
  width: 100%;
  max-width: 400px;
  background-color: $surface-card;
  border-radius: $radius-lg;
  box-shadow: var(--app-shadow-lg);
  padding: $space-8;
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.login-card__logo {
  display: flex;
  justify-content: center;
  margin-bottom: $space-2;

  img {
    height: 40px;
  }
}

.login-card__title {
  font-size: $font-size-2xl;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  text-align: center;
  line-height: $line-height-tight;
}

.login-card__subtitle {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  text-align: center;
}

.login-card__form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.login-card__links {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
}

// Form field
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

// Link button
.login-link {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $primary-color;
  text-decoration: underline;

  &:hover {
    color: $primary-600;
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
}
</style>
