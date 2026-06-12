<template>
  <div class="company-page">
    <!-- Page Header -->
    <PageHeader
      :title="company?.name ?? t('common.loading')"
      :subtitle="companySubtitle"
      icon="pi pi-building"
    >
      <template #actions>
        <Button
          icon="pi pi-arrow-left"
          :label="t('company.page.back')"
          severity="secondary"
          text
          @click="router.back()"
        />
        <Tag
          v-if="company?.category_code"
          :value="company.category_code"
          :severity="categorySeverity"
          class="company-page__category-tag"
        />
        <Button
          icon="pi pi-plus"
          :label="t('company.page.newDeal')"
          disabled
        />
      </template>
    </PageHeader>

    <!-- Error state -->
    <div v-if="companyError && !companyLoading" class="company-page__error">
      <Message severity="error">{{ t('company.page.errors.load') }}</Message>
      <Button
        :label="t('company.page.back')"
        severity="secondary"
        @click="router.push('/contacts')"
      />
    </div>

    <!-- Skeleton loading -->
    <div v-else-if="companyLoading" class="company-page__content">
      <div class="row g-4">
        <div class="col-9">
          <Skeleton height="32px" class="mb-3" />
          <Skeleton height="120px" class="mb-3" />
          <Skeleton height="120px" />
        </div>
        <div class="col-3">
          <Skeleton height="200px" />
        </div>
      </div>
    </div>

    <!-- Main content -->
    <div v-else-if="company" class="company-page__content">
      <div class="row g-4">
        <!-- Left: Tabs -->
        <div class="col-lg-9">
          <Tabs v-model:value="activeTab" class="company-page__tabs">
            <TabList>
              <Tab value="overview">{{ t('company.page.tabs.overview') }}</Tab>
              <Tab value="contacts">{{ t('company.page.tabs.contacts') }}</Tab>
              <Tab value="notes">{{ t('company.page.tabs.notes') }}</Tab>
              <Tab value="tasks">{{ t('company.page.tabs.tasks') }}</Tab>
              <Tab value="deals">{{ t('company.page.tabs.deals') }}</Tab>
              <Tab value="files">{{ t('company.page.tabs.files') }}</Tab>
              <Tab value="holding">{{ t('company.page.tabs.holding') }}</Tab>
            </TabList>
            <TabPanels>
              <!-- Overview -->
              <TabPanel value="overview">
                <div class="company-page__tab-content">
                  <CompanyOverviewTab
                    :company="company"
                    :is-saving="isSaving"
                    @save="patchField"
                  />
                </div>
              </TabPanel>

              <!-- Contacts (employees) -->
              <TabPanel value="contacts">
                <div class="company-page__tab-content">
                  <CompanyEmployeesTab
                    :employees="employees"
                    :loading="employeesLoading"
                    @add-employee="openAddEmployee"
                    @set-primary="setPrimaryEmployee"
                    @toggle-status="toggleEmployeeStatus"
                    @unlink="confirmUnlinkEmployee"
                  />
                </div>
              </TabPanel>

              <!-- Notes -->
              <TabPanel value="notes">
                <div class="company-page__tab-content">
                  <CompanyNotesTab
                    :company="company"
                    :is-saving="isSaving"
                    @save="patchField"
                  />
                </div>
              </TabPanel>

              <!-- Tasks: Activities tab -->
              <TabPanel value="tasks">
                <div class="company-page__tab-content">
                  <CompanyActivitiesTab
                    v-if="company"
                    :company-id="company.id"
                    :activities="companyActivities.activities.value"
                    :loading="companyActivities.loading.value"
                    :has-more="companyActivities.hasMore.value"
                    @load-more="companyActivities.loadMore()"
                    @complete="onActivityComplete"
                    @reopen="onActivityReopen"
                    @remove="onActivityRemove"
                    @updated="onActivityUpdated"
                    @created="onActivityCreated"
                  />
                </div>
              </TabPanel>

              <!-- Deals stub -->
              <TabPanel value="deals">
                <div class="company-page__tab-content">
                  <CompanyStubTab :message="t('company.page.stub.deals')" />
                </div>
              </TabPanel>

              <!-- Files stub -->
              <TabPanel value="files">
                <div class="company-page__tab-content">
                  <CompanyStubTab :message="t('company.page.stub.files')" />
                </div>
              </TabPanel>

              <!-- Holding stub -->
              <TabPanel value="holding">
                <div class="company-page__tab-content">
                  <CompanyStubTab :message="t('company.page.holding.stub')" />
                </div>
              </TabPanel>
            </TabPanels>
          </Tabs>
        </div>

        <!-- Right rail -->
        <div class="col-lg-3">
          <div class="company-page__rail-wrapper">
            <CompanyRightRail :company="company" />
          </div>
        </div>
      </div>
    </div>

    <!-- Add employee dialog -->
    <Dialog
      v-model:visible="addEmployeeOpen"
      :header="t('company.page.employees.add')"
      modal
      style="width: 480px"
    >
      <div class="company-page__dialog-form">
        <div class="company-page__field">
          <label class="company-page__label">{{ t('contacts.page.columns.name') }} *</label>
          <AutoComplete
            v-model="addEmployeeSearch"
            :suggestions="addEmployeeSuggestions"
            option-label="full_name"
            :placeholder="t('common.search')"
            class="w-full"
            force-selection
            @complete="searchEmployeeContacts($event.query)"
            @option-select="onEmployeeSelect($event.value)"
          >
            <template #option="{ option }">
              <div class="company-page__contact-option">
                <span class="company-page__contact-name">{{ option.full_name }}</span>
                <span v-if="option.email || option.phone" class="company-page__contact-meta">
                  {{ [option.email, option.phone].filter(Boolean).join(' · ') }}
                </span>
              </div>
            </template>
          </AutoComplete>
          <small class="company-page__hint">{{ t('company.page.employees.searchHint', 'Введите имя или email контакта') }}</small>
        </div>
        <div class="company-page__field">
          <label class="company-page__label">{{ t('company.page.employees.columns.position') }}</label>
          <InputText v-model="addEmployeePosition" class="w-full" />
        </div>
        <div class="company-page__field">
          <label class="company-page__label">{{ t('company.page.employees.columns.status') }}</label>
          <Select
            v-model="addEmployeeStatus"
            :options="statusOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="closeAddEmployee" />
        <Button
          :label="t('common.save')"
          :loading="isAddingEmployee"
          :disabled="!addEmployeeSearch"
          @click="onSubmitEmployee"
        />
      </template>
    </Dialog>

    <Toast position="top-right" />
    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import AutoComplete from 'primevue/autocomplete'
