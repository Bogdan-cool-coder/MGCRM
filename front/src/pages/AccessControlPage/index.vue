<template>
  <div class="access-control-page" :class="{ 'access-control-page--embedded': embedded }">
    <PageHeader
      v-if="!embedded"
      :title="t('accessControl.page.title')"
      :subtitle="t('accessControl.page.subtitle')"
      icon="pi pi-shield"
    />

    <!-- 403 fallback (guard should redirect, but just in case) -->
    <Message
      v-if="!isAllowed"
      severity="error"
      class="access-control-page__403"
    >
      {{ t('common.accessDenied') }}
    </Message>

    <div v-else class="access-control-page__body">
      <Tabs class="access-control-page__tabs" :value="activeTab" @update:value="onTabChange">
        <TabList>
          <Tab value="departments">{{ t('accessControl.tabs.departments') }}</Tab>
          <Tab value="roles">{{ t('accessControl.tabs.roles') }}</Tab>
          <Tab value="visibility">{{ t('accessControl.tabs.visibility') }}</Tab>
        </TabList>

        <TabPanels>
          <TabPanel value="departments">
            <DepartmentsTab :embedded="embedded" />
          </TabPanel>
          <TabPanel value="roles">
            <RolesPermissionsTab :embedded="embedded" />
          </TabPanel>
          <TabPanel value="visibility">
            <VisibilityScopeTab :embedded="embedded" />
          </TabPanel>
        </TabPanels>
      </Tabs>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Message from 'primevue/message'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import { useUserStore } from '@/stores/user'
import DepartmentsTab from './components/DepartmentsTab.vue'
import RolesPermissionsTab from './components/RolesPermissionsTab.vue'
import VisibilityScopeTab from './components/VisibilityScopeTab.vue'

const { t } = useI18n()
const userStore = useUserStore()

// This page is always rendered embedded inside the Settings shell.
// The standalone /admin/access-control/* paths redirect to /settings?section=access-control
// (see router/routes/base.ts). Tab state is managed locally.
withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

/** Allowed roles per spec: admin, director */
const isAllowed = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

const activeTab = ref<string>('departments')

function onTabChange(value: string | number) {
  activeTab.value = String(value)
}
</script>

<style scoped lang="scss">
.access-control-page {
  display: flex;
  flex-direction: column;
  height: 100%;

  &--embedded {
    padding: 0;
    margin: 0;
  }
}

.access-control-page__403 {
  margin: $space-6;
}

.access-control-page__body {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

// The PrimeVue <Tabs> root must itself be a bounded flex column so its
// <TabPanels> child can flex-fill and scroll. Without this the .p-tabs block
// height collapses to auto and grows past the parent — .p-tabpanels never gets
// a bounded context, so the long permission matrix overflows and gets clipped
// by the ancestor overflow:hidden (no scrollbar). Mirrors DealInfoTabs.
.access-control-page__tabs {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

// Make TabPanels scrollable
:deep(.p-tabpanels) {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: $space-4 $space-6;
}
</style>
