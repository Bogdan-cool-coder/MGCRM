<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('docx.catalog.title')"
    :style="{ width: '54rem', maxWidth: '95vw' }"
    :dismissable-mask="true"
    @update:visible="onVisibleChange"
  >
    <p class="catalog-intro">{{ t('docx.catalog.intro') }}</p>

    <LoadingState v-if="loading && groups.length === 0" />

    <EmptyState
      v-else-if="groups.length === 0"
      :message="t('docx.catalog.empty')"
      icon="pi pi-inbox"
    />

    <div v-else class="catalog-groups">
      <section v-for="group in groups" :key="group.group" class="catalog-group">
        <h4 class="group-title">{{ group.label }}</h4>

        <ul class="field-list">
          <li v-for="field in group.items" :key="field.key" class="field-row">
            <div class="field-main">
              <div class="field-head">
                <code class="field-token">{{ tokenOf(field.key) }}</code>
                <Button
                  v-tooltip.top="t('docx.catalog.copy')"
                  icon="pi pi-copy"
                  severity="secondary"
                  text
                  rounded
                  size="small"
                  :aria-label="t('docx.catalog.copy')"
                  @click="copyToken(field.key)"
                />
                <Tag
                  v-if="field.pii"
                  :value="t('docx.catalog.pii')"
                  severity="danger"
                  rounded
                />
              </div>

              <span class="field-label">{{ labelOf(field.label) }}</span>

              <small v-if="field.example" class="field-example">
                {{ t('docx.catalog.example') }}: {{ field.example }}
              </small>
            </div>

            <!-- Render filters: a chip per filter copies `${key|filter}`. -->
            <div v-if="field.filters.length > 0" class="field-filters">
              <button
                v-for="filter in field.filters"
                :key="filter"
                type="button"
                class="filter-chip"
                :title="t('docx.catalog.copyWithFilter')"
                @click="copyToken(field.key, filter)"
              >
                <span class="filter-chip__pipe">|</span>{{ filter }}
              </button>
            </div>
          </li>
        </ul>
      </section>
    </div>
  </Dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Tooltip from 'primevue/tooltip'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import { useNotifications } from '@/composables/useNotifications'
import { getLocalizedText } from '@/utils/localization'
import { FIELD_CATALOG_GROUPS } from '@/entities/document'
import type {
  DocumentFieldCatalog,
  DocumentFieldCatalogGroup,
} from '@/entities/document'
import type { LocalizedText } from '@/shared/types'

const vTooltip = Tooltip

interface Props {
  visible: boolean
  loading: boolean
  /** Full grouped catalogue (entity shape) — null until the first load. */
  catalog: DocumentFieldCatalog | null
  /** Page locale function (shares the DocumentPage namespace). */
  t: (_key: string) => string
  /** Active locale string for resolving catalog labels. */
  locale: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
}>()

const { notifySuccess, notifyError } = useNotifications()

const GROUP_LABELS: Record<DocumentFieldCatalogGroup, string> = {
  object: 'docx.groups.object',
  deal: 'docx.groups.deal',
  buyer: 'docx.groups.buyer',
  finances: 'docx.groups.finances',
  discount: 'docx.groups.discount',
  common: 'docx.groups.common',
  branding: 'docx.groups.branding',
}

/** Render only non-empty groups, in the canonical bucket order. */
const groups = computed(() => {
  const cat = props.catalog
  if (cat === null) return []
  return FIELD_CATALOG_GROUPS.map((group) => ({
    group,
    label: props.t(GROUP_LABELS[group]),
    items: cat[group],
  })).filter((g) => g.items.length > 0)
})

const labelOf = (label: LocalizedText): string => getLocalizedText(label, props.locale)

const tokenOf = (key: string, filter?: string): string =>
  filter ? `\${${key}|${filter}}` : `\${${key}}`

const onVisibleChange = (value: boolean) => {
  emit('update:visible', value)
}

const copyToken = async (key: string, filter?: string) => {
  const token = tokenOf(key, filter)
  try {
    await navigator.clipboard.writeText(token)
    notifySuccess(props.t('docx.catalog.copied'))
  } catch {
    notifyError(props.t('docx.catalog.copyFailed'))
  }
}
</script>

<style lang="scss" scoped>
.catalog-intro {
  margin: 0 0 1rem;
  font-size: $font-size-sm;
  color: $surface-600;
}

.catalog-groups {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.catalog-group {
  .group-title {
    margin: 0 0 0.5rem;
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }

  .field-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
  }

  .field-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.5rem 0.6rem;
    border-radius: $card-border-radius;

    &:hover {
      background: $surface-100;
    }
  }

  .field-main {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    min-width: 0;
  }

  .field-head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
  }

  .field-token {
    font-family: monospace;
    font-size: $font-size-sm;
    color: $primary-color;
    background: $surface-100;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    white-space: nowrap;
  }

  .field-label {
    font-size: $font-size-sm;
    color: $surface-700;
  }

  .field-example {
    font-size: $font-size-xs;
    color: $surface-500;
    font-style: italic;
  }

  .field-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    flex-shrink: 0;
    justify-content: flex-end;
    max-width: 14rem;
  }

  .filter-chip {
    font-family: monospace;
    font-size: $font-size-xs;
    color: $surface-700;
    background: $surface-50;
    border: 1px solid $surface-200;
    border-radius: 4px;
    padding: 0.1rem 0.4rem;
    cursor: pointer;
    white-space: nowrap;

    &__pipe {
      color: $surface-400;
      margin-right: 0.1rem;
    }

    &:hover {
      background: $surface-200;
    }
  }
}
</style>
