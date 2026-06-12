<template>
  <div class="contacts-page">
    <PageHeader
      :title="pageTitle"
      :subtitle="pageSubtitle"
      :icon="pageIcon"
    >
      <template #actions>
        <Button
          icon="pi pi-copy"
          :label="t('contacts.page.dedup')"
          severity="secondary"
          outlined
          @click="openDedup"
        />
        <Button
          icon="pi pi-plus"
          :label="t('contacts.page.create')"
          @click="openQuickCreate"
        />
      </template>
    </PageHeader>

    <div class="contacts-page__content">
      <!-- Type switch + filters -->
      <div class="contacts-page__toolbar">
        <SelectButton
          v-model="entityType"
          :options="typeOptions"
          option-label="label"
          option-value="value"
          class="contacts-page__type-switch"
        />

        <!-- Filter panel -->
        <div class="contacts-page__filters">
          <Select
            v-if="entityType === 'company'"
            v-model="filter.company_type_id"
            :options="directoriesStore.activeCompanyTypes"
            option-label="name"
            option-value="id"
            :placeholder="t('contacts.page.filters.companyType')"
            show-clear
            class="contacts-page__filter-select"
          />
          <Select
            v-model="filter.source"
            :options="directoriesStore.activeSources"
            option-label="name"
            option-value="code"
            :placeholder="t('contacts.page.filters.source')"
            show-clear
            class="contacts-page__filter-select"
          />
          <Select
            v-model="filter.country_code"
            :options="directoriesStore.activeCountries"
            option-label="name"
            option-value="code"
            :placeholder="t('contacts.page.filters.country')"
            show-clear
            class="contacts-page__filter-select"
          />
          <Button
            icon="pi pi-check"
            :label="t('contacts.page.filters.apply')"
            size="small"
            @click="applyFilter"
          />
          <Button
            icon="pi pi-refresh"
            :label="t('contacts.page.filters.reset')"
            size="small"
            severity="secondary"
            text
            @click="resetFilter"
          />
        </div>
      </div>

      <!-- Table card -->
      <div class="contacts-page__card">
        <!-- Empty state — no records -->
        <div v-if="!loading && items.length === 0 && !isFiltered" class="contacts-page__empty">
          <i class="pi pi-users contacts-page__empty-icon" />
          <p class="contacts-page__empty-title">{{ t('contacts.page.empty.title') }}</p>
          <p class="contacts-page__empty-subtitle">{{ t('contacts.page.empty.subtitle') }}</p>
          <Button
            icon="pi pi-plus"
            :label="t('contacts.page.create')"
            @click="openQuickCreate"
          />
        </div>

        <!-- Empty state — after filter -->
        <div v-else-if="!loading && items.length === 0 && isFiltered" class="contacts-page__empty">
          <i class="pi pi-filter-slash contacts-page__empty-icon" />
          <p class="contacts-page__empty-title">{{ t('contacts.page.empty.filtered') }}</p>
          <p class="contacts-page__empty-subtitle">{{ t('contacts.page.empty.filteredSub') }}</p>
          <Button
            severity="secondary"
            :label="t('contacts.page.empty.resetFilters')"
            @click="resetFilter"
          />
        </div>

        <!-- DataTable -->
        <DataTable
          v-else
          :value="items"
          :loading="loading"
          striped-rows
          :rows="perPage"
          class="contacts-page__table"
          @row-click="onRowClick"
        >
          <Column field="id" header="#" style="width: 60px" />

          <!-- Name column -->
          <Column :header="t('contacts.page.columns.name')">
            <template #body="{ data }">
              <span class="contacts-page__name">
                <i
                  :class="
                    entityType === 'company' ? 'pi pi-building' : 'pi pi-user'
                  "
                  class="contacts-page__name-icon"
                />
                {{ entityType === 'company' ? (data as Company).name : (data as Contact).full_name }}
              </span>
            </template>
          </Column>

          <!-- Type column (BUG-5: between Name and Source) -->
          <Column :header="t('contacts.page.columns.type')">
            <template #body>
              <Tag
                :value="
                  entityType === 'company'
                    ? t('contacts.page.typeSwitch.company')
                    : t('contacts.page.typeSwitch.contact')
                "
                :severity="entityType === 'company' ? 'secondary' : 'info'"
                size="small"
              />
            </template>
          </Column>

          <!-- Source column -->
          <Column :header="t('contacts.page.columns.source')">
            <template #body="{ data }">
              {{ directoriesStore.getSourceLabel(data.source) || '—' }}
            </template>
          </Column>

          <!-- Country column (company only) -->
          <Column v-if="entityType === 'company'" :header="t('contacts.page.columns.country')">
            <template #body="{ data }">
              {{ directoriesStore.getCountryName((data as Company).country_code) || '—' }}
            </template>
          </Column>

          <!-- Tags column -->
          <Column :header="t('contacts.page.columns.tags')">
            <template #body="{ data }">
              <span class="contacts-page__tags">
                <Tag
                  v-for="tag in (data.tags ?? []).slice(0, 3)"
                  :key="tag"
                  :value="tag"
                  severity="secondary"
                  size="small"
                />
              </span>
            </template>
          </Column>

          <!-- Actions column -->
          <Column :header="t('contacts.page.columns.actions')" style="width: 60px">
            <template #body="{ data }">
              <Button
                icon="pi pi-ellipsis-v"
                text
                severity="secondary"
                size="small"
                @click.stop="onMenuClick($event, data)"
              />
              <Menu
                :ref="(el) => setMenuRef(data.id, el)"
                :model="getMenuItems(data)"
                popup
              />
            </template>
          </Column>
        </DataTable>

        <!-- Paginator -->
        <Paginator
          v-show="total > 0"
          :rows="perPage"
          :total-records="total"
          :first="(page - 1) * perPage"
          :rows-per-page-options="[25, 50, 100]"
          class="contacts-page__paginator"
          @page="onPaginatorChange"
        />
        <div v-if="total > 0" class="contacts-page__total">
          {{ t('contacts.page.total', { count: total }) }}
        </div>
      </div>
    </div>

    <!-- Quick-create Drawer -->
    <Drawer
      v-model:visible="quickCreateOpen"
      position="right"
      style="width: 420px"
      :closable="false"
    >
      <template #header>
        <div class="contacts-page__drawer-header">
          <span class="contacts-page__drawer-header-title">{{ t('contacts.page.quickCreate.title') }}</span>
          <Button
            icon="pi pi-times"
            severity="secondary"
            text
            rounded
            :disabled="isCreating"
            :aria-label="t('common.close')"
            @click="closeQuickCreate"
          />
        </div>
      </template>
      <div class="contacts-page__drawer">
        <SelectButton
          v-model="quickCreateType"
          :options="typeOptions"
          option-label="label"
          option-value="value"
          class="contacts-page__drawer-type"
        />

        <!-- Contact form -->
        <template v-if="quickCreateType === 'contact'">
          <div class="contacts-page__field">
            <label class="contacts-page__label">
              {{ t('contact.page.fields.fullName') }} <span class="req">*</span>
            </label>
            <InputText
              v-model="contactForm.full_name"
              :placeholder="t('contact.page.fields.fullName')"
              class="w-full"
              :class="{ 'p-invalid': formErrors.full_name }"
            />
            <small v-if="formErrors.full_name" class="p-error">{{ formErrors.full_name }}</small>
          </div>
          <div class="contacts-page__field">
            <label class="contacts-page__label">{{ t('contact.page.fields.phone') }}</label>
            <InputText v-model="contactForm.phone" placeholder="+7 777 000 00 00" class="w-full" />
          </div>
          <div class="contacts-page__field">
            <label class="contacts-page__label">{{ t('contact.page.fields.email') }}</label>
            <InputText v-model="contactForm.email" placeholder="email@example.com" class="w-full" />
          </div>
          <div class="contacts-page__field">
            <label class="contacts-page__label">{{ t('contact.page.fields.source') }}</label>
            <Select
              v-model="contactForm.source"
              :options="directoriesStore.activeSources"
              option-label="name"
              option-value="code"
              :placeholder="t('contacts.page.filters.source')"
              show-clear
              class="w-full"
            />
          </div>
        </template>

        <!-- Company form -->
        <template v-else>
          <div class="contacts-page__field">
            <label class="contacts-page__label">
              {{ t('company.page.fields.name') }} <span class="req">*</span>
            </label>
            <InputText
              v-model="companyForm.name"
              :placeholder="t('company.page.fields.name')"
              class="w-full"
              :class="{ 'p-invalid': formErrors.name }"
            />
            <small v-if="formErrors.name" class="p-error">{{ formErrors.name }}</small>
          </div>
          <div class="contacts-page__field">
            <label class="contacts-page__label">{{ t('company.page.fields.legalForm') }}</label>
            <InputText v-model="companyForm.legal_form" placeholder="ТОО / ООО / ИП" class="w-full" />
          </div>
          <div class="contacts-page__field">
            <label class="contacts-page__label">{{ t('company.page.fields.taxId') }}</label>
            <InputText v-model="companyForm.tax_id" placeholder="БИН / ИНН" class="w-full" />
          </div>
          <div class="contacts-page__field">
            <label class="contacts-page__label">{{ t('company.page.fields.companyType') }}</label>
            <Select
              v-model="companyForm.company_type_id"
              :options="directoriesStore.activeCompanyTypes"
              option-label="name"
              option-value="id"
              :placeholder="t('contacts.page.filters.companyType')"
              show-clear
              class="w-full"
            />
          </div>
          <div class="contacts-page__field">
            <label class="contacts-page__label">{{ t('company.page.fields.country') }}</label>
            <Select
              v-model="companyForm.country_code"
              :options="directoriesStore.activeCountries"
              option-label="name"
              option-value="code"
              :placeholder="t('contacts.page.filters.country')"
              show-clear
              class="w-full"
            />
          </div>
          <div class="contacts-page__field">
            <label class="contacts-page__label">{{ t('company.page.fields.source') }}</label>
            <Select
              v-model="companyForm.source"
              :options="directoriesStore.activeSources"
              option-label="name"
              option-value="code"
              :placeholder="t('contacts.page.filters.source')"
              show-clear
              class="w-full"
            />
          </div>
        </template>
      </div>

      <template #footer>
        <div class="contacts-page__drawer-footer">
          <Button
            :label="t('contacts.page.quickCreate.cancel')"
            severity="secondary"
            text
            @click="closeQuickCreate"
          />
          <Button
            :label="t('contacts.page.quickCreate.submit')"
            :loading="isCreating"
            @click="submitQuickCreate"
          />
        </div>
      </template>
    </Drawer>

    <!-- Dedup dialog -->
    <MergeDialog v-model:visible="dedupOpen" @merged="load" />

    <Toast position="top-right" />
    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Drawer from 'primevue/drawer'
