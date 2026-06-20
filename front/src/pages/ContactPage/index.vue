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
        :engagement-tier="contact.engagement_tier ?? undefined"
        :last-activity-at="contact.last_activity_at"
        :menu-items="menuItems"
        @back="router.back()"
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
            <Tab value="log">{{ t('crm.contact.tabs.log') }}</Tab>
          </TabList>

          <TabPanels>
            <!-- ── Overview ─────────────────────────────────────────────────── -->
            <TabPanel value="overview">
              <div class="contact-page-v2__tab-body">
                <div class="row g-0">
                  <div class="col-12">
                    <div class="contact-page-v2__panels">

                      <!-- Каналы связи -->
                      <InfoPanel
                        :title="t('crm.contact.sections.channels')"
                        icon="pi-phone"
                        panel-key="contact-channels"
                        :default-collapsed="false"
                        :count="channels.length || undefined"
                      >
                        <ContactChannelsBlock
                          :contact-id="contact.id"
                          :channels="channels"
                          @updated="onChannelsUpdated"
                        />
                      </InfoPanel>

                      <!-- Компании -->
                      <InfoPanel
                        :title="t('crm.contact.sections.companies')"
                        icon="pi-building"
                        panel-key="contact-companies"
                        :default-collapsed="false"
                        :count="companies.length || undefined"
                      >
                        <template #header-action>
                          <Button
                            icon="pi pi-plus"
                            text
                            severity="secondary"
                            size="small"
                            :title="t('contact.page.companies.add')"
                            @click.stop="openAttachCompany"
                          />
                        </template>
                        <ContactCompaniesPanel
                          :companies="companies"
                          :loading="companiesLoading"
                          @attach="openAttachCompany"
                          @set-primary="setPrimaryCompany"
                          @detach="confirmDetachCompany"
                        />
                      </InfoPanel>

                      <!-- Связи -->
                      <InfoPanel
                        :title="t('crm.contact.sections.relations')"
                        icon="pi-share-alt"
                        panel-key="contact-relations"
                        :default-collapsed="false"
                        :count="relations.length || undefined"
                      >
                        <ContactRelationsPanel
                          :contact-id="contact.id"
                          :relations="relations"
                          :loading="relationsLoading"
                          @updated="onRelationsUpdated"
                        />
                      </InfoPanel>

                      <!-- Участвует в сделках -->
                      <InfoPanel
                        :title="t('crm.contact.sections.dealsParticipation')"
                        icon="pi-briefcase"
                        panel-key="contact-deals-overview"
                        :default-collapsed="false"
                        :count="deals.length || undefined"
                      >
                        <ContactDealsPanel
                          :deals="deals"
                          :loading="dealsLoading"
                          :loading-more="dealsLoadingMore"
                          :has-more="dealsHasMore"
                          @load-more="loadMoreDeals"
                        />
                      </InfoPanel>

                      <!-- Заметки -->
                      <InfoPanel
                        :title="t('crm.contact.sections.notes')"
                        icon="pi-comment"
                        panel-key="contact-notes"
                        :default-collapsed="false"
                      >
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

                      <!-- Доп. поля -->
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
                  entity-type="contact"
                  :entity-id="contact.id"
                />
              </div>
            </TabPanel>

            <!-- ── Deals (full) ──────────────────────────────────────────────── -->
            <TabPanel value="deals">
              <div class="contact-page-v2__tab-body">
                <ContactDealsPanel
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
              <div class="contact-page-v2__tab-body contact-page-v2__tab-body--placeholder">
                <i class="pi pi-folder-open contact-page-v2__placeholder-icon" />
                <p class="contact-page-v2__placeholder-text">{{ t('contact.page.stub.files') }}</p>
              </div>
            </TabPanel>

            <!-- ── Log ──────────────────────────────────────────────────────── -->
            <TabPanel value="log">
              <div class="contact-page-v2__tab-body">
                <EntityLogTab :log="contactLog" :metrics="contactMetrics" />
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
          <InputText
            v-model="attachCompanySearch"
            :placeholder="t('common.search')"
            class="w-full"
          />
        </div>
        <div class="contact-page-v2__dialog-field">
          <label class="contact-page-v2__dialog-label">{{ t('contact.page.companies.columns.position') }}</label>
          <InputText v-model="attachCompanyPosition" class="w-full" />
        </div>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="closeAttachCompany" />
        <Button
          :label="t('common.save')"
          :loading="isAttaching"
          :disabled="!attachCompanySearch"
          @click="submitAttachCompany"
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
import Select from 'primevue/select'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import EntityInfoHeader from '@/components/crm/entity/EntityInfoHeader.vue'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import EntityActivitiesTab from '@/components/crm/entity/EntityActivitiesTab.vue'
import EntityLogTab, { type LogMetric } from '@/components/crm/entity/EntityLogTab.vue'
import CustomFieldRenderer from '@/components/crm/entity/CustomFieldRenderer.vue'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import { useEntityLog } from '@/composables/crm/useEntityLog'
import ContactChannelsBlock from './components/ContactChannelsBlock.vue'
import ContactCompaniesPanel from './components/ContactCompaniesPanel.vue'
import ContactRelationsPanel from './components/ContactRelationsPanel.vue'
import ContactDealsPanel from './components/ContactDealsPanel.vue'
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
  isAttaching,
  openAttachCompany,
  closeAttachCompany,
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

