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
        :tags="company.tags"
        :menu-items="menuItems"
        @back="router.back()"
      >
        <template #status>
          <ClientStatusBadge
            v-if="company.client_status"
            :status="company.client_status"
            :since="company.unique_client_since"
            :disconnected-at="company.disconnected_at"
            :company-id="company.id"
          />
        </template>
      </EntityInfoHeader>

      <!-- KPI strip -->
      <EntityKpiStrip
        :items="companyKpiItems"
        :loading="companyLoading"
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
            <Tab value="log">{{ t('crm.company.tabs.log') }}</Tab>
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

                  <!-- Marketing panel (N1 FE-A.3) -->
                  <CompanyMarketingPanel
                    :company-id="company.id"
                    :acquisition-channel-id="company.acquisition_channel_id ?? null"
                    :is-saving="isSaving"
                    :channels="directoriesStore.activeAcquisitionChannels"
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
                  <!-- «Сейчас» strip -->
                  <InfoPanel
                    :title="t('crm.entity.nowStrip.label')"
                    icon="pi-bolt"
                    panel-key="company-now-strip"
                    :default-collapsed="false"
                  >
                    <EntityNowStrip :items="companyNowItems" />
                  </InfoPanel>

                  <!-- Mini pipeline / deals panel -->
                  <MiniPipelinePanel
                    :deals="deals"
                    :loading="dealsLoading"
                    @create-deal="onCreateDeal"
                    @filter-by-stage="(id) => { goToTab('deals') }"
                    @go-to-tab="goToTab"
                  />

                  <!-- Mini timeline (Хронология) -->
                  <InfoPanel
                    :title="t('crm.entity.miniTimeline.title')"
                    icon="pi-history"
                    panel-key="company-mini-timeline"
                    :default-collapsed="false"
                  >
                    <EntityMiniTimeline
                      :log="companyLog"
                      :max-items="5"
                      :on-go-to-log="() => goToTab('log')"
                    />
                  </InfoPanel>

                  <!-- Custom fields (collapsed) -->
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

            <!-- ── Log tab ───────────────────────────────────────────── -->
            <TabPanel value="log">
              <div class="company-page-v2__tab-content">
                <EntityLogTab :log="companyLog" :metrics="companyMetrics" />
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
            :suggestions="augmentedEmployeeSuggestions"
            option-label="full_name"
            :placeholder="t('common.search')"
            class="w-full"
            force-selection
            @complete="(e) => { createContactInlineQuery = e.query; searchEmployeeContacts(e.query) }"
            @option-select="onEmployeeOptionSelect($event.value)"
          >
            <template #option="{ option }">
              <!-- "create contact" sentinel -->
              <div
                v-if="option.__create"
                class="company-page-v2__create-option"
              >
                <i class="pi pi-user-plus company-page-v2__create-icon" />
                <span>{{ option.full_name }}</span>
              </div>
              <!-- regular contact -->
              <div v-else class="company-page-v2__contact-option">
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

    <!-- Inline contact creation from employee autocomplete -->
    <CreateContactInlineDialog
      v-model="createContactInlineOpen"
      :initial-name="createContactInlineQuery"
      :show-is-primary="true"
      @created="onInlineEmployeeCreated"
    />

    <!-- Disconnect dialog -->
    <DisconnectDialog
      v-if="company"
      v-model="disconnectDialogOpen"
      :company-id="company.id"
      :company-name="company.name"
      :reasons="directoriesStore.activeDisconnectReasons"
      :signatory-default="company.director_short ?? company.director_position ?? null"
      @created="onDisconnectCreated"
    />

    <!-- Termination document drawer -->
    <TerminationDocumentDrawer
      v-if="company && terminationDoc"
      v-model="terminationDrawerOpen"
      :company-name="company.name"
      :document-id="terminationDoc.id"
      @company-updated="onCompanyUpdatedFromTermination"
    />

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
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import AutoComplete from 'primevue/autocomplete'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import EntityInfoHeader from '@/components/crm/entity/EntityInfoHeader.vue'
import EntityKpiStrip, { type KpiItem } from '@/components/crm/entity/EntityKpiStrip.vue'
import EntityNowStrip, { type NowItem } from '@/components/crm/entity/EntityNowStrip.vue'
import EntityMiniTimeline from '@/components/crm/entity/EntityMiniTimeline.vue'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import EntityActivitiesTab from '@/components/crm/entity/EntityActivitiesTab.vue'
import EntityLogTab, { type LogMetric } from '@/components/crm/entity/EntityLogTab.vue'
import CustomFieldRenderer from '@/components/crm/entity/CustomFieldRenderer.vue'
import CreateContactInlineDialog from '@/components/crm/CreateContactInlineDialog.vue'
import { useEntityLog } from '@/composables/crm/useEntityLog'
import CompanyRequisitesPanel from './components/CompanyRequisitesPanel.vue'
import CompanyMarketingPanel from './components/CompanyMarketingPanel.vue'
import ClientStatusBadge from '@/components/crm/ClientStatusBadge.vue'
import DisconnectDialog from './components/DisconnectDialog.vue'
import TerminationDocumentDrawer from './components/TerminationDocumentDrawer.vue'
import CompanyEmployeesPanel from './components/CompanyEmployeesPanel.vue'
import CompanyEmployeesTab from './components/CompanyEmployeesTab.vue'
import CompanyDocumentsTab from './components/CompanyDocumentsTab.vue'
import CompanyDealsTab from './components/CompanyDealsTab.vue'
import HoldingTree from './components/HoldingTree.vue'
import MiniPipelinePanel from './components/MiniPipelinePanel.vue'
import { useCompanyPageData } from './composables/useCompanyPageData'
import { useCompanyPageActions } from './composables/useCompanyPageActions'
import { useBreakpoints } from '@/composables/useBreakpoints'
import { companiesApi } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { CompanyExtended, EmploymentStatus, Company, Contact } from '@/entities/crm'
import type { DocumentDto } from '@/entities/document'
import type { MenuItem } from 'primevue/menuitem'
import { useConfirm } from 'primevue/useconfirm'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()

