<template>
  <div class="deal-header">
    <!-- ── Row 1: title + icon buttons ────────────────────────────────────────── -->
    <div class="deal-header__row1">
      <h2 class="deal-header__title">{{ deal.title }}</h2>
      <div class="deal-header__btns">
        <button class="deal-header__btn-icon" :aria-label="t('common.back')" @click="$emit('back')">
          <i class="pi pi-arrow-left" />
        </button>
        <button class="deal-header__btn-icon" :aria-label="t('sales.deal.page.menu.copyLink')" @click="toggleMenu">
          <i class="pi pi-ellipsis-v" />
        </button>
      </div>
    </div>

    <!-- ── Row 2: stage + category badge + health chip + spacer + N дн. ──────── -->
    <div class="deal-header__stage-row">
      <button
        class="deal-header__stage-btn"
        v-tooltip.bottom="t('sales.deal.page.clickToChangeStage')"
        @click="$emit('openMoveDialog')"
      >
        <DealStageTag :stage="deal.stage" />
      </button>

      <!-- Category badge (L/M/S from deal.category) -->
      <span v-if="categoryLabel" class="deal-header__category-badge">
        {{ categoryLabel }}
      </span>

      <!-- Health chip -->
      <DealHealthChip :next-task="nextTask" />

      <!-- Won/Lost status badge (V4) -->
      <span v-if="deal.stage.is_won" class="deal-header__status-badge deal-header__status-badge--won">
        <i class="pi pi-check-circle" />
        {{ t('sales.deal.page.wonStatus') }}
      </span>
      <span v-else-if="deal.stage.is_lost" class="deal-header__status-badge deal-header__status-badge--lost">
        <i class="pi pi-times-circle" />
        {{ t('sales.deal.page.lostStatus') }}
      </span>

      <span class="deal-header__spacer" />

      <!-- N дн. в стадии -->
      <span class="deal-header__days-hint">
        {{ daysInStage }} {{ t('sales.deal.page.daysInStage') }}
      </span>
    </div>

    <!-- ── Row 3: tag chips ────────────────────────────────────────────────────── -->
    <div v-if="deal.tags && deal.tags.length > 0" class="deal-header__tags-row">
      <span v-for="tag in deal.tags" :key="tag" class="deal-header__tag-chip">
        <i class="pi pi-tag deal-header__tag-icon" />
        {{ tag }}
      </span>
    </div>

    <!-- ── Stage progress bar ─────────────────────────────────────────────────── -->
    <DealStageProgressBar
      :stages="stages"
      :current-stage-id="deal.stage.id"
      class="deal-header__progress"
      @stage-click="onSegmentClick"
    />

    <!-- ── ⋮ Menu (PrimeVue Menu popup — etalon DealFeedItem/DealsToolbar) ─────── -->
    <Menu ref="menuRef" :model="menuItems" popup />

    <!-- ── Dialogs: rename, tags ─────────────────────────────────────────────── -->
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
          :suggestions="tagSuggestions"
          fluid
          :placeholder="t('sales.deal.page.menu.editTags')"
          @complete="onSearchDealTags"
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
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import AutoComplete from 'primevue/autocomplete'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'
import { Tooltip } from 'primevue'
import DealStageTag from './DealStageTag.vue'
import DealStageProgressBar from './DealStageProgressBar.vue'
import DealHealthChip from './DealHealthChip.vue'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import { getApiErrorMessage } from '@/utils/errors'
import { useDirectoriesStore } from '@/stores/directories'
import type { DealDto, PipelineStageDto, NextTaskDto, KeyActionType } from '@/entities/sales'

const vTooltip = Tooltip

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
  scrollToFeedType: [type: KeyActionType]
}>()

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()
const router = useRouter()
const directoriesStore = useDirectoriesStore()

// ── Category badge ───────────────────────────────────────────────────────────────

const categoryLabel = computed((): string => {
  const cat = props.deal.category
  if (!cat) return ''
  // S1 and S2 both display as 'S'
  if (cat === 'S1' || cat === 'S2') return 'S'
  return cat
})

// ── ⋮ Menu (PrimeVue Menu popup) ───────────────────────────────────────────────

const menuRef = ref<InstanceType<typeof Menu> | null>(null)

const menuItems = computed<MenuItem[]>(() => [
  { label: t('sales.deal.page.menu.copyLink'), icon: 'pi pi-link', command: copyLink },
  { separator: true },
  { label: t('sales.deal.page.menu.rename'), icon: 'pi pi-pencil', command: openRenameDialog },
  { label: t('sales.deal.page.menu.editTags'), icon: 'pi pi-tag', command: openTagsDialog },
  { separator: true },
  { label: t('sales.deal.page.menu.collapseAll'), icon: 'pi pi-arrows-v', command: onCollapseAll },
  { label: t('sales.deal.page.menu.expandAll'), icon: 'pi pi-arrows-v', command: onExpandAll },
  { separator: true },
  {
    label: t('sales.deal.page.menu.delete'),
    icon: 'pi pi-trash',
    class: 'deal-header__menu-item--danger',
    command: confirmDelete,
  },
])

function toggleMenu(event: MouseEvent) {
  menuRef.value?.toggle(event)
}

