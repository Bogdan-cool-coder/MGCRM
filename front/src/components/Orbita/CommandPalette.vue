<template>
  <Dialog
    v-model:visible="visible"
    modal
    :closable="false"
    :dismissable-mask="true"
    class="command-palette"
    :pt="{
      root: { 'aria-modal': 'true', role: 'dialog', 'aria-label': t('commandPalette.placeholder') },
      content: { class: 'command-palette__content' },
      header: { class: 'command-palette__header-wrap' },
    }"
    @after-hide="onAfterHide"
  >
    <template #header>
      <div class="command-palette__header">
        <i class="pi pi-search command-palette__search-icon" aria-hidden="true" />
        <input
          ref="inputRef"
          v-model="query"
          type="text"
          class="command-palette__input"
          :placeholder="t('commandPalette.placeholder')"
          autocomplete="off"
          autocorrect="off"
          autocapitalize="off"
          spellcheck="false"
          @keydown="onInputKeydown"
        />
        <kbd class="command-palette__esc-hint">Esc</kbd>
      </div>
    </template>

    <!-- Results list -->
    <div
      v-if="flatResults.length > 0"
      ref="resultsListRef"
      class="command-palette__list"
      role="listbox"
      :aria-label="t('commandPalette.placeholder')"
    >
      <template v-for="section in groupedResults" :key="section.key">
        <div class="command-palette__section-header" role="presentation">
          {{ section.label }}
        </div>
        <button
          v-for="(item, itemIdx) in section.items"
          :key="item.id"
          :ref="(el) => setItemRef(el, flatResultIndex(section.key, itemIdx))"
          role="option"
          :aria-selected="activeIndex === flatResultIndex(section.key, itemIdx)"
          :class="[
            'command-palette__item',
            { 'is-active': activeIndex === flatResultIndex(section.key, itemIdx) },
          ]"
          @mouseenter="activeIndex = flatResultIndex(section.key, itemIdx)"
          @click="selectItem(item)"
        >
          <i :class="['command-palette__item-icon', item.icon]" aria-hidden="true" />
          <span class="command-palette__item-label">{{ item.label }}</span>
          <span v-if="item.meta" class="command-palette__item-meta">{{ item.meta }}</span>
        </button>
      </template>
    </div>

    <!-- Empty state -->
    <div v-else-if="query.length > 0" class="command-palette__empty">
      <i class="pi pi-search command-palette__empty-icon" aria-hidden="true" />
      <span>{{ t('commandPalette.empty') }}</span>
    </div>

    <!-- Recent routes (shown when query is empty) -->
    <div
      v-else-if="recentItems.length > 0"
      ref="recentListRef"
      class="command-palette__list"
      role="listbox"
      :aria-label="t('commandPalette.sectionRecent')"
    >
      <div class="command-palette__section-header" role="presentation">
        {{ t('commandPalette.sectionRecent') }}
      </div>
      <button
        v-for="(item, idx) in recentItems"
        :key="item.id"
        :ref="(el) => setItemRef(el, idx)"
        role="option"
        :aria-selected="activeIndex === idx"
        :class="['command-palette__item', { 'is-active': activeIndex === idx }]"
        @mouseenter="activeIndex = idx"
        @click="selectItem(item)"
      >
        <i :class="['command-palette__item-icon', item.icon]" aria-hidden="true" />
        <span class="command-palette__item-label">{{ item.label }}</span>
        <span v-if="item.meta" class="command-palette__item-meta">{{ item.meta }}</span>
      </button>
    </div>

    <template #footer>
      <div class="command-palette__hint" aria-hidden="true">
        {{ t('commandPalette.hint') }}
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import { useLayoutStore } from '@/stores/layout'
import { useThemeStore } from '@/stores/theme'
import { allNavItems, adminNavItems } from '@/shared/nav/navItems'

const { t, locale } = useI18n()
const router = useRouter()
const layoutStore = useLayoutStore()
const themeStore = useThemeStore()

// Controlled by layoutStore.commandPaletteOpen
const visible = computed({
  get: () => layoutStore.commandPaletteOpen,
  set: (v) => {
    if (!v) layoutStore.closeCommandPalette()
  },
})

// ─── Query & UI state ─────────────────────────────────────────────────────────
const query = ref('')
const activeIndex = ref(0)
const inputRef = ref<HTMLInputElement | null>(null)
const resultsListRef = ref<HTMLElement | null>(null)
const recentListRef = ref<HTMLElement | null>(null)
const itemRefs = ref<(HTMLButtonElement | null)[]>([])

function setItemRef(el: unknown, idx: number) {
  if (el instanceof HTMLButtonElement) {
    itemRefs.value[idx] = el
  }
}

// Reset when opening
watch(visible, (isOpen) => {
  if (isOpen) {
    query.value = ''
    activeIndex.value = 0
    itemRefs.value = []
    void nextTick(() => {
      inputRef.value?.focus()
    })
  }
})

function onAfterHide() {
  query.value = ''
  activeIndex.value = 0
  itemRefs.value = []
}

