<template>
  <div class="contact-relations">
    <!-- Loading -->
    <div v-if="loading" class="contact-relations__skeleton">
      <Skeleton height="40px" class="mb-2" />
      <Skeleton height="40px" class="mb-2" />
    </div>

    <!-- List -->
    <template v-else>
      <EntityRow
        v-for="rel in sortedRelations"
        :key="rel.id"
        :title="relatedName(rel)"
        :link-to="`/contacts/${relatedId(rel)}`"
        icon="pi-user"
      >
        <template #tags>
          <Tag
            :value="relationTypeLabel(rel.relation_type)"
            :severity="relationTypeSeverity(rel.relation_type)"
            size="small"
            :icon="`pi ${relationTypeIcon(rel.relation_type)}`"
          />
          <span v-if="rel.note" class="contact-relations__note">{{ rel.note }}</span>
        </template>
        <template #actions>
          <Button
            icon="pi pi-times"
            text
            severity="secondary"
            size="small"
            :title="t('common.delete')"
            @click="onDelete(rel)"
          />
        </template>
      </EntityRow>

      <!-- Empty -->
      <div v-if="sortedRelations.length === 0" class="contact-relations__empty">
        <i class="pi pi-share-alt contact-relations__empty-icon" />
        <p class="contact-relations__empty-text">{{ t('crm.contact.relations.empty') }}</p>
        <button class="contact-relations__add-btn contact-relations__add-btn--cta" @click="openDialog = true">
          <i class="pi pi-plus" />
          {{ t('crm.contact.relations.add') }}
        </button>
      </div>

      <!-- Add button -->
      <button class="contact-relations__add-btn" @click="openDialog = true">
        <i class="pi pi-plus" />
        {{ t('crm.contact.relations.add') }}
      </button>
    </template>

    <!-- Delete relation confirm dialog (local, NOT ConfirmService — avoids phantom on route leave) -->
    <Dialog
      v-model:visible="deleteDialogOpen"
      :header="t('common.confirm')"
      modal
      :draggable="false"
      :style="{ width: '28rem' }"
      class="contact-relations__delete-dialog"
    >
      <div class="contact-relations__delete-body">
        <i class="pi pi-exclamation-triangle contact-relations__delete-icon" />
        <p class="contact-relations__delete-message">{{ t('crm.contact.relations.deleteConfirm') }}</p>
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="deleteDialogLoading"
          @click="deleteDialogOpen = false"
        />
        <Button
          :label="t('common.delete')"
          severity="danger"
          :loading="deleteDialogLoading"
          @click="executeDelete"
        />
      </template>
    </Dialog>

    <!-- Add relation dialog -->
    <Dialog
      v-model:visible="openDialog"
      :header="t('crm.contact.relations.add')"
      modal
      style="width: 460px"
    >
      <div class="contact-relations__dialog-form">
        <div class="contact-relations__dialog-field">
          <label class="contact-relations__dialog-label">
            {{ t('crm.contact.relations.contactLabel') }} *
          </label>
          <AutoComplete
            v-model="contactSearch"
            :suggestions="contactSuggestions"
            option-label="label"
            :placeholder="t('crm.contact.relations.selectContact')"
            force-selection
            class="w-full"
            @complete="searchContacts($event.query)"
            @option-select="onContactSelect($event.value)"
            @clear="onContactClear"
          />
        </div>
        <div class="contact-relations__dialog-field">
          <label class="contact-relations__dialog-label">
            {{ t('crm.contact.relations.typeLabel') }} *
          </label>
          <Select
            v-model="form.relationType"
            :options="relationTypeOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('crm.contact.relations.selectType')"
            class="w-full"
          />
        </div>
        <div class="contact-relations__dialog-field">
          <label class="contact-relations__dialog-label">{{ t('crm.contact.relations.noteLabel') }}</label>
          <InputText v-model="form.note" class="w-full" :placeholder="t('crm.contact.relations.notePlaceholder')" />
        </div>
        <Message v-if="formError" severity="error" class="mt-2">{{ formError }}</Message>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="closeDialog" />
        <Button
          :label="t('common.save')"
          :loading="saving"
          :disabled="!form.relatedContactId || !form.relationType"
          @click="submitAdd"
        />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import type { ComputedRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import AutoComplete from 'primevue/autocomplete'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import EntityRow from '@/components/crm/entity/EntityRow.vue'
import { contactsApi } from '@/api/crm/contacts'
import { getApiErrorMessage } from '@/utils/errors'
import type { ContactRelation, RelationType } from '@/entities/crm'

const props = defineProps<{
  contactId: number
  relations: ContactRelation[]
  loading?: boolean
}>()

const emit = defineEmits<{
  updated: [relations: ContactRelation[]]
}>()

const { t } = useI18n()
const toast = useToast()

defineExpose({ openAdd: () => { openDialog.value = true } })

// ── Helpers ───────────────────────────────────────────────────────────────────

function relatedId(rel: ContactRelation): number {
  return rel.contact.id === props.contactId ? rel.related_contact.id : rel.contact.id
}

function relatedName(rel: ContactRelation): string {
  return rel.contact.id === props.contactId ? rel.related_contact.full_name : rel.contact.full_name
}

function relationTypeLabel(type: RelationType): string {
  return t(`crm.contact.relations.type.${type}`)
}

function relationTypeSeverity(type: RelationType): 'success' | 'info' | 'warning' | 'danger' | 'secondary' {
  const map: Record<RelationType, 'success' | 'info' | 'warning' | 'danger' | 'secondary'> = {
    partner: 'info',
    referrer: 'success',
    colleague: 'secondary',
    friend: 'success',
    investor: 'warning',
    mentor: 'info',
    other: 'secondary',
  }
  return map[type] ?? 'secondary'
}

const RELATION_SORT_ORDER: Record<RelationType, number> = {
  partner: 1,
  referrer: 2,
  investor: 3,
  colleague: 4,
  mentor: 5,
  friend: 6,
  other: 7,
}

function relationTypeIcon(type: RelationType): string {
  const map: Record<RelationType, string> = {
    partner: 'pi-handshake',
    referrer: 'pi-share-alt',
    investor: 'pi-chart-bar',
    colleague: 'pi-users',
    friend: 'pi-heart',
    mentor: 'pi-graduation-cap',
    other: 'pi-link',
  }
  return map[type] ?? 'pi-link'
}

const sortedRelations: ComputedRef<typeof props.relations> = computed(() =>
  [...props.relations].sort(
    (a, b) => (RELATION_SORT_ORDER[a.relation_type] ?? 99) - (RELATION_SORT_ORDER[b.relation_type] ?? 99),
  ),
)

const relationTypeOptions = computed(() => [
  { value: 'partner', label: t('crm.contact.relations.type.partner') },
  { value: 'referrer', label: t('crm.contact.relations.type.referrer') },
  { value: 'colleague', label: t('crm.contact.relations.type.colleague') },
  { value: 'friend', label: t('crm.contact.relations.type.friend') },
  { value: 'investor', label: t('crm.contact.relations.type.investor') },
  { value: 'mentor', label: t('crm.contact.relations.type.mentor') },
  { value: 'other', label: t('crm.contact.relations.type.other') },
])

// ── Add dialog ────────────────────────────────────────────────────────────────

const openDialog = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)

