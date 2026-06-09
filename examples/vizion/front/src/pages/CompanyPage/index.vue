<template>
  <div class="company-page">
    <div class="company-card">
      <!-- Header: title -->
      <div class="company-header">
        <h1 class="company-title">{{ t('title') }}</h1>
      </div>

      <!-- Divider -->
      <hr class="company-divider" />

      <!-- Tabs: Users / Settings / Branding / Promotions / MacroData mapping -->
      <Tabs v-model:value="activeTab" class="company-tabs">
        <TabList>
          <Tab value="0">{{ t('users') }}</Tab>
          <Tab value="1">{{ t('settings') }}</Tab>
          <Tab v-if="canEditBranding" value="branding">{{ t('brandingTab') }}</Tab>
          <Tab v-if="canEditPromotions" value="promotions">{{ t('promotionsTab') }}</Tab>
          <Tab v-if="canEditMacrodataMappings" value="2">{{ t('mapping') }}</Tab>
        </TabList>
        <TabPanels>
          <TabPanel value="0">
            <div class="tab-content">
              <div class="section-header">
                <Button
                  icon="pi pi-plus"
                  :label="t('common.create')"
                  @click="openCreateUserModal"
                />
              </div>

              <DataTable :value="users" :loading="usersLoading" class="users-table">
                <Column field="name" :header="t('nameColumn')"></Column>
                <Column field="email" :header="t('emailColumn')"></Column>
                <Column field="role" :header="t('roleColumn')">
                  <template #body="{ data }">
                    <Tag :value="t(`roles.${data.role}`)" size="small" />
                  </template>
                </Column>
                <Column :header="t('common.actions')" style="width: 150px">
                  <template #body="{ data }">
                    <ActionButtonGroup
                      :show-delete="canDeleteUser(data)"
                      @edit="openEditUserModal(data)"
                      @delete="confirmDeleteUser(data)"
                    />
                  </template>
                </Column>
              </DataTable>
            </div>
          </TabPanel>
          <TabPanel value="1">
            <div class="tab-content">
              <div class="section-header">
                <Button
                  icon="pi pi-pencil"
                  :label="t('common.edit')"
                  @click="openCompanySettings"
                />
              </div>
              <CompanySettingsSection :company="company" @edit="openCompanySettings" />
            </div>
          </TabPanel>
          <TabPanel v-if="canEditBranding" value="branding">
            <div class="tab-content">
              <CompanyBrandingSection :company-id="company?.id ?? null" />
            </div>
          </TabPanel>
          <TabPanel v-if="canEditPromotions" value="promotions">
            <div class="tab-content">
              <CompanyPromotionsSection :company-id="company?.id ?? null" />
            </div>
          </TabPanel>
          <TabPanel v-if="canEditMacrodataMappings" value="2">
            <div class="tab-content">
              <MacrodataMappingSection :company-id="company?.id ?? null" />
            </div>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </div>

    <!-- Модалка настроек компании -->
    <CompanyFormModal
      v-model:visible="companyFormVisible"
      :is-edit-mode="true"
      :form-data="companyFormData"
      :errors="companyFormErrors"
      :form-error="companyFormError"
      :saving="companySaving"
      :can-edit-all-fields="canEditAllFields"
      @cancel="closeCompanyForm"
      @submit="submitCompanyForm"
    />

    <!-- Модалка пользователя -->
    <CompanyUserFormModal
      v-model:visible="userFormVisible"
      :is-edit-mode="userFormEditMode"
      :form-data="userFormData"
      :errors="userFormErrors"
      :form-error="userFormError"
      :saving="userSaving"
      :iframe-url="userIframeUrl"
      :iframe-loading="iframeLoading"
      :iframe-regenerating="iframeRegenerating"
      :show-iframe-actions="canManageUserIframe"
      @cancel="closeUserForm"
      @submit="submitUserForm"
      @copy-iframe-link="copyUserIframeLink"
      @regenerate-iframe-link="regenerateUserIframeLink"
    />

    <!-- Подтверждение удаления -->
    <DeleteConfirmModal
      v-model:visible="deleteConfirmVisible"
      :item-name="userToDelete?.name || userToDelete?.email"
      :loading="userDeleting"
      @cancel="cancelDeleteUser"
      @confirm="deleteUser"
    />
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useLocalI18n } from '@/composables/useLocalI18n'
import {
  CompanyBrandingSection,
  CompanyFormModal,
  CompanyPromotionsSection,
  CompanySettingsSection,
  CompanyUserFormModal,
  MacrodataMappingSection,
} from '@/components/Company'
import { ActionButtonGroup } from '@/components/tables'
import DeleteConfirmModal from '@/components/modals/DeleteConfirmModal.vue'
import {
  canManageBranding,
  canManageCompanyMacrodataMappings,
  canManagePromotions,
} from '@/shared/auth/capabilities'
import { useUserStore } from '@/stores/user'
import { useCompanyPage } from './composables/useCompanyPage'
import pageEn from './locale/en.json'
import pageRu from './locale/ru.json'
import companyEn from '@/components/Company/locale/en.json'
import companyRu from '@/components/Company/locale/ru.json'

