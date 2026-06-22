<template>
  <div
    class="contact-page-v2"
    :class="{
      'contact-page-v2--mobile': isMobile,
      'contact-page-v2--tablet': isTablet,
    }"
  >
    <!-- ── Loading skeleton ───────────────────────────────────────────────────── -->
    <template v-if="contactLoading">
      <Skeleton height="140px" class="contact-page-v2__header-skeleton" />
      <div class="contact-page-v2__body">
        <div class="row g-3">
          <div class="col-md-6"><Skeleton height="120px" /></div>
          <div class="col-md-6"><Skeleton height="120px" /></div>
          <div class="col-12"><Skeleton height="80px" /></div>
        </div>
      </div>
    </template>

    <!-- ── Error ─────────────────────────────────────────────────────────────── -->
    <template v-else-if="contactError || !contact">
      <div class="contact-page-v2__error">
        <i class="pi pi-exclamation-triangle contact-page-v2__error-icon" />
        <p class="contact-page-v2__error-title">{{ t('contact.page.errors.load') }}</p>
        <Button
          icon="pi pi-arrow-left"
          :label="t('contact.page.back')"
          severity="secondary"
          outlined
          @click="router.push('/contacts')"
        />
      </div>
    </template>

    <!-- ── Main content ───────────────────────────────────────────────────────── -->
    <template v-else>
      <!-- Dark brand header -->
      <EntityInfoHeader
        :entity-id="contact.id"
        :title="contact.full_name"
        :subtitle="contact.position ?? undefined"
        :author-name="contact.owner?.full_name"
        :company-name="primaryCompanyName"
        :source-label="contactSourceLabel"
        :created-at="contact.created_at"
        :updated-at="contact.updated_at"
        :engagement-tier="contact.engagement_tier ?? undefined"
        :last-activity-at="contact.last_activity_at"
        :tags="contact.tags"
        :menu-items="menuItems"
        @back="router.back()"
      />

      <!-- KPI strip -->
      <EntityKpiStrip
        :items="contactKpiItems"
        :loading="contactLoading"
      />

      <!-- Body: mobile tab select + tab panels -->
      <div class="contact-page-v2__body">
        <!-- Mobile: Select-based tab navigation -->
        <div v-if="isMobile" class="contact-page-v2__mobile-tab-select">
          <Select
            v-model="activeTab"
            :options="tabOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>

        <Tabs v-model:value="activeTab" class="contact-page-v2__tabs">
          <TabList v-if="!isMobile" :class="{ 'contact-page-v2__tablist--scroll': isTablet }">
            <Tab value="overview">{{ t('contact.page.tabs.overview') }}</Tab>
            <Tab value="activity">{{ t('crm.contact.tabs.activity') }}</Tab>
            <Tab value="deals">
              {{ t('contact.page.tabs.deals') }}
              <Badge
                v-if="deals && deals.length"
                :value="deals.length"
                severity="secondary"
                size="small"
                class="ms-1"
              />
            </Tab>
            <Tab value="files">{{ t('crm.contact.tabs.files') }}</Tab>
          </TabList>

          <TabPanels>
            <!-- ── Overview ─────────────────────────────────────────────────── -->
            <TabPanel value="overview">
              <div class="contact-page-v2__tab-body">
                <div class="row g-0">
                  <div class="col-12">
                    <div class="contact-page-v2__panels">

                      <!-- 1. Каналы связи -->
                      <InfoPanel
                        :title="t('crm.contact.sections.channels')"
                        icon="pi-phone"
                        panel-key="contact-channels"
                        :default-collapsed="false"
                        :count="channels.length || undefined"
                      >
                        <template #header-action>
                          <!-- spec §4: AddBtn = text-link with icon + label, NOT icon-only Button -->
                          <button type="button" class="contact-page-v2__add-btn" @click.stop="openAddChannel">
                            <i class="pi pi-plus" />
                            {{ t('crm.contact.channels.addChannel') }}
                          </button>
                        </template>
                        <ContactChannelsBlock
                          ref="channelsBlockRef"
                          :contact-id="contact.id"
                          :channels="channels"
                          @updated="onChannelsUpdated"
                        />
                      </InfoPanel>

                      <!-- 2. Компании -->
                      <InfoPanel
                        :title="t('crm.contact.sections.companies')"
                        icon="pi-building"
                        panel-key="contact-companies"
                        :default-collapsed="false"
                        :count="companies.length || undefined"
                      >
                        <template #header-action>
                          <button type="button" class="contact-page-v2__add-btn" @click.stop="openAttachCompany">
                            <i class="pi pi-plus" />
                            {{ t('contact.page.companies.add') }}
                          </button>
                        </template>
                        <ContactCompaniesPanel
                          :companies="companies"
                          :loading="companiesLoading"
                          @attach="openAttachCompany"
                          @set-primary="setPrimaryCompany"
                          @detach="confirmDetachCompany"
                        />
                      </InfoPanel>

                      <!-- 3. Связи -->
                      <InfoPanel
                        :title="t('crm.contact.sections.relations')"
                        icon="pi-share-alt"
                        panel-key="contact-relations"
                        :default-collapsed="false"
                        :count="relations.length || undefined"
                      >
                        <template #header-action>
                          <button type="button" class="contact-page-v2__add-btn" @click.stop="openAddRelation">
                            <i class="pi pi-plus" />
                            {{ t('crm.contact.relations.add') }}
                          </button>
                        </template>
                        <ContactRelationsPanel
                          ref="relationsBlockRef"
                          :contact-id="contact.id"
                          :relations="relations"
                          :loading="relationsLoading"
                          @updated="onRelationsUpdated"
                        />
                      </InfoPanel>

                      <!-- 4. Участвует в сделках -->
                      <InfoPanel
                        :title="t('crm.contact.sections.dealsParticipation')"
                        icon="pi-briefcase"
                        panel-key="contact-deals-overview"
                        :default-collapsed="false"
                        :count="deals.length || undefined"
                      >
                        <template #header-action>
                          <button type="button" class="contact-page-v2__add-btn" @click.stop="addToDealOpen = true">
                            <i class="pi pi-plus" />
                            {{ t('crm.contact.deals.add') }}
                          </button>
                        </template>
                        <ContactDealsPanel
                          :deals="deals"
                          :loading="dealsLoading"
                          :loading-more="dealsLoadingMore"
                          :has-more="dealsHasMore"
                          @load-more="loadMoreDeals"
                        />
                      </InfoPanel>

                      <!-- 5. Заметки -->
                      <InfoPanel
                        :title="t('crm.contact.sections.notes')"
                        icon="pi-comment"
                        panel-key="contact-notes"
                        :default-collapsed="false"
                      >
                        <template #header-action>
                          <button type="button" class="contact-page-v2__add-btn" @click.stop="goToActivityTab">
                            <i class="pi pi-plus" />
                            {{ t('crm.contact.sections.notes') }}
                          </button>
                        </template>
                        <div class="contact-page-v2__notes-field">
                          <InlineEditableField
                            :model-value="contact.notes"
                            field-key="notes"
                            field-type="textarea"
                            :saving="isSaving"
                            :placeholder="t('crm.contact.notes.placeholder')"
                            @save="patchField"
                          />
                        </div>
                      </InfoPanel>

                      <!-- 6. История событий (мини) -->
                      <InfoPanel
                        :title="t('crm.entity.miniTimeline.title')"
                        icon="pi-history"
                        panel-key="contact-mini-timeline"
                        :default-collapsed="false"
                      >
                        <EntityMiniTimeline
                          :log="contactLog"
                          :max-items="5"
                          :on-go-to-log="() => { activeTab = 'activity' }"
                        />
                      </InfoPanel>

                      <!-- Доп. поля (свёрнуты) -->
                      <InfoPanel
                        :title="t('crm.contact.sections.customFields')"
                        icon="pi-sliders-h"
                        panel-key="contact-custom-fields"
                        :default-collapsed="true"
                      >
                        <CustomFieldRenderer
                          entity-scope="contact"
                          :entity-id="contact.id"
                          :extra-fields="contact.extra_fields ?? {}"
                          :on-save="saveExtraField"
                        />
                      </InfoPanel>

                    </div>
                  </div>
                </div>
              </div>
            </TabPanel>

            <!-- ── Activity ──────────────────────────────────────────────────── -->
            <TabPanel value="activity">
              <div class="contact-page-v2__tab-body">
                <EntityActivitiesTab
                  ref="activitiesTabRef"
                  entity-type="contact"
                  :entity-id="contact.id"
                />
              </div>
            </TabPanel>

            <!-- ── Deals (full) ──────────────────────────────────────────────── -->
            <TabPanel value="deals">
              <div class="contact-page-v2__tab-body contact-page-v2__tab-body--no-pad">
                <ContactDealsTab
                  :deals="deals"
                  :loading="dealsLoading"
                  :loading-more="dealsLoadingMore"
                  :has-more="dealsHasMore"
                  @load-more="loadMoreDeals"
                />
              </div>
            </TabPanel>

            <!-- ── Files ─────────────────────────────────────────────────────── -->
            <TabPanel value="files">
              <div class="contact-page-v2__tab-body contact-page-v2__tab-body--no-pad">
                <ContactFilesTab />
              </div>
            </TabPanel>
          </TabPanels>
        </Tabs>
      </div>
    </template>

    <!-- ── Attach company dialog ─────────────────────────────────────────────── -->
    <Dialog
      v-model:visible="attachCompanyOpen"
      :header="t('contact.page.companies.add')"
      modal
      style="width: 480px"
    >
      <div class="contact-page-v2__dialog-form">
        <div class="contact-page-v2__dialog-field">
          <label class="contact-page-v2__dialog-label">{{ t('company.page.fields.name') }} *</label>
          <AutoComplete
            v-model="attachCompanySearch"
            :suggestions="attachCompanySuggestions"
            option-label="name"
            :placeholder="t('common.search')"
            class="w-full"
            force-selection
            @complete="searchAttachCompany($event.query)"
            @option-select="onAttachCompanySelect($event.value)"
          />
        </div>
        <div class="contact-page-v2__dialog-field">
          <label class="contact-page-v2__dialog-label">{{ t('contact.page.companies.columns.position') }}</label>
          <InputText v-model="attachCompanyPosition" class="w-full" />
        </div>
        <div class="contact-page-v2__dialog-field contact-page-v2__dialog-field--row">
          <label class="contact-page-v2__dialog-label">{{ t('contact.page.companies.isPrimary') }}</label>
          <ToggleSwitch v-model="attachCompanyIsPrimary" />
        </div>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="closeAttachCompany" />
        <Button
          :label="t('common.save')"
          :loading="isAttaching"
          :disabled="!attachCompanyId"
          @click="submitAttachCompanyWithPrimary"
        />
      </template>
    </Dialog>

    <!-- Add to deal dialog (B5) -->
    <AddContactToDealDialog
      v-if="contact"
      v-model="addToDealOpen"
      :contact-id="contact.id"
      @added="loadDeals()"
    />

    <Toast position="top-right" />
    <ConfirmDialog />
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
import Select from 'primevue/select'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import AutoComplete from 'primevue/autocomplete'
import ToggleSwitch from 'primevue/toggleswitch'
import EntityInfoHeader from '@/components/crm/entity/EntityInfoHeader.vue'
import EntityKpiStrip, { type KpiItem } from '@/components/crm/entity/EntityKpiStrip.vue'
import EntityMiniTimeline from '@/components/crm/entity/EntityMiniTimeline.vue'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import EntityActivitiesTab from '@/components/crm/entity/EntityActivitiesTab.vue'
import CustomFieldRenderer from '@/components/crm/entity/CustomFieldRenderer.vue'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import { useEntityLog } from '@/composables/crm/useEntityLog'
import ContactChannelsBlock from './components/ContactChannelsBlock.vue'
import ContactCompaniesPanel from './components/ContactCompaniesPanel.vue'
import ContactRelationsPanel from './components/ContactRelationsPanel.vue'
import ContactDealsPanel from './components/ContactDealsPanel.vue'
import ContactDealsTab from './components/ContactDealsTab.vue'
import ContactFilesTab from './components/ContactFilesTab.vue'
import AddContactToDealDialog from './components/AddContactToDealDialog.vue'
import { useContactPageData } from './composables/useContactPageData'
import { useContactPageActions } from './composables/useContactPageActions'
import { useDirectoriesStore } from '@/stores/directories'
import { useBreakpoints } from '@/composables/useBreakpoints'
import type { MenuItem } from 'primevue/menuitem'

