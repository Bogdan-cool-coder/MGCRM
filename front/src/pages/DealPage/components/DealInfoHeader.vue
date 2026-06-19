<template>
  <div class="deal-header">
    <!-- Top row: back btn + id + menu btn -->
    <div class="deal-header__top-row">
      <button class="deal-header__btn-icon" @click="$emit('back')">
        <i class="pi pi-arrow-left" />
      </button>
      <div class="deal-header__spacer" />
      <span class="deal-header__id">#{{ deal.id }}</span>
      <button ref="menuBtnRef" class="deal-header__btn-icon" @click="toggleMenu">
        <i class="pi pi-ellipsis-v" />
      </button>
      <Menu ref="menuRef" :model="dealMenuItems" popup />
    </div>

    <!-- Title -->
    <h2 class="deal-header__title">{{ deal.title }}</h2>

    <!-- Stage row: clickable tag + health chip -->
    <div class="deal-header__stage-row">
      <button
        class="deal-header__stage-btn"
        v-tooltip.bottom="t('sales.deal.page.clickToChangeStage')"
        @click="$emit('openMoveDialog')"
      >
        <DealStageTag :stage="deal.stage" />
        <i class="pi pi-chevron-down deal-header__stage-chevron" />
      </button>
      <DealHealthChip :next-task="nextTask" />
    </div>

    <!-- Progress bar -->
    <DealStageProgressBar
      :stages="stages"
      :current-stage-id="deal.stage.id"
      class="deal-header__progress"
      @stage-click="onSegmentClick"
    />

    <!-- Days in stage info (just N дн., no deal name duplication) -->
    <p class="deal-header__days-hint">
      {{ daysInStage }} {{ t('sales.deal.page.daysInStage') }}
    </p>

    <!-- Planned dates row -->
    <div class="deal-header__planned-dates">
      <div class="deal-header__planned-date-block">
        <span class="deal-header__planned-date-label">{{ t('sales.deal.info.fields.plannedContract') }}</span>
        <span class="deal-header__planned-date-value" :class="{ 'deal-header__planned-date-value--empty': !deal.expected_sign_date }">
          {{ formatDate(deal.expected_sign_date) }}
        </span>
      </div>
      <div class="deal-header__planned-date-sep" />
      <div class="deal-header__planned-date-block">
        <span class="deal-header__planned-date-label">{{ t('sales.deal.info.fields.plannedPayment') }}</span>
        <span class="deal-header__planned-date-value" :class="{ 'deal-header__planned-date-value--empty': !deal.expected_payment_date }">
          {{ formatDate(deal.expected_payment_date) }}
        </span>
      </div>
    </div>

    <!-- Dialogs: rename, tags -->
    <Dialog
      v-model:visible="renameDialogVisible"
      :header="t('sales.deal.page.menu.rename')"
      modal
      style="width: 28rem"
    >
      <div class="deal-header__dialog-body">
        <InputText v-model="renameForm.title" fluid :placeholder="t('sales.deal.page.menu.rename')" />
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="renameDialogVisible = false" />
        <Button
          :label="t('common.save')"
          :loading="renameSaving"
          @click="submitRename"
        />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="tagsDialogVisible"
      :header="t('sales.deal.page.menu.editTags')"
      modal
      style="width: 28rem"
    >
      <div class="deal-header__dialog-body">
        <AutoComplete
          v-model="tagsForm.tags"
          multiple
          :suggestions="[]"
          fluid
          :placeholder="t('sales.deal.page.menu.editTags')"
          @complete="() => {}"
        />
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="tagsDialogVisible = false" />
        <Button
          :label="t('common.save')"
          :loading="tagsSaving"
          @click="submitTags"
        />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useRouter } from 'vue-router'
import Menu from 'primevue/menu'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import AutoComplete from 'primevue/autocomplete'
import DealStageTag from './DealStageTag.vue'
import DealStageProgressBar from './DealStageProgressBar.vue'
import DealHealthChip from './DealHealthChip.vue'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto, PipelineStageDto, NextTaskDto } from '@/entities/sales'