// ─── Actions catalog ──────────────────────────────────────────────────────────
interface PaletteItem {
  id: string
  label: string
  icon: string
  meta?: string
  action: () => void
  type: 'page' | 'action' | 'recent'
}

const builtinActions = computed<PaletteItem[]>(() => [
  {
    id: 'action:new-deal',
    label: t('sales.deal.actions.create', 'Создать сделку'),
    icon: 'pi pi-plus',
    type: 'action' as const,
    action: () => router.push('/deals/new'),
  },
  {
    id: 'action:new-contact',
    label: t('contacts.actions.create', 'Создать контакт'),
    icon: 'pi pi-plus',
    type: 'action' as const,
    action: () => router.push('/contacts/new'),
  },
  {
    id: 'action:toggle-theme',
    label: themeStore.theme === 'light'
      ? t('account.themeDark', 'Тёмная тема')
      : t('account.themeLight', 'Светлая тема'),
    icon: themeStore.theme === 'light' ? 'pi pi-moon' : 'pi pi-sun',
    type: 'action' as const,
    action: () => themeStore.setTheme(themeStore.theme === 'light' ? 'dark' : 'light'),
  },
  {
    id: 'action:toggle-nav',
    label: layoutStore.navMode === 'sidebar'
      ? t('layout.navModeOrbit', 'Переключить на Орбиту')
      : t('layout.navModeSidebar', 'Переключить на боковое меню'),
    icon: 'pi pi-th-large',
    type: 'action' as const,
    action: () => layoutStore.setNavMode(layoutStore.navMode === 'sidebar' ? 'orbit' : 'sidebar'),
  },
])

// ─── Pages catalog (navItems + adminNavItems) ─────────────────────────────────
const allPageItems = computed<PaletteItem[]>(() => {
  const allPages = [...allNavItems, ...adminNavItems]
  return allPages.map((item) => ({
    id: `page:${item.key}`,
    label: t(item.labelKey),
    icon: item.icon,
    meta: item.route,
    type: 'page' as const,
    action: () => router.push(item.route),
  }))
})

// ─── Fuzzy filter ─────────────────────────────────────────────────────────────
function fuzzyMatch(text: string, q: string): boolean {
  if (!q) return true
  const t = text.toLowerCase()
  const s = q.toLowerCase()
  let si = 0
  for (let i = 0; i < t.length && si < s.length; i++) {
    if (t[i] === s[si]) si++
  }
  return si === s.length
}

function fuzzyScore(text: string, q: string): number {
  const t = text.toLowerCase()
  const s = q.toLowerCase()
  // Exact prefix gets highest score
  if (t.startsWith(s)) return 3
  // Contains gets medium score
  if (t.includes(s)) return 2
  // Fuzzy match gets lowest
  return 1
}

// ─── Grouped results ──────────────────────────────────────────────────────────
interface ResultSection {
  key: string
  label: string
  items: PaletteItem[]
}

const groupedResults = computed<ResultSection[]>(() => {
  const q = query.value.trim()
  if (!q) return []

  const matchedPages = allPageItems.value
    .filter((item) => fuzzyMatch(item.label, q) || fuzzyMatch(item.meta ?? '', q))
    .sort((a, b) => fuzzyScore(b.label, q) - fuzzyScore(a.label, q))
    .slice(0, 5)

  const matchedActions = builtinActions.value
    .filter((item) => fuzzyMatch(item.label, q))
    .slice(0, 3)

  const sections: ResultSection[] = []
  if (matchedPages.length) {
    sections.push({ key: 'pages', label: t('commandPalette.sectionPages'), items: matchedPages })
  }
  if (matchedActions.length) {
    sections.push({ key: 'actions', label: t('commandPalette.sectionActions'), items: matchedActions })
  }
  return sections
})

// Flat list for keyboard navigation
const flatResults = computed<PaletteItem[]>(() => groupedResults.value.flatMap((s) => s.items))

function flatResultIndex(sectionKey: string, itemIdx: number): number {
  let offset = 0
  for (const section of groupedResults.value) {
    if (section.key === sectionKey) return offset + itemIdx
    offset += section.items.length
  }
  return offset + itemIdx
}

// ─── Recent routes converted to PaletteItems ─────────────────────────────────
const recentItems = computed<PaletteItem[]>(() => {
  return layoutStore.recentRoutes
    .map((routePath) => {
      // Find matching navItem
      const all = [...allNavItems, ...adminNavItems]
      const match = all.find((item) => item.route === routePath)
      return {
        id: `recent:${routePath}`,
        label: match ? t(match.labelKey) : routePath,
        icon: match?.icon ?? 'pi pi-clock',
        meta: routePath,
        type: 'recent' as const,
        action: () => router.push(routePath),
      }
    })
})

// Active count for keyboard nav
const activeListLength = computed(() =>
  query.value.trim() ? flatResults.value.length : recentItems.value.length,
)