const { isTablet, isMobile } = useBreakpoints()

// ── Disconnect / Termination state ─────────────────────────────────────────────

const disconnectDialogOpen = ref(false)
const terminationDrawerOpen = ref(false)
const terminationDoc = ref<DocumentDto | null>(null)

const activeTab = ref('overview')
const employeeSearch = ref('')
const showAttachHolding = ref(false)


// Inline contact creation (from employee autocomplete)
const createContactInlineOpen = ref(false)
const createContactInlineQuery = ref('')
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

// ── Entity log ─────────────────────────────────────────────────────────────────

const companyLog = useEntityLog('company', () => companyId.value ?? null)

const companyMetrics = computed((): LogMetric[] => [
  { key: 'openDeals', label: t('crm.log.metrics.openDeals'), metricValue: openDealsCount.value },
  { key: 'employees', label: t('crm.log.metrics.employees'), metricValue: employees.value.length },
  { key: 'documents', label: t('crm.log.metrics.documents'), metricValue: documents.value.length },
])

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

// ── KPI strip ──────────────────────────────────────────────────────────────────

function lastActivityAccent(lastAt: string | null): KpiItem['accent'] {
  if (!lastAt) return 'neutral'
  const days = Math.floor((Date.now() - new Date(lastAt).getTime()) / 86_400_000)
  if (days > 30) return 'danger'
  if (days > 7) return 'warning'
  return 'success'
}

function formatRelativeActivity(lastAt: string | null): string {
  if (!lastAt) return t('crm.entity.kpiStrip.never', 'Нет')
  const days = Math.floor((Date.now() - new Date(lastAt).getTime()) / 86_400_000)
  if (days === 0) return t('common.today', 'Сегодня')
  if (days === 1) return t('common.yesterday', 'Вчера')
  return t('crm.entity.kpiStrip.daysAgo', { n: days }, `${days}д`)
}

function formatKopecks(kopecks: number | null | undefined): string {
  if (kopecks == null) return '—'
  const units = Math.round(kopecks / 100)
  if (units >= 1_000_000) return `${(units / 1_000_000).toFixed(1)}M`
  if (units >= 1_000) return `${(units / 1_000).toFixed(0)}K`
  return units.toLocaleString('ru-RU')
}

