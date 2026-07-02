<template>
  <div class="section-system-reset">
    <!-- Access denied if not admin (director can't reach this section) -->
    <div v-if="!isAdmin" class="section-system-reset__denied">
      <i class="pi pi-lock section-system-reset__denied-icon" />
      <p>{{ t('common.access_denied') }}</p>
    </div>

    <div v-else class="section-system-reset__inner">
      <!-- Section header -->
      <div class="reset-head">
        <h2 class="reset-head__title">{{ t('settings.system.reset.title') }}</h2>
        <p class="reset-head__desc">{{ t('settings.system.reset.desc') }}</p>
      </div>

      <!-- Danger banner (red zone) -->
      <Message
        severity="error"
        :closable="false"
        icon="pi pi-exclamation-triangle"
        class="reset-banner"
      >
        <b class="reset-banner__lead">{{ t('settings.system.reset.dangerLead') }}</b>
        {{ ' ' + t('settings.system.reset.dangerBanner') }}
      </Message>

      <!-- Unavailable state: backend endpoints not deployed yet -->
      <div v-if="reset.unavailable.value" class="reset-empty">
        <i class="pi pi-cloud reset-empty__icon" aria-hidden="true" />
        <span class="reset-empty__title">{{ t('settings.system.reset.unavailableTitle') }}</span>
        <span class="reset-empty__hint">{{ t('settings.system.reset.unavailableHint') }}</span>
      </div>

      <!-- Disabled for environment (config flag off) -->
      <div v-else-if="!reset.previewEnabled.value && !reset.previewLoading.value" class="reset-empty">
        <i class="pi pi-ban reset-empty__icon" aria-hidden="true" />
        <span class="reset-empty__title">{{ t('settings.system.reset.disabledTitle') }}</span>
        <span class="reset-empty__hint">{{ t('settings.system.reset.disabledHint') }}</span>
      </div>

      <!-- Loading skeleton -->
      <div v-else-if="reset.previewLoading.value" class="reset-skeleton">
        <Skeleton height="46px" class="mb-2" />
        <Skeleton v-for="i in 6" :key="i" height="58px" class="mb-2" />
      </div>

      <template v-else>
        <!-- Selection card -->
        <SystemResetCategories :reset="reset" class="reset-selection" />

        <!-- Confirmation card -->
        <div class="reset-confirm">
          <div class="reset-confirm__title">{{ t('settings.system.reset.confirmTitle') }}</div>
          <p class="reset-confirm__hint">
            <span>{{ confirmHintPrefix }}</span>
            <code class="reset-confirm__phrase">{{ reset.confirmationPhrase.value }}</code>
            <span>{{ t('settings.system.reset.confirmHintSuffix') }}</span>
          </p>
          <div class="reset-confirm__row">
            <InputText
              v-model="reset.confirmWord.value"
              :placeholder="reset.confirmationPhrase.value"
              class="reset-confirm__input"
              :class="{ 'reset-confirm__input--invalid': reset.phraseInvalidShown.value }"
              :aria-invalid="reset.phraseInvalidShown.value"
              spellcheck="false"
              autocomplete="off"
            />
            <span class="reset-confirm__spacer" />
            <Button
              severity="danger"
              icon="pi pi-trash"
              :label="deleteBtnLabel"
              :disabled="!reset.canReset.value"
              :loading="reset.isPending.value"
              @click="reset.openConfirmModal()"
            />
          </div>
        </div>
      </template>
    </div>

    <!-- Final confirm modal -->
    <Dialog
      v-model:visible="reset.modalVisible.value"
      modal
      :header="t('settings.system.reset.modalTitle')"
      :style="{ width: '460px', maxWidth: '92vw' }"
      :dismissable-mask="true"
    >
      <div class="reset-modal__body">
        <span class="reset-modal__icon-wrap">
          <i class="pi pi-exclamation-triangle reset-modal__icon" aria-hidden="true" />
        </span>
        <p class="reset-modal__text">
          {{ t('settings.system.reset.modalBody', reset.selectedCount.value, {
            named: { n: reset.selectedCount.value, list: reset.selectedLabels.value.join(', ') },
          }) }}
        </p>
      </div>

      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="reset.isPending.value"
          @click="reset.closeConfirmModal()"
        />
        <Button
          severity="danger"
          icon="pi pi-trash"
          :label="t('settings.system.reset.modalConfirm')"
          :loading="reset.isPending.value"
          @click="reset.execute()"
        />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import InputText from 'primevue/inputtext'
import Dialog from 'primevue/dialog'
import Skeleton from 'primevue/skeleton'
import { useUserStore } from '@/stores/user'
import { useSystemResetSelective } from '../../composables/useSystemResetSelective'
import SystemResetCategories from './system/SystemResetCategories.vue'

const { t } = useI18n()
const userStore = useUserStore()

const isAdmin = computed(() => userStore.getUserRole === 'admin')

const reset = useSystemResetSelective()

onMounted(() => {
  if (isAdmin.value) {
    void reset.loadPreview()
  }
})

const confirmHintPrefix = computed(() =>
  reset.selectedCount.value > 0
    ? t('settings.system.reset.confirmHintPrefix', { n: reset.selectedCount.value })
    : t('settings.system.reset.confirmHintPrefixEmpty'),
)

const deleteBtnLabel = computed(() =>
  reset.selectedCount.value > 0
    ? t('settings.system.reset.deleteBtn', { n: reset.selectedCount.value })
    : t('settings.system.reset.deleteBtnEmpty'),
)
</script>

<style lang="scss" scoped>
.section-system-reset {
  height: 100%;
  overflow-y: auto;
}

.section-system-reset__denied {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8;
  color: var(--p-text-muted-color);
  min-height: 240px;
}

.section-system-reset__denied-icon {
  font-size: $font-size-2xl;
  opacity: 0.4;
}

.section-system-reset__inner {
  max-width: 680px;
  margin: 0 auto;
  padding: $space-6;
}

// ─── Header ───────────────────────────────────────────────────────────────────
.reset-head {
  margin-bottom: $space-5;
}

.reset-head__title {
  font-size: $font-size-xl;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.reset-head__desc {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;

  // Inverted dark scale: muted text needs surface-600 (#8593B0) to read on dark.
  .app-dark & {
    color: var(--p-surface-600);
  }
}

// ─── Danger banner ──────────────────────────────────────────────────────────
.reset-banner {
  margin-bottom: $space-4;
}

// Danger lead in the banner copy — red palette is NOT inverted in dark, so a
// single reactive token reads correctly on the Message error tint in both themes.
.reset-banner__lead {
  color: var(--p-red-600);
  font-weight: $font-weight-semibold;
}

// ─── Empty / unavailable / disabled states ────────────────────────────────────
.reset-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-8 $space-6;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  text-align: center;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

.reset-empty__icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
  opacity: 0.6;
}

.reset-empty__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.reset-empty__hint {
  font-size: $font-size-sm;
  color: $surface-500;
  max-width: 380px;

  // Inverted dark scale: muted text needs surface-600 to read on dark.
  .app-dark & {
    color: var(--p-surface-600);
  }
}

// ─── Skeleton ──────────────────────────────────────────────────────────────
.reset-skeleton {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  padding: $space-3;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

// ─── Selection card spacing ────────────────────────────────────────────────
.reset-selection {
  margin-bottom: $space-4;
}

// ─── Confirmation card ───────────────────────────────────────────────────────
.reset-confirm {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  padding: $space-5 $space-6;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

.reset-confirm__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin-bottom: $space-1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.reset-confirm__hint {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0 0 $space-3;
  line-height: $line-height-relaxed;

  // Inverted dark scale: muted text needs surface-600 to read on dark.
  .app-dark & {
    color: var(--p-surface-600);
  }
}

.reset-confirm__phrase {
  font-family: $font-family-mono;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0 4px;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.reset-confirm__row {
  display: flex;
  gap: $space-3;
  align-items: center;
  flex-wrap: wrap;
}

.reset-confirm__input {
  flex: 1;
  min-width: 200px;
  max-width: 280px;
  font-family: $font-family-mono;
  letter-spacing: 0.05em;

  &--invalid {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    border-color: var(--p-red-400);
  }
}

.reset-confirm__spacer {
  flex: 1;
}

// ─── Final modal ────────────────────────────────────────────────────────────
.reset-modal__body {
  display: flex;
  gap: $space-4;
  align-items: flex-start;
}

.reset-modal__icon-wrap {
  flex-shrink: 0;
  width: 42px;
  height: 42px;
  border-radius: $radius-circle;
  background: var(--p-red-50);
  display: flex;
  align-items: center;
  justify-content: center;

  .app-dark & {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(232, 120, 104, 0.18);
  }
}

.reset-modal__icon {
  font-size: $font-size-lg;
  color: var(--p-red-600);

  .app-dark & {
    color: var(--p-red-400);
  }
}

.reset-modal__text {
  font-size: $font-size-sm;
  color: $surface-700;
  line-height: $line-height-relaxed;
  margin: 0;
  padding-top: 2px;

  .app-dark & {
    color: var(--p-surface-800);
  }
}
</style>
