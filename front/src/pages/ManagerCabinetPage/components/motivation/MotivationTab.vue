<template>
  <div class="mk-tab">
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
    <div v-else-if="card" class="mk-tab__body">
      <!-- Refetch overlay: while a NEW period/user card is loading over an
           already-visible card, dim it + spinner so the previous month's
           numbers are not mistaken for the current selection. -->
      <div v-if="loading" class="mk-tab__overlay">
        <ProgressSpinner style="width: 40px; height: 40px" stroke-width="4" />
      </div>

      <!-- Compact header row: avatar + name · pipeline + status pill -->
      <MkHeader :meta="card.meta" class="mk-tab__section" />

      <!-- Pay hero (salary + team-bonus forecast) -->
      <MkPayHero
        :total="card.total"
        :forecast="card.team_bonus_forecast"
        :fact-source="card.meta.fact_source"
        :period-label="card.meta.period.label"
        class="mk-tab__section"
      />

      <MkDeptPlan
        :dept-plan="card.dept_plan"
        :pipeline-name="card.meta.pipeline?.name ?? null"
        :period-label="card.meta.period.label"
        class="mk-tab__section"
      />

      <MkSalaryTable :items="card.items" :total="card.total" class="mk-tab__section" />

      <MkRatesFooter v-if="card.rates" :rates="card.rates" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Message from 'primevue/message'
import Button from 'primevue/button'
import ProgressSpinner from 'primevue/progressspinner'
import MkHeader from './MkHeader.vue'
import MkPayHero from './MkPayHero.vue'
import MkDeptPlan from './MkDeptPlan.vue'
import MkSalaryTable from './MkSalaryTable.vue'
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

const { card, loading, error, reload } = useMotivationTab(() => props.viewedUserId)

const goToBuilder = (): void => {
  void router.push('/settings?section=motivation-builder')
}
</script>

<style lang="scss" scoped>
.mk-tab {
  display: flex;
  flex-direction: column;
}

.mk-tab__body {
  position: relative;
}

.mk-tab__overlay {
  position: absolute;
  inset: 0;
  z-index: 5;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: $space-8;
  border-radius: $radius-lg;
  background: color-mix(in srgb, $surface-card 55%, transparent);
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
