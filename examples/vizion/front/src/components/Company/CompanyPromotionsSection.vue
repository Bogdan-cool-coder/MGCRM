<template>
  <div class="company-promotions-section">
    <div class="section-header">
      <div class="section-header-text">
        <h3 class="section-title">{{ t('promotions.title') }}</h3>
        <p class="section-subtitle">{{ t('promotions.subtitle') }}</p>
      </div>
      <div v-if="canEdit" class="section-header-actions">
        <Button
          icon="pi pi-plus"
          :label="t('promotions.create')"
          :disabled="!companyId || loading"
          @click="openCreate"
        />
      </div>
    </div>

    <div v-if="loading && !promotions.length" class="loading-state">
      <ProgressSpinner style="width: 32px; height: 32px" />
    </div>

    <DataTable v-else :value="promotions" :loading="loading" class="promotions-table">
      <template #empty>
        <div class="empty-state">{{ t('promotions.emptyState') }}</div>
      </template>
      <Column :header="t('promotions.table.name')" style="width: 30%">
        <template #body="{ data }">{{ localizedName(data) }}</template>
      </Column>
      <Column :header="t('promotions.table.type')" style="width: 14%">
        <template #body="{ data }">
          <Tag :value="t(`promotions.type.${data.discountType}`)" severity="info" />
        </template>
      </Column>
      <Column :header="t('promotions.table.range')" style="width: 20%">
        <template #body="{ data }">
          {{ data.discountMin }}–{{ data.discountMax }}{{ data.discountType === 'percent' ? ' %' : '' }}
        </template>
      </Column>
      <Column :header="t('promotions.table.active')" style="width: 12%">
        <template #body="{ data }">
          <Tag
            :value="data.isActive ? t('promotions.active.yes') : t('promotions.active.no')"
            :severity="data.isActive ? 'success' : 'secondary'"
          />
        </template>
      </Column>
      <Column v-if="canEdit" :header="t('promotions.table.actions')" style="width: 120px">
        <template #body="{ data }">
          <ActionButtonGroup show-delete @edit="openEdit(data)" @delete="confirmDelete(data)" />
        </template>
      </Column>
    </DataTable>

    <!-- Create / Edit dialog -->
    <Dialog
      v-model:visible="formVisible"
      modal
      :header="isEditMode ? t('promotions.form.editTitle') : t('promotions.form.createTitle')"
      :breakpoints="{ '1199px': '75vw', '575px': '92vw' }"
      :style="{ width: '40rem' }"
    >
      <div class="promo-form">
        <div class="field">
          <label class="field-label">{{ t('promotions.form.nameRu') }} *</label>
          <InputText v-model="formData.nameRu" class="w-full" />
        </div>
        <div class="field">
          <label class="field-label">{{ t('promotions.form.nameEn') }}</label>
          <InputText v-model="formData.nameEn" class="w-full" />
        </div>
        <div class="field">
          <label class="field-label">{{ t('promotions.form.descriptionRu') }}</label>
          <Textarea v-model="formData.descriptionRu" rows="2" class="w-full" />
        </div>
        <div class="field">
          <label class="field-label">{{ t('promotions.form.descriptionEn') }}</label>
          <Textarea v-model="formData.descriptionEn" rows="2" class="w-full" />
        </div>
        <div class="field">
          <label class="field-label">{{ t('promotions.form.type') }} *</label>
          <Select
            v-model="formData.discountType"
            :options="typeOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
        <div class="field-row">
          <div class="field">
            <label class="field-label">{{ t('promotions.form.min') }} *</label>
            <InputNumber
              v-model="formData.discountMin"
              :min="0"
              :max-fraction-digits="2"
              class="w-full"
            />
          </div>
          <div class="field">
            <label class="field-label">{{ t('promotions.form.max') }} *</label>
            <InputNumber
              v-model="formData.discountMax"
              :min="0"
              :max-fraction-digits="2"
              class="w-full"
            />
          </div>
          <div class="field">
            <label class="field-label">{{ t('promotions.form.sortOrder') }}</label>
            <InputNumber v-model="formData.sortOrder" :min="0" class="w-full" />
          </div>
        </div>
        <div class="field field-inline">
          <ToggleSwitch v-model="formData.isActive" input-id="promo-active" />
          <label for="promo-active" class="field-label">{{ t('promotions.form.isActive') }}</label>
        </div>

        <Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>
      </div>

      <template #footer>
        <Button
          :label="t('promotions.form.cancel')"
          severity="secondary"
          :disabled="saving"
          @click="closeForm"
        />
        <Button
          :label="saving ? t('promotions.form.saving') : t('promotions.form.submit')"
          :loading="saving"
          @click="submit"
        />
      </template>
    </Dialog>

    <!-- Delete confirmation -->
    <DeleteConfirmModal
      v-model:visible="deleteVisible"
      :item-name="promotionToDelete ? localizedName(promotionToDelete) : ''"
      :loading="deleting"
      :show-warning="false"
      @cancel="cancelDelete"
      @confirm="performDelete"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import { ActionButtonGroup } from '@/components/tables'
