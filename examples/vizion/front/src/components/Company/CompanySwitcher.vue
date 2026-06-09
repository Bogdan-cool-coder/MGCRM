<template>
  <div class="company-switcher" :class="{ 'company-switcher--compact': compact }">
    <Button
      v-tooltip="resolvedTooltipOptions"
      class="company-btn"
      :class="{ 'company-btn--compact': compact, 'dropdown-open': isOpen }"
      :aria-label="resolvedAriaLabel"
      :icon="compact ? 'pi pi-building' : undefined"
      text
      @click="emit('toggle-request', $event)"
    >
      <i v-if="!compact" class="company-icon pi pi-building" aria-hidden="true"></i>
      <span v-if="!compact" class="company-name">{{ getCurrentCompanyName }}</span>
      <i
        v-if="!compact"
        class="arrow pi pi-chevron-down"
        :class="{ 'arrow-rotated': isOpen }"
        aria-hidden="true"
      ></i>
    </Button>
    <Popover
      ref="popoverRef"
      append-to="body"
      :base-z-index="TOOLBOX_POPOVER_BASE_Z_INDEX"
      :dismissable="true"
      :pt="{
        root: { class: 'company-switcher__overlay' },
        content: { class: 'company-switcher__content' },
      }"
      @show="handleShow"
      @hide="handleHide"
    >
      <div class="dropdown-list">
        <div
          v-for="company in companyOptions"
          :key="company.id"
          class="dropdown-item"
          :class="{
            active: selectedCompanyId === company.id,
            'is-disabled': isSwitching,
          }"
          @click="selectCompany(company.id)"
        >
          <span>{{ company.name }}</span>
          <Tag v-if="company.isSystem" :value="t('switcherSystem')" size="small" />
        </div>
      </div>
      <div v-if="canOpenManagement" class="dropdown-footer">
        <Divider />
        <Button
          :label="t('switcherManage')"
          icon="pi pi-cog"
          severity="secondary"
          text
          class="manage-btn"
          @click="openManageCompanies"
        />
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import Popover from 'primevue/popover'
import Tooltip from 'primevue/tooltip'
import Tag from 'primevue/tag'
import Divider from 'primevue/divider'
import Button from 'primevue/button'
import { useUserStore } from '@/stores/user'
import { useCompaniesStore } from '@/stores/companies'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { canManageCompanies } from '@/shared/auth/capabilities'
import { TOOLBOX_POPOVER_BASE_Z_INDEX, type ToolboxOverlayControl } from '@/components/Toolbox'
import { notifyCompanySwitchError } from '@/application'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const { t } = useLocalI18n({ en, ru })
const vTooltip = Tooltip

type PopoverInstance = Pick<ToolboxOverlayControl, 'syncPopover'> & {
  toggle: (event: Event, target?: HTMLElement) => void
  hide: () => void
  /**
   * PrimeVue Popover public method — recomputes overlay position against the
   * stored `target`. Used by `realign()` to keep the popover attached to the
   * trigger button after the Toolbox is dragged or its placement changes.
   */
  alignOverlay: () => void
}

interface CompanyOption {
  id: number
  name: string
  isSystem?: boolean
}

interface TooltipOptions {
  value: string
  showDelay?: number
  hideDelay?: number
}

interface Props {
  compact?: boolean
  tooltipOptions?: TooltipOptions | null
}

const props = withDefaults(defineProps<Props>(), {
  compact: false,
  tooltipOptions: null,
})

const userStore = useUserStore()
const companiesStore = useCompaniesStore()
const companiesModalVisible = defineModel<boolean>('modalVisible')
const emit = defineEmits<{
  'toggle-request': [event: MouseEvent]
  'visibility-change': [visible: boolean]
}>()

