<template>
  <Dialog
    :visible="visible"
    @update:visible="(value) => emit('update:visible', value)"
    modal
    :header="isEditMode ? t('userEditTitle') : t('userCreateTitle')"
    :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
    :closable="true"
  >
    <div class="user-form">
      <div class="form-group">
        <label for="name" class="form-label">{{ t('userNameLabel') }}</label>
        <InputText
          id="name"
          v-model="formData.name"
          :placeholder="t('userNamePlaceholder')"
          :class="{ 'p-invalid': errors.name }"
        />
        <small v-if="errors.name" class="p-error">{{ errors.name }}</small>
      </div>

      <div class="form-group">
        <label for="email" class="form-label">{{ t('userEmailLabel') }}</label>
        <InputText
          id="email"
          v-model="formData.email"
          type="email"
          :placeholder="t('userEmailPlaceholder')"
          :class="{ 'p-invalid': errors.email }"
        />
        <small v-if="errors.email" class="p-error">{{ errors.email }}</small>
      </div>

      <div class="form-group">
        <label for="password" class="form-label">
          {{ isEditMode ? t('userPasswordEditLabel') : t('userPasswordLabel') }}
        </label>
        <Password
          id="password"
          v-model="formData.password"
          toggleMask
          :feedback="false"
          :placeholder="
            isEditMode ? t('userPasswordEditPlaceholder') : t('userPasswordPlaceholder')
          "
        />
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="role" class="form-label">{{ t('userRoleLabel') }}</label>
          <Select
            id="role"
            v-model="formData.role"
            :options="roleOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('userRolePlaceholder')"
          />
        </div>

        <div class="form-group">
          <label for="locale" class="form-label">{{ t('userLocaleLabel') }}</label>
          <Select
            id="locale"
            v-model="formData.locale"
            :options="localeOptions"
            option-label="label"
            option-value="value"
          />
        </div>
      </div>

      <div v-if="showIframeActions" class="iframe-access">
        <div class="iframe-access__header">
          <span class="form-label">{{ t('userIframeTitle') }}</span>
          <small class="iframe-access__hint">{{ t('userIframeHint') }}</small>
        </div>

        <div class="iframe-access__url">
          <span v-if="iframeLoading">{{ t('common.loading') }}</span>
          <span v-else-if="iframeUrl">{{ iframeUrl }}</span>
          <span v-else>{{ t('userIframeUnavailable') }}</span>
        </div>

        <div class="iframe-access__actions">
          <Button
            :label="t('userIframeCopy')"
            outlined
            :disabled="iframeLoading"
            @click="emit('copy-iframe-link')"
          />
          <Button
            :label="t('userIframeRegenerate')"
            severity="contrast"
            :loading="iframeRegenerating"
            :disabled="iframeLoading"
            @click="emit('regenerate-iframe-link')"
          />
        </div>
      </div>

      <div v-if="formError" class="form-error">{{ formError }}</div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="danger" @click="handleCancel" />
      <Button
        :label="isEditMode ? t('common.save') : t('common.create')"
        :loading="saving"
        @click="handleSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  visible: boolean
  isEditMode: boolean
  formData: {
    id?: number
    name: string
    email: string
    password: string
    role: string
    company_id: number
    locale: string
  }
  errors: Record<string, string>
  formError: string
  saving: boolean
  iframeUrl: string
  iframeLoading: boolean
  iframeRegenerating: boolean
  showIframeActions: boolean
}

interface Emits {
  (e: 'update:visible', value: boolean): void
  (e: 'cancel'): void
  (e: 'submit'): void
  (e: 'copy-iframe-link'): void
  (e: 'regenerate-iframe-link'): void
}

defineProps<Props>()
const emit = defineEmits<Emits>()

const roleOptions = [
  { label: t('roles.admin'), value: 'admin' },
  { label: t('roles.analyst'), value: 'analyst' },
  { label: t('roles.viewer'), value: 'viewer' },
]

const localeOptions = [
  { label: t('localeRu'), value: 'ru' },
  { label: t('localeEn'), value: 'en' },
]

const handleCancel = () => {
  emit('cancel')
}

const handleSubmit = () => {
  emit('submit')
}
</script>

<style lang="scss" scoped>
.user-form {
  .form-group {
    margin-bottom: 1rem;

    .form-label {
      display: block;
      margin-bottom: 0.5rem;
      font-size: $font-size-sm;
      font-weight: $font-weight-medium;
      color: $surface-700;
    }

    .p-error {
      color: $red-500;
      font-size: $font-size-xs;
      margin-top: 0.25rem;
    }
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }

  .form-error {
    margin-top: 1rem;
    padding: 0.75rem;
    background-color: $red-50;
    border: 1px solid $red-200;
    border-radius: $border-radius;
    color: $red-700;
    font-size: $font-size-sm;
  }

  .iframe-access {
    margin-top: 1.5rem;
    padding: 1rem;
    border: 1px solid $surface-200;
    border-radius: $border-radius;
    background: $surface-50;
  }

  .iframe-access__header {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin-bottom: 0.75rem;
  }

  .iframe-access__hint {
    color: $surface-600;
  }

  .iframe-access__url {
    padding: 0.75rem;
    border-radius: $border-radius;
    background: $surface-0;
    border: 1px solid $surface-200;
    color: $surface-800;
    font-size: $font-size-sm;
    line-height: 1.5;
    word-break: break-all;
  }

  .iframe-access__actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 0.75rem;
    flex-wrap: wrap;
  }
}
</style>
