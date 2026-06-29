<template>
  <Dialog
    v-model:visible="visible"
    modal
    :closable="true"
    :header="t('quickActions.dialogTitle')"
    class="quick-actions-dialog"
    :style="{ width: '620px', maxWidth: 'calc(100vw - 2rem)' }"
    @after-hide="onHide"
  >
    <p class="quick-actions-dialog__hint">{{ t('quickActions.dialogHint') }}</p>

    <PickList
      v-model="lists"
      :data-key="'key'"
      :show-source-controls="false"
      :show-target-controls="true"
      :target-list-props="{ 'aria-label': t('quickActions.selected') }"

      class="quick-actions-picklist"
      @move-to-target="onMoveToTarget"
      @move-all-to-target="onMoveToTarget"
    >
      <template #sourceheader>
        <span class="quick-actions-picklist__header">{{ t('quickActions.available') }}</span>
      </template>
      <template #targetheader>
        <span class="quick-actions-picklist__header">
          {{ t('quickActions.selected') }} ({{ lists[1].length }}/{{ MAX_ACTIONS }})
        </span>
      </template>
      <template #item="{ item }">
        <div class="quick-action-item">
          <i :class="[item.icon, 'quick-action-item__icon']" aria-hidden="true" />
          <span class="quick-action-item__label">{{ t(item.labelKey) }}</span>
        </div>
      </template>
    </PickList>

    <p v-if="validationError" class="quick-actions-dialog__error">
      {{ validationError }}
    </p>

    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        outlined
        @click="visible = false"
      />
      <Button
        :label="t('common.save')"
        :loading="isSaving"
        :disabled="!!validationError"
        @click="save"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import PickList from 'primevue/picklist'
import { usePrimeVue } from 'primevue/config'
import { useToast } from 'primevue/usetoast'
import { useUserStore } from '@/stores/user'
import { useMutation } from '@/composables/async/useMutation'
import { profileApi } from '@/api/profile'
import type { MeResponse } from '@/api/types/auth'
import { mapUser } from '@/entities/user'
import {
  QUICK_ACTION_CATALOGUE,
  resolveQuickActions,
  type QuickActionDef,
} from '@/shared/nav/quickActionRegistry'
import { getApiErrorMessage } from '@/utils/errors'

const MAX_ACTIONS = 5

const { t, locale } = useI18n()
const toast = useToast()
const userStore = useUserStore()
const primevue = usePrimeVue()

/**
 * draftMode=true: dialog emits 'update:draft' with key[] on save instead of persisting to API.
 * Used by SectionAppearance to integrate quick-actions into the section save flow.
 */
const props = defineProps<{
  draftMode?: boolean
  draftKeys?: string[]
}>()

const emit = defineEmits<{
  'update:draft': [keys: string[]]
}>()

// Sync PrimeVue emptyMessage locale with vue-i18n so PickList shows translated "no items" text
watch(
  locale,
  () => {
    if (primevue.config.locale) {
      primevue.config.locale.emptyMessage = t('quickActions.noAvailable')
    }
  },
  { immediate: true },
)

// ─── v-model:visible ─────────────────────────────────────────────────────────
const modelVisible = defineModel<boolean>('visible', { default: false })

const visible = computed({
  get: () => modelVisible.value,
  set: (v) => {
    modelVisible.value = v
  },
})

// ─── PickList state ───────────────────────────────────────────────────────────
// lists[0] = available (not selected), lists[1] = selected (ordered)
const lists = ref<[QuickActionDef[], QuickActionDef[]]>([[], []])

function buildLists(): [QuickActionDef[], QuickActionDef[]] {
  // In draftMode, initialise from draftKeys prop; otherwise from user store
  const sourceKeys = props.draftMode && props.draftKeys != null
    ? props.draftKeys
    : userStore.getNavQuickActions
  const selected = resolveQuickActions(sourceKeys)
  const selectedKeys = new Set(selected.map((a) => a.key))
  const available = QUICK_ACTION_CATALOGUE.filter((a) => !selectedKeys.has(a.key))
  return [available, selected]
}

// Rebuild lists when dialog opens
watch(visible, (open) => {
  if (open) {
    lists.value = buildLists()
  }
})

// ─── Validation ───────────────────────────────────────────────────────────────
const validationError = computed<string>(() => {
  if (lists.value[1].length > MAX_ACTIONS) {
    return t('quickActions.maxError', { max: MAX_ACTIONS })
  }
  return ''
})

// Guard: prevent moving to target when already at max
function onMoveToTarget() {
  if (lists.value[1].length > MAX_ACTIONS) {
    // Roll back the excess: move the last excess items back to source
    const overflow = lists.value[1].splice(MAX_ACTIONS)
    lists.value[0].unshift(...overflow)
  }
}

// ─── Save ─────────────────────────────────────────────────────────────────────
const saveMutation = useMutation<MeResponse>()
const isSaving = computed(() => saveMutation.isPending.value)

async function save() {
  if (validationError.value) return

  const keys = lists.value[1].map((a) => a.key)

  if (props.draftMode) {
    // Draft mode: emit keys to parent section; parent persists on its own "Save"
    emit('update:draft', keys)
    // Close after emit so parent receives the draft before the dialog unmounts
    modelVisible.value = false
    return
  }

  // Non-draft: persist to API; close on success, keep open on error (let user retry)
  let succeeded = false
  await saveMutation.run(
    async () => {
      const response = await profileApi.updateProfile({ nav_quick_actions: keys })
      userStore.setCurrentUser(mapUser(response.data))
      toast.add({
        severity: 'success',
        summary: t('quickActions.saved'),
        life: 2500,
      })
      succeeded = true
      return response
    },
    {
      onError: (err) => {
        toast.add({
          severity: 'error',
          summary: getApiErrorMessage(err, t('errors.unknown', 'Ошибка сохранения')),
          life: 4000,
        })
      },
    },
  )
  if (succeeded) {
    modelVisible.value = false
  }
}

function onHide() {
  // Reset lists on hide so stale state doesn't linger
  lists.value = buildLists()
}
</script>

<style lang="scss">
// Unscoped: Dialog renders in portal
.quick-actions-dialog .p-dialog-content {
  padding-top: 0;
}
</style>

<style lang="scss" scoped>
.quick-actions-dialog__hint {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0 0 $space-4;
}

.quick-actions-dialog__error {
  margin-top: $space-3;
  font-size: $font-size-sm;
  color: var(--p-red-500);
}

.quick-action-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-1 0;

  &__icon {
    font-size: $font-size-md;
    color: $primary;
    width: $font-size-md;
    text-align: center;
    flex-shrink: 0;
  }

  &__label {
    font-size: $font-size-sm;
    color: var(--p-text-color);
  }
}

.quick-actions-picklist {
  :deep(.p-picklist-list) {
    min-height: 220px;
    max-height: 320px;
    overflow-y: auto;
  }

  &__header {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
  }
}
</style>