import Menu from 'primevue/menu'
import Paginator from 'primevue/paginator'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import MergeDialog from '@/components/crm/dedup/MergeDialog.vue'
import { useDirectoriesStore } from '@/stores/directories'
import { useContactsPageData } from './composables/useContactsPageData'
import { useContactsPageActions } from './composables/useContactsPageActions'
import type { Contact, Company } from '@/entities/crm'
import type { EntityType } from './composables/useContactsPageData'

const { t } = useI18n()
const route = useRoute()
const directoriesStore = useDirectoriesStore()

// Определяем начальный тип по маршруту: /companies → 'company', /contacts → 'contact'
const initialType: EntityType = route.name === 'Companies' ? 'company' : 'contact'

const {
  entityType,
  page,
  perPage,
  filter,
  loading,
  items,
  total,
  isFiltered,
  load,
  applyFilter,
  resetFilter,
  onPageChange,
  ensureDirectories,
} = useContactsPageData({ initialType })

// Динамический заголовок, иконка и подзаголовок зависят от текущего типа
const pageTitle = computed(() =>
  entityType.value === 'company'
    ? t('nav.companies')
    : t('contacts.page.title'),
)
const pageSubtitle = computed(() =>
  entityType.value === 'company'
    ? t('contacts.page.subtitle_companies')
    : t('contacts.page.subtitle'),
)
const pageIcon = computed(() =>
  entityType.value === 'company' ? 'pi pi-building' : 'pi pi-users',
)

