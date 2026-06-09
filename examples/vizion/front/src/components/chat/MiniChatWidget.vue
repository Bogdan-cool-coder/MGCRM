<template>
  <div class="mini-chat" :class="{ 'mini-chat--compact': compact }">
    <Button
      ref="triggerRef"
      v-tooltip="resolvedTooltipOptions"
      class="mini-chat__btn"
      :class="{ 'mini-chat__btn--compact': compact, 'is-open': isOpen }"
      :aria-label="tooltipLabel"
      icon="pi pi-comments"
      text
      @click="emit('toggle-request', $event)"
    />

    <Popover
      ref="popoverRef"
      append-to="body"
      :base-z-index="TOOLBOX_POPOVER_BASE_Z_INDEX"
      :dismissable="true"
      :pt="{
        root: { class: 'mini-chat__overlay' },
        content: { class: 'mini-chat__content' },
      }"
      @show="handleShow"
      @hide="handleHide"
    >
      <div class="mini-chat__panel" @click.stop>
        <header class="mini-chat__header">
          <MiniChatHeaderDropdown
            class="mini-chat__dropdown"
            :items="dropdownItems"
            :current-id="currentChat?.id ?? null"
            :trigger-label="triggerLabel"
            :is-preview="isPreview"
            :is-loading="isLoadingDropdown"
            :disabled="isInitializing"
            @select="handleSelectChat"
            @new-chat="handleNewChat"
          />

          <div class="mini-chat__actions">
            <Button
              v-tooltip.bottom="expandTooltipText"
              icon="pi pi-external-link"
              text
              rounded
              size="small"
              :aria-label="t('miniChat.openInFullScreen')"
              :disabled="isExpandDisabled"
              @click="openInFullScreen"
            />
            <Button
              v-tooltip.bottom="t('miniChat.close')"
              icon="pi pi-times"
              text
              rounded
              size="small"
              :aria-label="t('miniChat.close')"
              @click="closePopover"
            />
          </div>
        </header>

        <div
          v-if="hasAnyContext"
          class="mini-chat__context"
          :title="contextHint"
        >
          <i class="pi pi-paperclip" aria-hidden="true" />
          <span class="mini-chat__context-label">
            {{ t('miniChat.contextBadge', { title: contextTitle }) }}
          </span>
        </div>

        <div class="mini-chat__body">
          <LoadingState v-if="isInitializing || isLoadingChat" />

          <EmptyState
            v-else-if="isPreview"
            icon="pi pi-sparkles"
            :message="previewEmptyMessage"
          />

          <EmptyState
            v-else-if="!currentChat || currentChat.messages.length === 0"
            icon="pi pi-sparkles"
            :message="existingEmptyMessage"
          />

          <ChatMessageList
            v-else
            :messages="currentChat.messages"
            :is-sending="isSending"
            enable-action-marker
            @action="handleActionMarker"
          />
        </div>

        <ChatInput
          :disabled="isSending || isInitializing"
          :placeholder="placeholderText"
          @submit="handleSubmit"
        />
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import type { ComponentPublicInstance } from 'vue'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import Tooltip from 'primevue/tooltip'
import { useMiniChat } from './composables/useMiniChat'
import { useChatActionMarker } from './composables/useChatActionMarker'
import ChatInput from './ChatInput.vue'
import ChatMessageList from './ChatMessageList.vue'
import MiniChatHeaderDropdown from './MiniChatHeaderDropdown.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import LoadingState from '@/components/states/LoadingState.vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useReportContextStore } from '@/stores/reportContext'
import { useDashboardContextStore } from '@/stores/dashboardContext'
import { useDocumentContextStore } from '@/stores/documentContext'
import {
  TOOLBOX_POPOVER_BASE_Z_INDEX,
  type ToolboxOverlayControl,
} from '@/components/Toolbox'
import type { ReportContextPayload } from '@/api/types/chats'
import en from './locale/en.json'
import ru from './locale/ru.json'

const vTooltip = Tooltip

