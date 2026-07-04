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

      <!-- Tab bar (line-style) — scrollable: prev/next buttons appear when the
           11 tabs (~1400px) overflow the detail panel (~750px @ 1280). -->
      <div class="dir-tabs">
        <Tabs :value="activeTab" scrollable @update:value="onTabChange">
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
    color: var(--p-surface-900); // primary navy text (was surface-50 = darkest = invisible)
  }
}

.dir-section__desc {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;

  .app-dark & {
    // surface-600 (#8593B0) on card bg surface-100 (#111E38) = 4.83:1 → AA at 12.25px.
    // surface-500 was 3.46:1 (FAIL for small text). Inverted dark scale: 600 is LIGHTER.
    color: var(--p-surface-600);
  }
}

// Tab bar — line-style override on top of PrimeVue defaults
.dir-tabs {
  flex-shrink: 0;

  :deep(.p-tablist) {
    padding: 0 $space-6;
    background: $surface-card;
    border-bottom: 1px solid $surface-200;
    // $surface-card / $surface-200 theme-reactive → base reads in dark (navy).
    // No dark branch: nested `.app-dark &` inside :deep() compiles dead.
    // Overflow scroll is handled by the `scrollable` prop (p-tablist-viewport +
    // prev/next buttons); no manual overflow-x needed.
  }

  // Scrollable prev/next nav buttons — align with the line-style bar surface.
  :deep(.p-tablist-prev-button),
  :deep(.p-tablist-next-button) {
    background: $surface-card;
    color: $surface-600;
    border-bottom: 1px solid $surface-200;

    &:hover {
      color: $surface-900;
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
    // $surface-600 / $surface-900 theme-reactive → muted + hover read in dark.

    &:hover {
      color: $surface-900;
    }
  }

  // Active tab: --p-primary-color = navy #172747 (light) / accent #4C7DF0 (dark) —
  // correct in both from one rule (no dead nested dark branch).
  :deep(.p-tab[data-p-active="true"]),
  :deep(.p-tab-active) {
    color: var(--p-primary-color);
    font-weight: $font-weight-semibold;
    border-bottom-color: var(--p-primary-color);
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