import Select from 'primevue/select'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import CompanyOverviewTab from './components/CompanyOverviewTab.vue'
import CompanyEmployeesTab from './components/CompanyEmployeesTab.vue'
import CompanyRightRail from './components/CompanyRightRail.vue'
import CompanyNotesTab from './components/CompanyNotesTab.vue'
import CompanyStubTab from './components/CompanyStubTab.vue'
import CompanyActivitiesTab from './components/CompanyActivitiesTab.vue'
import { useCompanyPageData } from './composables/useCompanyPageData'
import { useCompanyPageActions } from './composables/useCompanyPageActions'
import { useCompanyActivities } from './composables/useCompanyActivities'
import { useDirectoriesStore } from '@/stores/directories'
import { activityApi } from '@/api/activity'
import { useToast } from 'primevue/usetoast'
import { getApiErrorMessage } from '@/utils/errors'
import type { CategoryCode, EmploymentStatus } from '@/entities/crm'
import type { ActivityDto } from '@/entities/activity'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const directoriesStore = useDirectoriesStore()

const activeTab = ref('overview')

const {
  companyId,
  company,
  companyLoading,
  companyError,
  employees,
  employeesLoading,
  loadAll,
  loadEmployees,
} = useCompanyPageData()

const {
  patchField,
  isSaving,
  addEmployeeOpen,
  addEmployeeSearch,
  addEmployeeContactId,
  addEmployeePosition,
  addEmployeeStatus,
  addEmployeeSuggestions,
  isAddingEmployee,
  openAddEmployee,
  closeAddEmployee,
  submitAddEmployee,
  searchEmployeeContacts,
  onEmployeeSelect,
  setPrimaryEmployee,
  toggleEmployeeStatus,
  confirmUnlinkEmployee,
} = useCompanyPageActions({
  companyId,
  company,
  employees,
  loadEmployees,
})

