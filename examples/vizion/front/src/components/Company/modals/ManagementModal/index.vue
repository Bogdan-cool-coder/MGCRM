<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="t('managementTitle')"
    :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
    :closable="true"
  >
    <div class="companies-modal">
      <CompaniesTable
        :companies="companies"
        :loading="loading"
        @create="openCreateModal"
        @edit="openEditModal"
        @delete="confirmDelete"
      />
    </div>

    <CompanyFormModal
      :visible="formVisible"
      :is-edit-mode="isEditMode"
      :form-data="formData"
      :errors="errors"
      :form-error="formError"
      :saving="saving"
      @update:visible="formVisible = $event"
      @cancel="closeFormModal"
      @submit="submitForm"
    />

    <DeleteConfirmModal
      :visible="deleteConfirmVisible"
      :company="companyToDelete"
      :deleting="deleting"
      @cancel="cancelDelete"
      @confirm="deleteCompany"
    />
  </Dialog>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import CompaniesTable from '@/components/Company/tables/CompaniesTable.vue'
import CompanyFormModal from '@/components/Company/modals/CompanyFormModal.vue'
import DeleteConfirmModal from './components/DeleteConfirmModal.vue'
import { useCompanyManagementModal } from './useCompanyManagementModal'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const { t } = useLocalI18n({ en, ru })

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const visible = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

const {
  companies,
  loading,
  saving,
  deleting,
  formVisible,
  isEditMode,
  errors,
  formError,
  formData,
  companyToDelete,
  deleteConfirmVisible,
  fetchCompanies,
  openCreateModal,
  openEditModal,
  closeFormModal,
  submitForm,
  confirmDelete,
  cancelDelete,
  deleteCompany,
} = useCompanyManagementModal()

watch(
  () => props.modelValue,
  (newVal) => {
    if (newVal) {
      fetchCompanies()
    }
  },
  { immediate: true },
)
</script>

<style lang="scss" scoped>
.companies-modal {
  padding: 0.5rem 0;
}
</style>
