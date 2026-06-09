<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('library.title')"
    :closable="true"
    class="widget-library-modal"
    :breakpoints="{ '960px': '92vw' }"
    :style="{ width: '640px' }"
    @update:visible="onVisibleChange"
    @show="onShow"
    @hide="onHide"
  >
    <div class="widget-library">
      <LoadingState v-if="isLoading" />

      <template v-else-if="hasAny">
        <WidgetLibrarySection
          v-if="systemWidgets.length > 0"
          :title="t('library.sections.system')"
          :items="systemWidgets"
          :preview="preview"
          @pick="pick"
          @delete="onDeleteRequest"
        />
        <WidgetLibrarySection
          v-if="publishedWidgets.length > 0"
          :title="t('library.sections.published')"
          :items="publishedWidgets"
          :preview="preview"
          @pick="pick"
          @delete="onDeleteRequest"
        />
        <WidgetLibrarySection
          v-if="personalWidgets.length > 0"
          :title="t('library.sections.personal')"
          :items="personalWidgets"
          :preview="preview"
          @pick="pick"
          @delete="onDeleteRequest"
        />
      </template>

      <EmptyState v-else :message="t('library.empty')" icon="pi pi-chart-pie" />
    </div>

    <template #footer>
      <Button
        v-if="canCreate"
        icon="pi pi-plus"
        :label="t('library.createWidget')"
        text
        @click="emit('create-widget')"
      />
    </template>
  </Dialog>

  <DeleteConfirmModal
    v-model:visible="deleteModalVisible"
    :title="t('library.delete_confirm_title')"
    :item-name="deleteTargetName"
    :warning-text="deleteWarningText"
    :loading="isDeleting"
    :confirm-label="t('library.delete')"
    @cancel="onDeleteCancel"
    @confirm="onDeleteConfirm"
  />
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import WidgetLibrarySection from './WidgetLibrarySection.vue'
import DeleteConfirmModal from '@/components/modals/DeleteConfirmModal.vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useUserStore } from '@/stores/user'
import { getLocalizedText } from '@/utils/localization'
import { canManageWidgets } from '@/shared/auth/capabilities'
import { useWidgetPreviewData } from '../composables/useWidgetPreviewData'
import type { WidgetChartType, WidgetListItem } from '@/entities/widget'
import en from './locale/en.json'
import ru from './locale/ru.json'

export interface LocalizedWidgetItem extends WidgetListItem {
  localizedName: string
  /** Chart kind resolved from `config.chart.type` (defaults to `bar`). */
  chartType: WidgetChartType
}

interface Props {
  visible: boolean
  /** Widget ids already attached to this dashboard — disabled in the picker. */
  attachedWidgetIds: number[]
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  pick: [widgetId: number]
  'create-widget': []
}>()

const { t, locale } = useLocalI18n({ en, ru })
const { widgetService } = useServices()
const { notifyApiError, notifySuccess } = useNotifications()
const userStore = useUserStore()

const widgets = ref<WidgetListItem[]>([])
const isLoading = ref(false)

// Short toast lifetime — the library modal sits over the dashboard; a sticky
// success toast would otherwise linger after the modal closes.
const TOAST_LIFE_MS = 3000

/**
 * Per-widget preview-data cache, shared down to every section/card so each
 * widget's `/data` is fetched at most once per modal open. Reset on hide.
 */
const preview = useWidgetPreviewData()

const VALID_CHART_TYPES: readonly WidgetChartType[] = ['bar', 'line', 'pie', 'doughnut']

const resolveChartType = (item: WidgetListItem): WidgetChartType => {
  const type = item.config?.chart?.type
  return VALID_CHART_TYPES.includes(type as WidgetChartType)
    ? (type as WidgetChartType)
    : 'bar'
}

const canCreate = computed(() => canManageWidgets(userStore.getUserRole))

const loadWidgets = async () => {
  isLoading.value = true
  try {
    widgets.value = await widgetService.fetchAllWidgets()
  } catch (error) {
    notifyApiError(error, t('library.loadFailed'), t('common.error'))
    widgets.value = []
  } finally {
    isLoading.value = false
  }
}

const attachedSet = computed(() => new Set(props.attachedWidgetIds))

const localize = (items: WidgetListItem[]): LocalizedWidgetItem[] =>
  items
    .filter((w) => !attachedSet.value.has(w.id))
    .map((w) => ({
      ...w,
      localizedName: getLocalizedText(w.name, locale.value),
      chartType: resolveChartType(w),
    }))

const systemWidgets = computed(() => localize(widgets.value.filter((w) => w.isSystem)))
const publishedWidgets = computed(() =>
  localize(widgets.value.filter((w) => !w.isSystem && w.isPublished)),
)
const personalWidgets = computed(() =>
  localize(widgets.value.filter((w) => !w.isSystem && !w.isPublished)),
)

const hasAny = computed(
  () =>
    systemWidgets.value.length +
      publishedWidgets.value.length +
      personalWidgets.value.length >
    0,
)

const pick = (widgetId: number) => {
  emit('pick', widgetId)
}

// ── Delete a widget from the library ────────────────────────────────────────
// The card gates visibility of its three-dots menu (own / admin / superadmin,
// never system) — here we own the confirm dialog, the cascade delete and the
// list refresh. We always send `?force=true` so the widget is detached from
// every dashboard it's pinned to; the 409 branch is a defensive race guard.
const deleteTarget = ref<LocalizedWidgetItem | null>(null)
const deleteModalVisible = ref(false)
const isDeleting = ref(false)

const deleteTargetName = computed(() => deleteTarget.value?.localizedName ?? '')

const deleteWarningText = computed(() => {
  const count = deleteTarget.value?.usedInDashboardsCount ?? 0
  if (count > 0) {
    return t('library.delete_confirm_message_in_use', count, { named: { count } })
  }
  return t('library.delete_confirm_message')
})

const onDeleteRequest = (item: LocalizedWidgetItem) => {
  if (isDeleting.value) return
  deleteTarget.value = item
  deleteModalVisible.value = true
}

const onDeleteCancel = () => {
  if (isDeleting.value) return
  deleteModalVisible.value = false
  deleteTarget.value = null
}

const onDeleteConfirm = async () => {
  const target = deleteTarget.value
  if (!target || isDeleting.value) return
  isDeleting.value = true
  try {
    await widgetService.deleteWidget(target.id, { force: true })
    // Remove the card from the list. Its preview-cache entry becomes orphaned
    // but harmless (the card unmounts; the cache is cleared on modal close).
    widgets.value = widgets.value.filter((w) => w.id !== target.id)
    notifySuccess(t('library.toast_deleted'), undefined, TOAST_LIFE_MS)
    deleteModalVisible.value = false
    deleteTarget.value = null
  } catch (error) {
    // With `force=true` a 409 (still-referenced) should not occur, but a
    // concurrent attach could race it. Either way we surface the backend
    // message and keep the card in the list.
    notifyApiError(error, t('library.toast_error'), t('common.error'))
  } finally {
    isDeleting.value = false
  }
}

const onShow = () => {
  void loadWidgets()
}

const onHide = () => {
  preview.clear()
}

const onVisibleChange = (value: boolean) => {
  emit('update:visible', value)
}

defineExpose({ loadWidgets })
</script>

<style lang="scss" scoped>
.widget-library {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-height: 12rem;
}
</style>