// Contact autocomplete for the related-contact picker
const contactSearch = ref<string | { label: string; value: number } | null>(null)
const contactSuggestions = ref<Array<{ value: number; label: string }>>([])

async function searchContacts(query: string) {
  if (!query || query.trim().length < 1) {
    contactSuggestions.value = []
    return
  }
  try {
    const res = await contactsApi.list({ search: query.trim(), per_page: 20 })
    const existing = new Set(props.relations.flatMap((r) => [r.contact.id, r.related_contact.id]))
    contactSuggestions.value = (res.data ?? [])
      .filter((c) => c.id !== props.contactId && !existing.has(c.id))
      .map((c) => ({ value: c.id, label: c.full_name }))
  } catch {
    contactSuggestions.value = []
  }
}

function onContactSelect(option: { value: number; label: string }) {
  form.relatedContactId = option.value
}

function onContactClear() {
  form.relatedContactId = null
}

const form = reactive({
  relatedContactId: null as number | null,
  relationType: null as RelationType | null,
  note: '',
})

function closeDialog() {
  openDialog.value = false
  form.relatedContactId = null
  form.relationType = null
  form.note = ''
  formError.value = null
  contactSearch.value = null
  contactSuggestions.value = []
}

async function submitAdd() {
  if (!form.relatedContactId || !form.relationType) return
  if (form.relatedContactId === props.contactId) {
    formError.value = t('crm.contact.relations.selfError')
    return
  }
  saving.value = true
  formError.value = null
  try {
    const created = await contactsApi.addRelation(props.contactId, {
      related_contact_id: form.relatedContactId,
      relation_type: form.relationType,
      note: form.note.trim() || null,
    })
    emit('updated', [...props.relations, created])
    closeDialog()
    toast.add({ severity: 'success', summary: t('crm.contact.relations.added'), life: 2500 })
  } catch (err) {
    formError.value = getApiErrorMessage(err, t('errors.server_error'))
  } finally {
    saving.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────────────────
// Uses a local Dialog (NOT useConfirm/ConfirmDialog) to avoid PrimeVue
// ConfirmService phantom-dialog on route-leave.

const deleteDialogOpen = ref(false)
const deleteDialogLoading = ref(false)
const relToDelete = ref<ContactRelation | null>(null)

function onDelete(rel: ContactRelation) {
  relToDelete.value = rel
  deleteDialogOpen.value = true
}

async function executeDelete() {
  if (!relToDelete.value) return
  deleteDialogLoading.value = true
  try {
    await contactsApi.deleteRelation(props.contactId, relToDelete.value.id)
    emit('updated', props.relations.filter((r) => r.id !== relToDelete.value!.id))
    deleteDialogOpen.value = false
    toast.add({ severity: 'success', summary: t('crm.contact.relations.deleted'), life: 2500 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    deleteDialogLoading.value = false
    relToDelete.value = null
  }
}
</script>

<style lang="scss" scoped>
.contact-relations {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contact-relations__skeleton {
  display: flex;
  flex-direction: column;
}

.contact-relations__note {
  font-size: $font-size-xs;
  color: $surface-500;
  font-style: italic;
}

.contact-relations__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4;
  text-align: center;
}

.contact-relations__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-300;
}

.contact-relations__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.contact-relations__add-btn {
  display: flex;
  align-items: center;
  gap: $space-2;
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--p-primary-color);
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  padding: $space-1 0;
  margin-top: $space-1;
  transition: opacity var(--app-transition-fast);

  &:hover {
    opacity: 0.75;
  }

  i {
    font-size: $font-size-2xs;
  }

  &--cta {
    margin-top: $space-2;
    border: 1px solid var(--p-primary-color);
    border-radius: $radius-sm;
    padding: $space-1 $space-3;

    &:hover {
      background: rgba(var(--p-primary-500-rgb, 23, 39, 71), 0.06);
      opacity: 1;
    }
  }
}

.contact-relations__delete-body {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
}

.contact-relations__delete-icon {
  font-size: $font-size-icon-sm;
  color: $color-danger;
  flex-shrink: 0;
  margin-top: 2px;
}

.contact-relations__delete-message {
  font-size: $font-size-sm;
  color: var(--p-text-color);
  margin: 0;
  line-height: 1.5;
}

.contact-relations__dialog-form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.contact-relations__dialog-field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contact-relations__dialog-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.w-full {
  width: 100%;
}
</style>
