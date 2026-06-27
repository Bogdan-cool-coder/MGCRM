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
        :author-name="company.owner_user?.full_name"
        :works-with-name="company.responsible_user?.full_name"
        :category-code="company.category_code"
        :engagement-tier="(company as CompanyExtended).engagement_tier ?? undefined"
        :last-activity-at="(company as CompanyExtended).last_activity_at"
        :tags="company.tags"
        :source-label="companySourceLabel"
        :created-at="company.created_at"
        :updated-at="company.updated_at"
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
            <!-- spec §3: tab order — Файлы before Холдинг; NO «Платежи» tab -->
            <Tab value="files">{{ t('crm.company.tabs.files') }}</Tab>
            <Tab value="holding">{{ t('company.page.tabs.holding') }}</Tab>
          </TabList>

          <TabPanels>
            <!-- ── Overview (одна колонка §3.2) ──────────────────────────── -->
            <TabPanel value="overview">
              <div class="company-page-v2__tab-content">
                <div class="row g-0">
                  <div class="col-12">
                    <div class="company-page-v2__panels">

                      <!-- 1. Реквизиты -->
                      <CompanyRequisitesPanel
                        :company="company"
                        :is-saving="isSaving"
                        @save="patchField"
                      />

                      <!-- 1b. Каналы связи -->
                      <InfoPanel
                        :title="t('crm.company.sections.channels')"
                        icon="pi-phone"
                        panel-key="company-channels"
                        :default-collapsed="false"
                        :count="companyChannels.length || undefined"
                      >
                        <template #header-action>
                          <button type="button" class="company-page-v2__add-btn" @click.stop="openAddChannel">
                            <i class="pi pi-plus" />
                            {{ t('crm.company.channels.addChannel') }}
                          </button>
                        </template>
                        <CompanyChannelsBlock
                          ref="channelsBlockRef"
                          :company-id="company.id"
                          :channels="companyChannels"
                          @updated="onChannelsUpdated"
                        />
                      </InfoPanel>

                      <!-- 2. Сотрудники (обзор) -->
                      <CompanyEmployeesPanel
                        :employees="employees"
                        @add-employee="openAddEmployee"
                        @set-primary="setPrimaryEmployee"
                        @go-to-tab="goToTab"
                      />

                      <!-- 3. Сделки в работе (CompanyMiniDealsPanel) -->
                      <InfoPanel
                        :title="t('crm.company.sections.dealsInProgress')"
                        icon="pi-briefcase"
                        panel-key="company-deals-overview"
                        :default-collapsed="false"
                        :count="deals.length || undefined"
                      >
                        <template #header-action>
                          <!-- spec §4: AddBtn text-link style -->
                          <button type="button" class="company-page-v2__add-btn" @click.stop="onCreateDeal">
                            <i class="pi pi-plus" />
                            {{ t('company.page.deals.createDeal') }}
                          </button>
                        </template>
                        <CompanyMiniDealsPanel :deals="deals" />
                      </InfoPanel>

                      <!-- 4. Холдинг -->
                      <InfoPanel
                        :title="t('company.page.tabs.holding')"
                        icon="pi-sitemap"
                        panel-key="company-holding-overview"
                        :default-collapsed="true"
                      >
                        <template #header-action>
                          <button type="button" class="company-page-v2__add-btn" @click.stop="showAttachHolding = true">
                            <i class="pi pi-plus" />
                            {{ t('crm.company.holding.addParent') }}
                          </button>
                        </template>
                        <HoldingTree
                          :tree="holding"
                          :loading="holdingLoading"
                          @attach-parent="showAttachHolding = true"
                          @detach-parent="onDetachHolding"
                        />
                      </InfoPanel>

                      <!-- 5. История событий (мини) -->
                      <InfoPanel
                        :title="t('crm.entity.miniTimeline.title')"
                        icon="pi-history"
                        panel-key="company-mini-timeline"
                        :default-collapsed="false"
                      >
                        <EntityMiniTimeline
                          :log="companyLog"
                          :max-items="5"
                          :on-go-to-log="() => goToTab('activity')"
                        />
                      </InfoPanel>

                      <!-- Доп. поля (свёрнуты) -->
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
                </div>
              </div>
            </TabPanel>

            <!-- ── Activity ───────────────────────────────────────── -->
            <TabPanel value="activity">
              <div class="company-page-v2__tab-content">
                <EntityActivitiesTab
                  ref="companyActivitiesTabRef"
                  entity-type="company"
                  :entity-id="company.id"
                  @changed="onActivityChanged"
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
                  @set-status="setEmployeeStatus"
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

            <!-- ── Files tab — spec §3: Файлы before Холдинг ────── -->
            <TabPanel value="files">
              <div class="company-page-v2__tab-content company-page-v2__tab-content--no-pad">
                <CompanyFilesTab :company-id="companyId" />
              </div>
            </TabPanel>

            <!-- ── Holding tab ────────────────────────────────────── -->
            <TabPanel value="holding">
              <div class="company-page-v2__tab-content company-page-v2__tab-content--no-pad">
                <!-- TabHead -->
                <div class="company-page-v2__tab-head">
                  <span class="company-page-v2__tab-head-title">{{ t('company.page.tabs.holding') }}</span>
                  <Button
                    icon="pi pi-plus"
                    :label="t('crm.company.holding.addParent')"
                    size="small"
                    severity="secondary"
                    outlined
                    @click="showAttachHolding = true"
                  />
                </div>
                <div class="company-page-v2__holding-tab-wrapper">
                  <!-- spec §10: standalone mode — no InfoPanel wrapper, raw tree content -->
                  <HoldingTree
                    :tree="holding"
                    :loading="holdingLoading"
                    :standalone="true"
                    @attach-parent="showAttachHolding = true"
                    @detach-parent="onDetachHolding"
                  />
                </div>
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

    <!-- Attach holding dialog -->
    <Dialog
      v-model:visible="showAttachHolding"
      :header="t('crm.company.holding.addParent')"
      modal
      style="width: 440px"
    >
      <div class="row g-3" style="padding-top: 4px">
        <div class="col-12">
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
        <div class="col-12">
          <label class="company-page-v2__label">{{ t('crm.company.holding.roleLabel') }} *</label>
          <Select
            v-model="holdingRole"
            :options="holdingRoleOptions"
            option-label="label"
            option-value="value"
            class="w-full"
            :placeholder="t('common.select')"
          />
        </div>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="showAttachHolding = false" />
        <Button
          :label="t('common.save')"
          :loading="holdingAttaching"
          :disabled="!holdingParentId || !holdingRole"
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

    <!--
      spec §11: DealCreateDrawer right-500 pre-filled with company.
      EXISTS_NOT_WIRED → now wired: prefill company; on @created refresh deal list.
    -->
    <DealCreateDrawer
      v-if="dealCreatePipelines.length > 0"
      v-model="dealCreateOpen"
      :pipelines="dealCreatePipelines"
      :initial-company="company ? { id: company.id, name: company.name } : undefined"
      @created="onDealCreated"
    />

  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
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
import DealCreateDrawer from '@/pages/DealsPage/components/DealCreateDrawer.vue'
import { salesApi } from '@/api/sales'
import EntityInfoHeader from '@/components/crm/entity/EntityInfoHeader.vue'
import EntityKpiStrip, { type KpiItem } from '@/components/crm/entity/EntityKpiStrip.vue'
import EntityMiniTimeline from '@/components/crm/entity/EntityMiniTimeline.vue'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import EntityActivitiesTab from '@/components/crm/entity/EntityActivitiesTab.vue'
import CustomFieldRenderer from '@/components/crm/entity/CustomFieldRenderer.vue'
import CreateContactInlineDialog from '@/components/crm/CreateContactInlineDialog.vue'
import { useEntityLog } from '@/composables/crm/useEntityLog'
import CompanyRequisitesPanel from './components/CompanyRequisitesPanel.vue'
import ClientStatusBadge from '@/components/crm/ClientStatusBadge.vue'
import DisconnectDialog from './components/DisconnectDialog.vue'
import TerminationDocumentDrawer from './components/TerminationDocumentDrawer.vue'
import CompanyEmployeesPanel from './components/CompanyEmployeesPanel.vue'
import CompanyEmployeesTab from './components/CompanyEmployeesTab.vue'
import CompanyDocumentsTab from './components/CompanyDocumentsTab.vue'
import CompanyDealsTab from './components/CompanyDealsTab.vue'
import CompanyMiniDealsPanel from './components/CompanyMiniDealsPanel.vue'
import CompanyChannelsBlock from './components/CompanyChannelsBlock.vue'
import CompanyFilesTab from './components/CompanyFilesTab.vue'
import HoldingTree from './components/HoldingTree.vue'
import { useCompanyPageData } from './composables/useCompanyPageData'
import { useCompanyPageActions } from './composables/useCompanyPageActions'
import { useBreakpoints } from '@/composables/useBreakpoints'
import { companiesApi } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { CompanyExtended, EmploymentStatus, Company, Contact, CompanyChannel } from '@/entities/crm'
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
const companyActivitiesTabRef = ref<{ focusNote: () => void; focusTask: () => void } | null>(null)

