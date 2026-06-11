<template>
  <div class="contact-page">
    <PageHeader
      :title="contact?.full_name ?? t('common.loading')"
      :subtitle="contactSubtitle"
      icon="pi pi-user"
    >
      <template #actions>
        <Button
          icon="pi pi-arrow-left"
          :label="t('contact.page.back')"
          severity="secondary"
          text
          @click="router.back()"
        />
        <Tag
          v-if="contact?.status"
          :value="contact.status === 'active' ? t('contact.page.status.active') : t('contact.page.status.inactive')"
          :severity="contact.status === 'active' ? 'success' : 'secondary'"
        />
      </template>
    </PageHeader>

    <div v-if="contactError && !contactLoading" class="contact-page__error">
      <Message severity="error">{{ t('contact.page.errors.load') }}</Message>
      <Button :label="t('contact.page.back')" severity="secondary" @click="router.push('/contacts')" />
    </div>

    <div v-else-if="contactLoading" class="contact-page__content">
      <div class="row g-4">
        <div class="col-9">
          <Skeleton height="32px" class="mb-3" />
          <Skeleton height="200px" />
        </div>
        <div class="col-3">
          <Skeleton height="200px" />
        </div>
      </div>
    </div>

    <div v-else-if="contact" class="contact-page__content">
      <div class="row g-4">
        <!-- Tabs -->
        <div class="col-lg-9">
          <Tabs v-model:value="activeTab" class="contact-page__tabs">
            <TabList>
              <Tab value="overview">{{ t('contact.page.tabs.overview') }}</Tab>
              <Tab value="companies">{{ t('contact.page.tabs.companies') }}</Tab>
              <Tab value="notes">{{ t('contact.page.tabs.notes') }}</Tab>
              <Tab value="tasks">{{ t('contact.page.tabs.tasks') }}</Tab>
              <Tab value="files">{{ t('contact.page.tabs.files') }}</Tab>
            </TabList>
            <TabPanels>
              <TabPanel value="overview">
                <div class="contact-page__tab-content">
                  <ContactOverviewTab
                    :contact="contact"
                    :is-saving="isSaving"
                    @save="patchField"
                  />
                </div>
              </TabPanel>

              <TabPanel value="companies">
                <div class="contact-page__tab-content">
                  <ContactCompaniesTab
                    :companies="companies"
                    :loading="companiesLoading"
                    @attach-company="openAttachCompany"
                    @set-primary="setPrimaryCompany"
                    @detach="confirmDetachCompany"
                  />
                </div>
              </TabPanel>

              <TabPanel value="notes">
                <div class="contact-page__tab-content">
                  <CompanyStubTab :message="t('contact.page.tabs.notes') + ' (inline field)'" />
                </div>
              </TabPanel>

              <TabPanel value="tasks">
                <div class="contact-page__tab-content">
                  <CompanyStubTab :message="t('contact.page.stub.tasks')" />
                </div>
              </TabPanel>

              <TabPanel value="files">
                <div class="contact-page__tab-content">
                  <CompanyStubTab :message="t('contact.page.stub.files')" />
                </div>
              </TabPanel>
            </TabPanels>
          </Tabs>
        </div>

        <!-- Right rail -->
        <div class="col-lg-3">
          <div class="contact-page__rail-wrapper">
            <ContactRightRail :contact="contact" />
          </div>
        </div>
      </div>
    </div>

    <!-- Attach company dialog -->
    <Dialog
      v-model:visible="attachCompanyOpen"
      :header="t('contact.page.companies.add')"
      modal
      style="width: 480px"
    >
      <div class="contact-page__dialog-form">
        <div class="contact-page__field">
          <label class="contact-page__label">{{ t('company.page.fields.name') }} *</label>
          <InputText
            v-model="attachCompanySearch"
            :placeholder="t('common.search')"
            class="w-full"
          />
        </div>
        <div class="contact-page__field">
          <label class="contact-page__label">{{ t('contact.page.companies.columns.position') }}</label>
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
import PageHeader from '@/components/AppShell/PageHeader.vue'
import ContactOverviewTab from './components/ContactOverviewTab.vue'
import ContactCompaniesTab from './components/ContactCompaniesTab.vue'
import ContactRightRail from './components/ContactRightRail.vue'
import CompanyStubTab from '@/pages/CompanyPage/components/CompanyStubTab.vue'
import { useContactPageData } from './composables/useContactPageData'
import { useContactPageActions } from './composables/useContactPageActions'
import { useDirectoriesStore } from '@/stores/directories'

const { t } = useI18n()
const router = useRouter()
const directoriesStore = useDirectoriesStore()

const activeTab = ref('overview')

const {
  contact,
  contactLoading,
  contactError,
  companies,
  companiesLoading,
  loadAll,
  loadCompanies,
  contactId,
} = useContactPageData()

const {
  patchField,
  isSaving,
  attachCompanyOpen,
  attachCompanySearch,
  attachCompanyPosition,
  isAttaching,
  openAttachCompany,
  closeAttachCompany,
  submitAttachCompany,
  setPrimaryCompany,
  confirmDetachCompany,
} = useContactPageActions({ contactId, contact, companies, loadCompanies })

const contactSubtitle = computed(() => {
  if (!contact.value) return ''
  const parts = []
  if (contact.value.position) parts.push(contact.value.position)
  if (contact.value.source) parts.push(directoriesStore.getSourceLabel(contact.value.source))
  return parts.join(' · ')
})

onMounted(async () => {
  if (!directoriesStore.loaded) void directoriesStore.fetchAll()
  await loadAll()
})
</script>

<style lang="scss" scoped>
.contact-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.contact-page__content {
  padding: $space-4 $space-6;
  flex: 1;
  overflow-y: auto;
}

.contact-page__error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-4;
  padding: $space-8;
}

.contact-page__tabs {
  background: $surface-0;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
}

.contact-page__tab-content {
  padding: $space-4;
}

.contact-page__rail-wrapper {
  background: $surface-0;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  padding: $space-4;
}

.contact-page__dialog-form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.contact-page__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contact-page__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.w-full {
  width: 100%;
}
</style>
