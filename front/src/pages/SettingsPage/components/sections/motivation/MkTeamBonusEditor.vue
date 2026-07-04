<template>
  <div class="mkb-card mkb-team">
    <header class="mkb-card__head">
      <span class="mkb-eyebrow">{{ t('motivation.builder.step_team_bonus') }}</span>
    </header>

    <div class="row g-4">
      <!-- Dept plan -->
      <div class="col-12 col-md-6">
        <div class="mkb-team__field">
          <label class="mkb-label">{{ t('motivation.builder.team_pipeline') }}</label>
          <SelectButton
            :model-value="teamRule.pipelineId"
            :options="pipelineOptions"
            option-label="label"
            option-value="value"
            :allow-empty="false"
            :disabled="disabled"
            @update:model-value="(v) => emit('update', 'pipelineId', v as number)"
          />
        </div>

        <div class="mkb-team__field">
          <label class="mkb-label">{{ t('motivation.builder.dept_plan_label') }}</label>
          <div class="mkb-team__money">
            <InputNumber
              :model-value="teamRule.teamIncomeTarget"
              :disabled="disabled"
              :min="0"
              locale="ru-RU"
              placeholder="800 000"
              class="mkb-team__money-input"
              @update:model-value="(v) => emit('update', 'teamIncomeTarget', v)"
            />
            <Select
              :model-value="teamRule.targetCurrency"
              :options="currencyOptions"
              option-label="label"
              option-value="value"
              :disabled="disabled"
              class="mkb-team__currency"
              @update:model-value="(v) => emit('update', 'targetCurrency', v as string)"
            />
          </div>
        </div>
      </div>

      <!-- Team bonus -->
      <div class="col-12 col-md-6">
        <div class="mkb-team__field">
          <label class="mkb-label">{{ t('motivation.builder.bonus_pool_label') }}</label>
          <div class="mkb-team__money">
            <InputNumber
              :model-value="teamRule.basePool"
              :disabled="disabled"
              :min="0"
              locale="ru-RU"
              placeholder="500 000"
              class="mkb-team__money-input"
              @update:model-value="(v) => emit('update', 'basePool', v)"
            />
            <Select
              :model-value="teamRule.poolCurrency"
              :options="currencyOptions"
              option-label="label"
              option-value="value"
              :disabled="disabled"
              class="mkb-team__currency"
              @update:model-value="(v) => emit('update', 'poolCurrency', v as string)"
            />
          </div>
          <small v-if="errors.pool" class="mkb-error">{{ errors.pool }}</small>
        </div>

        <div class="mkb-team__field">
          <label class="mkb-label">{{ t('motivation.builder.bonus_threshold_label') }}</label>
          <InputNumber
            :model-value="teamRule.minThresholdPct"
            :disabled="disabled"
            :min="1"
            :max="100"
            suffix=" %"
            class="mkb-team__pct"
            @update:model-value="(v) => emit('update', 'minThresholdPct', v ?? 0)"
          />
          <small v-if="errors.threshold" class="mkb-error">{{ errors.threshold }}</small>
        </div>

        <div class="mkb-team__split">
          <div class="mkb-team__field mkb-team__field--half">
            <label class="mkb-label">{{ t('motivation.builder.bonus_part1_label') }}</label>
            <InputNumber
              :model-value="teamRule.splitContributionPct"
              :disabled="disabled"
              :min="1"
              :max="99"
              suffix=" %"
              class="mkb-team__pct"
              @update:model-value="(v) => emit('update', 'splitContributionPct', v ?? 0)"
            />
          </div>
          <div class="mkb-team__field mkb-team__field--half">
            <label class="mkb-label">{{ t('motivation.builder.bonus_part2_label') }}</label>
            <InputNumber
              :model-value="splitEqualPct"
              disabled
              suffix=" %"
              class="mkb-team__pct mkb-team__pct--auto"
            />
          </div>
        </div>
        <small v-if="errors.split" class="mkb-error">{{ errors.split }}</small>
      </div>
    </div>

    <!-- N>2 pool info -->
    <div class="mkb-team__extra">
      <p class="mkb-hint">
        <i class="pi pi-info-circle" aria-hidden="true" />
        {{ t('motivation.builder.pool_extra_hint') }}
      </p>
      <div class="mkb-team__money mkb-team__money--extra">
        <InputNumber
          :model-value="teamRule.perExtraMember"
          :disabled="disabled"
          :min="0"
          locale="ru-RU"
          :placeholder="t('motivation.builder.bonus_per_member_label')"
          class="mkb-team__money-input"
          @update:model-value="(v) => emit('update', 'perExtraMember', v)"
        />
        <Select
          :model-value="teamRule.poolCurrency"
          :options="currencyOptions"
          option-label="label"
          option-value="value"
          :disabled="disabled"
          class="mkb-team__currency"
          @update:model-value="(v) => emit('update', 'poolCurrency', v as string)"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import { type TeamRuleForm } from './useMotivationBuilder'

defineProps<{
  teamRule: TeamRuleForm
  splitEqualPct: number
  currencyOptions: { label: string; value: string }[]
  // Live pipelines loaded from /api/pipelines (owned by useMotivationBuilder).
  pipelineOptions: { label: string; value: number }[]
  errors: Record<string, string>
  disabled: boolean
}>()

// Emit field updates instead of mutating the prop (vue/no-mutating-props). The
// parent applies them to its own reactive `teamRule`.
const emit = defineEmits<{
  update: [key: keyof TeamRuleForm, value: TeamRuleForm[keyof TeamRuleForm]]
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
@use '@/pages/SettingsPage/components/sections/motivation/mkb-shared' as *;

.mkb-team__field {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  margin-bottom: $space-4;

  &--half {
    flex: 1;
  }
}

.mkb-team__money {
  display: flex;
  gap: $space-2;

  &--extra {
    max-width: 320px;
  }
}

.mkb-team__money-input {
  flex: 1;
}

.mkb-team__currency {
  width: 100px;
}

.mkb-team__pct {
  width: 100%;
}

.mkb-team__pct--auto :deep(.p-inputnumber-input) {
  background: $surface-100;

  .app-dark & {
    background: var(--p-surface-100);
  }
}

.mkb-team__split {
  display: flex;
  gap: $space-3;
}

.mkb-team__extra {
  margin-top: $space-2;
  padding-top: $space-3;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

.mkb-team__extra .mkb-hint {
  margin: 0 0 $space-2;
}
</style>
