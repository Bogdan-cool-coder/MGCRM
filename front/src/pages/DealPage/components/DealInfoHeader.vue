<template>
  <div class="deal-header">
    <!-- ── Row 1: title + icon buttons ────────────────────────────────────────── -->
    <div class="deal-header__row1">
      <h2 class="deal-header__title">{{ deal.title }}</h2>
      <div class="deal-header__btns">
        <button class="deal-header__btn-icon" :aria-label="t('common.back')" @click="$emit('back')">
          <i class="pi pi-arrow-left" />
        </button>
        <button ref="menuBtnRef" class="deal-header__btn-icon" :aria-label="t('sales.deal.page.menu.copyLink')" @click="toggleMenu">
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

    <!-- ── ⋮ Menu popover (opens RIGHT of button) ─────────────────────────────── -->
    <Teleport to="body">
      <Transition name="deal-menu-fade">
        <div
          v-if="menuOpen"
          ref="menuPanelRef"
          class="deal-header__menu-panel"
          :style="menuPanelStyle"
          role="menu"
          @click.stop
        >
          <button class="deal-header__menu-item" role="menuitem" @click="copyLink">
            <i class="pi pi-link deal-header__menu-icon" />
            {{ t('sales.deal.page.menu.copyLink') }}
          </button>
          <div class="deal-header__menu-sep" />
          <button class="deal-header__menu-item" role="menuitem" @click="openRenameDialog">
            <i class="pi pi-pencil deal-header__menu-icon" />
            {{ t('sales.deal.page.menu.rename') }}
          </button>
          <button class="deal-header__menu-item" role="menuitem" @click="openTagsDialog">
            <i class="pi pi-tag deal-header__menu-icon" />
            {{ t('sales.deal.page.menu.editTags') }}
          </button>
          <div class="deal-header__menu-sep" />
          <button class="deal-header__menu-item" role="menuitem" @click="onCollapseAll">
            <i class="pi pi-arrows-v deal-header__menu-icon" />
            {{ t('sales.deal.page.menu.collapseAll') }}
          </button>
          <button class="deal-header__menu-item" role="menuitem" @click="onExpandAll">
            <i class="pi pi-arrows-v deal-header__menu-icon" />
            {{ t('sales.deal.page.menu.expandAll') }}
          </button>
          <div class="deal-header__menu-sep" />
          <button class="deal-header__menu-item deal-header__menu-item--danger" role="menuitem" @click="confirmDelete">
            <i class="pi pi-trash deal-header__menu-icon" />
            {{ t('sales.deal.page.menu.delete') }}
          </button>
        </div>
      </Transition>
    </Teleport>

    <!-- Backdrop to close menu -->
    <Teleport to="body">
      <div v-if="menuOpen" class="deal-header__menu-backdrop" @click="menuOpen = false" />
    </Teleport>

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
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useRouter } from 'vue-router'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import AutoComplete from 'primevue/autocomplete'
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

// ── Right-positioned menu popover ───────────────────────────────────────────────

const menuOpen = ref(false)
const menuBtnRef = ref<HTMLElement | null>(null)
const menuPanelRef = ref<HTMLElement | null>(null)

const menuPanelStyle = ref<Record<string, string>>({})

async function toggleMenu() {
  menuOpen.value = !menuOpen.value
  if (menuOpen.value) {
    await nextTick()
    positionMenu()
  }
}

function positionMenu() {
  const btn = menuBtnRef.value
  if (!btn) return
  const rect = btn.getBoundingClientRect()
  // Position panel to the right of the button, aligned to top
  menuPanelStyle.value = {
    position: 'fixed',
    top: `${rect.top}px`,
    left: `${rect.right + 6}px`,
    zIndex: '9999',
  }
}

function onKeyDown(e: KeyboardEvent) {
  if (e.key === 'Escape' && menuOpen.value) {
    menuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('keydown', onKeyDown)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeyDown)
})

function copyLink() {
  void navigator.clipboard.writeText(window.location.href)
  toast.add({ severity: 'success', summary: t('sales.deal.page.menu.copyLink'), life: 2000 })
  menuOpen.value = false
}

function openRenameDialog() {
  renameForm.value.title = props.deal.title
  renameDialogVisible.value = true
  menuOpen.value = false
}

function openTagsDialog() {
  tagsForm.value.tags = [...(props.deal.tags ?? [])]
  tagsDialogVisible.value = true
  menuOpen.value = false
}

function onCollapseAll() {
  emit('collapseAllGroups')
  menuOpen.value = false
}

function onExpandAll() {
  emit('expandAllGroups')
  menuOpen.value = false
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
  menuOpen.value = false
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
// ── Container ──────────────────────────────────────────────────────────────────
.deal-header {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: #172747; // brand invariant navy
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

// ── Menu panel (Teleport, right-side popover) ─────────────────────────────────
.deal-header__menu-panel {
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  min-width: 210px;
  padding: $space-1;
  display: flex;
  flex-direction: column;
  gap: 1px;

  .app-dark & {
    border-color: var(--p-surface-600);
  }
}

.deal-header__menu-backdrop {
  position: fixed;
  inset: 0;
  z-index: 9998;
}

.deal-header__menu-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 7px $space-3;
  border-radius: $radius-sm;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: var(--p-text-color);
  text-align: left;
  transition: background 0.12s;

  &:hover {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-700);
    }
  }

  &--danger {
    color: var(--p-red-500);

    .app-dark & {
      color: var(--p-red-400);
    }
  }
}

.deal-header__menu-icon {
  font-size: $font-size-sm;
  flex-shrink: 0;
  color: $surface-500;

  .deal-header__menu-item--danger & {
    color: var(--p-red-500);

    .app-dark & {
      color: var(--p-red-400);
    }
  }
}

.deal-header__menu-sep {
  height: 1px;
  background: var(--p-surface-200);
  margin: $space-1 0;

  .app-dark & {
    background: var(--p-surface-700);
  }
}

// ── Dialog body ───────────────────────────────────────────────────────────────
.deal-header__dialog-body {
  padding: $space-2 0 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

// ── Fade transition ───────────────────────────────────────────────────────────
.deal-menu-fade-enter-active,
.deal-menu-fade-leave-active {
  transition: opacity 0.12s, transform 0.12s;
}

.deal-menu-fade-enter-from,
.deal-menu-fade-leave-to {
  opacity: 0;
  transform: translateX(-4px);
}
</style>