const { t } = useI18n()
const router = useRouter()
const directoriesStore = useDirectoriesStore()
const { isMobile, isTablet } = useBreakpoints()

const activeTab = ref('overview')
const attachCompanyIsPrimary = ref(false)
const addToDealOpen = ref(false)

const {
  contact,
  contactLoading,
  contactError,
  companies,
  companiesLoading,
  relations,
  relationsLoading,
  deals,
  dealsLoading,
  dealsHasMore,
  channels,
  loadAll,
  loadCompanies,
  loadRelations,
  loadDeals,
  contactId,
} = useContactPageData()

const {
  patchField,
  saveExtraField,
  isSaving,
  attachCompanyOpen,
  attachCompanySearch,
  attachCompanyId,
  attachCompanyPosition,
  attachCompanyStatus,
  attachCompanySuggestions,
  isAttaching,
  openAttachCompany,
  closeAttachCompany,
  searchAttachCompany,
  onAttachCompanySelect,
  submitAttachCompany,
  setPrimaryCompany,
  confirmDetachCompany,
  onChannelsUpdated,
  onRelationsUpdated,
  confirmDeleteContact,
  copyLink,
} = useContactPageActions({ contactId, contact, companies, relations, loadCompanies, loadRelations })

// ── Entity log ────────────────────────────────────────────────────────────────

