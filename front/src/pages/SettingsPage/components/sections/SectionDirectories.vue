<template>
  <div class="dir-section">
    <!-- Access denied: user has no access to any directories section -->
    <div v-if="!hasAnyAccess" class="dir-section__access-denied">
      <i class="pi pi-lock dir-section__lock-icon" />
      <p>{{ t('common.access_denied') }}</p>
    </div>

    <template v-else>
      <!-- Sub-header -->
      <div class="dir-section__header">
        <h2 class="dir-section__title">{{ t('settings.directories.sectionTitle') }}</h2>
        <p class="dir-section__desc">{{ t('settings.directories.sectionDesc') }}</p>
      </div>

      <!-- Tab bar (line-style) -->
      <div class="dir-tabs">
        <Tabs :value="activeTab" @update:value="onTabChange">
          <TabList>
            <Tab v-if="isAdminOrDirector" value="countries">{{ t('settings.directories.tabs.countries') }}</Tab>
            <Tab v-if="isAdminOrDirector" value="tags">{{ t('settings.directories.tabs.tags') }}</Tab>
            <Tab v-if="isAdminOrDirector" value="custom-fields">{{ t('settings.directories.tabs.customFields') }}</Tab>
            <Tab v-if="isAdminOrDirector" value="acq-channels">{{ t('settings.directories.tabs.acqChannels') }}</Tab>
            <Tab v-if="isAdminOrDirector" value="disc-reasons">{{ t('settings.directories.tabs.discReasons') }}</Tab>
            <Tab v-if="isAdminOrDirector" value="catalog">{{ t('settings.directories.tabs.catalog') }}</Tab>
            <Tab v-if="isAdminOrDirector" value="exchange-rates">{{ t('settings.directories.tabs.exchangeRates') }}</Tab>
            <Tab v-if="canSeeDocTemplates" value="doc-templates">{{ t('settings.directories.tabs.docTemplates') }}</Tab>
            <Tab v-if="canSeeTplVariables" value="tpl-variables">{{ t('settings.directories.tabs.tplVariables') }}</Tab>
            <Tab v-if="canSeeApprovalRoutes" value="approval-routes">{{ t('settings.directories.tabs.approvalRoutes') }}</Tab>
            <Tab v-if="canSeeMsgTemplates" value="msg-templates">{{ t('settings.directories.tabs.msgTemplates') }}</Tab>
          </TabList>
        </Tabs>
      </div>

      <!-- Tab content — v-if for lazy load (each mounts on first show) -->
      <div class="dir-tab-content">
        <DirTabCountries v-if="activeTab === 'countries' && isAdminOrDirector" />
        <DirTabTags v-else-if="activeTab === 'tags' && isAdminOrDirector" />
        <DirTabCustomFields v-else-if="activeTab === 'custom-fields' && isAdminOrDirector" />
        <DirTabAcqChannels v-else-if="activeTab === 'acq-channels' && isAdminOrDirector" />
        <DirTabDiscReasons v-else-if="activeTab === 'disc-reasons' && isAdminOrDirector" />
        <DirTabCatalog v-else-if="activeTab === 'catalog' && isAdminOrDirector" />
        <DirTabExchangeRates v-else-if="activeTab === 'exchange-rates' && isAdminOrDirector" />
        <DirTabDocTemplates v-else-if="activeTab === 'doc-templates' && canSeeDocTemplates" />
        <DirTabTplVariables v-else-if="activeTab === 'tpl-variables' && canSeeTplVariables" />
        <DirTabApprovalRoutes v-else-if="activeTab === 'approval-routes' && canSeeApprovalRoutes" />
        <DirTabMsgTemplates v-else-if="activeTab === 'msg-templates' && canSeeMsgTemplates" />
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import { useUserStore } from '@/stores/user'
import DirTabCountries from './directories/DirTabCountries.vue'
import DirTabTags from './directories/DirTabTags.vue'
import DirTabCustomFields from './directories/DirTabCustomFields.vue'
import DirTabAcqChannels from './directories/DirTabAcqChannels.vue'
import DirTabDiscReasons from './directories/DirTabDiscReasons.vue'
import DirTabCatalog from './directories/DirTabCatalog.vue'
import DirTabExchangeRates from './directories/DirTabExchangeRates.vue'
import DirTabDocTemplates from './directories/DirTabDocTemplates.vue'
import DirTabTplVariables from './directories/DirTabTplVariables.vue'
import DirTabApprovalRoutes from './directories/DirTabApprovalRoutes.vue'
import DirTabMsgTemplates from './directories/DirTabMsgTemplates.vue'

