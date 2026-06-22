<template>
  <div class="contacts-paginator">
    <!-- Left: page-size + showing text -->
    <div class="contacts-paginator__left">
      <div class="contacts-paginator__per-page">
        <span class="contacts-paginator__per-page-label">{{ t('contacts.paginator.perPageLabel') }}</span>
        <div class="contacts-paginator__per-page-wrap">
          <button
            class="contacts-paginator__per-page-btn"
            type="button"
            @click.stop="togglePerPageMenu"
          >
            {{ perPage }}
            <i class="pi pi-chevron-down contacts-paginator__per-page-chevron" />
          </button>
          <div v-if="perPageMenuOpen" class="contacts-paginator__per-page-menu" @click.stop>
            <button
              v-for="opt in perPageOptions"
              :key="opt"
              class="contacts-paginator__per-page-option"
              :class="{ 'contacts-paginator__per-page-option--active': opt === perPage }"
              type="button"
              @click="selectPerPage(opt)"
            >
              {{ opt }}
            </button>
          </div>
        </div>
      </div>
      <span class="contacts-paginator__showing">{{ showingText }}</span>
    </div>

    <!-- Right: page buttons -->
    <div v-if="lastPage > 1" class="contacts-paginator__right">
      <button
        class="contacts-paginator__nav-btn"
        :disabled="page <= 1"
        type="button"
        @click="goTo(1)"
      >
        <i class="pi pi-angle-double-left" />
      </button>
      <button
        class="contacts-paginator__nav-btn"
        :disabled="page <= 1"
        type="button"
        @click="goTo(page - 1)"
      >
        <i class="pi pi-angle-left" />
      </button>

      <button
        v-for="p in visiblePages"
        :key="p"
        class="contacts-paginator__page-btn"
        :class="{ 'contacts-paginator__page-btn--active': p === page }"
        type="button"
        @click="goTo(p)"
      >
        {{ p }}
      </button>

      <button
        class="contacts-paginator__nav-btn"
        :disabled="page >= lastPage"
        type="button"
        @click="goTo(page + 1)"
      >
        <i class="pi pi-angle-right" />
      </button>
      <button
        class="contacts-paginator__nav-btn"
        :disabled="page >= lastPage"
        type="button"
        @click="goTo(lastPage)"
      >
        <i class="pi pi-angle-double-right" />
      </button>
    </div>
  </div>
</template>

<script lang="ts">
// Module-scope constants referenced in withDefaults() must live outside <script setup>
// so they are hoisted and available during defineProps() compilation.
export const DEFAULT_PER_PAGE_OPTIONS = [50, 100, 200] as const
</script>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'

const PER_PAGE_KEY = 'mgcrm_contacts_per_page_v1'

const props = withDefaults(
  defineProps<{
    page: number
    perPage: number
    total: number
    perPageOptions?: number[]
  }>(),
  {
    perPageOptions: () => [...DEFAULT_PER_PAGE_OPTIONS],
  },
)

const emit = defineEmits<{
  'update:page': [page: number]
  'update:perPage': [perPage: number]
}>()

const { t } = useI18n()
const perPageMenuOpen = ref(false)

const lastPage = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))

const fromRecord = computed(() => props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1)
const toRecord = computed(() => Math.min(props.page * props.perPage, props.total))

const showingText = computed(() =>
  t('contacts.paginator.showing', {
    from: fromRecord.value,
    to: toRecord.value,
    total: props.total,
  }),
)

const visiblePages = computed(() => {
  const total = lastPage.value
  const current = props.page
  const pages: number[] = []

  if (total <= 7) {
    for (let i = 1; i <= total; i++) pages.push(i)
    return pages
  }

  const start = Math.max(1, current - 2)
  const end = Math.min(total, current + 2)

  if (start > 1) pages.push(1)
  if (start > 2) pages.push(-1) // ellipsis placeholder
  for (let i = start; i <= end; i++) pages.push(i)
  if (end < total - 1) pages.push(-2)
  if (end < total) pages.push(total)

  return pages
})

function goTo(p: number) {
  if (p < 1 || p > lastPage.value || p === props.page) return
  emit('update:page', p)
}

function togglePerPageMenu() {
  perPageMenuOpen.value = !perPageMenuOpen.value
}

function selectPerPage(opt: number) {
  perPageMenuOpen.value = false
  if (opt === props.perPage) return
  localStorage.setItem(PER_PAGE_KEY, String(opt))
  emit('update:perPage', opt)
}

function onDocumentClick() {
  perPageMenuOpen.value = false
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
})
</script>

<style lang="scss" scoped>
.contacts-paginator {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px $space-5;
  border-top: 1px solid $surface-200;
  flex-shrink: 0;
  background: $surface-card;

  .app-dark & {
    border-top-color: var(--p-surface-700);
    background: var(--p-surface-100);
  }
}

.contacts-paginator__left {
  display: flex;
  align-items: center;
  gap: $space-4;
}

.contacts-paginator__per-page {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.contacts-paginator__per-page-label {
  font-size: $font-size-sm;
  color: $surface-400;
  white-space: nowrap;
}

.contacts-paginator__per-page-wrap {
  position: relative;
}

.contacts-paginator__per-page-btn {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  height: 30px;
  padding: 0 $space-2;
  border: 1px solid $surface-300;
  border-radius: $radius-md;
  background: $surface-card;
  color: var(--p-text-color);
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  cursor: pointer;
  transition: border-color 0.15s;
  box-sizing: border-box;

  &:hover {
    border-color: $primary-900;
  }

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-600);
    color: var(--p-text-color);
  }
}

.contacts-paginator__per-page-chevron {
  font-size: $font-size-3xs;
  color: $surface-400;
}

.contacts-paginator__per-page-menu {
  position: absolute;
  bottom: calc(100% + 5px);
  left: 0;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  box-shadow: $shadow-overlay-sm;
  min-width: 80px;
  z-index: 100;
  padding: $space-1;
  display: flex;
  flex-direction: column;
  gap: 2px;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-600);
    box-shadow: var(--p-overlay-navigation-shadow);
  }
}

.contacts-paginator__per-page-option {
  display: block;
  width: 100%;
  padding: 6px $space-2;
  background: transparent;
  border: none;
  border-radius: $radius-sm;
  color: var(--p-text-color);
  font-size: $font-size-sm;
  cursor: pointer;
  text-align: left;

  &:hover {
    background: $surface-50;

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  &--active {
    font-weight: $font-weight-bold;
    color: $primary-900;
  }
}

.contacts-paginator__showing {
  font-size: $font-size-sm;
  color: $surface-400;
  white-space: nowrap;
}

.contacts-paginator__right {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.contacts-paginator__nav-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  color: $surface-400;
  border-radius: $radius-sm;
  cursor: pointer;
  font-size: $font-size-xs;
  padding: 0;
  transition: background 0.15s;

  &:hover:not(:disabled) {
    background: $surface-100;
    color: var(--p-text-color);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  &:disabled {
    opacity: 0.4;
    cursor: default;
  }
}

.contacts-paginator__page-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  color: $surface-600;
  border-radius: $radius-sm;
  cursor: pointer;
  font-size: $font-size-sm;
  padding: 0 $space-1;
  transition: background 0.15s;

  &:hover:not(.contacts-paginator__page-btn--active) {
    background: $surface-100;

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  &--active {
    background: $primary-900;
    color: $surface-0;
    font-weight: $font-weight-semibold;
    cursor: default;
  }

  .app-dark & {
    color: var(--p-surface-300);
  }
}
</style>