const contactLog = useEntityLog('contact', () => contactId.value ?? null)

// ── Computed ──────────────────────────────────────────────────────────────────

const primaryCompanyName = computed((): string | undefined => {
  const primary = companies.value.find((c) => c.is_primary)
  return primary?.company?.name ?? companies.value[0]?.company?.name ?? undefined
})

const contactSourceLabel = computed((): string | null => {
  const ext = contact.value as (typeof contact.value & { source?: string | null; acquisition_channel?: { name?: string } | null }) | null
  if (!ext) return null
  if (ext.acquisition_channel?.name) return ext.acquisition_channel.name
  if (ext.source) return directoriesStore.getSourceLabel?.(ext.source) ?? ext.source
  return null
})

// ── KPI strip ──────────────────────────────────────────────────────────────────

function contactLastActivityAccent(lastAt: string | null): KpiItem['accent'] {
  if (!lastAt) return 'neutral'
  const days = Math.floor((Date.now() - new Date(lastAt).getTime()) / 86_400_000)
  if (days > 30) return 'danger'
  if (days > 7) return 'warning'
  return 'success'
}

function formatRelativeContact(lastAt: string | null): string {
  if (!lastAt) return t('crm.entity.kpiStrip.never', 'Нет')
  const days = Math.floor((Date.now() - new Date(lastAt).getTime()) / 86_400_000)
  if (days === 0) return t('common.today', 'Сегодня')
  if (days === 1) return t('common.yesterday', 'Вчера')
  return t('crm.entity.kpiStrip.daysAgo', { n: days }, `${days}д`)
}

