<template>
  <div v-if="open" class="pipeline-menu">
    <div
      v-for="pipeline in pipelines"
      :key="pipeline.id"
      class="pipeline-menu__item"
      :class="{ 'pipeline-menu__item--active': pipeline.id === activePipelineId }"
      @click="onSelect(pipeline.id)"
    >
      <i :class="['pipeline-menu__icon', pipelineIcon(pipeline.kind)]" />
      <div class="pipeline-menu__labels">
        <span class="pipeline-menu__name">{{ pipeline.name }}</span>
        <span class="pipeline-menu__subtitle">{{ pipelineSubtitle(pipeline.kind) }}</span>
      </div>
      <i v-if="pipeline.id === activePipelineId" class="pi pi-check pipeline-menu__check" />
    </div>

    <div v-if="pipelines.length" class="pipeline-menu__divider" />

    <div class="pipeline-menu__item pipeline-menu__item--settings" @click="onSettings">
      <i class="pi pi-cog pipeline-menu__icon" />
      <div class="pipeline-menu__labels">
        <span class="pipeline-menu__name">{{ t('sales.deals.page.toolbar.pipelineSettings') }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import type { PipelineDto } from '@/entities/sales'

defineProps<{
  open: boolean
  pipelines: PipelineDto[]
  activePipelineId: number | null
}>()

const emit = defineEmits<{
  setPipeline: [id: number]
  close: []
}>()

const { t } = useI18n()
const router = useRouter()

function pipelineIcon(kind: string | null): string {
  if (kind === 'onboarding') return 'pi pi-flag'
  if (kind === 'partner') return 'pi pi-users'
  return 'pi pi-sitemap'
}

function pipelineSubtitle(kind: string | null): string {
  if (kind === 'onboarding') return t('sales.deals.page.pipeline.onboardingSubtitle')
  if (kind === 'partner') return t('sales.deals.page.pipeline.partnerSubtitle')
  return t('sales.deals.page.pipeline.salesSubtitle')
}

function onSelect(id: number) {
  emit('setPipeline', id)
  emit('close')
}

function onSettings() {
  void router.push({ name: 'PipelineSettings' })
  emit('close')
}
</script>

<style lang="scss" scoped>
.pipeline-menu {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 30;
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-overlay-sm;
  min-width: 240px;
  padding: $space-1 0;

  .app-dark & {
    border-color: var(--p-surface-300);
  }
}

.pipeline-menu__item {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  cursor: pointer;
  transition: background var(--app-transition-fast);

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-100);
    }
  }

  &--active {
    background: $primary-100;

    .app-dark & {
      background: rgba(23, 39, 71, 0.35);
    }
  }

  &--settings {
    .pipeline-menu__name {
      color: $surface-600;

      .app-dark & {
        color: var(--p-surface-300);
      }
    }
  }
}

.pipeline-menu__icon {
  font-size: $font-size-sm;
  color: $surface-500;
  flex-shrink: 0;
  width: 16px;
  text-align: center;

  .pipeline-menu__item--active & {
    color: $primary-900;
  }

  .app-dark & {
    color: var(--p-surface-400);
  }

  .pipeline-menu__item--active .app-dark & {
    color: var(--p-primary-200);
  }
}

.pipeline-menu__labels {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.pipeline-menu__name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
  line-height: 1.2;

  .app-dark & {
    color: var(--p-surface-50);
  }
}

.pipeline-menu__subtitle {
  font-size: $font-size-xs;
  color: $surface-400;
  line-height: 1.2;
  margin-top: 1px;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.pipeline-menu__check {
  font-size: $font-size-xs;
  color: $primary-900;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-primary-200);
  }
}

.pipeline-menu__divider {
  height: 1px;
  background: $surface-100;
  margin: $space-1 0;

  .app-dark & {
    background: var(--p-surface-700);
  }
}
</style>