const {
  quickCreateOpen,
  quickCreateType,
  contactForm,
  companyForm,
  formErrors,
  dedupOpen,
  isCreating,
  openQuickCreate,
  closeQuickCreate,
  openDedup,
  submitQuickCreate,
  openCard,
  confirmDelete,
} = useContactsPageActions({ reload: load, entityType })

const typeOptions = [
  { label: t('contacts.page.typeSwitch.contact'), value: 'contact' as EntityType },
  { label: t('contacts.page.typeSwitch.company'), value: 'company' as EntityType },
]

// Menu refs per row
const menuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setMenuRef(id: number, el: unknown) {
  if (el) {
    menuRefs.value.set(id, el as InstanceType<typeof Menu>)
  }
}

function onMenuClick(event: Event, data: Contact | Company) {
  const menu = menuRefs.value.get(data.id)
  menu?.toggle(event)
}

function getMenuItems(data: Contact | Company) {
  return [
    {
      label: t('common.delete'),
      icon: 'pi pi-trash',
      command: () => {
        confirmDelete(data as (typeof data) & { full_name?: string; name?: string }, entityType.value)
      },
    },
  ]
}

function onRowClick(event: { data: Contact | Company }) {
  openCard(event.data, entityType.value)
}

function onPaginatorChange(event: { page: number }) {
  onPageChange(event.page + 1)
}