function formatMoney(kopecks: number | null | undefined, currency?: string | null): string {
  if (kopecks == null) return '0 ₽'
  const units = Math.round(kopecks / 100)
  const cur = currency ?? 'RUB'
  try {
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency: cur,
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(units)
  } catch {
    return `${units.toLocaleString('ru-RU')} ${cur}`
  }
}

const contactKpiItems = computed((): KpiItem[] => {
  const ext = contact.value as (typeof contact.value & {
    last_activity_at?: string | null
    kpi?: {
      deals_count?: number
      deals_sum?: number
      deals_sum_currency?: string
      open_tasks_count?: number
      companies_count?: number
      last_touch_at?: string | null
    } | null
  }) | null
  const lastAt = ext?.last_activity_at ?? ext?.kpi?.last_touch_at ?? null
  const openTasksCount = ext?.kpi?.open_tasks_count ?? 0
  const companiesCount = ext?.kpi?.companies_count ?? companies.value.length
  const dealsSum = ext?.kpi?.deals_sum ?? null
  const dealsSumCurrency = ext?.kpi?.deals_sum_currency ?? null
  return [
    {
      key: 'deals',
      icon: 'pi-briefcase',
      label: 'contact.kpi.deals',
      value: ext?.kpi?.deals_count ?? deals.value.length,
      accent: 'info',
      clickable: true,
      onClick: () => { activeTab.value = 'deals' },
    },
    {
      key: 'deals_sum',
      icon: 'pi-wallet',
      label: 'contact.kpi.dealsSum',
      value: formatMoney(dealsSum, dealsSumCurrency),
      accent: 'brand',
    },
    {
      key: 'open_tasks',
      icon: 'pi-check-square',
      label: 'contact.kpi.openTasks',
      value: openTasksCount,
      accent: 'amber', // spec §2: tasks pill always amber
    },
    {
      key: 'companies',
      icon: 'pi-building',
      label: 'contact.kpi.companies',
      value: companiesCount,
      accent: 'teal',
    },
    {
      key: 'last_contact',
      icon: 'pi-clock',
      label: 'contact.kpi.lastContact',
      value: formatRelativeContact(lastAt),
      accent: contactLastActivityAccent(lastAt),
    },
  ]
})