const { t } = useLocalI18n({
  en: { ...companyEn, ...pageEn },
  ru: { ...companyRu, ...pageRu },
})

// MacroData mapping tab is admin/superadmin only — mirrors the backend ACL
// on `/api/companies/{id}/macrodata-mappings*`. Viewers and analysts get the
// usual 403 server-side, so we hide the surface entirely instead of letting
// them click into an empty/error state.
const userStore = useUserStore()
const canEditMacrodataMappings = computed(() =>
  canManageCompanyMacrodataMappings(userStore.getUserRole),
)
const canEditBranding = computed(() => canManageBranding(userStore.getUserRole))
const canEditPromotions = computed(() => canManagePromotions(userStore.getUserRole))

// The DocumentPage gear deep-links here with `?tab=promotions` (or branding).
// Seed the active tab from the query once, then let the user navigate freely.
const route = useRoute()
const initialTab = (): string => {
  const tab = route.query.tab
  if (tab === 'promotions' && canEditPromotions.value) return 'promotions'
  if (tab === 'branding' && canEditBranding.value) return 'branding'
  return '0'
}
const activeTab = ref<string>(initialTab())

const {
  company,
  users,
  usersLoading,
  companyFormVisible,
  companySaving,
  companyFormError,
  companyFormData,
  companyFormErrors,
  canEditAllFields,
  userFormVisible,
  userFormEditMode,
  userSaving,
  userFormError,
  userFormData,
  userFormErrors,
  userIframeUrl,
  iframeLoading,
  iframeRegenerating,
  canManageUserIframe,
  deleteConfirmVisible,
  userDeleting,
  userToDelete,
  openCompanySettings,
  closeCompanyForm,
  submitCompanyForm,
  openCreateUserModal,
  openEditUserModal,
  closeUserForm,
  submitUserForm,
  copyUserIframeLink,
  regenerateUserIframeLink,
  canDeleteUser,
  confirmDeleteUser,
  cancelDeleteUser,
  deleteUser,
} = useCompanyPage({
  successSummary: t('successSummary'),
  commonError: t('common.error'),
  networkError: t('errors.networkError'),
  companyUpdatedSuccess: t('companyUpdatedSuccess'),
  currencyInvalid: t('currencyInvalid'),
  forbiddenError: t('forbiddenError'),
  userCreatedSuccess: t('userCreatedSuccess'),
  userUpdatedSuccess: t('userUpdatedSuccess'),
  userDeletedSuccess: t('userDeletedSuccess'),
  userIframeReady: t('userIframeReady'),
  userIframeCopiedSuccess: t('userIframeCopiedSuccess'),
  userIframeRegeneratedSuccess: t('userIframeRegeneratedSuccess'),
  userIframeUnavailable: t('userIframeUnavailable'),
  userIframeRegenerateConfirm: t('userIframeRegenerateConfirm'),
})
</script>

<style lang="scss" scoped>
.company-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0.75rem;

  .company-card {
    background: $surface-0;
    border-radius: $card-border-radius;
    padding: 1rem;
    box-shadow: $shadow-md;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;

    .company-header {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
      flex-shrink: 0;

      .company-title {
        margin: 0;
        font-size: $font-size-2xl;
        font-weight: $font-weight-semibold;
        color: $surface-900;
      }
    }

    .company-divider {
      border: none;
      border-top: 1px solid $surface-200;
      margin: 1rem 0;
      flex-shrink: 0;
    }

    .company-tabs {
      flex: 1;
      min-height: 0;
      display: flex;
      flex-direction: column;
      margin-top: 0.5rem;

      :deep(.p-tabs-content) {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
      }

      :deep(.p-tabpanels) {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
      }

      :deep(.p-tabpanel) {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
      }

      .tab-content {
        padding: 1rem 0;
        flex: 1;
        min-height: 0;
        overflow: auto;
        display: flex;
        flex-direction: column;

        .section-header {
          display: flex;
          align-items: center;
          margin-bottom: 1rem;
          gap: 1rem;
          flex-wrap: wrap;
          flex-shrink: 0;
        }

        .users-table {
          flex: 1;
          min-height: 0;

          :deep(.p-datatable-wrapper) {
            max-height: 400px;
            overflow: auto;
          }
        }
      }
    }
  }
}
</style>