const companyKpiItems = computed((): KpiItem[] => {
  const ext = company.value as CompanyExtended | null
  const lastAt = ext?.last_activity_at ?? null
  const kpi = (ext as (CompanyExtended & { kpi?: { open_count?: number; base_total?: number; employees_count?: number; documents_count?: number; won_count?: number; upsell_count?: number } | null }) | null)?.kpi ?? null
  const openCount = kpi?.open_count ?? openDealsCount.value
  const dealsSum = kpi?.base_total ?? null
  const employeesCount = kpi?.employees_count ?? employees.value.length
  const documentsCount = kpi?.documents_count ?? documents.value.length
  const wonCount = kpi?.won_count ?? 0
  const upsellCount = kpi?.upsell_count ?? 0
  return [
    {
      key: 'open_deals',
      icon: 'pi-briefcase',
      label: 'company.kpi.openDeals',
      value: openCount,
      accent: openCount === 0 ? 'neutral' : 'info',
      clickable: true,
      onClick: () => goToTab('deals'),
    },
    {
      key: 'deals_sum',
      icon: 'pi-chart-line',
      label: 'company.kpi.dealsSum',
      value: formatKopecks(dealsSum),
      accent: 'brand',
    },
    {
      key: 'won_deals',
      icon: 'pi-trophy',
      label: 'company.kpi.wonDeals',
      value: wonCount,
      accent: 'success',
    },
    {
      key: 'upsell_deals',
      icon: 'pi-refresh',
      label: 'company.kpi.upsellDeals',
      value: upsellCount,
      accent: 'info',
    },
    {
      key: 'employees',
      icon: 'pi-users',
      label: 'company.kpi.employees',
      value: employeesCount,
      accent: 'teal',
      clickable: true,
      onClick: () => goToTab('contacts'),
    },
    {
      key: 'documents',
      icon: 'pi-file',
      label: 'company.kpi.documents',
      value: documentsCount,
      accent: 'amber',
      clickable: true,
      onClick: () => goToTab('documents'),
    },
    {
      key: 'last_activity',
      icon: 'pi-clock',
      label: 'company.kpi.lastActivity',
      value: formatRelativeActivity(lastAt),
      accent: lastActivityAccent(lastAt),
    },
  ]
})

// ── Now strip ──────────────────────────────────────────────────────────────────

const companyNowItems = computed((): NowItem[] => {
  const ext = company.value as CompanyExtended | null
  const lastAt = ext?.last_activity_at ?? null
  const lastDays = lastAt
    ? Math.floor((Date.now() - new Date(lastAt).getTime()) / 86_400_000)
    : null
  const lastContactLabel = lastDays === null
    ? t('crm.entity.kpiStrip.never', 'Нет')
    : lastDays === 0
      ? t('common.today', 'Сегодня')
      : lastDays === 1
        ? t('common.yesterday', 'Вчера')
        : `${lastDays}${t('crm.entity.kpiStrip.daysUnit', 'д')}`
  const lastContactSeverity: NowItem['severity'] = lastDays === null
    ? 'neutral'
    : lastDays > 30
      ? 'danger'
      : lastDays > 7
        ? 'warning'
        : 'success'

  const openTasks = (ext as (CompanyExtended & { open_tasks_count?: number }) | null)?.open_tasks_count ?? 0
  const overdue = (ext as (CompanyExtended & { overdue_tasks_count?: number }) | null)?.overdue_tasks_count ?? 0

  return [
    {
      label: t('crm.entity.nowStrip.lastContact'),
      value: lastContactLabel,
      severity: lastContactSeverity,
    },
    {
      label: t('crm.entity.nowStrip.openTasks'),
      value: openTasks,
      severity: openTasks > 0 ? 'warning' : 'neutral',
    },
    {
      label: t('crm.entity.nowStrip.overdue'),
      value: overdue,
      severity: overdue > 0 ? 'danger' : 'neutral',
    },
  ]
})

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

const menuItems = computed((): MenuItem[] => {
  const items: MenuItem[] = [
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
  ]

  // Client lifecycle actions
  const clientStatus = company.value?.client_status
  if (clientStatus === 'active') {
    items.push({
      label: t('crm.company.menu.disconnect'),
      icon: 'pi pi-times-circle',
      class: 'menu-item--danger',
      command: () => { disconnectDialogOpen.value = true },
    })
  } else if (clientStatus === 'disconnected') {
    items.push({
      label: t('crm.company.menu.reconnect'),
      icon: 'pi pi-refresh',
      command: onReconnect,
    })
  }

  items.push({ separator: true })
  items.push({
    label: t('common.delete'),
    icon: 'pi pi-trash',
    command: onDeleteCompany,
  })

  return items
})

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
  { label: t('crm.company.tabs.log'), value: 'log' },
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