// ── Deals pagination ──────────────────────────────────────────────────────────

const dealsLoadingMore = ref(false)
let currentDealsPage = 1

async function loadMoreDeals() {
  dealsLoadingMore.value = true
  currentDealsPage += 1
  await loadDeals(currentDealsPage)
  dealsLoadingMore.value = false
}

// ── Menu items ────────────────────────────────────────────────────────────────

const menuItems = computed<MenuItem[]>(() => [
  {
    label: t('crm.contact.menu.addNote'),
    icon: 'pi pi-comment',
    command: () => {
      activeTab.value = 'activity'
      void nextTick(() => activitiesTabRef.value?.focusNote())
    },
  },
  {
    label: t('crm.contact.menu.addRelation'),
    icon: 'pi pi-link',
    command: () => {
      activeTab.value = 'overview'
      void nextTick(() => relationsBlockRef.value?.openAdd())
    },
  },
  {
    label: t('crm.contact.menu.copyLink'),
    icon: 'pi pi-copy',
    command: copyLink,
  },
  { separator: true },
  {
    label: t('crm.contact.menu.delete'),
    icon: 'pi pi-trash',
    command: confirmDeleteContact,
  },
])

// ── Tab options for mobile Select ─────────────────────────────────────────────

const tabOptions = computed(() => [
  { value: 'overview', label: t('contact.page.tabs.overview') },
  { value: 'activity', label: t('crm.contact.tabs.activity') },
  { value: 'deals', label: t('contact.page.tabs.deals') },
  { value: 'files', label: t('crm.contact.tabs.files') },
])

// ── Attach company with isPrimary ─────────────────────────────────────────────

async function submitAttachCompanyWithPrimary() {
  await submitAttachCompany(attachCompanyIsPrimary.value)
}

// ── Navigation helpers ────────────────────────────────────────────────────────

const channelsBlockRef = ref<{ openAdd: () => void } | null>(null)
const relationsBlockRef = ref<{ openAdd: () => void } | null>(null)
const activitiesTabRef = ref<{ focusNote: () => void; focusTask: () => void } | null>(null)