// ── DealCreateDrawer state ─────────────────────────────────────────────────────
const dealCreateOpen = ref(false)
const dealCreatePipelines = ref<import('@/entities/sales').PipelineDto[]>([])


// Inline contact creation (from employee autocomplete)
const createContactInlineOpen = ref(false)
const createContactInlineQuery = ref('')
const holdingParentSearch = ref('')
const holdingParentId = ref<number | null>(null)
const holdingParentSuggestions = ref<Array<{ id: number; name: string }>>([])
const holdingAttaching = ref(false)
const holdingRole = ref<'parent' | 'subsidiary' | 'affiliate' | null>(null)

const holdingRoleOptions = computed(() => [
  { value: 'parent', label: t('crm.company.holding.roleParent') },
  { value: 'subsidiary', label: t('crm.company.holding.roleSubsidiary') },
  { value: 'affiliate', label: t('crm.company.holding.roleAffiliate') },
])

// ── Channels state ─────────────────────────────────────────────────────────────

const companyChannels = ref<CompanyChannel[]>([])
const channelsBlockRef = ref<{ openAdd: () => void } | null>(null)

function onChannelsUpdated(updated: CompanyChannel[]) {
  companyChannels.value = updated
}

function openAddChannel() {
  channelsBlockRef.value?.openAdd()
}

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
  loadCompany,
  loadEmployees,
  loadHolding,
  loadDeals,
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
  setEmployeeStatus,
  confirmUnlinkEmployee,
} = useCompanyPageActions({
  companyId,
  company,
  employees,
  loadEmployees,
})