function copyLink() {
  void navigator.clipboard.writeText(window.location.href)
  toast.add({ severity: 'success', summary: t('sales.deal.page.menu.copyLink'), life: 2000 })
}

function openRenameDialog() {
  renameForm.value.title = props.deal.title
  renameDialogVisible.value = true
}

function openTagsDialog() {
  tagsForm.value.tags = [...(props.deal.tags ?? [])]
  tagsDialogVisible.value = true
}

function onCollapseAll() {
  emit('collapseAllGroups')
}

function onExpandAll() {
  emit('expandAllGroups')
}

// ── Rename dialog ─────────────────────────────────────────────────────────────────

const renameDialogVisible = ref(false)
const renameForm = ref({ title: '' })
const renameMutation = useMutation<DealDto>()
const renameSaving = computed(() => renameMutation.isPending.value)

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

// ── Tags dialog ───────────────────────────────────────────────────────────────────

const tagsDialogVisible = ref(false)
const tagsForm = ref<{ tags: string[] }>({ tags: [] })
const tagsMutation = useMutation<DealDto>()
const tagsSaving = computed(() => tagsMutation.isPending.value)
const tagSuggestions = ref<string[]>([])

function onSearchDealTags(event: { query: string }) {
  const q = event.query.toLowerCase()
  const dealTags = directoriesStore.getTagsForScope('deal')
  tagSuggestions.value = dealTags
    .map((t) => t.name)
    .filter((name) => name.toLowerCase().includes(q) && !tagsForm.value.tags.includes(name))
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

// ── Segment click → open move dialog with stage ───────────────────────────────────

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
    acceptLabel: t('common.delete'),
    rejectLabel: t('common.cancel'),
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
// ── Container ──────────────────────────────────────────────────────────────────
.deal-header {
  // Brand chrome: navy #172747 (light) → darkens to #111E38 (dark) via token,
  // per MSales 2.0 navy repaint. Do NOT pin the raw light hex here.
  background: $brand-header-bg;
  padding: 14px $space-4 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  flex-shrink: 0;
}

// ── Row 1: h2 + icon buttons ──────────────────────────────────────────────────
.deal-header__row1 {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
}

.deal-header__title {
  flex: 1;
  color: $sidebar-text-active;
  font-size: 18px; // stylelint-disable-line scale-unlimited/declaration-strict-value
  font-weight: $font-weight-semibold;
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.35;
}

.deal-header__btns {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}

.deal-header__btn-icon {
  background: transparent;
  border: none;
  cursor: pointer;
  color: $sidebar-text-active;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  transition: background 0.15s;
  padding: 0;

  &:hover {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(255, 255, 255, 0.12); // decorative alpha tint on brand navy header
  }

  i {
    font-size: $font-size-sm;
  }
}

// ── Row 2: stage + badges + spacer + days ─────────────────────────────────────
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

.deal-header__category-badge {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: #c0392b; // --mg-red-600 brand invariant
  color: $sidebar-text-active;
  font-size: $font-size-3xs;
  font-weight: $font-weight-bold;
  border-radius: $radius-sm;
  padding: 2px 5px;
  line-height: 1.4;
  white-space: nowrap;
}

.deal-header__spacer {
  flex: 1;
}

// ── Won/Lost status badge (V4) ────────────────────────────────────────────────
.deal-header__status-badge {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  border-radius: $radius-sm;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2px 7px;

  &--won {
    color: var(--p-green-700);
    background: var(--p-green-50);
    border: 1px solid var(--p-green-200);

    .app-dark & {
      color: var(--p-green-300);
      background: var(--p-green-950);
      border-color: var(--p-green-800);
    }
  }

  &--lost {
    color: var(--p-red-700);
    background: var(--p-red-50);
    border: 1px solid var(--p-red-200);

    .app-dark & {
      color: var(--p-red-300);
      background: var(--p-red-950);
      border-color: var(--p-red-800);
    }
  }
}

.deal-header__days-hint {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.5); // alpha overlay on brand navy — no token
  font-size: $font-size-xs;
  white-space: nowrap;
}

// ── Row 3: tag chips ──────────────────────────────────────────────────────────
.deal-header__tags-row {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
}

.deal-header__tag-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: rgba(255, 255, 255, 0.12); // decorative alpha tint on brand navy
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.9); // brand nav overlay
  font-size: $font-size-xs;
  padding: 2px 8px;
  border-radius: $radius-pill;
}

.deal-header__tag-icon {
  font-size: $font-size-3xs;
}

// ── Progress bar ──────────────────────────────────────────────────────────────
.deal-header__progress {
  margin-top: $space-1;
}

// ── Dialog body ───────────────────────────────────────────────────────────────
.deal-header__dialog-body {
  padding: $space-2 0 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-3;
}
</style>

<!-- Danger tint for the delete item in the teleported PrimeVue Menu popup.
     The overlay renders outside this scoped subtree (appendTo body), so the
     danger colour is applied via a non-scoped rule keyed on the item class. -->
<style lang="scss">
.p-menu .deal-header__menu-item--danger .p-menu-item-link {
  color: var(--p-red-500);

  .p-menu-item-icon {
    color: var(--p-red-500);
  }

  .app-dark & {
    color: var(--p-red-400);

    .p-menu-item-icon {
      color: var(--p-red-400);
    }
  }
}
</style>
