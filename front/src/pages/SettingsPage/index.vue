<template>
  <div class="settings-page">
    <PageHeader
      :title="t('settings.title')"
      icon="pi pi-cog"
      :subtitle="t('settings.subtitle')"
    />

    <div class="settings-page__body">
      <div class="row g-4">
        <div
          v-for="section in sections"
          :key="section.key"
          class="col-md-6 col-lg-4"
        >
          <router-link :to="section.route" class="settings-card" tabindex="0">
            <div class="settings-card__icon-wrap">
              <i :class="['settings-card__icon', section.icon]" />
            </div>
            <div class="settings-card__body">
              <h3 class="settings-card__title">{{ t(section.titleKey) }}</h3>
              <p class="settings-card__desc">{{ t(section.descKey) }}</p>
            </div>
            <i class="pi pi-chevron-right settings-card__arrow" />
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'

const { t } = useI18n()

interface SettingsSection {
  key: string
  route: string
  icon: string
  titleKey: string
  descKey: string
}

const sections: SettingsSection[] = [
  {
    key: 'pipeline',
    route: '/settings/pipeline',
    icon: 'pi pi-sliders-h',
    titleKey: 'settings.sections.pipeline.title',
    descKey: 'settings.sections.pipeline.desc',
  },
  {
    key: 'templates',
    route: '/admin/templates',
    icon: 'pi pi-file-edit',
    titleKey: 'settings.sections.templates.title',
    descKey: 'settings.sections.templates.desc',
  },
  {
    key: 'template-variables',
    route: '/admin/template-variables',
    icon: 'pi pi-list',
    titleKey: 'settings.sections.templateVariables.title',
    descKey: 'settings.sections.templateVariables.desc',
  },
  {
    key: 'approval-routes',
    route: '/admin/approval-routes',
    icon: 'pi pi-sitemap',
    titleKey: 'settings.sections.approvalRoutes.title',
    descKey: 'settings.sections.approvalRoutes.desc',
  },
  {
    key: 'message-templates',
    route: '/admin/message-templates',
    icon: 'pi pi-envelope',
    titleKey: 'settings.sections.messageTemplates.title',
    descKey: 'settings.sections.messageTemplates.desc',
  },
  {
    key: 'automation-runs',
    route: '/admin/automation-runs',
    icon: 'pi pi-clock',
    titleKey: 'settings.sections.automationRuns.title',
    descKey: 'settings.sections.automationRuns.desc',
  },
  {
    key: 'acquisition-channels',
    route: '/admin/acquisition-channels',
    icon: 'pi pi-megaphone',
    titleKey: 'settings.sections.acquisitionChannels.title',
    descKey: 'settings.sections.acquisitionChannels.desc',
  },
  {
    key: 'disconnect-reasons',
    route: '/admin/disconnect-reasons',
    icon: 'pi pi-times-circle',
    titleKey: 'settings.sections.disconnectReasons.title',
    descKey: 'settings.sections.disconnectReasons.desc',
  },
]
</script>

<style lang="scss" scoped>
.settings-page {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.settings-page__body {
  flex: 1;
  padding: $space-6;
  overflow-y: auto;
}

// ─── Settings card ────────────────────────────────────────────────────────────
.settings-card {
  display: flex;
  align-items: center;
  gap: $space-4;
  padding: $space-4 $space-5;
  background-color: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  text-decoration: none;
  color: inherit;
  transition:
    border-color var(--app-transition-fast),
    box-shadow var(--app-transition-fast),
    background-color var(--app-transition-fast);
  cursor: pointer;
  height: 100%;
  min-height: 80px;

  &:hover {
    border-color: var(--p-primary-300);
    box-shadow: $shadow-card-hover;
  }

  &:focus-visible {
    outline: 2px solid var(--p-primary-500);
    outline-offset: 2px;
  }
}

.settings-card__icon-wrap {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  border-radius: $radius-md;
  background-color: var(--p-primary-50);
  display: flex;
  align-items: center;
  justify-content: center;

  :global(.app-dark) & {
    background-color: rgba($primary-900, 0.3);
  }
}

.settings-card__icon {
  font-size: $font-size-xl;
  color: var(--p-primary-600);
}

.settings-card__body {
  flex: 1;
  min-width: 0;
}

.settings-card__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.settings-card__desc {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  line-height: $line-height-normal;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.settings-card__arrow {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
  transition: color var(--app-transition-fast), transform var(--app-transition-fast);

  .settings-card:hover & {
    color: var(--p-primary-500);
    transform: translateX(2px);
  }
}
</style>
