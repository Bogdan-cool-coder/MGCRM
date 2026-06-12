<template>
  <div class="deal-overview">
    <!-- Main info card -->
    <div class="deal-overview__card">
      <h3 class="deal-overview__card-title">{{ t('sales.deal.page.overview.sectionMain') }}</h3>

      <div class="deal-overview__fields">
        <!-- Title -->
        <div class="deal-overview__row">
          <span class="deal-overview__field-label">{{ t('sales.deal.page.overview.fields.title') }}</span>
          <InlineEditableField
            :model-value="deal.title"
            field-key="title"
            field-type="text"
            :saving="isSaving"
            @save="(_, v) => emit('save', 'title', v)"
          />
        </div>

        <!-- Company (read-only link) -->
        <div class="deal-overview__row">
          <span class="deal-overview__field-label">{{ t('sales.deal.page.overview.fields.company') }}</span>
          <RouterLink :to="`/companies/${deal.company.id}`" class="deal-overview__link">
            {{ deal.company.name }}
          </RouterLink>
        </div>

        <!-- Currency -->
        <div class="deal-overview__row">
          <span class="deal-overview__field-label">{{ t('sales.deal.page.overview.fields.currency') }}</span>
          <InlineEditableField
            :model-value="deal.currency"
            field-key="currency"
            field-type="select"
            :options="currencyOptions"
            option-label="label"
            option-value="value"
            :saving="isSaving"
            @save="(_, v) => emit('save', 'currency', v)"
          />
        </div>

        <!-- Expected close date -->
        <div class="deal-overview__row">
          <span class="deal-overview__field-label">{{ t('sales.deal.page.overview.fields.expectedCloseDate') }}</span>
          <DateEditField
            :value="deal.expected_close_date"
            :saving="isSaving"
            @save="(v) => emit('save', 'expected_close_date', v)"
          />
        </div>

        <!-- Expected sign date -->
        <div class="deal-overview__row">
          <span class="deal-overview__field-label">{{ t('sales.deal.page.overview.fields.expectedSignDate') }}</span>
          <DateEditField
            :value="deal.expected_sign_date"
            :saving="isSaving"
            @save="(v) => emit('save', 'expected_sign_date', v)"
          />
        </div>

        <!-- Expected payment date -->
        <div class="deal-overview__row">
          <span class="deal-overview__field-label">{{ t('sales.deal.page.overview.fields.expectedPaymentDate') }}</span>
          <DateEditField
            :value="deal.expected_payment_date"
            :saving="isSaving"
            @save="(v) => emit('save', 'expected_payment_date', v)"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DatePicker from 'primevue/datepicker'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import { CURRENCY_WHITELIST } from '@/utils/currency'
import type { DealDto } from '@/entities/sales'

// ── Inline date edit field (local component, render fn to satisfy eslint) ───

const DateEditField = defineComponent({
  name: 'DateEditField',
  props: {
    value: { type: String as () => string | null, default: null },
    saving: { type: Boolean, default: false },
  },
  emits: ['save'],
  setup(props, { emit: emitField }) {
    const isEditing = ref(false)
    const localDate = ref<Date | null>(null)

    function startEdit() {
      localDate.value = props.value ? new Date(props.value) : null
      isEditing.value = true
    }

    function formatDisplay(val: string | null): string {
      if (!val) return '—'
      const d = new Date(val)
      return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
    }

    function submitDate() {
      if (localDate.value) {
        const d = localDate.value
        const y = d.getFullYear()
        const m = String(d.getMonth() + 1).padStart(2, '0')
        const day = String(d.getDate()).padStart(2, '0')
        emitField('save', `${y}-${m}-${day}`)
      } else {
        emitField('save', null)
      }
      isEditing.value = false
    }

    return () => {
      if (!isEditing.value) {
        return h('div', { class: 'date-edit' }, [
          h('span', { class: 'date-edit__value', onDblclick: startEdit }, formatDisplay(props.value)),
          h('i', { class: 'pi pi-pencil date-edit__icon', onClick: startEdit }),
        ])
      }
      return h('div', { class: 'date-edit' }, [
        h(DatePicker, {
          modelValue: localDate.value,
          dateFormat: 'dd.mm.yy',
          showClear: true,
          showIcon: true,
          style: 'width: 180px',
          'onUpdate:modelValue': (v: Date | null) => { localDate.value = v },
          onBlur: submitDate,
          onKeydown: (e: KeyboardEvent) => {
            if (e.key === 'Enter') submitDate()
            if (e.key === 'Escape') { isEditing.value = false }
          },
        }),
      ])
    }
  },
})

defineProps<{
  deal: DealDto
  isSaving: boolean
}>()

const emit = defineEmits<{
  save: [field: string, value: unknown]
}>()

const { t } = useI18n()

const currencyOptions = computed(() =>
  CURRENCY_WHITELIST.map((c) => ({ value: c, label: c })),
)
</script>

<style lang="scss" scoped>
.deal-overview {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.deal-overview__card {
  background: $surface-card;
  border-radius: $radius-md;
  border: 1px solid $surface-200;
  padding: $space-4;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.deal-overview__card-title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0 0 $space-4;
}

.deal-overview__fields {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.deal-overview__row {
  display: grid;
  grid-template-columns: 200px 1fr;
  align-items: start;
  gap: $space-2;
}

.deal-overview__field-label {
  font-size: $font-size-sm;
  color: $surface-500;
  padding-top: 4px;
}

.deal-overview__link {
  font-size: $font-size-sm;
  color: $primary-color;
  text-decoration: none;
  font-weight: $font-weight-medium;

  &:hover {
    text-decoration: underline;
  }
}

:global(.date-edit) {
  display: flex;
  align-items: center;
  gap: $space-1;
  cursor: pointer;
}

:global(.date-edit__value) {
  font-size: $font-size-sm;
  color: $surface-800;
}

:global(.date-edit__icon) {
  font-size: $font-size-xs;
  color: $surface-400;
  cursor: pointer;
  &:hover {
    color: $primary-color;
  }
}
</style>