import DeleteConfirmModal from '@/components/modals/DeleteConfirmModal.vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import { useUserStore } from '@/stores/user'
import { canManagePromotions } from '@/shared/auth/capabilities'
import { getLocalizedText } from '@/utils/localization'
import type { Promotion, PromotionDiscountType } from '@/entities/promotion'
import type { CreatePromotionRequest, UpdatePromotionRequest } from '@/api/types/promotions'
import type { LocalizedText } from '@/shared/types'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const props = defineProps<{
  companyId: number | null
}>()

const { t, locale } = useLocalI18n({ en, ru })
const { notifyApiError, notifySuccess } = useNotifications()
const { promotionService } = useServices()
const userStore = useUserStore()

const canEdit = computed(() => canManagePromotions(userStore.getUserRole))

const promotions = ref<Promotion[]>([])
const loading = ref(false)

const typeOptions = computed<{ label: string; value: PromotionDiscountType }[]>(() => [
  { label: t('promotions.type.percent'), value: 'percent' },
  { label: t('promotions.type.absolute'), value: 'absolute' },
])

const localizedName = (p: Promotion): string => getLocalizedText(p.name, locale.value)

// Active company is resolved by backend middleware, so the list call takes no
// id — `companyId` only drives the reactive (re)load watch below.
const load = async () => {
  loading.value = true
  try {
    // No activeOnly filter — admins manage the full list including inactive.
    promotions.value = await promotionService.fetchAllPromotions(false)
  } catch (error: unknown) {
    notifyApiError(error, t('promotions.errors.load'))
  } finally {
    loading.value = false
  }
}

// ─── Create / Edit form ───────────────────────────────────────────────────
const formVisible = ref(false)
const isEditMode = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const formError = ref<string | null>(null)

const formData = reactive<{
  nameRu: string
  nameEn: string
  descriptionRu: string
  descriptionEn: string
  discountType: PromotionDiscountType
  discountMin: number
  discountMax: number
  isActive: boolean
  sortOrder: number | null
}>({
  nameRu: '',
  nameEn: '',
  descriptionRu: '',
  descriptionEn: '',
  discountType: 'percent',
  discountMin: 0,
  discountMax: 0,
  isActive: true,
  sortOrder: null,
})

const resetForm = () => {
  formData.nameRu = ''
  formData.nameEn = ''
  formData.descriptionRu = ''
  formData.descriptionEn = ''
  formData.discountType = 'percent'
  formData.discountMin = 0
  formData.discountMax = 0
  formData.isActive = true
  formData.sortOrder = null
  formError.value = null
}

const openCreate = () => {
  resetForm()
  isEditMode.value = false
  editingId.value = null
  formVisible.value = true
}

// LocalizedText is `string | { ru, en }`; read a specific locale safely.
const localeValue = (text: LocalizedText | null | undefined, key: 'ru' | 'en'): string => {
  if (text === null || text === undefined) return ''
  if (typeof text === 'string') return text
  return text[key] ?? ''
}

const openEdit = (p: Promotion) => {
  resetForm()
  isEditMode.value = true
  editingId.value = p.id
  formData.nameRu = localeValue(p.name, 'ru')
  formData.nameEn = localeValue(p.name, 'en')
  formData.descriptionRu = localeValue(p.description, 'ru')
  formData.descriptionEn = localeValue(p.description, 'en')
  formData.discountType = p.discountType
  formData.discountMin = p.discountMin
  formData.discountMax = p.discountMax
  formData.isActive = p.isActive
  formData.sortOrder = p.sortOrder
  formVisible.value = true
}

