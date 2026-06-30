<template>
  <div class="change-password-form">
    <!-- Текущий пароль -->
    <div class="change-password-form__field">
      <label class="profile-field__label">
        {{ t('settings.security.password.currentLabel') }}
        <span class="change-password-form__required">*</span>
      </label>
      <Password
        v-model="currentPassword"
        :feedback="false"
        :toggle-mask="true"
        class="w-100"
        :invalid="!!currentPasswordApiError"
        fluid
        @input="currentPasswordApiError = ''"
      />
      <small v-if="currentPasswordApiError" class="change-password-form__error">
        {{ currentPasswordApiError }}
      </small>
    </div>

    <!-- Новый пароль -->
    <div class="change-password-form__field">
      <label class="profile-field__label">
        {{ t('settings.security.password.newLabel') }}
        <span class="change-password-form__required">*</span>
      </label>
      <Password
        v-model="newPassword"
        :feedback="false"
        :toggle-mask="true"
        class="w-100"
        :invalid="!!newPasswordError && !!newPassword"
        fluid
      />
      <small v-if="newPasswordError && newPassword" class="change-password-form__error">
        {{ newPasswordError }}
      </small>
      <small v-else class="change-password-form__hint">
        {{ t('settings.security.password.hint') }}
      </small>
    </div>

    <!-- Повтор нового пароля -->
    <div class="change-password-form__field">
      <label class="profile-field__label">
        {{ t('settings.security.password.confirmLabel') }}
        <span class="change-password-form__required">*</span>
      </label>
      <Password
        v-model="confirmPassword"
        :feedback="false"
        :toggle-mask="true"
        class="w-100"
        :invalid="!!confirmPasswordError && !!confirmPassword"
        fluid
      />
      <small v-if="confirmPasswordError && confirmPassword" class="change-password-form__error">
        {{ confirmPasswordError }}
      </small>
    </div>

    <div class="change-password-form__actions">
      <Button
        icon="pi pi-key"
        :label="t('settings.security.password.submitBtn')"
        :loading="changePasswordMutation.isPending.value"
        :disabled="!isFormValid"
        @click="changePassword"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Password from 'primevue/password'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { profileApi } from '@/api/profile'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage, getValidationErrors } from '@/utils/errors'

const { t } = useI18n()
const toast = useToast()

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
/** Серверная ошибка «неверный текущий пароль» (422 current_password) */
const currentPasswordApiError = ref('')

// Клиентская валидация
const newPasswordError = computed(() => {
  if (!newPassword.value) return ''
  if (newPassword.value.length < 8) return t('settings.security.password.tooShort')
  return ''
})

const confirmPasswordError = computed(() => {
  if (!confirmPassword.value) return ''
  if (confirmPassword.value !== newPassword.value) return t('settings.security.password.mismatch')
  return ''
})

const isFormValid = computed(
  () =>
    !!currentPassword.value &&
    !!newPassword.value &&
    newPassword.value.length >= 8 &&
    newPassword.value === confirmPassword.value,
)

const changePasswordMutation = useMutation<void>()

async function changePassword() {
  if (!isFormValid.value) return

  await changePasswordMutation.run(
    async () => {
      await profileApi.changePassword({
        current_password: currentPassword.value,
        password: newPassword.value,
        password_confirmation: confirmPassword.value,
      })
    },
    {
      onSuccess: () => {
        toast.add({
          severity: 'success',
          summary: t('settings.security.password.successToast'),
          life: 4000,
        })
        currentPassword.value = ''
        newPassword.value = ''
        confirmPassword.value = ''
        currentPasswordApiError.value = ''
      },
      onError: (error: unknown) => {
        const validationErrors = getValidationErrors(error)
        if (validationErrors?.current_password) {
          currentPasswordApiError.value = validationErrors.current_password ?? t('settings.security.password.wrongCurrent')
        } else {
          const msg = getApiErrorMessage(error, t('errors.server_error'))
          toast.add({ severity: 'error', summary: msg, life: 5000 })
        }
      },
    },
  )
}
</script>

<style lang="scss" scoped>
.change-password-form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  max-width: 400px;

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__required {
    color: $color-danger;
    margin-left: $space-1;
  }

  &__error {
    font-size: $font-size-xs;
    color: $red-700;

    .app-dark & {
      color: var(--p-red-400);
    }
  }

  &__hint {
    font-size: $font-size-xs;
    color: $surface-500;
    margin-top: $space-1;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }

  &__actions {
    display: flex;
    justify-content: flex-end;
    padding-top: $space-2;
  }
}

.profile-field__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-700);
  }
}
</style>
