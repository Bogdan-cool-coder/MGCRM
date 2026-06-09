<template>
  <div class="profile-menu" :class="{ 'profile-menu--compact': compact }">
    <Button
      v-tooltip="resolvedTooltipOptions"
      class="profile-btn"
      :class="{ 'profile-btn--compact': compact }"
      :aria-label="tooltipLabel"
      text
      @click="emit('toggle-request', $event)"
    >
      <!-- Compact (Toolbox) mode shows a settings gear glyph; the expanded
           profile menu keeps the user's initial avatar. Behaviour / menu
           contents are unchanged — only the glyph differs. -->
      <span class="avatar">
        <i v-if="compact" class="pi pi-cog" aria-hidden="true"></i>
        <template v-else>{{ userInitial }}</template>
      </span>
      <span v-if="!compact" class="name">{{ user?.name ?? '...' }}</span>
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
        root: { class: 'profile-menu__overlay' },
        content: { class: 'profile-menu__content' },
      }"
      @show="handleShow"
      @hide="handleHide"
    >
      <div class="dropdown-header">
        <div class="user-info">
          <div class="name">{{ user?.name ?? '—' }}</div>
          <div class="email">{{ user?.email ?? '—' }}</div>
        </div>
      </div>
      <div class="dropdown-item role">
        <span class="label">{{ t('role') }}:</span>
        <span class="value">{{ user?.role ? t(`roles.${user.role}`) : '—' }}</span>
      </div>
      <hr class="divider" />
      <div class="dropdown-item language">
        <span class="label">{{ t('language') }}:</span>
        <Select
          v-model="selectedLocale"
          :options="localeOptions"
          option-label="label"
          option-value="value"
          append-to="self"
          :disabled="isChangingLocale"
          size="small"
          class="locale-select"
        />
      </div>
      <hr class="divider" />
      <button class="dropdown-item dropdown-action logout" @click="logout">
        <span class="dropdown-action__content">
          <i class="pi pi-sign-out"></i>
          <span>{{ t('logout') }}</span>
        </span>
      </button>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import Tooltip from 'primevue/tooltip'
import Select from 'primevue/select'
import { useApplicationServices } from '@/application'
import { useUserStore } from '@/stores/user'
import { useRouter } from 'vue-router'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { localeManager } from '@/composables/useLocaleManager'
import { resetAuthenticatedSessionState } from '@/application'
import { AVAILABLE_LOCALES, type AvailableLocales } from '@/plugins/i18n'
import { TOOLBOX_POPOVER_BASE_Z_INDEX, type ToolboxOverlayControl } from '@/components/Toolbox'
import en from './locale/en.json'
import ru from './locale/ru.json'

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

const { t, locale } = useLocalI18n({ en, ru })
const userStore = useUserStore()
const { userSessionService } = useApplicationServices()
const router = useRouter()
const { changeLocaleAndSync } = localeManager
const emit = defineEmits<{
  'toggle-request': [event: MouseEvent]
  'visibility-change': [visible: boolean]
}>()

const user = computed(() => userStore.getUser)
const isOpen = ref(false)
const isChangingLocale = ref(false)
const popoverRef = ref<PopoverInstance | null>(null)
const tooltipLabel = computed(() => user.value?.name ?? 'User')
const resolvedTooltipOptions = computed(() =>
  props.compact ? (props.tooltipOptions ?? { value: tooltipLabel.value }) : undefined,
)
const localeOptions = AVAILABLE_LOCALES.map((value) => ({
  value,
  label: value === 'ru' ? 'Русский' : 'English',
}))

// Close dropdown on route change (e.g. after logout)
watch(
  () => router.currentRoute.value.fullPath,
  () => {
    closePopover()
  },
)

// Two-way computed for locale selector
const selectedLocale = computed({
  get: () => locale.value as AvailableLocales,
  set: async (val) => {
    if (isChangingLocale.value) return
    isChangingLocale.value = true
    try {
      await changeLocaleAndSync(val)
      closePopover()
    } finally {
      isChangingLocale.value = false
    }
  },
})

const userInitial = computed(() => {
  if (!user.value) return 'U'
  return user.value.name?.charAt(0).toUpperCase() || 'U'
})

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

const logout = async () => {
  try {
    await userSessionService.logout()
  } finally {
    // Always run cleanup — even if logout API fails (500 / network error)
    resetAuthenticatedSessionState({ clearIframeToken: true })
    await router.push('/login')
  }
}

defineExpose({
  syncPopover,
  realign,
})
</script>

<style lang="scss" scoped>
@use '@/components/Toolbox/styles/compact-control' as compact;

.profile-menu {
  position: relative;
  width: 100%;
}
.profile-menu--compact {
  width: auto;
}
.profile-btn {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  min-height: 40px;
  border: 1px solid $surface-200;
  border-radius: $border-radius;
  background: transparent;
  color: $surface-800;
  padding: 0.375rem 0.5rem;
  box-shadow: none;
  transition: border-color $transition-fast;
}
.profile-btn--compact {
  @include compact.compact-control-button();
}
.profile-btn:hover {
  border-color: $surface-400;
}
.avatar {
  width: 32px;
  height: 32px;
  min-width: 32px;
  min-height: 32px;
  display: flex;
  justify-content: center;
  align-items: center;
  flex-shrink: 0;
  aspect-ratio: 1;
  background: $primary;
  color: $monochrome-white;
  border-radius: 50%;
  font-weight: $font-weight-semibold;
  font-size: $font-size-sm;
}
.profile-btn--compact .avatar {
  @include compact.compact-control-avatar(1.75rem, $font-size-xs);
}
.name {
  font-size: $font-size-sm;
  color: $surface-800;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.arrow {
  transition: transform $transition-fast;
  font-size: $font-size-xs;
  color: $surface-600;
}
.arrow-rotated {
  transform: rotate(180deg);
}
:deep(.profile-menu__overlay) {
  position: absolute;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-md;
  min-width: 200px;
  max-width: 100%;
  max-height: 400px;
}
.profile-menu :deep(.profile-menu__content) {
  padding: 0;
  overflow-y: auto;
  max-height: 400px;
}
.dropdown-header {
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
}
.user-info .name {
  font-weight: $font-weight-semibold;
  color: $surface-800;
}
.user-info .email {
  font-size: $font-size-xs;
  color: $surface-600;
  margin-top: 0.125rem;
}
.dropdown-item {
  padding: $space-2 $space-4;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  border: none;
  background: transparent;
  font: inherit;
  color: inherit;
}
.dropdown-item.role .label {
  color: $surface-600;
}
.dropdown-item.role .value {
  color: $surface-800;
  text-transform: capitalize;
}
.divider {
  margin: 0;
  border-top: 1px solid $surface-200;
}
.dropdown-action {
  transition:
    background-color $transition-fast,
    color $transition-fast;

  &__content {
    display: inline-flex;
    align-items: center;
    gap: $space-2;
  }
}
.dropdown-action:hover {
  background-color: $surface-100;
}
.dropdown-item.logout {
  justify-content: center;
  color: $surface-700;
}
.dropdown-item.logout:hover {
  color: $danger;
  background-color: $errorBg;
}
.dropdown-item.language {
  display: flex;
  align-items: center;
  gap: $space-2;
  .label {
    color: $surface-600;
  }
}
.locale-select {
  min-width: 8rem;

  :deep(.p-select-label) {
    font-size: $font-size-sm;
  }

  :deep(.p-select-overlay) {
    min-width: 100%;
  }
}
</style>