// ── Company Activities ─────────────────────────────────────────────────────────

const companyActivities = useCompanyActivities(() => companyId.value)

// ── Activity handlers ──────────────────────────────────────────────────────────

async function onActivityComplete(activity: ActivityDto) {
  companyActivities.updateLocal({ ...activity, status: 'done', is_closed: true })
  try {
    const updated = await activityApi.completeActivity(activity.id)
    companyActivities.updateLocal(updated)
    toast.add({ severity: 'success', summary: t('activity.actions.completeSuccess'), life: 3000 })
  } catch (err) {
    companyActivities.updateLocal(activity)
    const status = (err as { response?: { status?: number } })?.response?.status
    toast.add({
      severity: 'error',
      summary:
        status === 403
          ? t('activity.actions.noPermissionComplete')
          : getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

async function onActivityReopen(activity: ActivityDto) {
  companyActivities.updateLocal({ ...activity, status: 'in_progress', is_closed: false })
  try {
    const updated = await activityApi.reopenActivity(activity.id)
    companyActivities.updateLocal(updated)
  } catch (err) {
    companyActivities.updateLocal(activity)
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 3000,
    })
  }
}

async function onActivityRemove(activity: ActivityDto) {
  try {
    await activityApi.deleteActivity(activity.id)
    await companyActivities.remove(activity.id)
    toast.add({ severity: 'success', summary: t('activity.actions.deleteSuccess'), life: 3000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 3000,
    })
  }
}

async function onActivityUpdated(activity: ActivityDto) {
  try {
    const updated = await activityApi.updateActivity(activity.id, { is_pinned: activity.is_pinned })
    companyActivities.updateLocal(updated)
  } catch {
    // non-critical
  }
}

function onActivityCreated(activity: ActivityDto) {
  companyActivities.addLocal(activity)
}

const companySubtitle = computed(() => {
  if (!company.value) return ''
  const parts = []
  if (company.value.company_type_id) {
    parts.push(directoriesStore.getCompanyTypeLabel(company.value.company_type_id))
  }
  if (company.value.country_code) {
    parts.push(company.value.country_code)
  }
  if (company.value.source) {
    parts.push(directoriesStore.getSourceLabel(company.value.source))
  }
  return parts.filter(Boolean).join(' · ')
})

const categorySeverity = computed(() => {
  const map: Record<CategoryCode, 'danger' | 'warning' | 'success' | 'info'> = {
    L: 'danger',
    M: 'warning',
    S1: 'success',
    S2: 'info',
  }
  return company.value?.category_code ? map[company.value.category_code] : 'secondary'
})

const statusOptions = [
  { label: t('company.page.employees.status.works'), value: 'works' as EmploymentStatus },
  { label: t('company.page.employees.status.left'), value: 'left' as EmploymentStatus },
]

function onSubmitEmployee() {
  if (addEmployeeContactId.value) {
    void submitAddEmployee()
  }
}

onMounted(async () => {
  if (!directoriesStore.loaded) void directoriesStore.fetchAll()
  await loadAll()
  void companyActivities.load()
})
</script>

<style lang="scss" scoped>
.company-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.company-page__content {
  padding: $space-4 $space-6;
  flex: 1;
  overflow-y: auto;
}

.company-page__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-4;
  padding: $space-8;
}

.company-page__category-tag {
  margin: 0 $space-2;
}

.company-page__tabs {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
}

.company-page__tab-content {
  padding: $space-4;
}

.company-page__rail-wrapper {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  padding: $space-4;
}

.company-page__dialog-form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.company-page__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.company-page__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.company-page__hint {
  font-size: $font-size-xs;
  color: $surface-400;
}

.w-full {
  width: 100%;
}

.company-page__contact-option {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.company-page__contact-name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
}

.company-page__contact-meta {
  font-size: $font-size-xs;
  color: $surface-500;
}
</style>
