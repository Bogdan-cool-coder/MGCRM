<template>
  <Drawer
    :visible="visible"
    position="right"
    :style="{ width: '480px' }"
    @update:visible="$emit('update:visible', $event)"
  >
    <template #header>
      <div class="d-flex align-items-center justify-content-between w-100">
        <span class="fw-semibold">{{ t('automation.dryrun.drawerTitle') }}</span>
        <Button
          icon="pi pi-times"
          text
          severity="secondary"
          size="small"
          @click="$emit('update:visible', false)"
        />
      </div>
    </template>

    <div class="dry-run-drawer">
      <!-- on_enter_stage: требуется выбор сделки -->
      <div
        v-if="isInlineTrigger"
        class="dry-run-drawer__target-section mb-3"
      >
        <label class="dry-run-drawer__label">{{ t('automation.dryrun.targetDeal') }}</label>
        <Select
          v-model="targetDealId"
          :options="dealOptions"
          option-label="title"
          option-value="id"
          :placeholder="t('automation.dryrun.targetDealPlaceholder')"
          filter
          :filter-placeholder="t('automation.dryrun.dealSearch')"
          :loading="dealsLoading"
          class="w-100"
          show-clear
        />
        <small class="dry-run-drawer__hint">{{ t('automation.dryrun.targetDealHint') }}</small>
      </div>

      <!-- Limit -->
      <div class="dry-run-drawer__limit-section mb-3">
        <label class="dry-run-drawer__label">{{ t('automation.dryrun.limitLabel') }}</label>
        <InputNumber
          v-model="limit"
          :min="1"
          :max="500"
          :use-grouping="false"
          class="w-100"
        />
      </div>

      <!-- Loading state -->
      <div v-if="dryRunLoading" class="dry-run-drawer__loading">
        <ProgressSpinner style="width: 40px; height: 40px" />
        <span>{{ t('automation.dryrun.analyzing') }}</span>
      </div>

      <!-- Error state -->
      <Message v-else-if="dryRunError" severity="error" :closable="false">
        {{ dryRunError }}
      </Message>

      <!-- Result -->
      <template v-else-if="dryRunResult">
        <div class="dry-run-drawer__matched mb-2">
          <strong>{{ t('automation.dryrun.matchedCount', { n: dryRunResult.match_count }) }}</strong>
        </div>

        <div v-if="(dryRunResult.matched_targets ?? []).length === 0">
          <Message severity="info" :closable="false">
            {{ t('automation.dryrun.noMatches') }}
          </Message>
        </div>
        <ul v-else class="dry-run-drawer__list">
          <li v-for="rec in (dryRunResult.matched_targets ?? [])" :key="rec.target_id">
            {{ rec.label || `#${rec.target_id}` }}
          </li>
        </ul>

        <div v-if="(dryRunResult.actions_plan ?? []).length > 0" class="mt-3">
          <p class="dry-run-drawer__plan-label">{{ t('automation.dryrun.actionsLabel') }}</p>
          <ul class="dry-run-drawer__plan-list">
            <li
              v-for="item in (dryRunResult.actions_plan ?? [])"
              :key="item.target_id"
              class="dry-run-drawer__plan-item"
              :class="{ 'dry-run-drawer__plan-item--skip': !item.would_execute }"
            >
              <i
                class="pi"
                :class="item.would_execute ? 'pi-check-circle' : 'pi-times-circle'"
              />
              <span>{{ item.summary }}</span>
            </li>
          </ul>
        </div>
      </template>

      <!-- Empty hint -->
      <template v-else>
        <p class="text-muted">{{ t('automation.dryrun.emptyHint') }}</p>
      </template>
    </div>

    <template #footer>
      <div class="d-flex justify-content-between align-items-center w-100">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          @click="$emit('update:visible', false)"
        />
        <div class="d-flex gap-2">
          <Button
            :label="t('automation.dryrun.button')"
            icon="pi pi-search"
            severity="secondary"
            outlined
            :loading="dryRunLoading"
            :disabled="isInlineTrigger && !targetDealId"
            @click="runDryRun"
          />
          <Button
            v-if="dryRunResult && dryRunResult.match_count > 0"
            :label="t('automation.dryrun.execute')"
            icon="pi pi-play"
            :loading="executing"
            @click="onExecute"
          />
        </div>
      </div>
    </template>
  </Drawer>

  <ConfirmDialog />
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import ProgressSpinner from 'primevue/progressspinner'
import Message from 'primevue/message'
import ConfirmDialog from 'primevue/confirmdialog'
import { automationsApi } from '@/api/automation'
import { salesApi } from '@/api/sales'
import type { AutomationDto, DryRunResponse } from '@/entities/automation'
import type { DealDto, DealListParams } from '@/entities/sales'
import { extractMsg } from './composables/useAutomationRuns'

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  visible: boolean
  automation: AutomationDto | null
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  ran: []
}>()

// ─── State ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()

const limit = ref(50)
const targetDealId = ref<number | null>(null)

const dryRunLoading = ref(false)
const dryRunError = ref<string | null>(null)
const dryRunResult = ref<DryRunResponse | null>(null)
const executing = ref(false)