type PopoverInstance = Pick<ToolboxOverlayControl, 'syncPopover'> & {
  toggle: (event: Event, target?: HTMLElement) => void
  hide: () => void
  /**
   * PrimeVue Popover public method — recomputes overlay position against the
   * stored `target` (the element passed to `toggle()`). Used by `realign()`
   * to keep the open popover anchored to the trigger button after the
   * Toolbox is dragged or its placement changes.
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

const emit = defineEmits<{
  'toggle-request': [event: MouseEvent]
  'visibility-change': [visible: boolean]
}>()

const { t } = useLocalI18n({ en, ru })
const reportContextStore = useReportContextStore()
const dashboardContextStore = useDashboardContextStore()
const documentContextStore = useDocumentContextStore()
const mini = useMiniChat()
const { handleActionMarker } = useChatActionMarker()

/**
 * Context badge — shown when the widget is opened on a report, dashboard OR
 * document page. Precedence report > dashboard > document (a page is never two
 * at once).
 */
const hasAnyContext = computed(
  () =>
    reportContextStore.hasReportContext ||
    dashboardContextStore.hasDashboardContext ||
    documentContextStore.hasDocumentContext,
)
const contextTitle = computed(() => {
  if (reportContextStore.hasReportContext) return reportContextStore.title ?? '—'
  if (dashboardContextStore.hasDashboardContext) return dashboardContextStore.title ?? '—'
  if (documentContextStore.hasDocumentContext) return documentContextStore.title ?? '—'
  return '—'
})
const contextHint = computed(() => {
  if (reportContextStore.hasReportContext) return t('miniChat.contextHint')
  if (dashboardContextStore.hasDashboardContext) return t('miniChat.dashboardContextHint')
  if (documentContextStore.hasDocumentContext) return t('miniChat.documentContextHint')
  return t('miniChat.contextHint')
})
// Destructure refs for idiomatic template access (no `.value` chain in markup).
// Vue's template-auto-unwrap only applies to top-level keys of the setup-return
// object, so refs nested inside an object (like `mini.currentChat`) would
// otherwise require `.value` in templates.
const {
  isPreview,
  currentChat,
  dropdownItems,
  isInitializing,
  isLoadingChat,
  isLoadingDropdown,
  isSending,
} = mini

const isOpen = ref(false)
const popoverRef = ref<PopoverInstance | null>(null)
const triggerRef = ref<ComponentPublicInstance | null>(null)

/**
 * Resolves the trigger Button's root DOM element to use as the Popover anchor.
 *
 * Why we can't rely on `event.currentTarget`: the Toolbox captures the click
 * event and *replays* it asynchronously (through a `watch(openOverlay)` flush,
 * not synchronously inside the handler) when it drives `syncPopover`. By the
 * time PrimeVue's `show(event)` runs, the DOM has already reset
 * `event.currentTarget` to `null` (it is only live during dispatch), so
 * `this.target = target || event.currentTarget` becomes `null` and
 * `alignOverlay()` dereferences `null.offsetHeight` → TypeError. Passing the
 * resolved element explicitly as the second `toggle()` arg sidesteps the stale
 * event entirely.
 *
 * Returns `null` when the element is missing or has no layout box (e.g. the
 * Toolbox panel is collapsed → `opacity:0` keeps it in the DOM but
 * `offsetHeight` can be 0); callers must guard on `null` and skip alignment.
 */
const resolveAnchorEl = (): HTMLElement | null => {
  const el = triggerRef.value?.$el
  if (!(el instanceof HTMLElement) || el.offsetHeight === 0) return null
  return el
}

const tooltipLabel = computed(() => t('miniChat.tooltip'))
const resolvedTooltipOptions = computed(() =>
  props.compact ? (props.tooltipOptions ?? { value: tooltipLabel.value }) : undefined,
)

/**
 * Trigger label shown in the header dropdown button. Priority:
 *  1. Open chat's title.
 *  2. Open chat's first user message (truncated) — same fallback the
 *     dropdown items use, keeps continuity when backend hasn't yet auto-
 *     generated a title.
 *  3. "AI чат" fallback when in preview-state.
 */
const triggerLabel = computed(() => {
  const chat = currentChat.value
  if (!chat) return t('miniChat.triggerFallback')
  if (chat.title) return chat.title
  const firstUserMsg = chat.messages.find((m) => m.role === 'user')?.content ?? null
  if (firstUserMsg) {
    return firstUserMsg.length > 60 ? firstUserMsg.slice(0, 60) + '…' : firstUserMsg
  }
  return t('miniChat.triggerFallback')
})

const onDashboard = computed(
  () => dashboardContextStore.hasDashboardContext && !reportContextStore.hasReportContext,
)

const onDocument = computed(
  () =>
    documentContextStore.hasDocumentContext &&
    !reportContextStore.hasReportContext &&
    !dashboardContextStore.hasDashboardContext,
)

const placeholderText = computed(() => {
  if (reportContextStore.hasReportContext) return t('miniChat.placeholderOnReport')
  if (onDashboard.value) return t('miniChat.placeholderOnDashboard')
  if (onDocument.value) return t('miniChat.placeholderOnDocument')
  return t('miniChat.placeholder')
})

const previewEmptyMessage = computed(() => {
  if (reportContextStore.hasReportContext) return t('miniChat.previewPlaceholderOnReport')
  if (onDashboard.value) return t('miniChat.previewPlaceholderOnDashboard')
  if (onDocument.value) return t('miniChat.previewPlaceholderOnDocument')
  return t('miniChat.previewPlaceholder')
})

const existingEmptyMessage = computed(() => {
  if (reportContextStore.hasReportContext) return t('miniChat.emptyOnReport')
  if (onDashboard.value) return t('miniChat.emptyOnDashboard')
  if (onDocument.value) return t('miniChat.emptyOnDocument')
  return t('miniChat.empty')
})

const isExpandDisabled = computed(
  () => isPreview.value || !currentChat.value || isInitializing.value || isLoadingChat.value,
)

const expandTooltipText = computed(() =>
  isExpandDisabled.value
    ? t('miniChat.expandDisabled')
    : t('miniChat.openInFullScreen'),
)

/**
 * Opens the Popover anchored to the trigger button.
 *
 * We pass the explicitly-resolved trigger element as the second `toggle()`
 * arg so PrimeVue uses it as `this.target` regardless of the (possibly stale,
 * replayed) `event.currentTarget`. If the element can't be resolved yet
 * (collapsed Toolbox panel mid-transition → zero layout box), we wait one tick
 * for the panel to settle, then resolve again. If it still has no layout box
 * we skip opening rather than let `alignOverlay()` deref a null target.
 */
const togglePopover = (event: MouseEvent) => {
  const anchor = resolveAnchorEl()
  if (anchor) {
    popoverRef.value?.toggle(event, anchor)
    return
  }

  void nextTick().then(() => {
    const settled = resolveAnchorEl()
    if (settled) {
      popoverRef.value?.toggle(event, settled)
    }
  })
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
  // Guard against re-aligning against a torn-down/zero-box anchor (e.g. the
  // Toolbox collapsed while the overlay was open). PrimeVue's `alignOverlay`
  // reads `this.target.offsetHeight`, so a missing anchor throws.
  if (!resolveAnchorEl()) return
  popoverRef.value?.alignOverlay()
}

/**
 * Builds the slim in-report snapshot that backend uses to swap the bulky
 * QUICK_QA_PROMPT.md catalog for a primaryModel-specific note. Returns `null`
 * when the widget is not on a report page or when `primary_model` is missing —
 * without `primaryModel` backend silently falls back to the legacy catalog,
 * so sending the partial payload would be wasteful.
 *
 * Contract — `ReportContextPayload` in `chats_frontend.md` §`report_context`:
 *  - `primaryModel` required (PascalCase MacroData model).
 *  - `columns` flattened to `string[]` of field names.
 *  - `filters` passed through as-is (jsonb dict).
 */
const buildReportContextPayload = (): ReportContextPayload | null => {
  if (!reportContextStore.hasReportContext) return null

  const config = reportContextStore.config
  const primaryModel = config?.primary_model
  if (!primaryModel || typeof primaryModel !== 'string') return null

  const columnFields = Array.isArray(config?.columns)
    ? config.columns
        .map((col) => col.field)
        .filter((field): field is string => typeof field === 'string' && field.length > 0)
    : []

  return {
    primaryModel,
    reportId: reportContextStore.reportId ?? undefined,
    reportTitle: reportContextStore.title ?? undefined,
    columns: columnFields,
    filters: reportContextStore.filtersApplied ?? undefined,
  }
}

const handleSubmit = async (content: string) => {
  const reportContext = buildReportContextPayload()
  await mini.sendMessage(content, reportContext ? { reportContext } : undefined)
}

const handleSelectChat = async (chatId: number) => {
  await mini.selectFromDropdown(chatId)
}

const handleNewChat = () => {
  mini.enterPreview()
}

/**
 * Opens the active mini-chat in a new tab on the full-screen chat page.
 *
 * Mini-chat ALWAYS creates `type='quick_qa'` chats (regardless of
 * `scope_type` — a report-scoped mini-chat is still `quick_qa`, with the
 * report context baked into the message history as a prefix). The full-screen
 * `/ai-chat` page filters by `type='quick_qa'`, so the expand target is
 * unconditionally `/ai-chat`. (report_generation chats now live in a modal,
 * not a standalone page, and are never the expand target here.)
 */
const openInFullScreen = () => {
  const current = currentChat.value
  if (!current) return
  closePopover()
  window.open(`/ai-chat?activate=${current.id}`, '_blank', 'noopener,noreferrer')
}

const handleShow = () => {
  isOpen.value = true
  emit('visibility-change', true)
  void mini.initializeOnOpen()
}

const handleHide = () => {
  isOpen.value = false
  emit('visibility-change', false)
}

defineExpose({
  syncPopover,
  realign,
})
</script>

<style lang="scss" scoped>
@use '@/components/Toolbox/styles/compact-control' as compact;

.mini-chat {
  position: relative;
}

.mini-chat--compact {
  width: auto;
}

.mini-chat__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid transparent;
  border-radius: $radius-md;
  color: $surface-700;
  background: transparent;
  box-shadow: none;
  transition:
    background-color $transition-fast,
    border-color $transition-fast,
    color $transition-fast,
    transform $transition-fast;

  &:hover {
    background: $surface-100;
    color: $surface-900;
    transform: translateY(-1px);
  }

  &.is-open {
    background: rgba($primary, 0.12);
    border-color: rgba($primary, 0.18);
    color: $primary;
  }
}

