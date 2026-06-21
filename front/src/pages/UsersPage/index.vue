<template>
  <div class="users-page">
    <PageHeader
      :title="t('admin.users.title')"
      icon="pi pi-users"
      :subtitle="t('admin.users.subtitle')"
    >
      <template #actions>
        <Button
          v-if="canManage"
          icon="pi pi-plus"
          :label="t('admin.users.addUser')"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <!-- Filters row -->
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap users-page__filters">
      <!-- Search -->
      <IconField class="users-page__search">
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="searchFilter"
          :placeholder="t('admin.users.filters.search')"
          class="w-100"
        />
      </IconField>

      <!-- Role filter -->
      <Select
        v-model="roleFilter"
        :options="roleOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('admin.users.filters.role')"
        style="width: 160px"
      />

      <!-- Department filter -->
      <Select
        v-model="departmentFilter"
        :options="departments"
        option-label="name"
        option-value="id"
        show-clear
        :loading="departmentsLoading"
        :placeholder="t('admin.users.filters.department')"
        style="width: 180px"
      />

      <!-- Active filter -->
      <Select
        v-model="isActiveFilter"
        :options="isActiveOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('admin.users.filters.status')"
        style="width: 150px"
      />
    </div>

    <!-- Table -->
    <Card>
      <template #content>
        <DataTable
          :value="users"
          :loading="loading"
          row-hover
          size="small"
        >
          <!-- Full name -->
          <Column :header="t('admin.users.columns.full_name')">
            <template #body="{ data }">
              <span class="d-flex align-items-center gap-2">
                <i class="pi pi-user users-page__avatar-icon" aria-hidden="true" />
                <span class="users-page__name">{{ data.full_name }}</span>
              </span>
            </template>
          </Column>

          <!-- Email -->
          <Column :header="t('admin.users.columns.email')" style="width: 220px">
            <template #body="{ data }">
              <a :href="`mailto:${data.email}`" class="users-page__email">{{ data.email }}</a>
            </template>
          </Column>

          <!-- Phone -->
          <Column :header="t('admin.users.columns.phone')" style="width: 150px">
            <template #body="{ data }">{{ data.phone ?? '—' }}</template>
          </Column>

          <!-- Job title -->
          <Column :header="t('admin.users.columns.job_title')" style="width: 160px">
            <template #body="{ data }">{{ data.job_title ?? '—' }}</template>
          </Column>

          <!-- Department -->
          <Column :header="t('admin.users.columns.department')" style="width: 150px">
            <template #body="{ data }">{{ data.department_name ?? '—' }}</template>
          </Column>

          <!-- Role -->
          <Column :header="t('admin.users.columns.role')" style="width: 140px">
            <template #body="{ data }">
              <Tag
                v-if="data.role"
                :value="t(`roles.${data.role}`, data.role)"
                :severity="roleSeverity(data.role)"
              />
              <span v-else>—</span>
            </template>
          </Column>

          <!-- Active -->
          <Column :header="t('admin.users.columns.is_active')" style="width: 90px">
            <template #body="{ data }">
              <Tag
                :value="data.is_active ? t('admin.users.active') : t('admin.users.inactive')"
                :severity="data.is_active ? 'success' : 'secondary'"
              />
            </template>
          </Column>

          <!-- Empty state -->
          <template #empty>
            <div class="users-page__empty">
              <i class="pi pi-users users-page__empty-icon" aria-hidden="true" />
              <span>{{ t('admin.users.empty') }}</span>
              <Button
                v-if="canManage"
                :label="t('admin.users.addUser')"
                icon="pi pi-plus"
                size="small"
                text
                severity="secondary"
                @click="openCreate"
              />
            </div>
          </template>
        </DataTable>

        <!-- Pagination -->
        <div v-if="total > perPage" class="users-page__pagination">
          <Paginator
            :first="(currentPage - 1) * perPage"
            :rows="perPage"
            :total-records="total"
            :rows-per-page-options="[]"
            template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink"
            @page="onPageChange"
          />
        </div>
      </template>
    </Card>

    <!-- Create dialog -->
    <CreateUserDialog
      v-model="dialogVisible"
      :loading="createMutation.isPending.value"
      :departments="departments"
      :departments-loading="departmentsLoading"
      :role-options="roleOptions"
      @create="createUser"
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
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Tag from 'primevue/tag'
import Paginator from 'primevue/paginator'
import type { PageState } from 'primevue/paginator'
import CreateUserDialog from './components/CreateUserDialog.vue'
import { useUsersPage } from './composables/useUsersPage'
import type { UserRole } from '@/entities/user'

const { t } = useI18n()

const {
  users,
  total,
  loading,
  currentPage,
  perPage,
  canManage,
  searchFilter,
  roleFilter,
  departmentFilter,
  isActiveFilter,
  departments,
  departmentsLoading,
  dialogVisible,
  openCreate,
  createUser,
  createMutation,
  roleOptions,
  isActiveOptions,
} = useUsersPage()

function onPageChange(event: PageState) {
  currentPage.value = event.page + 1
}

function roleSeverity(role: UserRole): 'success' | 'warn' | 'info' | 'secondary' | 'danger' {
  switch (role) {
    case 'admin':
      return 'danger'
    case 'director':
      return 'warn'
    case 'lawyer':
      return 'info'
    case 'cfo':
      return 'warn'
    default:
      return 'secondary'
  }
}
</script>

<style lang="scss" scoped>
.users-page {
  padding: 0.75rem;

  &__filters {
    margin-bottom: $space-3;
  }

  &__search {
    flex: 1;
    min-width: 200px;
    max-width: 340px;
  }

  &__avatar-icon {
    font-size: $font-size-sm; // snap from 0.85rem (~13.6px → 14px)
    color: var(--p-text-muted-color);
    flex-shrink: 0;
  }

  &__name {
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
  }

  &__email {
    color: var(--p-primary-color);
    text-decoration: none;
    font-size: $font-size-sm;

    &:hover {
      text-decoration: underline;
    }
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: $space-3;
    padding: $space-8;
    color: var(--p-text-muted-color);
  }

  &__empty-icon {
    font-size: $font-size-icon-xl;
    opacity: 0.3;
  }

  &__pagination {
    margin-top: $space-3;
    display: flex;
    justify-content: center;
  }
}
</style>