function openAddChannel() {
  // Make sure we're on overview tab so the block is mounted
  activeTab.value = 'overview'
  // Delegate to ContactChannelsBlock exposed openAdd (via defineExpose)
  void nextTick(() => {
    channelsBlockRef.value?.openAdd()
  })
}

function openAddRelation() {
  activeTab.value = 'overview'
  void nextTick(() => {
    relationsBlockRef.value?.openAdd()
  })
}

function goToActivityTab() {
  activeTab.value = 'activity'
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(async () => {
  if (!directoriesStore.loaded) void directoriesStore.fetchAll()
  await loadAll()
  if (contactId.value) void contactLog.load()
})

// suppress unused warning — isSaving passed via InlineEditableField, attachCompanyStatus unused but kept in composable
void isSaving
void attachCompanyStatus
</script>

<style lang="scss" scoped>
.contact-page-v2 {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
  overflow: hidden;
}

// ── Skeleton / Error ─────────────────────────────────────────────────────────

.contact-page-v2__header-skeleton {
  flex-shrink: 0;
  border-radius: 0;
}

.contact-page-v2__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-4;
  padding: $space-8;
  text-align: center;
}

.contact-page-v2__error-icon {
  font-size: $font-size-icon-lg;
  color: var(--p-red-400);
}

.contact-page-v2__error-title {
  font-size: $font-size-base;
  color: $surface-600;
  margin: 0;
}

// ── Body ─────────────────────────────────────────────────────────────────────

// hidden scrollbar — spec §13 global rule
.contact-page-v2__body {
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

.contact-page-v2__mobile-tab-select {
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

// ── Tabs ─────────────────────────────────────────────────────────────────────

.contact-page-v2__tabs {
  display: flex;
  flex-direction: column;
  min-height: 100%;

  // spec §3: active tab label = 600, underline = 2px (bar height set in preset)
  :deep(.p-tab[aria-selected="true"]) {
    font-weight: $font-weight-semibold;
  }

  // #4 tablist bg: transparent in light (inherits card bg), dark explicit via preset.
  // But for sticky tablist at top in this page, set bg explicitly:
  :deep(.p-tablist) {
    background: $surface-card;
    border-bottom: 1px solid var(--p-surface-200);
    padding: 0 $space-4;

    .app-dark & {
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

.contact-page-v2__tablist--scroll {
  overflow-x: auto;
  white-space: nowrap;

  &::-webkit-scrollbar {
    display: none;
  }
}

// ── Tab body ─────────────────────────────────────────────────────────────────

.contact-page-v2__tab-body {
  padding: $space-4;

  &--no-pad {
    padding: 0;
  }
}

// ── Panels layout ─────────────────────────────────────────────────────────────

.contact-page-v2__panels {
  background: $surface-card;
  border-radius: $radius-lg;
  // var(--p-surface-200) is reactive: light=#E3E4E6, dark=#616263 (inverted palette §dark-guide).
  // No dark override needed.
  border: 1px solid var(--p-surface-200);
  box-shadow: $shadow-card;
  overflow: hidden;
}

// ── Notes field ───────────────────────────────────────────────────────────────

.contact-page-v2__notes-field {
  padding: $space-1 0;
}

// ── Attach company dialog ────────────────────────────────────────────────────

.contact-page-v2__dialog-form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.contact-page-v2__dialog-field {
  display: flex;
  flex-direction: column;
  gap: $space-1;

  &--row {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
}

.contact-page-v2__dialog-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

// ── AddBtn (spec §4) — text-link with icon + label ───────────────────────────

.contact-page-v2__add-btn {
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

// ── Adaptive ─────────────────────────────────────────────────────────────────

.contact-page-v2--tablet {
  .contact-page-v2__tab-body {
    padding: $space-3;
  }
}

.contact-page-v2--mobile {
  .contact-page-v2__tab-body {
    padding: $space-3;
  }
}

// Utility
.w-full {
  width: 100%;
}

.ms-1 {
  margin-left: $space-1;
}
</style>
