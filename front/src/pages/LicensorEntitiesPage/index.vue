<template>
  <div class="licensors-page">
    <PageHeader :title="t('licensors.title', 'Лицензиары')" icon="pi pi-building" />

    <div v-if="loading" class="d-flex flex-column gap-3">
      <Skeleton height="200px" v-for="i in 2" :key="i" />
    </div>

    <div v-else class="d-flex flex-column gap-4">
      <Card v-for="licensor in licensors" :key="licensor.id" class="licensors-page__card">
        <template #title>
          <div class="d-flex align-items-center justify-content-between">
            <span>{{ licensor.name }}
              <Tag :value="licensor.country_code" severity="secondary" class="ms-2" />
            </span>
            <Button
              v-if="canWrite"
              icon="pi pi-pencil"
              text
              severity="secondary"
              size="small"
              @click="openEdit(licensor)"
            />
          </div>
        </template>

        <template #content>
          <!-- Requisite info -->
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <dl class="licensors-page__dl">
                <dt>{{ t('licensors.fields.directorGenitive', 'Директор (род. п.)') }}</dt>
                <dd>{{ licensor.director_genitive }}</dd>
                <dt>{{ t('licensors.fields.taxId', 'ИНН / БИН') }}</dt>
                <dd>{{ licensor.tax_id_label }}: {{ licensor.tax_id }}</dd>
                <dt>{{ t('licensors.fields.address', 'Адрес') }}</dt>
                <dd>{{ licensor.address }}</dd>
              </dl>
            </div>
            <div class="col-md-6">
              <dl class="licensors-page__dl">
                <dt>{{ t('licensors.fields.bank', 'Основной банк') }}</dt>
                <dd>{{ licensor.bank }}</dd>
                <dt>{{ t('licensors.fields.account', 'Счёт (запасной)') }}</dt>
                <dd><code>{{ licensor.account }}</code></dd>
              </dl>
            </div>
          </div>

          <!-- Bank accounts table -->
          <div class="d-flex align-items-center justify-content-between mb-2">
            <p class="fw-semibold mb-0 licensors-page__section-title">
              {{ t('licensors.bankAccounts', 'Счета по валютам') }}
            </p>
            <Button
              v-if="canWrite"
              icon="pi pi-plus"
              :label="t('licensors.addAccount', 'Добавить счёт')"
              text
              severity="secondary"
              size="small"
              @click="openBankDialog(licensor)"
            />
          </div>

          <DataTable :value="licensor.accounts" size="small">
            <Column :header="t('licensors.bankDialog.currency', 'Валюта')" style="width: 80px">
              <template #body="{ data }">
                <Tag :value="data.currency" severity="secondary" />
              </template>
            </Column>
            <Column :header="t('licensors.bankDialog.bank', 'Банк')">
              <template #body="{ data }">{{ data.bank }}</template>
            </Column>
            <Column :header="t('licensors.bankDialog.bankCode', 'Код банка')" style="width: 130px">
              <template #body="{ data }">{{ data.bank_code }}</template>
            </Column>
            <Column :header="t('licensors.bankDialog.account', 'Счёт')">
              <template #body="{ data }"><code>{{ data.account }}</code></template>
            </Column>
            <Column :header="t('licensors.bankDialog.isPrimary', 'Основной')" style="width: 90px">
              <template #body="{ data }">
                <i :class="data.is_primary ? 'pi pi-check text-success' : 'pi pi-times text-secondary'" />
              </template>
            </Column>
            <Column v-if="canWrite" style="width: 80px">
              <template #body="{ data }">
                <div class="d-flex gap-1">
                  <Button
                    icon="pi pi-pencil"
                    text
                    severity="secondary"
                    size="small"
                    @click="openBankDialog(licensor, data)"
                  />
                  <Button
                    v-if="canDeleteAccount"
                    icon="pi pi-trash"
                    text
                    severity="danger"
                    size="small"
                    @click="deleteAccount(data)"
                  />
                </div>
              </template>
            </Column>
            <template #empty>
              <span class="text-secondary">{{ t('licensors.noAccounts', 'Нет счетов') }}</span>
            </template>
          </DataTable>
        </template>
      </Card>
    </div>

    <!-- Edit licensor dialog -->
    <LicensorEditDialog
      v-model="editDialogVisible"
      :licensor="editingLicensor"
      :saving="editMutation.isPending.value"
      @save="saveLicensor"
    />

    <!-- Bank account dialog -->
    <LicensorBankAccountDialog
      v-model="bankDialogVisible"
      :editing-account="editingAccount"
      :saving="bankMutation.isPending.value"
      @save="saveAccount"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import LicensorEditDialog from './components/LicensorEditDialog.vue'
import LicensorBankAccountDialog from './components/LicensorBankAccountDialog.vue'
import { useLicensorEntitiesPage } from './composables/useLicensorEntitiesPage'

const { t } = useI18n()
const {
  licensors,
  loading,
  editDialogVisible,
  editingLicensor,
  openEdit,
  editMutation,
  saveLicensor,
  bankDialogVisible,
  editingAccount,
  openBankDialog,
  bankMutation,
  saveAccount,
  deleteAccount,
  canWrite,
  canDeleteAccount,
} = useLicensorEntitiesPage()
</script>

<style lang="scss" scoped>
.licensors-page {
  padding: $space-3;

  &__card {
    :deep(.p-card-title) {
      font-size: $font-size-base;
    }
  }

  &__dl {
    margin: 0;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.25rem 1rem;
    font-size: $font-size-sm;

    dt {
      color: var(--p-text-muted-color);
      font-weight: normal;
    }

    dd {
      margin: 0;
    }
  }

  &__section-title {
    font-size: $font-size-sm;
    color: var(--p-text-color);
  }
}
</style>