function formatDate(val: string | null | undefined): string {
  if (!val) return '—'
  const d = new Date(val)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

interface MenuUser {
  id: number
  name: string
}

const props = defineProps<{
  deal: DealDto
  stages: PipelineStageDto[]
  usersList: MenuUser[]
  daysInStage: number
  nextTask: NextTaskDto | null
}>()

const emit = defineEmits<{
  back: []
  openMoveDialog: []
  openMoveDialogWithStage: [stageId: number]
  dealUpdated: [deal: DealDto]
  dealDeleted: []
  dealArchived: []
  collapseAllGroups: []
  expandAllGroups: []
}>()

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()
const router = useRouter()

// ── Menu ────────────────────────────────────────────────────────────────────────
const menuRef = ref<InstanceType<typeof Menu> | null>(null)
const menuBtnRef = ref<HTMLElement | null>(null)

function toggleMenu(event: MouseEvent) {
  menuRef.value?.toggle(event)
}

function copyLink() {
  void navigator.clipboard.writeText(window.location.href)
  toast.add({ severity: 'success', summary: t('sales.deal.page.menu.copyLink'), life: 2000 })
}

const dealMenuItems = computed(() => [
  {
    label: t('sales.deal.page.menu.rename'),
    icon: 'pi pi-pencil',
    command: openRenameDialog,
  },
  {
    label: t('sales.deal.page.menu.editTags'),
    icon: 'pi pi-tag',
    command: openTagsDialog,
  },
  { separator: true },
  {
    label: t('sales.deal.page.menu.collapseAll'),
    icon: 'pi pi-arrows-v',
    command: () => emit('collapseAllGroups'),
  },
  {
    label: t('sales.deal.page.menu.expandAll'),
    icon: 'pi pi-arrows-v',
    command: () => emit('expandAllGroups'),
  },
  {
    label: t('sales.deal.page.menu.customizeFields'),
    icon: 'pi pi-cog',
    command: () => void router.push('/admin/custom-fields?scope=deal'),
  },
  {
    label: t('sales.deal.page.menu.copyLink'),
    icon: 'pi pi-link',
    command: copyLink,
  },
  { separator: true },
  {
    label: t('sales.deal.page.menu.delete'),
    icon: 'pi pi-trash',
    class: 'text-red-500',
    command: confirmDelete,
  },
])

// ── Rename dialog ────────────────────────────────────────────────────────────────
const renameDialogVisible = ref(false)
const renameForm = ref({ title: '' })
const renameMutation = useMutation<DealDto>()
const renameSaving = computed(() => renameMutation.isPending.value)

function openRenameDialog() {
  renameForm.value.title = props.deal.title
  renameDialogVisible.value = true
}

async function submitRename() {
  if (!renameForm.value.title.trim()) return
  try {
    const updated = await renameMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { title: renameForm.value.title.trim() }),
    )
    emit('dealUpdated', updated)
    renameDialogVisible.value = false
    toast.add({ severity: 'success', summary: t('sales.deal.page.menu.rename'), life: 3000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Tags dialog ──────────────────────────────────────────────────────────────────
const tagsDialogVisible = ref(false)
const tagsForm = ref<{ tags: string[] }>({ tags: [] })
const tagsMutation = useMutation<DealDto>()
const tagsSaving = computed(() => tagsMutation.isPending.value)

function openTagsDialog() {
  tagsForm.value.tags = [...(props.deal.tags ?? [])]
  tagsDialogVisible.value = true
}

async function submitTags() {
  try {
    const updated = await tagsMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { tags: tagsForm.value.tags }),
    )
    emit('dealUpdated', updated)
    tagsDialogVisible.value = false
    toast.add({ severity: 'success', summary: t('sales.deal.page.menu.editTags'), life: 3000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Segment click ────────────────────────────────────────────────────────────────
function onSegmentClick(stageId: number) {
  emit('openMoveDialogWithStage', stageId)
}

// ── Delete ────────────────────────────────────────────────────────────────────────
const deleteMutation = useMutation()

function confirmDelete() {
  confirm.require({
    header: t('sales.deal.page.menu.deleteConfirm'),
    message: t('sales.deal.page.menu.deleteDetail'),
    icon: 'pi pi-trash',
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await deleteMutation.run(() => salesApi.deleteDeal(props.deal.id))
        toast.add({ severity: 'success', summary: t('sales.deal.page.menu.delete'), life: 3000 })
        emit('dealDeleted')
        void router.push('/deals')
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    },
  })
}
</script>

<style lang="scss" scoped>
.deal-header {
  background: $brand-header-bg;
  padding: $space-3 $space-4 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  flex-shrink: 0;
}

.deal-header__top-row {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.deal-header__spacer {
  flex: 1;
}

.deal-header__id {
  font-size: $font-size-xs;
  color: rgba(255, 255, 255, 0.4);
  letter-spacing: 0.02em;
}

.deal-header__btn-icon {
  background: transparent;
  border: none;
  cursor: pointer;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  transition: background 0.15s;
  padding: 0;

  &:hover {
    background: rgba(255, 255, 255, 0.12);
  }

  i {
    font-size: 14px;
  }
}

.deal-header__title {
  color: #fff;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.35;
}

.deal-header__stage-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.deal-header__stage-btn {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 0;
  border-radius: $radius-sm;
  transition: opacity 0.15s;

  &:hover {
    opacity: 0.85;
  }
}

.deal-header__stage-chevron {
  font-size: 10px;
  color: rgba(255, 255, 255, 0.6);
}

.deal-header__progress {
  margin-top: $space-1;
}

.deal-header__days-hint {
  color: rgba(255, 255, 255, 0.6);
  font-size: $font-size-xs;
  margin: 0;
}

.deal-header__planned-dates {
  display: flex;
  align-items: stretch;
  gap: 0;
  margin-top: $space-1;
  border-radius: $radius-sm;
  background: rgba(255, 255, 255, 0.07);
  overflow: hidden;
}

.deal-header__planned-date-block {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: $space-2 $space-3;
}

.deal-header__planned-date-sep {
  width: 1px;
  background: rgba(255, 255, 255, 0.15);
  flex-shrink: 0;
}

.deal-header__planned-date-label {
  font-size: $font-size-xs;
  color: rgba(255, 255, 255, 0.5);
  letter-spacing: 0.02em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.deal-header__planned-date-value {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: #fff;

  &--empty {
    color: rgba(255, 255, 255, 0.35);
    font-weight: $font-weight-normal;
  }
}

.deal-header__dialog-body {
  padding: $space-2 0 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-3;
}
</style>
