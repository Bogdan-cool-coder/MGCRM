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
      <Tabs :value="activeTab" @update:value="onTabChange">
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
import { useRoute, useRouter } from 'vue-router'
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
const route = useRoute()
const router = useRouter()
const userStore = useUserStore()

const props = withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

/** Allowed roles per spec: admin, director */
const isAllowed = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

/** Map route name → tab value (used in standalone mode only) */
const routeTabMap: Record<string, string> = {
  AccessControlDepartments: 'departments',
  AccessControlRoles: 'roles',
  AccessControlVisibility: 'visibility',
}

/**
 * Internal tab state for embedded mode — avoids URL sync conflict with
 * the Settings shell ?section= parameter (OV-1 resolution).
 */
const internalTab = ref<string>('departments')

const activeTab = computed(() => {
  if (props.embedded) return internalTab.value
  return routeTabMap[String(route.name)] ?? 'departments'
})

function onTabChange(value: string | number) {
  const tab = String(value)
  if (props.embedded) {
    // Embedded: switch tabs locally, no router involvement
    internalTab.value = tab
    return
  }
  // Standalone: sync to URL as before
  const routeMap: Record<string, string> = {
    departments: '/admin/access-control/departments',
    roles: '/admin/access-control/roles',
    visibility: '/admin/access-control/visibility',
  }
  const target = routeMap[tab]
  if (target && route.path !== target) {
    router.push(target)
  }
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

// Make TabPanels scrollable
:deep(.p-tabpanels) {
  flex: 1;
  overflow-y: auto;
  padding: $space-4 $space-6;
}
</style>