const { t } = useI18n()
const userStore = useUserStore()

defineProps<{
  activeTab: string
}>()

const emit = defineEmits<{
  tabChange: [key: string]
}>()

const userRole = computed(() => userStore.getUserRole ?? '')

const isAdminOrDirector = computed(() =>
  userRole.value === 'admin' || userRole.value === 'director',
)

const canSeeDocTemplates = computed(() =>
  ['admin', 'lawyer', 'director'].includes(userRole.value),
)
const canSeeTplVariables = computed(() =>
  ['admin', 'lawyer', 'director'].includes(userRole.value),
)
const canSeeApprovalRoutes = computed(() =>
  ['admin', 'lawyer'].includes(userRole.value),
)
const canSeeMsgTemplates = computed(() =>
  ['admin', 'lawyer', 'director', 'manager'].includes(userRole.value),
)

/** True if the current user can see at least one tab in this section */
const hasAnyAccess = computed(() =>
  isAdminOrDirector.value ||
  canSeeDocTemplates.value ||
  canSeeTplVariables.value ||
  canSeeApprovalRoutes.value ||
  canSeeMsgTemplates.value,
)

function onTabChange(value: string | number) {
  emit('tabChange', String(value))
}
</script>

<style lang="scss" scoped>
.dir-section {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.dir-section__access-denied {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8;
  color: var(--p-text-muted-color);
}

.dir-section__lock-icon {
  font-size: $font-size-2xl;
  opacity: 0.4;
}

.dir-section__header {
  padding: $space-4 $space-6 $space-3;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-200);
  }
}

.dir-section__title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-1;

  .app-dark & {
    color: var(--p-surface-50);
  }
}

.dir-section__desc {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Tab bar — line-style override on top of PrimeVue defaults
.dir-tabs {
  flex-shrink: 0;

  :deep(.p-tablist) {
    padding: 0 $space-6;
    background: $surface-card;
    border-bottom: 1px solid $surface-200;
    overflow-x: auto;

    .app-dark & {
      background: var(--p-surface-100);
      border-bottom-color: var(--p-surface-200);
    }
  }

  :deep(.p-tab) {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    padding: $space-3 $space-4;
    color: $surface-600;
    border-bottom: 2px solid transparent;
    transition: color var(--app-transition-fast), border-color var(--app-transition-fast);
    cursor: pointer;
    white-space: nowrap;

    &:hover {
      color: $surface-900;
    }

    .app-dark & {
      color: var(--p-surface-400);

      &:hover {
        color: var(--p-surface-100);
      }
    }
  }

  :deep(.p-tab[data-p-active="true"]),
  :deep(.p-tab-active) {
    color: $primary-900;
    font-weight: $font-weight-semibold;
    border-bottom-color: $primary-900;

    .app-dark & {
      color: var(--p-primary-200);
      border-bottom-color: var(--p-primary-200);
    }
  }

  // Hide PrimeVue default active-bar indicator (we use border-bottom instead)
  :deep(.p-tablist-active-bar) {
    display: none;
  }
}

.dir-tab-content {
  flex: 1;
  overflow-y: auto;
  background: $surface-50;

  .app-dark & {
    background: var(--p-surface-50);
  }
}
</style>