const contactMetrics = computed((): LogMetric[] => [
  { key: 'deals', label: t('crm.log.metrics.deals'), value: deals.value.length },
  { key: 'companies', label: t('crm.log.metrics.companies'), value: companies.value.length },
])

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
  { label: t('crm.contact.menu.addTask'), icon: 'pi pi-check-square', command: () => {} },
  { label: t('crm.contact.menu.addNote'), icon: 'pi pi-comment', command: () => {} },
  { label: t('crm.contact.menu.call'), icon: 'pi pi-phone', command: () => {} },
  { label: t('crm.contact.menu.email'), icon: 'pi pi-envelope', command: () => {} },
  { separator: true },
  { label: t('crm.contact.menu.addRelation'), icon: 'pi pi-link', command: () => { activeTab.value = 'overview' } },
  { label: t('crm.contact.menu.copyLink'), icon: 'pi pi-copy', command: copyLink },
  { separator: true },
  { label: t('crm.contact.menu.delete'), icon: 'pi pi-trash', command: confirmDeleteContact },
])

// ── Tab options for mobile Select ─────────────────────────────────────────────

const tabOptions = computed(() => [
  { value: 'overview', label: t('contact.page.tabs.overview') },
  { value: 'activity', label: t('crm.contact.tabs.activity') },
  { value: 'deals', label: t('contact.page.tabs.deals') },
  { value: 'files', label: t('crm.contact.tabs.files') },
  { value: 'log', label: t('crm.contact.tabs.log') },
])

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(async () => {
  if (!directoriesStore.loaded) void directoriesStore.fetchAll()
  await loadAll()
  if (contactId.value) void contactLog.load()
})

// suppress unused warning — isSaving used by InlineEditableField internally via patchField
void isSaving
void attachCompanyId
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
  font-size: 3rem;
  color: var(--p-red-400);
}

.contact-page-v2__error-title {
  font-size: $font-size-base;
  color: $surface-600;
  margin: 0;
}

// ── Body ─────────────────────────────────────────────────────────────────────

.contact-page-v2__body {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
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

  &--placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-4;
    padding: $space-8;
  }
}

.contact-page-v2__placeholder-icon {
  font-size: 2.5rem;
  color: $surface-300;
}

.contact-page-v2__placeholder-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// ── Panels layout ─────────────────────────────────────────────────────────────

.contact-page-v2__panels {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid var(--p-surface-200);
  box-shadow: $shadow-card;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
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
}

.contact-page-v2__dialog-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-300);
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