// ── Disconnect / Reconnect ────────────────────────────────────────────────────

function onDisconnectCreated(doc: DocumentDto) {
  // Store the document and the payload for the TerminationDocumentDrawer
  terminationDoc.value = doc
  // Build a generate-compatible payload from what was sent (drawer re-uses it for generate)
  // The doc carries the context implicitly; we just open the drawer
  terminationDrawerOpen.value = true
}

function onReconnect() {
  if (!company.value) return
  confirm.require({
    message: t('crm.company.reconnect.confirm'),
    header: t('crm.company.menu.reconnect'),
    icon: 'pi pi-refresh',
    acceptLabel: t('common.confirm', 'Подтвердить'),
    rejectLabel: t('common.cancel'),
    accept: async () => {
      if (!company.value) return
      try {
        const updated = await companiesApi.reconnect(company.value.id)
        Object.assign(company.value, updated)
        toast.add({ severity: 'success', summary: t('crm.company.reconnect.success'), life: 3000 })
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    },
  })
}

async function onCompanyUpdatedFromTermination() {
  // Refetch company — backend set client_status=disconnected
  if (!companyId.value) return
  try {
    const updated = await companiesApi.get(companyId.value)
    if (company.value) Object.assign(company.value, updated)
    toast.add({
      severity: 'success',
      summary: t('crm.termination.statusDisconnected'),
      detail: t('crm.company.clientStatus.disconnected'),
      life: 4000,
    })
  } catch {
    // non-fatal — just show toast
    toast.add({ severity: 'info', summary: t('crm.termination.scanUploaded'), life: 3000 })
  }
}

function onDeleteCompany() {
  if (!company.value) return
  confirm.require({
    message: t('company.page.menu.deleteConfirm'),
    header: t('common.delete'),
    icon: 'pi pi-trash',
    acceptLabel: t('common.confirm', 'Подтвердить'),
    rejectLabel: t('common.cancel'),
    acceptClass: 'p-button-danger',
    accept: async () => {
      if (!company.value) return
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
    },
  })
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

/** Employee suggestions augmented with the "create contact" sentinel at the bottom */
const augmentedEmployeeSuggestions = computed(() => {
  const sentinel = {
    id: -1,
    full_name: t('contacts.inline_create.create_option'),
    email: null as string | null,
    phone: null as string | null,
    __create: true as const,
  }
  return [...addEmployeeSuggestions.value, sentinel]
})

/** Handle option selected in employee autocomplete — intercept sentinel */
function onEmployeeOptionSelect(option: Contact & { __create?: true }) {
  if (option.__create) {
    // Close the regular add-employee dialog and open inline create
    addEmployeeSearch.value = ''
    addEmployeeContactId.value = null
    closeAddEmployee()
    createContactInlineOpen.value = true
    return
  }
  onEmployeeSelect(option)
}

/** Called after inline create dialog creates a contact — attach to company */
async function onInlineEmployeeCreated(contact: Contact, position: string, _isPrimary: boolean) {
  if (!companyId.value) return
  try {
    await companiesApi.attachEmployee(companyId.value, {
      contact_id: contact.id,
      position: position || undefined,
      employment_status: 'works',
      is_primary: _isPrimary,
    })
    await loadEmployees()
    toast.add({
      severity: 'success',
      summary: t('company.page.employees.addSuccess', 'Сотрудник добавлен'),
      life: 3000,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

function onSubmitEmployee() {
  if (addEmployeeContactId.value) {
    void submitAddEmployee()
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────────

onMounted(async () => {
  if (!directoriesStore.loaded) void directoriesStore.fetchAll()
  await loadAll()
  // Load entity log (company may now be loaded)
  if (companyId.value) void companyLog.load()
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

.company-page-v2__create-option {
  display: flex;
  align-items: center;
  gap: $space-2;
  color: $primary-color;
  font-weight: $font-weight-medium;
  font-size: $font-size-sm;
}

.company-page-v2__create-icon {
  font-size: $font-size-sm;
}

.w-full {
  width: 100%;
}
</style>
