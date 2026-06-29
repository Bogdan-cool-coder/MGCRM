<template>
  <div class="section-system-reset">
    <!-- Access denied if not admin (director can't reach this section) -->
    <div v-if="!isAdmin" class="section-system-reset__denied">
      <i class="pi pi-lock section-system-reset__denied-icon" />
      <p>{{ t('common.access_denied') }}</p>
    </div>

    <template v-else>
      <!-- Section header -->
      <div class="section-system-reset__header">
        <h2 class="section-system-reset__title">{{ t('settings.system.reset.title') }}</h2>
        <p class="section-system-reset__desc">{{ t('settings.system.reset.desc') }}</p>
      </div>

      <!-- Hero danger block -->
      <div class="section-system-reset__content">
        <div class="reset-hero">
          <div class="reset-hero__icon-wrap">
            <i class="pi pi-exclamation-triangle reset-hero__icon" />
          </div>

          <div class="reset-hero__body">
            <h3 class="reset-hero__label">{{ t('system.reset.section_title') }}</h3>
            <p class="reset-hero__hint">{{ t('system.reset.section_hint') }}</p>
          </div>

          <Button
            :label="t('system.reset.open_dialog_btn')"
            icon="pi pi-refresh"
            severity="danger"
            outlined
            @click="reset.openDialog()"
          />
        </div>
      </div>
    </template>

    <!-- Dialog — rendered always so it can animate out properly -->
    <SystemResetDialog
      v-model:visible="reset.dialogVisible.value"
      v-model:confirm-input="reset.confirmInput.value"
      :is-confirmed="reset.isConfirmed.value"
      :is-pending="reset.isPending.value"
      :RESET_CONFIRM_PHRASE="reset.RESET_CONFIRM_PHRASE"
      @confirm="reset.executeReset()"
      @cancel="reset.closeDialog()"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import { useUserStore } from '@/stores/user'
import { useSystemReset } from '@/pages/ProfilePage/composables/useSystemReset'
import SystemResetDialog from '@/pages/ProfilePage/components/SystemResetDialog.vue'

const { t } = useI18n()
const userStore = useUserStore()
const reset = useSystemReset()

const isAdmin = computed(() => userStore.getUserRole === 'admin')
</script>

<style lang="scss" scoped>
.section-system-reset {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.section-system-reset__denied {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8;
  color: var(--p-text-muted-color);
}

.section-system-reset__denied-icon {
  font-size: $font-size-2xl;
  opacity: 0.4;
}

.section-system-reset__header {
  padding: $space-4 $space-6 $space-3;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-200);
  }
}

.section-system-reset__title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.section-system-reset__desc {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.section-system-reset__content {
  padding: $space-6;
}

// ─── Hero danger card ─────────────────────────────────────────────────────────

.reset-hero {
  display: flex;
  align-items: center;
  gap: $space-4;
  padding: $space-5 $space-6;
  background: $surface-card;
  border: 1px solid var(--p-red-200);
  border-radius: $radius-lg;
  border-left: 4px solid var(--p-red-500);

  .app-dark & {
    background: var(--p-surface-100);
    border-color: rgba(var(--p-red-500-rgb, 239 68 68), 0.3);
    border-left-color: var(--p-red-400);
  }
}

.reset-hero__icon-wrap {
  flex-shrink: 0;
  width: 48px;
  height: 48px;
  border-radius: $radius-md;
  background: rgba(var(--p-red-500-rgb, 239 68 68), 0.08);
  display: flex;
  align-items: center;
  justify-content: center;

  .app-dark & {
    background: rgba(var(--p-red-500-rgb, 239 68 68), 0.15);
  }
}

.reset-hero__icon {
  font-size: $font-size-xl;
  color: var(--p-red-500);

  .app-dark & {
    color: var(--p-red-400);
  }
}

.reset-hero__body {
  flex: 1;
  min-width: 0;
}

.reset-hero__label {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.reset-hero__hint {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  line-height: $line-height-relaxed;

  .app-dark & {
    color: var(--p-surface-400);
  }
}
</style>