// ─── Keyboard navigation ──────────────────────────────────────────────────────
function onInputKeydown(e: KeyboardEvent) {
  switch (e.key) {
    case 'ArrowDown':
      e.preventDefault()
      activeIndex.value = (activeIndex.value + 1) % Math.max(activeListLength.value, 1)
      scrollActiveIntoView()
      break
    case 'ArrowUp':
      e.preventDefault()
      activeIndex.value =
        (activeIndex.value - 1 + Math.max(activeListLength.value, 1)) %
        Math.max(activeListLength.value, 1)
      scrollActiveIntoView()
      break
    case 'Enter':
      e.preventDefault()
      {
        const items = query.value.trim() ? flatResults.value : recentItems.value
        const item = items[activeIndex.value]
        if (item) selectItem(item)
      }
      break
    case 'Escape':
      e.preventDefault()
      layoutStore.closeCommandPalette()
      break
  }
}

function scrollActiveIntoView() {
  void nextTick(() => {
    itemRefs.value[activeIndex.value]?.scrollIntoView({ block: 'nearest' })
  })
}

// Reset active when query changes
watch(query, () => {
  activeIndex.value = 0
  itemRefs.value = []
})

// ─── Select ───────────────────────────────────────────────────────────────────
function selectItem(item: PaletteItem) {
  if (item.meta) {
    layoutStore.pushRecentRoute(item.meta)
  }
  layoutStore.closeCommandPalette()
  item.action()
}

// Keep locale reactivity — force recompute when locale changes
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const _localeRef = locale
</script>

<style lang="scss">
// Unscoped: Dialog portal renders outside component scope
.command-palette.p-dialog {
  width: 640px;
  max-width: calc(100vw - 2rem);
  border-radius: $radius-lg;
  box-shadow: $shadow-lg;

  .p-dialog-header {
    padding: 0;
    border-bottom: 1px solid $surface-200;
    border-radius: $radius-lg $radius-lg 0 0;
  }

  .p-dialog-content {
    padding: 0;
    max-height: 420px;
    overflow-y: auto;
    scrollbar-width: thin;

    &::-webkit-scrollbar {
      width: 4px;
    }

    &::-webkit-scrollbar-thumb {
      background: $surface-300;
      border-radius: $radius-2xs;
    }
  }

  .p-dialog-footer {
    padding: $space-2 $space-4;
    border-top: 1px solid $surface-100;
    background: $surface-50;
    border-radius: 0 0 $radius-lg $radius-lg;
  }
}

// Dark mode
.app-dark .command-palette.p-dialog {
  .p-dialog-header {
    border-bottom-color: $surface-700;
  }

  .p-dialog-footer {
    background: $surface-900;
    border-top-color: $surface-700;
  }
}
</style>

<style lang="scss" scoped>
// ─── Header / Search input ────────────────────────────────────────────────────
.command-palette__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3 $space-4;
  width: 100%;
}

.command-palette__search-icon {
  font-size: $font-size-md;
  color: $surface-400;
  flex-shrink: 0;
}

.command-palette__input {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: $font-size-md;
  color: var(--p-text-color);
  line-height: 1.5;

  &::placeholder {
    color: $surface-400;
  }
}

.command-palette__esc-hint {
  font-size: $font-size-2xs;
  padding: 2px 6px;
  border: 1px solid $surface-300;
  border-radius: $radius-sm;
  color: $surface-400;
  background: $surface-50;
  flex-shrink: 0;
  font-family: inherit;
  line-height: 1.5;
}

// ─── Results list ─────────────────────────────────────────────────────────────
.command-palette__list {
  padding: $space-2 0;
}

.command-palette__section-header {
  padding: $space-1 $space-4;
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-400;
  margin-top: $space-1;
}

.command-palette__item {
  display: flex;
  align-items: center;
  gap: $space-3;
  width: 100%;
  padding: $space-2 $space-4;
  border: none;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: var(--p-text-color);
  transition: background-color var(--app-transition-fast);
  border-radius: 0;

  &:hover,
  &.is-active {
    background: $surface-100;
  }

  &.is-active {
    background: rgba($primary, 0.08);
  }
}

.command-palette__item-icon {
  font-size: $font-size-sm; // snap from 0.9rem (≈14.4px→14px)
  color: $surface-500;
  width: $font-size-md;
  text-align: center;
  flex-shrink: 0;
}

.command-palette__item-label {
  flex: 1;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
}

.command-palette__item-meta {
  font-size: $font-size-xs;
  color: $surface-400;
  font-family: $font-family-mono;
  flex-shrink: 0;
}

// ─── Empty state ──────────────────────────────────────────────────────────────
.command-palette__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-8 $space-4;
  color: $surface-400;
  font-size: $font-size-sm;
}

.command-palette__empty-icon {
  font-size: $font-size-icon-lg;
  opacity: 0.4;
}

// ─── Hint bar ─────────────────────────────────────────────────────────────────
.command-palette__hint {
  font-size: $font-size-2xs;
  color: $surface-400;
  text-align: center;
}

// ─── Dark mode ────────────────────────────────────────────────────────────────
:global(.app-dark) {
  .command-palette__esc-hint {
    border-color: $surface-600;
    background: $surface-800;
  }

  .command-palette__item {
    &:hover,
    &.is-active {
      background: $surface-800;
    }

    &.is-active {
      background: rgba($primary, 0.18);
    }
  }
}
</style>
