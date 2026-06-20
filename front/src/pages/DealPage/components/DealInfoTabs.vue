<template>
  <div class="deal-info-tabs">
    <Tabs v-model:value="activeTab" class="deal-info-tabs__tabs">
      <TabList>
        <Tab value="main">{{ t('sales.deal.info.tabs.main') }}</Tab>
        <Tab value="documents">{{ t('sales.deal.info.tabs.documents') }}</Tab>
        <Tab value="finances">{{ t('sales.deal.info.tabs.finances') }}</Tab>
        <Tab value="log">{{ t('sales.deal.info.tabs.log') }}</Tab>
      </TabList>
      <TabPanels class="deal-info-tabs__panels">
        <TabPanel value="main">
          <slot name="main" />
        </TabPanel>
        <TabPanel value="documents">
          <slot name="documents" />
        </TabPanel>
        <TabPanel value="finances">
          <slot name="finances" />
        </TabPanel>
        <TabPanel value="log">
          <slot name="log" />
        </TabPanel>
      </TabPanels>
    </Tabs>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'

const { t } = useI18n()

const activeTab = ref<'main' | 'documents' | 'finances' | 'log'>('main')
</script>

<style lang="scss" scoped>
.deal-info-tabs {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.deal-info-tabs__tabs {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.deal-info-tabs__panels {
  flex: 1;
  overflow-y: auto;
  padding: 0;
}

// Make tabs compact / underline style
:deep(.p-tabs-tablist) {
  padding: 0 $space-3;
  border-bottom: 1px solid var(--p-surface-200);
  background: var(--p-card-background);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

:deep(.p-tab) {
  font-size: $font-size-sm;
  padding: $space-2 $space-3;
}

:deep(.p-tabpanel) {
  padding: 0;
}
</style>