.mini-chat__btn--compact {
  @include compact.compact-control-button();
}

// NOTE: `.mini-chat__overlay` / `.mini-chat__content` styles live in
// `front/src/assets/styles/_mini-chat-overlay.scss` (unscoped global). The
// overlay is teleported to <body> via `<Popover append-to="body">`, so a
// scoped `:deep()` selector here would never match the body-mounted element
// (no `data-v-XXX` ancestor). See that file for the width-cap rationale.

.mini-chat__panel {
  display: flex;
  flex-direction: column;
  width: 100%;
  height: min(520px, calc(100vh - 6rem));
  min-height: 360px;
  background: $surface-0;
  border-radius: inherit;
  overflow: hidden;
}

.mini-chat__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-bottom: 1px solid $surface-200;
  flex-shrink: 0;
}

.mini-chat__dropdown {
  flex: 1 1 auto;
  min-width: 0;
}

.mini-chat__actions {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  flex-shrink: 0;

  :deep(.p-button) {
    color: $surface-600;
  }

  :deep(.p-button:hover) {
    color: $surface-900;
  }
}

.mini-chat__context {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  background: rgba($primary, 0.06);
  color: $surface-700;
  font-size: $font-size-xs;
  border-bottom: 1px solid $surface-200;
  flex-shrink: 0;

  .pi {
    color: $primary;
  }
}

.mini-chat__context-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  flex: 1;
}

.mini-chat__body {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;

  :deep(.empty-state) {
    flex: 1;
    border: none;
    background: transparent;
    border-radius: 0;
    padding: $space-4;
  }

  :deep(.loading-state) {
    flex: 1;
  }
}

@media (max-width: 560px) {
  .mini-chat__panel {
    height: min(480px, calc(100vh - 4rem));
  }
}
</style>