// ── Entity log ─────────────────────────────────────────────────────────────────

const companyLog = useEntityLog('company', () => companyId.value ?? null)

// ── Activity changed handler ────────────────────────────────────────────────────
// Refreshes both the log timeline AND the company KPI strip (last_activity_at and
// deal/employee counters come from CompanyController::show).

function onActivityChanged() {
  void companyLog.load()
  void loadCompany()
}

// ── Computed ───────────────────────────────────────────────────────────────────

const companySourceLabel = computed((): string | null => {
  const ext = company.value as (typeof company.value & { source?: string | null; acquisition_channel?: { name?: string } | null }) | null
  if (!ext) return null
  if (ext.acquisition_channel?.name) return ext.acquisition_channel.name
  if (ext.source) return directoriesStore.getSourceLabel?.(ext.source) ?? ext.source
  return null
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
  const kpi = (ext as (CompanyExtended & { kpi?: { open_deals_count?: number; open_count?: number; deals_sum?: number; base_total?: number; employees_count?: number; documents_count?: number; won_count?: number } | null }) | null)?.kpi ?? null
  // backend sends open_deals_count; open_count kept as legacy fallback
  const openCount = kpi?.open_deals_count ?? kpi?.open_count ?? openDealsCount.value
  // E2: backend now returns deals_sum (was base_total — wrong field caused 0/— display)
  const dealsSum = kpi?.deals_sum ?? kpi?.base_total ?? null
  const employeesCount = kpi?.employees_count ?? employees.value.length
  const documentsCount = kpi?.documents_count ?? documents.value.length
  const wonCount = kpi?.won_count ?? 0
  // spec §2 company: 6 chips — Откр.сделки · Сумма · Выиграно · Сотрудники · Документы · Послед.активность
  // NO upsell chip; pi-wallet for deals_sum (not pi-chart-line)
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
      icon: 'pi-wallet', // spec §2: pi-wallet (not pi-chart-line)
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
  // spec §1: menu = 5 items only: Добавить заметку · Добавить связь · Скопировать ссылку · — · Удалить
  return [
    {
      label: t('company.page.menu.addNote'),
      icon: 'pi pi-comment',
      command: () => {
        goToTab('activity')
        void nextTick(() => companyActivitiesTabRef.value?.focusNote())
      },
    },
    {
      label: t('crm.contact.menu.addRelation'),
      icon: 'pi pi-link',
      command: () => { goToTab('overview') },
    },
    {
      label: t('company.page.menu.copyLink'),
      icon: 'pi pi-copy',
      command: () => {
        void navigator.clipboard.writeText(window.location.href)
        toast.add({ severity: 'success', summary: t('common.copied'), life: 2000 })
      },
    },
    { separator: true },
    {
      label: t('common.delete'),
      icon: 'pi pi-trash',
      command: onDeleteCompany,
    },
  ]
})

// ── Tab navigation options (mobile Select) ─────────────────────────────────────

