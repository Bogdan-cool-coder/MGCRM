<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('deleteTitle')"
    :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
    @update:visible="$emit('update:visible', $event)"
  >
    <div class="delete-confirm">
      <p>
        {{ t('deleteCompanyPrompt') }}
        <strong>{{ company?.name }}</strong
        >?
      </p>
      <p class="warning">{{ t('deleteWarning') }}</p>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" @click="$emit('cancel')" />
      <Button
        :label="t('common.delete')"
        severity="danger"
        :loading="deleting"
        @click="$emit('confirm')"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import type { Company } from '@/entities/company'
import { useLocalI18n } from '@/composables/useLocalI18n'
import companyEn from '@/components/Company/locale/en.json'
import companyRu from '@/components/Company/locale/ru.json'
import modalEn from '@/components/modals/locale/en.json'
import modalRu from '@/components/modals/locale/ru.json'

const { t } = useLocalI18n({
  en: { ...modalEn, ...companyEn },
  ru: { ...modalRu, ...companyRu },
})

interface Props {
  visible: boolean
  company: Company | null
  deleting: boolean
}

defineProps<Props>()

defineEmits<{
  'update:visible': [value: boolean]
  cancel: []
  confirm: []
}>()
</script>

<style lang="scss" scoped>
.delete-confirm {
  p {
    margin: 0 0 0.5rem;
    color: $surface-700;
  }

  .warning {
    color: $danger;
    font-size: $font-size-sm;
  }
}
</style>