const closeForm = () => {
  formVisible.value = false
}

/** Client-side mirror of the backend cross-field invariants. */
const validate = (): boolean => {
  if (!formData.nameRu.trim() && !formData.nameEn.trim()) {
    formError.value = t('promotions.errors.nameRequired')
    return false
  }
  if (formData.discountMin > formData.discountMax) {
    formError.value = t('promotions.errors.minGtMax')
    return false
  }
  if (formData.discountType === 'percent' && formData.discountMax > 100) {
    formError.value = t('promotions.errors.percentOver100')
    return false
  }
  formError.value = null
  return true
}

const submit = async () => {
  if (props.companyId === null || !validate()) return
  saving.value = true
  try {
    const name = { ru: formData.nameRu, en: formData.nameEn }
    const description =
      formData.descriptionRu || formData.descriptionEn
        ? { ru: formData.descriptionRu, en: formData.descriptionEn }
        : null

    if (isEditMode.value && editingId.value !== null) {
      const payload: UpdatePromotionRequest = {
        name,
        description,
        discount_type: formData.discountType,
        discount_min: formData.discountMin,
        discount_max: formData.discountMax,
        is_active: formData.isActive,
        sort_order: formData.sortOrder,
      }
      await promotionService.updatePromotion(editingId.value, payload)
      notifySuccess(t('promotions.success.updated'))
    } else {
      const payload: CreatePromotionRequest = {
        name,
        description,
        discount_type: formData.discountType,
        discount_min: formData.discountMin,
        discount_max: formData.discountMax,
        is_active: formData.isActive,
        sort_order: formData.sortOrder,
      }
      await promotionService.createPromotion(payload)
      notifySuccess(t('promotions.success.created'))
    }
    formVisible.value = false
    if (props.companyId !== null) await load()
  } catch (error: unknown) {
    notifyApiError(error, t('promotions.errors.save'))
  } finally {
    saving.value = false
  }
}

// ─── Delete ────────────────────────────────────────────────────────────────
const deleteVisible = ref(false)
const deleting = ref(false)
const promotionToDelete = ref<Promotion | null>(null)

const confirmDelete = (p: Promotion) => {
  promotionToDelete.value = p
  deleteVisible.value = true
}

const cancelDelete = () => {
  deleteVisible.value = false
  promotionToDelete.value = null
}

const performDelete = async () => {
  if (promotionToDelete.value === null) return
  deleting.value = true
  try {
    await promotionService.deletePromotion(promotionToDelete.value.id)
    notifySuccess(t('promotions.success.deleted'))
    deleteVisible.value = false
    promotionToDelete.value = null
    if (props.companyId !== null) await load()
  } catch (error: unknown) {
    notifyApiError(error, t('promotions.errors.delete'))
  } finally {
    deleting.value = false
  }
}

watch(
  () => props.companyId,
  (id) => {
    if (id !== null && id > 0) {
      void load()
    }
  },
  { immediate: true },
)
</script>

<style lang="scss" scoped>
.company-promotions-section {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;

  .section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;

    .section-title {
      margin: 0 0 0.25rem;
      font-size: $font-size-lg;
      font-weight: $font-weight-semibold;
      color: $surface-900;
    }

    .section-subtitle {
      margin: 0;
      font-size: $font-size-sm;
      color: $surface-600;
    }
  }

  .loading-state {
    display: flex;
    justify-content: center;
    padding: 2rem;
  }

  .empty-state {
    padding: 1.5rem;
    text-align: center;
    color: $surface-500;
  }
}

.promo-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;

  .field {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;

    .field-label {
      font-size: $font-size-sm;
      font-weight: $font-weight-medium;
      color: $surface-700;
    }
  }

  .field-inline {
    flex-direction: row;
    align-items: center;
    gap: 0.75rem;
  }

  .field-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;

    @media (max-width: 575px) {
      grid-template-columns: 1fr;
    }
  }
}
</style>
