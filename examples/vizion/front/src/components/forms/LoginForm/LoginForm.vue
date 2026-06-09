<template>
  <div class="login-form-card">
    <div v-if="error" class="login-error" role="alert">
      <i class="pi pi-exclamation-circle"></i>
      <span>{{ error }}</span>
    </div>

    <div class="form-group">
      <label for="email" class="form-label">{{ t('email') }}</label>
      <InputText
        id="email"
        v-model="email"
        class="form-input"
        :placeholder="t('email')"
        type="email"
        :disabled="loading"
      />
    </div>

    <div class="form-group">
      <label for="password" class="form-label">{{ t('password') }}</label>
      <Password
        id="password"
        v-model="password"
        toggleMask
        class="form-input"
        :placeholder="t('password')"
        :feedback="false"
        :disabled="loading"
      />
    </div>

    <Button
      :disabled="loading"
      :label="loading ? t('common.loading') : t('loginButton')"
      class="login-btn"
      @click="$emit('submit', { email, password })"
    />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Emits {
  (e: 'submit', credentials: { email: string; password: string }): void
}

defineEmits<Emits>()

const email = ref('')
const password = ref('')

defineProps<{
  error: string
  loading: boolean
}>()
</script>

<style lang="scss" scoped>
.login-form-card {
  background: $surface-0;
  border-radius: $card-border-radius;
  box-shadow: $shadow-md;
  padding: $space-8;
  width: 100%;
}

.login-error {
  padding: $space-3;
  margin-bottom: $space-4;
  background-color: $red-50;
  border: 1px solid $red-200;
  border-radius: $border-radius;
  color: $red-700;
  font-size: $font-size-sm;
  display: flex;
  align-items: center;
  gap: $space-2;

  i {
    font-size: $font-size-md;
  }
}

.form-group {
  margin-bottom: $space-4;

  .form-label {
    display: block;
    margin-bottom: $space-2;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
  }

  .form-input {
    width: 100%;
  }

  .form-input :deep(.p-password),
  .form-input :deep(.p-inputtext) {
    width: 100%;
  }
}

.login-btn {
  width: 100%;
  margin-top: $space-2;
}
</style>