onMounted(() => {
  ensureDirectories()
  void load()
})
</script>

<style lang="scss" scoped>
.contacts-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.contacts-page__content {
  padding: $space-4 $space-6;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.contacts-page__toolbar {
  display: flex;
  align-items: center;
  gap: $space-4;
  flex-wrap: wrap;
}

.contacts-page__type-switch {
  flex-shrink: 0;
}

.contacts-page__filters {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  flex: 1;
}

.contacts-page__filter-select {
  min-width: 160px;
}

.contacts-page__card {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.contacts-page__table {
  flex: 1;
  cursor: pointer;
}

.contacts-page__name {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.contacts-page__name-icon {
  color: $surface-500;
  font-size: $font-size-sm;
}

.contacts-page__tags {
  display: flex;
  gap: $space-1;
  flex-wrap: wrap;
}

.contacts-page__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 300px;
  text-align: center;
}

.contacts-page__empty-icon {
  font-size: 3rem;
  color: $surface-400;
}

.contacts-page__empty-title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;
}

.contacts-page__empty-subtitle {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.contacts-page__paginator {
  border-top: 1px solid $surface-200;
}

.contacts-page__total {
  padding: $space-2 $space-4;
  font-size: $font-size-sm;
  color: $surface-500;
  text-align: right;
}

.contacts-page__drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  gap: $space-2;
}

.contacts-page__drawer-header-title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.contacts-page__drawer {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;
}

.contacts-page__drawer-type {
  margin-bottom: $space-2;
}

.contacts-page__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contacts-page__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.contacts-page__drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-4;
  border-top: 1px solid $surface-200;
}

.w-full {
  width: 100%;
}
</style>
