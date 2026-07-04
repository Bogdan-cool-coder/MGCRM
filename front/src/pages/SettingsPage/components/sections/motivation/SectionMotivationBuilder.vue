<template>
  <div class="section-mkb">
    <!-- Access denied guard (permission-gated) -->
    <Message
      v-if="!canManage"
      severity="warn"
      :closable="false"
      class="section-mkb__denied"
    >
      {{ t('motivation.builder.access_denied') }}
    </Message>

    <template v-else>
      <div class="section-mkb__head">
        <h2 class="section-mkb__title">{{ t('motivation.builder.title') }}</h2>
        <p class="section-mkb__crumb">{{ t('motivation.builder.breadcrumb') }}</p>
      </div>

      <!-- Step 1 — always visible -->
      <MkBuilderHeader
        v-model:model-employee="builder.selectedEmployee.value"
        :year="builder.year.value"
        :month="builder.month.value"
        :year-options="builder.yearOptions.value"
        :loading="builder.loading.value"
        :error="builder.errors.employee"
        class="section-mkb__block"
        @update:year="(v) => (builder.year.value = v)"
        @update:month="(v) => (builder.month.value = v)"
        @load="builder.loadCard"
      />

      <!-- Steps 2–5 — after a card is loaded -->
      <div v-if="builder.loaded.value" class="section-mkb__loaded">
        <div v-if="builder.loading.value" class="section-mkb__overlay">
          <ProgressSpinner style="width: 44px; height: 44px" stroke-width="4" />
        </div>

        <MkPositionToggles
          :rows="builder.rows"
          :disabled="builder.isReadOnly.value"
          class="section-mkb__block"
        />

        <MkPlanTable
          :rows="builder.rows"
          :currency-options="builder.currencyOptions"
          :errors="builder.errors"
          :disabled="builder.isReadOnly.value"
          class="section-mkb__block"
        />

        <MkTeamBonusEditor
          v-if="teamKpiEnabled"
          :team-rule="builder.teamRule"
          :split-equal-pct="builder.splitEqualPct.value"
          :currency-options="builder.currencyOptions"
          :pipeline-options="builder.pipelineOptions.value"
          :errors="builder.errors"
          :disabled="builder.isReadOnly.value"
          class="section-mkb__block"
          @update="builder.updateTeamRule"
        />

        <MkCurrencyEditor
          :base-currency="builder.baseCurrency.value"
          :currency-options="builder.currencyOptions"
          :auto-rates="builder.autoRates.value"
          :rates-loading="builder.ratesLoading.value"
          :disabled="builder.isReadOnly.value"
          class="section-mkb__block"
          @update:base-currency="(v) => (builder.baseCurrency.value = v)"
          @refresh="builder.refreshRates"
        />

        <MkSaveBar
          :total-kopecks="builder.salaryPlanTotalKopecks.value"
          :base-currency="builder.baseCurrency.value"
          :status="builder.status.value"
          :card-exists="builder.cardExists.value"
          :read-only="builder.isReadOnly.value"
          :can-transition="canTransition"
          :can-persist="builder.canPersist.value"
          :saving="builder.saving.value"
          :copying-prev="builder.copyingPrev.value"
          :transitioning="builder.transitioning.value"
          @save="builder.save"
          @copy-prev="builder.copyPreviousMonth"
          @finalize="builder.finalize"
          @mark-paid="builder.markPaid"
        />
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import MkBuilderHeader from './MkBuilderHeader.vue'
import MkPositionToggles from './MkPositionToggles.vue'
import MkPlanTable from './MkPlanTable.vue'
import MkTeamBonusEditor from './MkTeamBonusEditor.vue'
import MkCurrencyEditor from './MkCurrencyEditor.vue'
import MkSaveBar from './MkSaveBar.vue'
import { useMotivationBuilder } from './useMotivationBuilder'
import { useMotivationPermissions } from '@/composables/useMotivationPermissions'

const { t } = useI18n()
const { canManage, canTransition } = useMotivationPermissions()

const builder = useMotivationBuilder()

const teamKpiEnabled = computed<boolean>(
  () => builder.rows.find((r) => r.kind === 'team_kpi')?.enabled ?? false,
)
</script>

<style lang="scss" scoped>
.section-mkb {
  padding: $space-6;
}

.section-mkb__denied {
  max-width: 480px;
}

.section-mkb__head {
  margin-bottom: $space-4;
}

.section-mkb__title {
  font-size: var(--app-font-size-lg, 1.125rem);
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-1;
}

.section-mkb__crumb {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.section-mkb__loaded {
  position: relative;
}

.section-mkb__block {
  margin-bottom: $space-4;
}

.section-mkb__overlay {
  position: absolute;
  inset: 0;
  // Above the sticky MkSaveBar (z-index:10) so its Save/Copy/Finalize buttons
  // are covered (not clickable) while the loading spinner is shown.
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
  background: color-mix(in srgb, $surface-card 60%, transparent);
  border-radius: $radius-lg;
}
</style>
