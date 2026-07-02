<template>
  <div class="deal-info-tabs">
    <Tabs v-model:value="activeTab" class="deal-info-tabs__tabs">
      <TabList>
        <Tab value="main">{{ t('sales.deal.info.tabs.main') }}</Tab>
        <Tab value="documents">{{ t('sales.deal.info.tabs.documents') }}</Tab>
        <Tab value="finances">{{ t('sales.deal.info.tabs.finances') }}</Tab>
        <Tab value="activity">{{ t('sales.deal.info.tabs.activity') }}</Tab>
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
        <TabPanel value="activity">
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

const activeTab = ref<'main' | 'documents' | 'finances' | 'activity'>('main')
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

  // Hidden scrollbar — spec §0
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

// Make tabs compact / underline style.
// --p-surface-200 is theme-reactive (light #E3E4E6, dark navy #172847) — the base
// rule alone reads correctly in both themes. No dark branch: a nested `.app-dark &`
// inside :deep() would compile to `.app-dark[data-v-*]` and never match.
:deep(.p-tabs-tablist) {
  padding: 0 $space-3;
  border-bottom: 1px solid var(--p-surface-200);
  background: var(--p-card-background);
}

:deep(.p-tab) {
  font-size: $font-size-sm;
  padding: $space-2 $space-3;
}

// spec §2.2: active tab = font-weight 600; color/underline = brand primary.
// Use --p-primary-color directly: it IS navy #172747 in light and #4C7DF0 in dark
// (MSales 2.0 accent lightens on the darkened #111E38 header). Single rule, no
// dark branch — a nested `.app-dark &` inside :deep() would compile to
// `.app-dark[data-v-*]` which never matches (.app-dark sits on <html>, outside scope).
:deep(.p-tab[aria-selected="true"]) {
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
  border-bottom-color: var(--p-primary-color);
}

:deep(.p-tabpanel) {
  padding: 0;
}
</style>
