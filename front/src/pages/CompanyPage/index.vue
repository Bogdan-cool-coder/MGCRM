<template>
  <div
    class="company-page-v2"
    :class="{
      'company-page-v2--mobile': isMobile,
      'company-page-v2--tablet': isTablet,
    }"
  >
    <!-- ── Loading skeleton ─────────────────────────────────────────────────── -->
    <template v-if="companyLoading">
      <Skeleton height="180px" class="company-page-v2__header-skeleton" />
      <div class="company-page-v2__body">
        <div class="row g-0">
          <div class="col-12">
            <Skeleton height="44px" class="mb-3" />
            <div class="row g-3">
              <div class="col-md-6"><Skeleton height="180px" /></div>
              <div class="col-md-6"><Skeleton height="180px" /></div>
              <div class="col-md-6"><Skeleton height="120px" /></div>
              <div class="col-md-6"><Skeleton height="120px" /></div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ── Error ────────────────────────────────────────────────────────────── -->
    <template v-else-if="companyError || !company">
      <div class="company-page-v2__error">
        <i class="pi pi-exclamation-triangle company-page-v2__error-icon" />
        <p class="company-page-v2__error-title">{{ t('company.page.errors.load') }}</p>
        <Button
          icon="pi pi-arrow-left"
          :label="t('company.page.back')"
          severity="secondary"
          outlined
          @click="router.push('/contacts')"
        />
      </div>
    </template>

    <!-- ── Main content ──────────────────────────────────────────────────────── -->
    <template v-else>
      <!-- EntityInfoHeader (dark brand header) -->
      <EntityInfoHeader
        :entity-id="company.id"
        :title="company.name"
        :subtitle="companySubtitle || undefined"
        :author-name="company.owner_user?.full_name"
        :works-with-name="company.responsible_user?.full_name"
        :category-code="company.category_code"
        :engagement-tier="(company as CompanyExtended).engagement_tier ?? undefined"
        :last-activity-at="(company as CompanyExtended).last_activity_at"
        :menu-items="menuItems"
        @back="router.back()"
      />

      <!-- Tabs body -->
      <div class="company-page-v2__body">
        <!-- Mobile: Select-based tab navigation -->
        <div v-if="isMobile" class="company-page-v2__mobile-tab-select">
          <Select
            v-model="activeTab"
            :options="tabOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>

        <Tabs v-model:value="activeTab" class="company-page-v2__tabs">
          <!-- Desktop: tab list hidden on mobile -->
          <TabList
            v-if="!isMobile"
            :class="{ 'company-page-v2__tablist--scroll': isTablet }"
          >
            <Tab value="overview">{{ t('company.page.tabs.overview') }}</Tab>
            <Tab value="activity">{{ t('crm.company.tabs.activity') }}</Tab>
            <Tab value="contacts">
              {{ t('crm.company.tabs.employees') }}
              <Badge v-if="employees.length" :value="employees.length" severity="secondary" size="small" class="ms-1" />
            </Tab>
            <Tab value="deals">
              {{ t('company.page.tabs.deals') }}
              <Badge v-if="openDealsCount" :value="openDealsCount" severity="secondary" size="small" class="ms-1" />
            </Tab>
            <Tab value="documents">
              {{ t('company.page.tabs.documents') }}
              <Badge v-if="documents.length" :value="documents.length" severity="secondary" size="small" class="ms-1" />
            </Tab>
            <Tab value="payments">{{ t('crm.company.tabs.payments') }}</Tab>
            <Tab value="holding">{{ t('company.page.tabs.holding') }}</Tab>
            <Tab value="files">{{ t('crm.company.tabs.files') }}</Tab>
          </TabList>

          <TabPanels>
            <!-- ── Overview ───────────────────────────────────────── -->
            <TabPanel value="overview">
              <div class="row g-0">
                <div class="col-12 col-xl-6">
                  <!-- Requisites panel -->
                  <CompanyRequisitesPanel
                    :company="company"
                    :is-saving="isSaving"
                    @save="patchField"
                  />

                  <!-- Employees overview panel -->
                  <CompanyEmployeesPanel
                    :employees="employees"
                    @add-employee="openAddEmployee"
                    @set-primary="setPrimaryEmployee"
                    @go-to-tab="goToTab"
                  />

                  <!-- Holding tree panel (always visible, collapsed by default) -->
                  <HoldingTree
                    :tree="holding"
                    :loading="holdingLoading"
                    @attach-parent="showAttachHolding = true"
                    @detach-parent="onDetachHolding"
                  />
                </div>

                <div class="col-12 col-xl-6">
                  <!-- Mini pipeline / deals panel -->
                  <MiniPipelinePanel
                    :deals="deals"
                    :loading="dealsLoading"
                    @create-deal="onCreateDeal"
                    @filter-by-stage="(id) => { goToTab('deals') }"
                    @go-to-tab="goToTab"
                  />

                  <!-- Multi-currency totals -->
                  <MultiCurrencyTotals
                    :totals="(company as CompanyExtended).deal_totals"
                    :loading="companyLoading"
                  />

                  <!-- Documents panel -->
                  <CompanyDocumentsPanel
                    :documents="documents"
                    :loading="documentsLoading"
                    @go-to-tab="goToTab"
                  />

                  <!-- Payments placeholder -->
                  <InfoPanel
                    :title="t('crm.company.sections.payments')"
                    icon="pi-credit-card"
                    panel-key="company-payments"
                    :default-collapsed="true"
                  >
                    <div class="company-page-v2__payments-placeholder">
                      <i class="pi pi-lock company-page-v2__payments-icon" />
                      <p class="company-page-v2__payments-text">
                        {{ t('crm.company.payments.placeholder') }}
                      </p>
                    </div>
                  </InfoPanel>

                  <!-- Custom fields -->
                  <InfoPanel
                    :title="t('crm.contact.sections.customFields')"
                    icon="pi-sliders-h"
                    panel-key="company-custom-fields"
                    :default-collapsed="true"
                  >
                    <CustomFieldRenderer
                      v-if="company"
                      entity-scope="company"
                      :entity-id="company.id"
                      :extra-fields="company.extra_fields"
                      :on-save="saveCustomField"
                    />
                  </InfoPanel>
                </div>
              </div>
            </TabPanel>

            <!-- ── Activity ───────────────────────────────────────── -->
            <TabPanel value="activity">
              <div class="company-page-v2__tab-content">
                <EntityActivitiesTab
                  entity-type="company"
                  :entity-id="company.id"
                />
              </div>
            </TabPanel>

            <!-- ── Employees (full tab) ───────────────────────────── -->
            <TabPanel value="contacts">
              <div class="company-page-v2__tab-content">
                <!-- Mini toolbar -->
                <div class="company-page-v2__employees-toolbar">
                  <InputText
                    v-model="employeeSearch"
                    :placeholder="t('common.search')"
                    class="company-page-v2__employees-search"
                  />
                  <Button
                    icon="pi pi-user-plus"
                    :label="t('company.page.employees.add')"
                    size="small"
                    @click="openAddEmployee"
                  />
                </div>

                <CompanyEmployeesTab
                  :employees="filteredEmployees"
                  :loading="employeesLoading"
                  @add-employee="openAddEmployee"
                  @set-primary="setPrimaryEmployee"
                  @toggle-status="toggleEmployeeStatus"
                  @unlink="confirmUnlinkEmployee"
                />
              </div>
            </TabPanel>

            <!-- ── Deals tab ──────────────────────────────────────── -->
            <TabPanel value="deals">
              <div class="company-page-v2__tab-content">
                <CompanyDealsTab
                  :deals="deals"
                  :loading="dealsLoading"
                  :has-more="false"
                  @create-deal="onCreateDeal"
                />
              </div>
            </TabPanel>

            <!-- ── Documents tab ──────────────────────────────────── -->
            <TabPanel value="documents">
              <div class="company-page-v2__tab-content">
                <CompanyDocumentsTab v-if="company" :company-id="company.id" />
              </div>
            </TabPanel>

            <!-- ── Payments tab (M9 placeholder) ─────────────────── -->
            <TabPanel value="payments">
              <div class="company-page-v2__tab-content company-page-v2__payments-tab">
                <i class="pi pi-lock company-page-v2__payments-tab-icon" />
                <p class="company-page-v2__payments-tab-title">{{ t('crm.company.payments.placeholder') }}</p>
                <p class="company-page-v2__payments-tab-hint">{{ t('crm.company.payments.hint') }}</p>
              </div>
            </TabPanel>

            <!-- ── Holding tab ────────────────────────────────────── -->
            <TabPanel value="holding">
              <div class="company-page-v2__tab-content">
                <div class="company-page-v2__holding-tab-wrapper">
                  <HoldingTree
                    :tree="holding"
                    :loading="holdingLoading"
                    @attach-parent="showAttachHolding = true"
                    @detach-parent="onDetachHolding"
                  />
                </div>
              </div>
            </TabPanel>

            <!-- ── Files tab ──────────────────────────────────────── -->
            <TabPanel value="files">
              <div class="company-page-v2__tab-content company-page-v2__files-tab">
                <i class="pi pi-folder company-page-v2__files-icon" />
                <p class="company-page-v2__files-text">{{ t('company.page.stub.files') }}</p>
              </div>
            </TabPanel>
          </TabPanels>
        </Tabs>
      </div>
    </template>

    <!-- ── Add employee dialog ──────────────────────────────────────────────── -->
    <Dialog
      v-model:visible="addEmployeeOpen"
      :header="t('company.page.employees.add')"
      modal
      style="width: 480px"
    >
      <div class="company-page-v2__dialog-form">
        <div class="company-page-v2__field">
          <label class="company-page-v2__label">{{ t('contacts.page.columns.name') }} *</label>
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
              <div class="company-page-v2__contact-option">
                <span class="company-page-v2__contact-name">{{ option.full_name }}</span>
                <span v-if="option.email || option.phone" class="company-page-v2__contact-meta">
                  {{ [option.email, option.phone].filter(Boolean).join(' · ') }}
                </span>
              </div>
            </template>
          </AutoComplete>
        </div>
        <div class="company-page-v2__field">
          <label class="company-page-v2__label">{{ t('company.page.employees.columns.position') }}</label>
          <InputText v-model="addEmployeePosition" class="w-full" />
        </div>
        <div class="company-page-v2__field">
          <label class="company-page-v2__label">{{ t('company.page.employees.columns.status') }}</label>
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

    <!-- Attach holding dialog (simple) -->
    <Dialog
      v-model:visible="showAttachHolding"
      :header="t('crm.company.holding.addParent')"
      modal
      style="width: 420px"
    >
      <div class="company-page-v2__field">
        <label class="company-page-v2__label">{{ t('crm.company.holding.parentCompany') }} *</label>
        <AutoComplete
          v-model="holdingParentSearch"
          :suggestions="holdingParentSuggestions"
          option-label="name"
          :placeholder="t('common.search')"
          class="w-full"
          force-selection
          @complete="searchHoldingParent($event.query)"
          @option-select="holdingParentId = $event.value.id"
        />
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="showAttachHolding = false" />
        <Button
          :label="t('common.save')"
          :loading="holdingAttaching"
          :disabled="!holdingParentId"
          @click="onAttachHolding"
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
import Badge from 'primevue/badge'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Skeleton from 'primevue/skeleton'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import AutoComplete from 'primevue/autocomplete'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import EntityInfoHeader from '@/components/crm/entity/EntityInfoHeader.vue'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import EntityActivitiesTab from '@/components/crm/entity/EntityActivitiesTab.vue'
import CustomFieldRenderer from '@/components/crm/entity/CustomFieldRenderer.vue'
import CompanyRequisitesPanel from './components/CompanyRequisitesPanel.vue'
import CompanyEmployeesPanel from './components/CompanyEmployeesPanel.vue'
import CompanyEmployeesTab from './components/CompanyEmployeesTab.vue'
import CompanyDocumentsTab from './components/CompanyDocumentsTab.vue'
import CompanyDocumentsPanel from './components/CompanyDocumentsPanel.vue'
import CompanyDealsTab from './components/CompanyDealsTab.vue'
import HoldingTree from './components/HoldingTree.vue'
import MiniPipelinePanel from './components/MiniPipelinePanel.vue'
import MultiCurrencyTotals from './components/MultiCurrencyTotals.vue'
import { useCompanyPageData } from './composables/useCompanyPageData'
import { useCompanyPageActions } from './composables/useCompanyPageActions'
import { useBreakpoints } from '@/composables/useBreakpoints'
import { companiesApi } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { CompanyExtended, EmploymentStatus, Company } from '@/entities/crm'
import type { MenuItem } from 'primevue/menuitem'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()

