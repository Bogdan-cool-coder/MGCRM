<template>
  <div class="login-page d-flex flex-column">
    <div class="login-container h-100 w-100 px-3">
      <div class="login-row h-100 d-flex justify-content-center align-items-center">
        <div class="login-col">
          <LoginForm :error="error" :loading="loading" @submit="handleLogin" />

          <div v-if="hasIframeToken" class="iframe-reauth-block">
            <div class="iframe-reauth-divider">
              <span>{{ t('iframeReauthOr') }}</span>
            </div>
            <Button
              :disabled="loading"
              :label="loading ? t('common.loading') : t('iframeReauthButton')"
              class="iframe-reauth-btn"
              severity="secondary"
              outlined
              @click="handleIframeReauth"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import LoginForm from '@/components/forms/LoginForm'
import Button from 'primevue/button'
import { useLoginPage } from './composables/useLoginPage'

const { t, error, loading, hasIframeToken, handleLogin, handleIframeReauth } = useLoginPage()
</script>

<style lang="scss" scoped>
.login-page {
  background-color: $background-color;
  height: 100%;
}

.login-container {
  height: 100%;
}

.login-row {
  height: 100%;
}

.login-col {
  width: 100%;
  max-width: 400px;
}

.iframe-reauth-block {
  margin-top: 1rem;
}

.iframe-reauth-divider {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
  color: $surface-400;
  font-size: $font-size-sm;

  &::before,
  &::after {
    content: '';
    flex: 1;
    height: 1px;
    background-color: $surface-200;
  }
}

.iframe-reauth-btn {
  width: 100%;
}
</style>
