<template>
  <!--
    Pipeline-editor form-mode toolbar (Periphery uplift §D.2) — one row matching
    the core 2.0 canon (HubToolbar): 38×38 icon tile + title → spacer → segmented
    "Форма / Полотно" (PrimeVue SelectButton, already the canon control). Shown only
    in form mode; canvas mode keeps its own compact bar. Presentational: props down,
    v-model up. All view-mode logic stays in the page.
  -->
  <div class="pipeline-settings-toolbar">
    <span class="pipeline-settings-toolbar__icon-tile">
      <i class="pi pi-sliders-h" aria-hidden="true" />
    </span>

    <div class="pipeline-settings-toolbar__heading">
      <h1 class="pipeline-settings-toolbar__title">{{ t('sales.pipelineEditor.pageTitle') }}</h1>
    </div>

    <span class="pipeline-settings-toolbar__spacer" />

    <SelectButton
      v-model="viewMode"
      :options="viewModeOptions"
      option-label="label"
      option-value="value"
      :allow-empty="false"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import SelectButton from 'primevue/selectbutton'

interface ViewModeOption {
  label: string
  value: 'form' | 'canvas'
}

defineProps<{
  viewModeOptions: ViewModeOption[]
}>()

const viewMode = defineModel<'form' | 'canvas'>({ required: true })

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.pipeline-settings-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-3;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 14px $space-5; // toolbar row height matches DealsToolbar / HubToolbar
  background: $surface-card;
  border-bottom: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

.pipeline-settings-toolbar__icon-tile {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  border-radius: $radius-md;
  background: $primary-100;

  .app-dark & {
    background: color-mix(in srgb, $primary-900 35%, transparent);
  }

  i {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    font-size: 17px; // icon tile — between icon-sm (18px) and font-size-md (16px); no exact token
    color: $primary-900;

    .app-dark & {
      color: var(--p-primary-200);
    }
  }
}

.pipeline-settings-toolbar__heading {
  min-width: 0;
}

.pipeline-settings-toolbar__title {
  margin: 0;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 19px; // page h1 — between font-size-lg (18px) and font-size-xl (20px); no exact token
  font-weight: $font-weight-semibold;
  color: $surface-900;
  line-height: 1.1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.pipeline-settings-toolbar__spacer {
  flex: 1;
  min-width: $space-3;
}
</style>