const { isTablet, isMobile } = useBreakpoints()

const activeTab = ref('overview')
const employeeSearch = ref('')
const showAttachHolding = ref(false)
const holdingParentSearch = ref('')
const holdingParentId = ref<number | null>(null)
const holdingParentSuggestions = ref<Array<{ id: number; name: string }>>([])
const holdingAttaching = ref(false)

const {
  companyId,
  company,
  companyLoading,
  companyError,
  employees,
  employeesLoading,
  holding,
  holdingLoading,
  deals,
  dealsLoading,
  documents,
  documentsLoading,
  loadAll,
  loadEmployees,
  loadHolding,
  directoriesStore,
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

// ── Computed ───────────────────────────────────────────────────────────────────

const companySubtitle = computed(() => {
  if (!company.value) return ''
  const parts: string[] = []
  if (company.value.company_type_id) {
    parts.push(directoriesStore.getCompanyTypeLabel(company.value.company_type_id))
  }
  if (company.value.country_code) {
    parts.push(company.value.country_code)
  }
  return parts.filter(Boolean).join(' · ')
})

const openDealsCount = computed(() => deals.value.filter((d) => d.status === 'open').length)

const filteredEmployees = computed(() => {
  if (!employeeSearch.value) return employees.value
  const q = employeeSearch.value.toLowerCase()
  return employees.value.filter((e) => {
    const name = e.contact?.full_name?.toLowerCase() ?? ''
    const pos = e.position?.toLowerCase() ?? ''
    return name.includes(q) || pos.includes(q)
  })
})

// ── Menu items ─────────────────────────────────────────────────────────────────

const menuItems = computed((): MenuItem[] => [
  {
    label: t('company.page.menu.createDeal'),
    icon: 'pi pi-briefcase',
    command: onCreateDeal,
  },
  {
    label: t('company.page.menu.addTask'),
    icon: 'pi pi-check-square',
    command: () => { /* TODO: open task dialog */ },
  },
  {
    label: t('company.page.menu.addNote'),
    icon: 'pi pi-comment',
    command: () => { /* TODO: open note dialog */ },
  },
  {
    label: t('company.page.menu.call'),
    icon: 'pi pi-phone',
    command: () => { /* TODO: call action */ },
  },
  {
    label: t('company.page.menu.email'),
    icon: 'pi pi-envelope',
    command: () => { /* TODO: email action */ },
  },
  { separator: true },
  {
    label: t('company.page.menu.copyLink'),
    icon: 'pi pi-link',
    command: () => {
      void navigator.clipboard.writeText(window.location.href)
      toast.add({ severity: 'success', summary: t('common.copied'), life: 2000 })
    },
  },
  {
    label: t('company.page.menu.export'),
    icon: 'pi pi-download',
    command: () => { /* TODO: export */ },
  },
  { separator: true },
  {
    label: t('common.delete'),
    icon: 'pi pi-trash',
    command: onDeleteCompany,
  },
])

// ── Tab navigation options (mobile Select) ─────────────────────────────────────

const tabOptions = computed(() => [
  { label: t('company.page.tabs.overview'), value: 'overview' },
  { label: t('crm.company.tabs.activity'), value: 'activity' },
  { label: t('crm.company.tabs.employees'), value: 'contacts' },
  { label: t('company.page.tabs.deals'), value: 'deals' },
  { label: t('company.page.tabs.documents'), value: 'documents' },
  { label: t('crm.company.tabs.payments'), value: 'payments' },
  { label: t('company.page.tabs.holding'), value: 'holding' },
  { label: t('crm.company.tabs.files'), value: 'files' },
])

// ── Actions ────────────────────────────────────────────────────────────────────

function goToTab(tab: string) {
  activeTab.value = tab
}

function onCreateDeal() {
  if (company.value) {
    void router.push(`/deals?company_id=${company.value.id}&create=1`)
  }
}

async function onDeleteCompany() {
  if (!company.value) return
  if (!confirm(t('company.page.menu.deleteConfirm'))) return
  try {
    await companiesApi.remove(company.value.id)
    toast.add({ severity: 'success', summary: t('company.page.menu.deleteSuccess'), life: 3000 })
    void router.push('/contacts')
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

async function saveCustomField(code: string, value: unknown) {
  if (!company.value) return
  const updated = await companiesApi.update(company.value.id, {
    extra_fields: { ...company.value.extra_fields, [code]: value },
  })
  Object.assign(company.value, updated)
}

// ── Holding attach/detach ──────────────────────────────────────────────────────

let holdingSearchTimer: ReturnType<typeof setTimeout> | null = null

function searchHoldingParent(query: string) {
  if (holdingSearchTimer) clearTimeout(holdingSearchTimer)
  if (!query || query.length < 2) {
    holdingParentSuggestions.value = []
    return
  }
  holdingSearchTimer = setTimeout(async () => {
    try {
      const result = await companiesApi.list({ search: query, per_page: 10 })
      holdingParentSuggestions.value = result.data.map((c: Company) => ({ id: c.id, name: c.name }))
    } catch {
      holdingParentSuggestions.value = []
    }
  }, 300)
}

async function onAttachHolding() {
  if (!holdingParentId.value || !companyId.value) return
  holdingAttaching.value = true
  try {
    await companiesApi.attachHolding(companyId.value, {
      parent_id: holdingParentId.value,
      holding_role: 'subsidiary',
    })
    showAttachHolding.value = false
    holdingParentSearch.value = ''
    holdingParentId.value = null
    await loadHolding()
    toast.add({ severity: 'success', summary: t('crm.company.holding.attached'), life: 3000 })
  } catch (err) {
    const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
    if (msg === 'holding_cycle') {
      toast.add({ severity: 'error', summary: t('crm.company.holding.cyclicError'), life: 4000 })
    } else {
      toast.add({ severity: 'error', summary: getApiErrorMessage(err, t('errors.server_error')), life: 4000 })
    }
  } finally {
    holdingAttaching.value = false
  }
}

async function onDetachHolding() {
  if (!companyId.value) return
  try {
    await companiesApi.detachHolding(companyId.value)
    await loadHolding()
    toast.add({ severity: 'success', summary: t('crm.company.holding.detached'), life: 3000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: getApiErrorMessage(err, t('errors.server_error')), life: 4000 })
  }
}

// ── Employee form helpers ──────────────────────────────────────────────────────

const statusOptions = [
  { label: t('company.page.employees.status.works'), value: 'works' as EmploymentStatus },
  { label: t('company.page.employees.status.left'), value: 'left' as EmploymentStatus },
]

function onSubmitEmployee() {
  if (addEmployeeContactId.value) {
    void submitAddEmployee()
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────────

onMounted(async () => {
  if (!directoriesStore.loaded) void directoriesStore.fetchAll()
  await loadAll()
})
</script>

<style lang="scss" scoped>
.company-page-v2 {
  display: flex;
  flex-direction: column;
  min-height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

// ── Header skeleton ────────────────────────────────────────────────────────────
.company-page-v2__header-skeleton {
  flex-shrink: 0;
}

// ── Error state ────────────────────────────────────────────────────────────────
.company-page-v2__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-4;
  padding: $space-8 * 1.5;
  text-align: center;
}

.company-page-v2__error-icon {
  font-size: 3rem;
  color: $surface-300;
}

.company-page-v2__error-title {
  font-size: $font-size-base;
  color: $surface-600;
  margin: 0;
}

// ── Body ───────────────────────────────────────────────────────────────────────
.company-page-v2__body {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}

// ── Tabs ───────────────────────────────────────────────────────────────────────
.company-page-v2__tabs {
  :deep(.p-tablist) {
    background: $surface-card;
    border-bottom: 1px solid var(--p-surface-200);
    padding: 0 $space-4;
    position: sticky;
    top: 0;
    z-index: 10;

    .app-dark & {
      background: var(--p-surface-900);
      border-bottom-color: var(--p-surface-700);
    }
  }

  :deep(.p-tabpanels) {
    padding: 0;
    background: transparent;
  }

  :deep(.p-tabpanel) {
    background: transparent;
  }
}

.company-page-v2__tablist--scroll {
  overflow-x: auto;
  flex-wrap: nowrap;

  :deep(.p-tab) {
    white-space: nowrap;
  }
}

// ── Mobile tab select ──────────────────────────────────────────────────────────
.company-page-v2__mobile-tab-select {
  padding: $space-3 $space-4;
  background: $surface-card;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    background: var(--p-surface-900);
    border-bottom-color: var(--p-surface-700);
  }
}

// ── Tab content wrapper ────────────────────────────────────────────────────────
.company-page-v2__tab-content {
  padding: $space-4 $space-6;

  @media (max-width: 768px) {
    padding: $space-3 $space-4;
  }

  @media (max-width: 375px) {
    padding: $space-3;
  }
}

// ── Employees toolbar ──────────────────────────────────────────────────────────
.company-page-v2__employees-toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-4 $space-6 $space-3;

  @media (max-width: 768px) {
    padding: $space-3 $space-4;
  }
}

.company-page-v2__employees-search {
  flex: 1;
  max-width: 320px;
}

// ── Payments tab ───────────────────────────────────────────────────────────────
.company-page-v2__payments-tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  min-height: 240px;
  text-align: center;
}

.company-page-v2__payments-tab-icon {
  font-size: 3rem;
  color: $surface-300;
}

.company-page-v2__payments-tab-title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;
  max-width: 400px;
}

.company-page-v2__payments-tab-hint {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}

// ── Overview — payments inline placeholder ─────────────────────────────────────
.company-page-v2__payments-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4;
  text-align: center;
}

.company-page-v2__payments-icon {
  font-size: 1.5rem;
  color: $surface-300;
}

.company-page-v2__payments-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// ── Files tab ──────────────────────────────────────────────────────────────────
.company-page-v2__files-tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  min-height: 240px;
  text-align: center;
}

.company-page-v2__files-icon {
  font-size: 3rem;
  color: $surface-300;
}

.company-page-v2__files-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// ── Holding tab wrapper ────────────────────────────────────────────────────────
.company-page-v2__holding-tab-wrapper {
  max-width: 600px;
}

// ── Dialog form ────────────────────────────────────────────────────────────────
.company-page-v2__dialog-form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.company-page-v2__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.company-page-v2__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.company-page-v2__contact-option {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.company-page-v2__contact-name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
}

.company-page-v2__contact-meta {
  font-size: $font-size-xs;
  color: $surface-500;
}

.w-full {
  width: 100%;
}
</style>