const selectedCompanyId = computed(() => companiesStore.getActiveCompanyId)
const isSwitching = computed(() => companiesStore.getIsSwitching)
const companyOptions = computed<CompanyOption[]>(() =>
  companiesStore.getCompanies.map((company) => ({
    id: company.id,
    name: company.name,
    isSystem: company.is_system,
  })),
)
const canOpenManagement = computed(() => canManageCompanies(userStore.getUserRole))
const isOpen = ref(false)
const popoverRef = ref<PopoverInstance | null>(null)
const resolvedAriaLabel = computed(() => getCurrentCompanyName.value || t('switcherSelect'))
const resolvedTooltipOptions = computed(() =>
  props.compact ? (props.tooltipOptions ?? { value: resolvedAriaLabel.value }) : undefined,
)

const getCurrentCompanyName = computed(() => {
  if (!selectedCompanyId.value) return t('switcherSelect')
  const company = companyOptions.value.find((c) => c.id === selectedCompanyId.value)
  return company?.name || companiesStore.getCurrentCompany?.name || t('switcherFallback')
})

const selectCompany = async (id: number) => {
  if (isSwitching.value) {
    return
  }

  if (selectedCompanyId.value === id) {
    closePopover()
    return
  }

  closePopover()
  const result = await companiesStore.switchActiveCompany(id)

  // `in_progress` / `invalid_target` are silent guards — only the actual
  // backend failure surfaces a toast. Display strings live in the global
  // `companies.switch*` namespace and are resolved by notifyCompanySwitchError.
  if (!result.ok && result.reason === 'request_failed') {
    notifyCompanySwitchError(result.status, t('errors.serverError'))
  }
}

const togglePopover = (event: MouseEvent) => {
  popoverRef.value?.toggle(event)
}

const closePopover = () => {
  popoverRef.value?.hide()
}

const syncPopover = (open: boolean, event?: MouseEvent | null) => {
  if (open) {
    if (!isOpen.value && event) {
      togglePopover(event)
    }
    return
  }

  if (isOpen.value) {
    closePopover()
  }
}

const realign = () => {
  if (!isOpen.value) return
  popoverRef.value?.alignOverlay()
}

const handleShow = () => {
  isOpen.value = true
  emit('visibility-change', true)
}

const handleHide = () => {
  isOpen.value = false
  emit('visibility-change', false)
}

const openManageCompanies = () => {
  if (!canOpenManagement.value) {
    return
  }

  closePopover()
  if (companiesModalVisible) companiesModalVisible.value = true
}

defineExpose({
  syncPopover,
  realign,
})
</script>

<style lang="scss" scoped>
@use '@/components/Toolbox/styles/compact-control' as compact;

.company-switcher {
  position: relative;
  width: 100%;
}
.company-switcher--compact {
  width: auto;
}
.company-btn {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  min-height: 40px;
  border: 1px solid $surface-200;
  border-radius: $border-radius;
  background: transparent;
  color: $surface-700;
  padding: 0.375rem 0.5rem;
  box-shadow: none;
}
.company-btn--compact {
  @include compact.compact-control-button();
}
.company-btn:hover {
  border-color: $surface-400;
}
.company-btn--compact:deep(.p-button-label) {
  display: none;
}
.company-btn--compact:deep(.p-button-icon) {
  @include compact.compact-control-icon();
}
.company-icon {
  width: 32px;
  height: 32px;
  background: $primary;
  color: $monochrome-white;
  border-radius: 50%;
  display: flex;
  justify-content: center;
  align-items: center;
}
.company-btn--compact .company-icon {
  width: 1.25rem;
  height: 1.25rem;
  background: transparent;
  color: $surface-700;
}
.company-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.arrow {
  transition: transform $transition-fast;
}
.arrow-rotated {
  transform: rotate(180deg);
}
:deep(.company-switcher__overlay) {
  position: absolute;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  min-width: 200px;
  max-width: 100%;
  box-shadow: $shadow-md;
  max-height: 300px;
}
.company-switcher :deep(.company-switcher__content) {
  padding: 0;
  overflow-y: auto;
  max-height: 300px;
}
.dropdown-item {
  padding: $space-2 $space-4;
  display: flex;
  justify-content: space-between;
  cursor: pointer;
}
.dropdown-item.active {
  background-color: $blue-100;
}
.dropdown-item.is-disabled {
  opacity: 0.6;
  cursor: progress;
  pointer-events: none;
}
.dropdown-footer {
  padding: $space-2 0;
}
</style>
