<template>
  <div
    class="kanban-card"
    :class="{ 'kanban-card--dragging': isDragging }"
    @click="onClick"
  >
    <div class="kanban-card__header">
      <span class="kanban-card__company">
        <i class="pi pi-building kanban-card__company-icon" />
        {{ card.company.name }}
      </span>
    </div>

    <div
      class="kanban-card__title"
      :title="card.title"
      @dblclick.stop="startEdit"
    >
      <template v-if="!isEditing">{{ card.title }}</template>
      <InputText
        v-else
        v-model="editTitle"
        class="kanban-card__title-input"
        @blur="submitEdit"
        @keydown="onEditKeydown"
        @click.stop
      />
    </div>

    <div class="kanban-card__footer">
      <span class="kanban-card__amount">{{ formatCurrency(card.amount, card.currency) }}</span>
      <span class="kanban-card__meta">
        <span class="kanban-card__owner">@{{ shortName(card.owner.name) }}</span>
        <span
          class="kanban-card__days"
          :class="{ 'kanban-card__days--warn': daysInStage > 7 }"
        >
          · {{ t('sales.deals.page.kanban.daysInStage', { n: daysInStage }) }}
        </span>
      </span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import InputText from 'primevue/inputtext'
import { formatCurrency } from '@/utils/currency'
import type { DealCardDto } from '@/entities/sales'

const props = defineProps<{
  card: DealCardDto
  isDragging?: boolean
}>()

const emit = defineEmits<{
  titleChange: [cardId: number, title: string]
}>()

const { t } = useI18n()
const router = useRouter()

const isEditing = ref(false)
const editTitle = ref('')

const daysInStage = computed(() => {
  if (!props.card.stage_changed_at) return 0
  return Math.floor((Date.now() - new Date(props.card.stage_changed_at).getTime()) / 86400000)
})

function shortName(name: string): string {
  const parts = name.trim().split(' ')
  if (parts.length === 1) return parts[0] ?? name
  const first = parts[0] ?? ''
  const secondInitial = parts[1]?.charAt(0).toUpperCase() ?? ''
  return `${first} ${secondInitial}.`
}

function onClick() {
  if (!isEditing.value) {
    void router.push(`/deals/${props.card.id}`)
  }
}

function onEditKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter') {
    event.preventDefault()
    submitEdit()
  } else if (event.key === 'Escape') {
    event.preventDefault()
    cancelEdit()
  }
}

function startEdit() {
  isEditing.value = true
  editTitle.value = props.card.title
}

function submitEdit() {
  const trimmed = editTitle.value.trim()
  if (trimmed && trimmed !== props.card.title) {
    emit('titleChange', props.card.id, trimmed)
  }
  isEditing.value = false
}

function cancelEdit() {
  isEditing.value = false
}
</script>

<style lang="scss" scoped>
.kanban-card {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  padding: $space-3;
  cursor: pointer;
  transition: box-shadow var(--app-transition-fast), background-color var(--app-transition-fast);
  user-select: none;

  &:hover {
    background: var(--p-surface-50);
    box-shadow: var(--p-card-shadow);
  }

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
    &:hover {
      background: var(--p-surface-800);
    }
  }

  &--dragging {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    opacity: 0.95;
  }
}

.kanban-card__header {
  margin-bottom: $space-1;
}

.kanban-card__company {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  color: $surface-500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.kanban-card__company-icon {
  font-size: 10px;
  flex-shrink: 0;
}

.kanban-card__title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  margin-bottom: $space-2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-height: 20px;
}

.kanban-card__title-input {
  width: 100%;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  padding: 2px 4px;
}

.kanban-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-1;
}

.kanban-card__amount {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $primary-color;
  white-space: nowrap;
}

.kanban-card__meta {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  color: $surface-500;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.kanban-card__owner {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 80px;
}

.kanban-card__days--warn {
  color: var(--p-orange-500);
}

// Ghost (drag placeholder)
:global(.kanban-card--ghost) {
  opacity: 0.4;
  background: var(--p-surface-100);
}
</style>