// spec §3: 7 tabs in order — Обзор·Активность·Сотрудники·Сделки·Документы·Файлы·Холдинг
// NO «Платежи» tab
const tabOptions = computed(() => [
  { label: t('company.page.tabs.overview'), value: 'overview' },
  { label: t('crm.company.tabs.activity'), value: 'activity' },
  { label: t('crm.company.tabs.employees'), value: 'contacts' },
  { label: t('company.page.tabs.deals'), value: 'deals' },
  { label: t('company.page.tabs.documents'), value: 'documents' },
  { label: t('crm.company.tabs.files'), value: 'files' },
  { label: t('company.page.tabs.holding'), value: 'holding' },
])

// ── Actions ────────────────────────────────────────────────────────────────────

function goToTab(tab: string) {
  activeTab.value = tab
}

async function onCreateDeal() {
  if (!company.value) return
  // spec §11: open DealCreateDrawer (right 500px) pre-filled with company
  if (dealCreatePipelines.value.length === 0) {
    try {
      dealCreatePipelines.value = await salesApi.getPipelines()
    } catch {
      // fall back to redirect if pipelines fail to load
      void router.push(`/deals?company_id=${company.value.id}&create=1`)
      return
    }
  }
  dealCreateOpen.value = true
}

function onDealCreated() {
  dealCreateOpen.value = false
  void loadDeals()
  toast.add({ severity: 'success', summary: t('sales.deals.form.createSuccess'), life: 3000 })
}

// ── Disconnect / Reconnect ────────────────────────────────────────────────────

function onDisconnectCreated(doc: DocumentDto) {
  // Store the document and the payload for the TerminationDocumentDrawer
  terminationDoc.value = doc
  // Build a generate-compatible payload from what was sent (drawer re-uses it for generate)
  // The doc carries the context implicitly; we just open the drawer
  terminationDrawerOpen.value = true
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
  if (!holdingParentId.value || !companyId.value || !holdingRole.value) return
  holdingAttaching.value = true
  try {
    await companiesApi.attachHolding(companyId.value, {
      parent_id: holdingParentId.value,
      holding_role: holdingRole.value,
    })
    showAttachHolding.value = false
    holdingParentSearch.value = ''
    holdingParentId.value = null
    holdingRole.value = null
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
  // Load company channels
  if (companyId.value) {
    try {
      companyChannels.value = await companiesApi.getChannels(companyId.value)
    } catch {
      // non-critical: channels panel will show empty state
    }
  }
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
  font-size: $font-size-icon-2xl;
  color: $surface-300;
}

.company-page-v2__error-title {
  font-size: $font-size-base;
  color: $surface-600;
  margin: 0;
}

// ── Body — hidden scrollbar (spec §13 global rule) ────────────────────────────
.company-page-v2__body {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

// ── AddBtn (spec §4) — text-link with icon + label ────────────────────────────

.company-page-v2__add-btn {
  display: inline-flex;
  align-items: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  gap: 5px; // spec §4: gap 5px
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
  background: transparent;
  border: none;
  cursor: pointer;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 4px 9px; // spec §4: padding 4px 9px
  border-radius: $radius-sm;
  white-space: nowrap;
  transition: background var(--app-transition-fast);

  &:hover {
    background: $primary-100;
  }

  .app-dark & {
    color: var(--p-primary-300);

    &:hover {
      background: var(--p-primary-900);
    }
  }

  i {
    font-size: $font-size-xs;
  }
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

    // #4 fix: dark tablist must use {surface.100}=#444547 (card bg), NOT {surface.900}=#F9FAFB.
    // {surface.900} in our inverted dark palette is surfacePalette[50]=#F9FAFB (nearly white).
    .app-dark & {
      background: var(--p-surface-100); // dark #444547 (card bg canon §5.2)
      border-bottom-color: var(--p-surface-700); // dark #616263
    }
  }

  // spec §3: active tab label font-weight = 600
  :deep(.p-tab[aria-selected="true"]) {
    font-weight: $font-weight-semibold;
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

  // #4 fix: same dark correction as tablist above — {surface.100}=#444547 (card bg)
  .app-dark & {
    background: var(--p-surface-100); // dark #444547 (card bg canon)
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

  &--no-pad {
    padding: 0;
  }
}

// ── Panels stacked layout (overview one-column) ────────────────────────────────
.company-page-v2__panels {
  background: $surface-card;
  border-radius: $radius-lg;
  // var(--p-surface-200) reactive: light=#E3E4E6, dark=#616263 (inverted palette).
  border: 1px solid var(--p-surface-200);
  box-shadow: $shadow-card;
  overflow: hidden;
}

// ── Holding / Files TabHead ────────────────────────────────────────────────────
.company-page-v2__tab-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

.company-page-v2__tab-head-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;
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
  font-size: $font-size-icon-2xl;
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
  font-size: $font-size-2xl;
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
  font-size: $font-size-icon-2xl;
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
