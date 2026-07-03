<template>
  <div class="mk-tab">
    <!-- Header row: title + month nav -->
    <div class="mk-tab__toolbar">
      <h2 class="mk-tab__title">{{ t('motivation.card.title') }}</h2>
      <MkMonthNav
        :options="monthOptions"
        :selected-value="selectedMonthValue"
        :is-current-month="isCurrentMonth"
        @step="stepMonth"
        @select="selectMonthByValue"
      />
    </div>

    <!-- Loading -->
    <MkSkeleton v-if="loading && !card" />

    <!-- Error -->
    <Message
      v-else-if="error"
      severity="error"
      :closable="false"
      class="mk-tab__message"
    >
      <div class="mk-tab__error">
        <span>{{ t('motivation.card.load_error') }}</span>
        <Button
          size="small"
          severity="secondary"
          outlined
          :label="t('common.retry')"
          @click="reload"
        />
      </div>
    </Message>

    <!-- Empty: no card created -->
    <div v-else-if="card && !card.meta.has_card" class="mk-tab__empty">
      <i class="pi pi-file-edit mk-tab__empty-icon" aria-hidden="true" />
      <p class="mk-tab__empty-text">{{ t('motivation.card.no_card') }}</p>
      <Button
        v-if="canManage"
        :label="t('motivation.card.go_to_builder')"
        icon="pi pi-arrow-right"
        icon-pos="right"
        severity="secondary"
        outlined
        @click="goToBuilder"
      />
    </div>

    <!-- Card content -->
    <template v-else-if="card">
      <Message
        v-if="card.meta.multi_currency_warning"
        severity="warn"
        :closable="false"
        icon="pi pi-info-circle"
        class="mk-tab__message"
      >
        {{ t('managerCabinet.multiCurrencyWarning') }}
      </Message>

      <MkHeader :meta="card.meta" class="mk-tab__section" />

      <MkDeptPlan
        :dept-plan="card.dept_plan"
        :pipeline-name="card.meta.pipeline?.name ?? null"
        :period-label="card.meta.period.label"
        class="mk-tab__section"
      />

      <div class="row g-4 mk-tab__section">
        <div class="col-12 col-lg-8 order-2 order-lg-1">
          <MkSalaryTable :items="card.items" :total="card.total" />
        </div>
        <div class="col-12 col-lg-4 order-1 order-lg-2">
          <MkTotalCard
            :total="card.total"
            :forecast="card.team_bonus_forecast"
            :fact-source="card.meta.fact_source"
          />
        </div>
      </div>

      <MkKpiStrip :items="card.items" class="mk-tab__section" />

      <MkRatesFooter v-if="card.rates" :rates="card.rates" />
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Message from 'primevue/message'
import Button from 'primevue/button'
import MkMonthNav from './MkMonthNav.vue'
import MkHeader from './MkHeader.vue'
import MkDeptPlan from './MkDeptPlan.vue'
import MkSalaryTable from './MkSalaryTable.vue'
import MkTotalCard from './MkTotalCard.vue'
import MkKpiStrip from './MkKpiStrip.vue'
import MkRatesFooter from './MkRatesFooter.vue'
import MkSkeleton from './MkSkeleton.vue'
import { useMotivationTab } from '../../composables/useMotivationTab'
import { useMotivationPermissions } from '@/composables/useMotivationPermissions'

const props = defineProps<{
  viewedUserId: number | null
}>()

const { t } = useI18n()
const router = useRouter()
const { canManage } = useMotivationPermissions()

const {
  card,
  loading,
  error,
  isCurrentMonth,
  monthOptions,
  selectedMonthValue,
  stepMonth,
  selectMonthByValue,
  reload,
} = useMotivationTab(() => props.viewedUserId)

const goToBuilder = (): void => {
  void router.push('/settings?section=motivation-builder')
}
</script>

<style lang="scss" scoped>
.mk-tab {
  display: flex;
  flex-direction: column;
}

.mk-tab__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-4;
  margin-bottom: $space-4;
  flex-wrap: wrap;
}

.mk-tab__title {
  font-size: var(--app-font-size-lg, 1.125rem);
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
}

.mk-tab__section {
  margin-bottom: $space-4;
}

.mk-tab__message {
  margin-bottom: $space-4;
}

.mk-tab__error {
  display: flex;
  align-items: center;
  gap: $space-3;
}

.mk-tab__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.mk-tab__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.mk-tab__empty-text {
  margin: 0;
  font-size: $font-size-base;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}
</style>
