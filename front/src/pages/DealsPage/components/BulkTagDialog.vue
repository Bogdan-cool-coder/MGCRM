<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deals.page.bulk.tagDialog.title', { n: dealIds.length })"
    modal
    style="width: 460px"
    :closable="!saving"
    class="bulk-tag-dialog"
  >
    <div class="bulk-tag-dialog__body">
      <p class="bulk-tag-dialog__hint">
        {{ t('sales.deals.page.bulk.tagDialog.hint') }}
      </p>

      <div class="bulk-tag-dialog__field">
        <label class="bulk-tag-dialog__label">
          {{ t('sales.deals.page.bulk.tagDialog.tags') }}
        </label>
        <!-- Chips input — using AutoComplete in multiple mode -->
        <AutoComplete
          v-model="tags"
          :suggestions="tagSuggestions"
          multiple
          dropdown
          class="w-full"
          :placeholder="tags.length === 0 ? t('sales.deals.page.bulk.tagDialog.tagsPlaceholder') : ''"
          @complete="onSearchTags"
        />
      </div>
    </div>

    <template #footer>
      <div class="bulk-tag-dialog__footer">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="visible = false"
        />
        <Button
          icon="pi pi-check"
          :label="t('sales.deals.page.bulk.tagDialog.apply')"
          :loading="saving"
          @click="onSubmit"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import AutoComplete from 'primevue/autocomplete'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'

const props = defineProps<{
  modelValue: boolean
  dealIds: number[]
  existingTags?: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  done: []
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const tags = ref<string[]>([])
const tagSuggestions = ref<string[]>([])

const mutation = useMutation()
const saving = computed(() => mutation.isPending.value)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      tags.value = []
    }
  },
)

function onSearchTags(event: { query: string }) {
  const q = event.query.toLowerCase()
  tagSuggestions.value = (props.existingTags ?? []).filter(
    (tag) => tag.toLowerCase().includes(q) && !tags.value.includes(tag),
  )
}

async function onSubmit() {
  await mutation.run(() =>
    salesApi.bulkPatchDeals({
      deal_ids: props.dealIds,
      operation: 'edit_tags',
      tags: tags.value,
    }),
  )

  visible.value = false
  emit('done')
}
</script>

<style lang="scss" scoped>
.bulk-tag-dialog {
  &__body {
    display: flex;
    flex-direction: column;
    gap: $space-4;
    padding: $space-2 0;
  }

  &__hint {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
  }
}

.w-full {
  :deep(.p-autocomplete) {
    width: 100%;
  }
}
</style>
