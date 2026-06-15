<template>
  <div class="deal-header">
    <!-- Top row: back btn + menu btn -->
    <div class="deal-header__top-row">
      <button class="deal-header__btn-icon" @click="$emit('back')">
        <i class="pi pi-arrow-left" />
      </button>
      <div class="deal-header__spacer" />
      <button ref="menuBtnRef" class="deal-header__btn-icon" @click="toggleMenu">
        <i class="pi pi-ellipsis-v" />
      </button>
      <Menu ref="menuRef" :model="dealMenuItems" popup />
    </div>

    <!-- Title -->
    <h2 class="deal-header__title">{{ deal.title }}</h2>

    <!-- Subtitle: #id · company -->
    <p class="deal-header__subtitle">#{{ deal.id }} · {{ deal.company.name }}</p>

    <!-- Stage tag -->
    <div class="deal-header__stage-row">
      <DealStageTag :stage="deal.stage" />
    </div>

    <!-- Progress bar -->
    <DealStageProgressBar
      :stages="stages"
      :current-stage-id="deal.stage.id"
      class="deal-header__progress"
      @stage-click="onSegmentClick"
    />

    <!-- Days in stage info -->
    <p class="deal-header__days-hint">
      {{ deal.stage.name }} · {{ daysInStage }} {{ t('sales.deal.page.daysInStage') }}
    </p>

    <!-- Change stage button -->
    <button class="deal-header__change-stage-btn" @click="$emit('openMoveDialog')">
      <i class="pi pi-arrows-h" />
      {{ t('sales.deal.page.changeStage') }}
    </button>

    <!-- Dialogs: rename, owner, tags -->
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
      v-model:visible="ownerDialogVisible"
      :header="t('sales.deal.page.menu.changeOwner')"
      modal
      style="width: 28rem"
    >
      <div class="deal-header__dialog-body">
        <Select
          v-model="ownerForm.owner_user_id"
          :options="usersList"
          option-label="name"
          option-value="id"
          fluid
          :placeholder="t('sales.deal.info.fields.owner')"
        />
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="ownerDialogVisible = false" />
        <Button
          :label="t('common.save')"
          :loading="ownerSaving"
          @click="submitOwner"
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
import Select from 'primevue/select'
import AutoComplete from 'primevue/autocomplete'
import DealStageTag from './DealStageTag.vue'
import DealStageProgressBar from './DealStageProgressBar.vue'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto, PipelineStageDto } from '@/entities/sales'

interface MenuUser {
  id: number
  name: string
}

const props = defineProps<{
  deal: DealDto
  stages: PipelineStageDto[]
  usersList: MenuUser[]
  daysInStage: number
}>()

const emit = defineEmits<{
  back: []
  openMoveDialog: []
  openMoveDialogWithStage: [stageId: number]
  dealUpdated: [deal: DealDto]
  dealDeleted: []
  dealArchived: []
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

const dealMenuItems = computed(() => [
  {
    label: t('sales.deal.page.menu.rename'),
    icon: 'pi pi-pencil',
    command: openRenameDialog,
  },
  {
    label: t('sales.deal.page.menu.changeOwner'),
    icon: 'pi pi-user',
    command: openOwnerDialog,
  },
  {
    label: t('sales.deal.page.menu.editTags'),
    icon: 'pi pi-tag',
    command: openTagsDialog,
  },
  {
    label: t('sales.deal.page.menu.moveStage'),
    icon: 'pi pi-arrows-h',
    command: () => emit('openMoveDialog'),
  },
  { separator: true },
  {
    label: t('sales.deal.page.menu.duplicate'),
    icon: 'pi pi-copy',
    disabled: true,
    tooltip: t('sales.deal.page.menu.duplicateSoon'),
  },
  { separator: true },
  {
    label: t('sales.deal.page.menu.archive'),
    icon: 'pi pi-inbox',
    command: confirmArchive,
  },
  {
    label: t('sales.deal.page.menu.delete'),
    icon: 'pi pi-trash',
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

// ── Owner dialog ─────────────────────────────────────────────────────────────────
const ownerDialogVisible = ref(false)
const ownerForm = ref<{ owner_user_id: number | null }>({ owner_user_id: null })
const ownerMutation = useMutation<DealDto>()
const ownerSaving = computed(() => ownerMutation.isPending.value)

function openOwnerDialog() {
  ownerForm.value.owner_user_id = props.deal.owner.id
  ownerDialogVisible.value = true
}

async function submitOwner() {
  if (!ownerForm.value.owner_user_id) return
  try {
    const updated = await ownerMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { owner_user_id: ownerForm.value.owner_user_id! }),
    )
    emit('dealUpdated', updated)
    ownerDialogVisible.value = false
    toast.add({ severity: 'success', summary: t('sales.deal.page.menu.changeOwner'), life: 3000 })
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

// ── Archive ───────────────────────────────────────────────────────────────────────
const archiveMutation = useMutation<DealDto>()

function confirmArchive() {
  confirm.require({
    header: t('sales.deal.page.menu.archiveConfirm'),
    message: '',
    icon: 'pi pi-inbox',
    acceptClass: 'p-button-warning',
    accept: async () => {
      try {
        await archiveMutation.run(() => salesApi.archiveDeal(props.deal.id))
        toast.add({ severity: 'success', summary: t('sales.deal.page.menu.archive'), life: 3000 })
        emit('dealArchived')
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
  background: #172747;
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

.deal-header__subtitle {
  color: rgba(255, 255, 255, 0.6);
  font-size: $font-size-xs;
  margin: 0;
}

.deal-header__stage-row {
  display: flex;
  align-items: center;
}

.deal-header__progress {
  margin-top: $space-1;
}

.deal-header__days-hint {
  color: rgba(255, 255, 255, 0.6);
  font-size: $font-size-xs;
  margin: 0;
}

.deal-header__change-stage-btn {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  background: transparent;
  border: 1px solid rgba(255, 255, 255, 0.6);
  color: #fff;
  cursor: pointer;
  border-radius: $radius-sm;
  padding: 4px 10px;
  font-size: $font-size-xs;
  transition: border-color 0.15s, background 0.15s;
  width: fit-content;

  &:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: #fff;
  }
}

.deal-header__dialog-body {
  padding: $space-2 0 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-3;
}
</style>