// ─── Deals for on_enter_stage ─────────────────────────────────────────────────

const dealOptions = ref<Pick<DealDto, 'id' | 'title'>[]>([])
const dealsLoading = ref(false)

const INLINE_TRIGGERS = new Set(['on_enter_stage', 'on_create'])

const isInlineTrigger = computed<boolean>(() => {
  const kind = props.automation?.trigger_kind
  return !!kind && INLINE_TRIGGERS.has(kind)
})

async function loadDeals(): Promise<void> {
  if (!isInlineTrigger.value) return
  dealsLoading.value = true
  try {
    const params: DealListParams = { per_page: 100 }
    const resp = await salesApi.getDeals(params)
    dealOptions.value = (resp.data ?? []).map((d: DealDto) => ({ id: d.id, title: d.title }))
  } catch {
    // non-critical — user sees empty dropdown
  } finally {
    dealsLoading.value = false
  }
}

// Reset and reload when drawer opens or automation changes
watch(
  () => props.visible,
  (open) => {
    if (open) {
      dryRunResult.value = null
      dryRunError.value = null
      targetDealId.value = null
      void loadDeals()
    }
  },
)

watch(
  () => props.automation?.id,
  () => {
    dryRunResult.value = null
    dryRunError.value = null
    targetDealId.value = null
  },
)

// ─── Dry-run ──────────────────────────────────────────────────────────────────

async function runDryRun(): Promise<void> {
  if (!props.automation) return
  if (isInlineTrigger.value && !targetDealId.value) return

  dryRunLoading.value = true
  dryRunError.value = null
  dryRunResult.value = null

  try {
    const options: { target_type?: string; target_id?: number; limit?: number } = {
      limit: limit.value,
    }
    if (isInlineTrigger.value && targetDealId.value) {
      options.target_type = 'deal'
      options.target_id = targetDealId.value
    }
    dryRunResult.value = await automationsApi.test(props.automation.id, options)
  } catch (e: unknown) {
    dryRunError.value = extractMsg(e)
  } finally {
    dryRunLoading.value = false
  }
}

// ─── Execute ──────────────────────────────────────────────────────────────────

function onExecute(): void {
  if (!props.automation || !dryRunResult.value) return
  const n = dryRunResult.value.match_count
  confirm.require({
    header: t('automation.dryrun.confirmHeader'),
    message: t('automation.dryrun.confirmBody', { n }),
    acceptLabel: t('automation.dryrun.execute'),
    rejectLabel: t('common.cancel'),
    acceptProps: { severity: 'danger' },
    accept: () => void doExecute(),
  })
}

async function doExecute(): Promise<void> {
  if (!props.automation) return
  executing.value = true
  try {
    const opts: { limit: number; target_id?: number } = { limit: limit.value }
    if (isInlineTrigger.value && targetDealId.value) {
      opts.target_id = targetDealId.value
    }
    const result = await automationsApi.execute(props.automation.id, opts)
    toast.add({
      severity: 'success',
      summary: t('automation.dryrun.resultToast', {
        executed: result.executed,
        skipped: result.skipped,
      }),
      life: 5000,
    })
    emit('update:visible', false)
    emit('ran')
  } catch (e: unknown) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: extractMsg(e),
      life: 5000,
    })
  } finally {
    executing.value = false
  }
}
</script>

<style lang="scss" scoped>
.dry-run-drawer {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-2 0;

  &__label {
    display: block;
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin-bottom: $space-1;
  }

  &__hint {
    display: block;
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    margin-top: $space-1;
  }

  &__limit-section {
    display: flex;
    flex-direction: column;
  }

  &__target-section {
    display: flex;
    flex-direction: column;
  }

  &__loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-6;
    color: var(--p-text-muted-color);
  }

  &__matched {
    font-size: $font-size-sm;
  }

  &__list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: $space-1;

    li {
      font-size: $font-size-sm;
      padding: $space-1 $space-2;
      background-color: var(--p-surface-50);
      border-radius: $radius-sm;
      border: 1px solid var(--p-surface-200);

      .app-dark & {
        background-color: var(--p-surface-800);
        border-color: var(--p-surface-700);
      }
    }
  }

  &__plan-label {
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;
    color: var(--p-text-muted-color);
    text-transform: uppercase;
    margin-bottom: $space-1;
  }

  &__plan-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__plan-item {
    display: flex;
    align-items: flex-start;
    gap: $space-2;
    font-size: $font-size-sm;
    color: var(--p-text-color);
    padding: $space-1 $space-2;
    border-radius: $radius-sm;
    background-color: var(--p-surface-50);
    border: 1px solid var(--p-surface-200);

    .app-dark & {
      background-color: var(--p-surface-800);
      border-color: var(--p-surface-700);
    }

    .pi-check-circle {
      color: var(--p-green-500);
      flex-shrink: 0;
      margin-top: 2px;
    }

    .pi-times-circle {
      color: var(--p-text-muted-color);
      flex-shrink: 0;
      margin-top: 2px;
    }

    &--skip {
      opacity: 0.7;
    }
  }
}
</style>
