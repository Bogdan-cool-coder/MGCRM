<template>
  <div class="contacts-page">
    <!-- ── Toolbar ─────────────────────────────────────────────────────────── -->
    <ContactsBulkToolbar
      v-if="bulk.bulkMode.value"
      :selected-count="bulk.selectedCount.value"
      :total-visible="items.length"
      :exporting="bulk.exporting.value"
      @cancel="bulk.exitBulk()"
      @select-all="bulk.selectAll()"
      @clear-selection="bulk.clearSelection()"
      @assign-owner="bulk.openAssignOwner()"
      @add-tag="bulk.openAddTag()"
      @merge="onMergeClick"
      @export="bulk.exportXlsx()"
      @delete="bulk.confirmBulkDelete()"
    />
    <ContactsToolbar
      v-else
      :active-view="activeView"
      :saved-views="savedViews.views.value"
      :default-view-id="savedViews.defaultViewId.value"
      :entity-type="entityType"
      :total="total"
      :search="filter.search"
      :active-filter-count="activeFilterCount"
      :density="view.density.value"
      :saved-views-loading="savedViews.isLoading.value"
      @set-view="onSetView"
      @save-view="onSaveView"
      @delete-view="onDeleteView"
      @set-default-view="onSetDefaultView"
      @rename-view="onRenameView"
      @set-entity-type="entityType = $event"
      @search="onSearch"
      @open-filter="filterOverlayOpen = true"
      @open-columns="columnChooserOpen = true"
      @set-density="view.setDensity($event)"
      @create="openQuickCreate"
      @export="bulk.exportXlsx()"
      @open-dedup="openDedup"
      @enter-bulk="bulk.enterBulk()"
    />

    <!-- ── Active filter chips ──────────────────────────────────────────── -->
    <ContactsActiveFiltersBar
      :filters="overlayFilters"
      :entity-type="entityType"
      :search="filter.search"
      :users="usersCache"
      :sources="directoriesStore.activeSources"
      :company-types="directoriesStore.activeCompanyTypes"
      :countries="directoriesStore.activeCountries"
      @remove="removeChipFilter"
      @reset="resetFilter"
    />

    <!-- ── Filter overlay ──────────────────────────────────────────────── -->
    <ContactsFilterOverlay
      :visible="filterOverlayOpen"
      :entity-type="entityType"
      :filters="overlayFilters"
      :users="usersCache"
      :sources="directoriesStore.activeSources"
      :company-types="directoriesStore.activeCompanyTypes"
      :countries="directoriesStore.activeCountries"
      :available-tags="availableTags"
      @close="filterOverlayOpen = false"
      @apply="onApplyOverlay"
      @reset="onResetOverlay"
    />

    <!-- ── Main content ───────────────────────────────────────────────────── -->
    <div class="contacts-page__body">
      <!-- ── Empty: no records ──────────────────────────────────────────── -->
      <div
        v-if="!loading && items.length === 0 && !isFiltered && activeView !== 'duplicates'"
        class="contacts-page__empty"
      >
        <i
          :class="entityType === 'company' ? 'pi pi-building' : 'pi pi-users'"
          class="contacts-page__empty-icon"
        />
        <p class="contacts-page__empty-title">{{ t('crm.contacts_page.empty.noRecords') }}</p>
        <Button
          icon="pi pi-plus"
          :label="t('contacts.page.create')"
          @click="openQuickCreate"
        />
      </div>

      <!-- ── Empty: filter has no results ──────────────────────────────── -->
      <div
        v-else-if="!loading && items.length === 0 && isFiltered && activeView !== 'duplicates'"
        class="contacts-page__empty"
      >
        <i class="pi pi-filter-slash contacts-page__empty-icon" />
        <p class="contacts-page__empty-title">{{ t('crm.contacts_page.empty.noResults') }}</p>
        <Button
          severity="secondary"
          :label="t('contacts.page.empty.resetFilters')"
          @click="resetFilter"
        />
      </div>

      <!-- ── Empty: segment empty ───────────────────────────────────────── -->
      <div
        v-else-if="!loading && items.length === 0 && activeView !== 'default' && activeView !== 'duplicates'"
        class="contacts-page__empty"
      >
        <i class="pi pi-bookmark contacts-page__empty-icon" />
        <p class="contacts-page__empty-title">{{ t('crm.contacts_page.empty.noSegment') }}</p>
        <Button
          severity="secondary"
          :label="t('contacts_filter.changeFilters', 'Изменить фильтры')"
          @click="filterOverlayOpen = true"
        />
      </div>

      <!-- ── Empty: duplicates not found ─────────────────────────────────── -->
      <div
        v-else-if="!loading && items.length === 0 && activeView === 'duplicates'"
        class="contacts-page__empty"
      >
        <i class="pi pi-check-circle contacts-page__empty-icon contacts-page__empty-icon--success" />
        <p class="contacts-page__empty-title">{{ t('crm.contacts_page.empty.duplicates') }}</p>
      </div>

      <!-- ── DataTable ──────────────────────────────────────────────────── -->
      <div v-else class="contacts-page__table-wrap">
        <DataTable
          v-model:selection="selectedRows"
          :value="items"
          :loading="loading"
          :row-class="rowClass"
          data-key="id"
          :selection-mode="bulk.bulkMode.value ? 'multiple' : undefined"
          striped-rows
          scroll-height="flex"
          scrollable
          :rows="perPage"
          class="contacts-page__table"
          @row-click="onRowClick"
        >
          <!-- Bulk checkbox column -->
          <Column
            v-if="bulk.bulkMode.value"
            selection-mode="multiple"
            style="width: 48px; flex-shrink: 0"
            frozen
          />

          <!-- Render visible columns dynamically -->
          <template v-for="col in visibleColumnDefs" :key="col.field">
            <!-- ID -->
            <Column
              v-if="col.field === 'id'"
              field="id"
              :header="col.header"
              :style="{ width: `${col.width ?? 60}px` }"
              :sortable="col.sortable"
            />

            <!-- Name / full_name (frozen) -->
            <Column
              v-else-if="col.field === 'full_name'"
              field="full_name"
              :header="col.header"
              :frozen="col.frozen"
              :sortable="col.sortable"
              style="min-width: 200px"
            >
              <template #body="{ data }">
                <RouterLink
                  :to="`/contacts/${data.id}`"
                  class="contacts-page__name-link"
                  @click.stop
                >
                  <i class="pi pi-user contacts-page__name-icon" />
                  {{ (data as Contact).full_name }}
                </RouterLink>
              </template>
            </Column>

            <!-- Company name (frozen) -->
            <Column
              v-else-if="col.field === 'name'"
              field="name"
              :header="col.header"
              :frozen="col.frozen"
              :sortable="col.sortable"
              style="min-width: 200px"
            >
              <template #body="{ data }">
                <RouterLink
                  :to="`/companies/${data.id}`"
                  class="contacts-page__name-link"
                  @click.stop
                >
                  <i class="pi pi-building contacts-page__name-icon" />
                  {{ (data as Company).name }}
                </RouterLink>
              </template>
            </Column>

            <!-- Engagement tier dot -->
            <Column
              v-else-if="col.field === 'engagement_tier'"
              field="engagement_tier"
              :header="col.header"
              :style="{ width: '80px' }"
            >
              <template #body="{ data }">
                <EngagementChip
                  v-if="(data as ContactExtended).engagement_tier"
                  :tier="(data as ContactExtended).engagement_tier!"
                  :last-activity-at="(data as ContactExtended).last_activity_at"
                  dot-only
                />
                <span v-else class="contacts-page__na">—</span>
              </template>
            </Column>

            <!-- Position (contact) -->
            <Column
              v-else-if="col.field === 'position'"
              field="position"
              :header="col.header"
              :sortable="col.sortable"
            >
              <template #body="{ data }">
                {{ (data as Contact).position || '—' }}
              </template>
            </Column>

            <!-- Company (contact → company link) -->
            <Column
              v-else-if="col.field === 'company'"
              :header="col.header"
            >
              <template #body="{ data }">
                <span v-if="getPrimaryCompanyLink(data as Contact)">
                  <RouterLink
                    :to="`/companies/${getPrimaryCompanyLink(data as Contact)!.company_id}`"
                    class="contacts-page__company-link"
                    @click.stop
                  >
                    {{ getPrimaryCompanyLink(data as Contact)?.company?.name ?? '—' }}
                  </RouterLink>
                </span>
                <span v-else class="contacts-page__na">—</span>
              </template>
            </Column>

            <!-- Last activity (last_activity_at) -->
            <Column
              v-else-if="col.field === 'last_activity_at'"
              field="last_activity_at"
              :header="col.header"
              :sortable="col.sortable"
            >
              <template #body="{ data }">
                <span
                  v-if="(data as ContactExtended).last_activity_at"
                  v-tooltip="(data as ContactExtended).last_activity_at"
                  class="contacts-page__date"
                >
                  {{ formatDate((data as ContactExtended).last_activity_at) }}
                </span>
                <span v-else class="contacts-page__na">—</span>
              </template>
            </Column>

            <!-- Open deals count -->
            <Column
              v-else-if="col.field === 'open_deals_count'"
              :header="col.header"
              :sortable="col.sortable"
            >
              <template #body="{ data }">
                <span
                  v-if="(data as Record<string, unknown>)['open_deals_count'] != null"
                  class="contacts-page__deals-count"
                >
                  {{ (data as Record<string, unknown>)['open_deals_count'] }}
                </span>
                <span v-else class="contacts-page__na">—</span>
              </template>
            </Column>

            <!-- Owner (inline-editable) -->
            <Column
              v-else-if="col.field === 'owner'"
              :header="col.header"
            >
              <template #body="{ data }">
                <span
                  class="contacts-page__owner-cell"
                  @click.stop="openOwnerInlineEdit(data)"
                >
                  <span v-if="getOwner(data)">{{ getOwner(data)!.full_name }}</span>
                  <span v-else class="contacts-page__na">—</span>
                  <i class="pi pi-pencil contacts-page__inline-edit-icon" />
                </span>
              </template>
            </Column>

            <!-- Tags (inline-editable) -->
            <Column
              v-else-if="col.field === 'tags'"
              :header="col.header"
            >
              <template #body="{ data }">
                <span class="contacts-page__tags">
                  <Tag
                    v-for="tag in (data.tags ?? []).slice(0, 2)"
                    :key="tag"
                    :value="tag"
                    severity="secondary"
                    size="small"
                  />
                  <span
                    v-if="(data.tags ?? []).length > 2"
                    class="contacts-page__tags-more"
                  >+{{ data.tags.length - 2 }}</span>
                </span>
              </template>
            </Column>

            <!-- Company type -->
            <Column
              v-else-if="col.field === 'company_type'"
              :header="col.header"
            >
              <template #body="{ data }">
                {{ (data as Company).company_type?.name ?? '—' }}
              </template>
            </Column>

            <!-- Category code -->
            <Column
              v-else-if="col.field === 'category_code'"
              :header="col.header"
              style="width: 90px"
            >
              <template #body="{ data }">
                <Tag
                  v-if="(data as Company).category_code"
                  :value="(data as Company).category_code!"
                  :severity="categorySeverity((data as Company).category_code)"
                  size="small"
                />
                <span v-else class="contacts-page__na">—</span>
              </template>
            </Column>

            <!-- Country code -->
            <Column
              v-else-if="col.field === 'country_code'"
              field="country_code"
              :header="col.header"
              :sortable="col.sortable"
            >
              <template #body="{ data }">
                {{ directoriesStore.getCountryName((data as Company).country_code) || '—' }}
              </template>
            </Column>

            <!-- Employees count (company) -->
            <Column
              v-else-if="col.field === 'employees_count'"
              :header="col.header"
              :sortable="col.sortable"
            >
              <template #body="{ data }">
                {{ (data as Record<string, unknown>)['employees_count'] ?? '—' }}
              </template>
            </Column>

            <!-- Fallback for unknown column -->
            <Column
              v-else
              :field="col.field"
              :header="col.header"
              :sortable="col.sortable"
            />
          </template>

          <!-- Row actions -->
          <Column header="" style="width: 48px; flex-shrink: 0">
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
      </div>
    </div>

    <!-- ── Quick-create Drawer ─────────────────────────────────────────────── -->
    <Drawer
      v-model:visible="quickCreateOpen"
      position="right"
      style="width: 420px"
      :show-close-icon="false"
    >
      <template #header>
        <div class="contacts-page__drawer-header">
          <span class="contacts-page__drawer-header-title">
            {{ t('contacts.page.quickCreate.title') }}
          </span>
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
              :class="{ 'p-invalid': formErrors['full_name'] }"
            />
            <small v-if="formErrors['full_name']" class="p-error">{{ formErrors['full_name'] }}</small>
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
          <!-- Dedup hint -->
          <Message
            v-if="hasDuplicateHint"
            severity="warn"
            class="contacts-page__dedup-hint"
          >
            {{ t('contacts_create.dedupHint', 'Найдены похожие записи') }}
            <Button
              :label="t('contacts_create.dedupSee', 'Посмотреть')"
              text
              size="small"
              @click="openDedup"
            />
          </Message>
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
              :class="{ 'p-invalid': formErrors['name'] }"
            />
            <small v-if="formErrors['name']" class="p-error">{{ formErrors['name'] }}</small>
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

    <!-- ── Dedup dialog ────────────────────────────────────────────────────── -->
    <MergeDialog v-model:visible="dedupOpen" @merged="load" />

    <!-- ── Bulk dialogs ────────────────────────────────────────────────────── -->
    <ContactsAssignOwnerDialog
      v-model="bulk.assignOwnerOpen.value"
      :users="usersCache"
      :loading="bulk.assignOwnerLoading.value"
      @apply="bulk.submitAssignOwner($event)"
    />
    <ContactsAddTagDialog
      v-model="bulk.addTagOpen.value"
      :loading="bulk.addTagLoading.value"
      @apply="bulk.submitAddTag($event)"
    />

    <!-- Column chooser dialog -->
    <ContactsColumnChooser
      v-model="columnChooserOpen"
      :all-columns="view.allColumns.value"
      :visible-fields="view.visibleFields.value"
      @apply="view.setVisibleFields($event)"
    />

    <!-- Inline edit overlay for owner -->
    <Popover ref="ownerPopover">
      <div class="contacts-page__inline-owner">
        <Select
          v-model="inlineEditOwnerId"
          :options="usersCache"
          option-label="full_name"
          option-value="id"
          filter
          show-clear
          style="width: 220px"
          @update:model-value="submitOwnerInlineEdit"
        />
      </div>
    </Popover>

    <Toast position="top-right" />
    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
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
import Popover from 'primevue/popover'
import Message from 'primevue/message'

import MergeDialog from '@/components/crm/dedup/MergeDialog.vue'
import EngagementChip from '@/components/crm/entity/EngagementChip.vue'

import { useDirectoriesStore } from '@/stores/directories'
import { useUiTriggersStore } from '@/stores/uiTriggers'
import { useUsersCache } from '@/composables/crm/useUsersCache'
import { useContactsPageData } from './composables/useContactsPageData'
import { useContactsPageActions } from './composables/useContactsPageActions'
import { useContactsView } from './composables/useContactsView'
import { useContactsBulk } from './composables/useContactsBulk'
import { useSavedViews } from './composables/useSavedViews'
import { contactsApi } from '@/api/crm/contacts'
import { companiesApi } from '@/api/crm/companies'

import ContactsToolbar from './components/ContactsToolbar.vue'
import ContactsBulkToolbar from './components/ContactsBulkToolbar.vue'
import ContactsFilterOverlay from './components/ContactsFilterOverlay.vue'
import ContactsActiveFiltersBar from './components/ContactsActiveFiltersBar.vue'
import ContactsColumnChooser from './components/ContactsColumnChooser.vue'
import ContactsAssignOwnerDialog from './components/ContactsAssignOwnerDialog.vue'
import ContactsAddTagDialog from './components/ContactsAddTagDialog.vue'

import type { Contact, Company, ContactExtended, CategoryCode } from '@/entities/crm'
import type { EntityType } from './composables/useContactsPageData'
import type { SavedViewState } from './composables/useSavedViews'

const { t } = useI18n()
const route = useRoute()
const directoriesStore = useDirectoriesStore()
const uiTriggers = useUiTriggersStore()
const { users: usersCache, load: loadUsers } = useUsersCache()

const initialType: EntityType = route.name === 'Companies' ? 'company' : 'contact'

// ── Data layer ────────────────────────────────────────────────────────────────

const {
  entityType,
  page,
  perPage,
  filter,
  overlayFilters,
  loading,
  items,
  allItemIds,
  total,
  isFiltered,
  activeFilterCount,
  load,
  applyFilter,
  applyOverlayFilters,
  resetFilter,
  resetOverlayFilters,
  removeChipFilter,
  onPageChange,
  ensureDirectories,
} = useContactsPageData({ initialType })

// ── View (columns, density) ───────────────────────────────────────────────────

const view = useContactsView(entityType)

const visibleColumnDefs = computed(() =>
  view.allColumns.value.filter((c) => view.visibleFields.value.includes(c.field)),
)

// ── Bulk ──────────────────────────────────────────────────────────────────────

const bulk = useContactsBulk({
  entityType,
  allIds: allItemIds,
  reload: load,
})

// Sync DataTable v-model:selection ↔ bulk.selectedIds
const selectedRows = computed({
  get: () => items.value.filter((i) => bulk.selectedIds.value.has(i.id)),
  set: (rows: Array<Contact | Company>) => {
    bulk.selectedIds.value = new Set(rows.map((r) => r.id))
  },
})

// ── Saved views ───────────────────────────────────────────────────────────────

const savedViews = useSavedViews({ entityType })
const activeView = ref<string>('default')

function onSetView(viewId: string) {
  activeView.value = viewId
  if (viewId === 'duplicates') {
    overlayFilters.value.only_duplicates = true
    applyFilter()
    return
  }
  overlayFilters.value.only_duplicates = false
  const state = savedViews.getViewState(viewId)
  if (state) {
    view.setDensity(state.density)
    view.setVisibleFields(state.visibleFields)
    Object.assign(overlayFilters.value, state.filters)
    filter.value.search = state.search
    applyFilter()
  } else if (viewId === 'default') {
    resetFilter()
  }
}

function onSaveView(name: string, type: 'personal' | 'team', makeDefault: boolean) {
  const state: SavedViewState = {
    visibleFields: [...view.visibleFields.value],
    sort: null,
    density: view.density.value,
    filters: { ...overlayFilters.value },
    search: filter.value.search,
  }
  void savedViews.addView(name, type, state, makeDefault)
}

function onDeleteView(id: string) {
  void savedViews.removeView(id).then(() => {
    if (activeView.value === id) {
      activeView.value = 'default'
      resetFilter()
    }
  })
}

function onSetDefaultView(id: string) {
  void savedViews.setDefault(id)
}

function onRenameView(id: string, name: string) {
  void savedViews.updateView(id, { name })
}

// ── Filter overlay ────────────────────────────────────────────────────────────

const filterOverlayOpen = ref(false)
const columnChooserOpen = ref(false)

function onApplyOverlay(filters: typeof overlayFilters.value) {
  filterOverlayOpen.value = false
  applyOverlayFilters(filters)
}

function onResetOverlay() {
  filterOverlayOpen.value = false
  resetOverlayFilters()
}

// ── Actions ───────────────────────────────────────────────────────────────────

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

const typeOptions = computed(() => [
  { label: t('contacts.page.typeSwitch.contact'), value: 'contact' as EntityType },
  { label: t('contacts.page.typeSwitch.company'), value: 'company' as EntityType },
])

// ── Dedup hint on quick-create ─────────────────────────────────────────────

const hasDuplicateHint = computed(
  () => activeView.value === 'duplicates' || overlayFilters.value.only_duplicates,
)

// ── Merge (dedup segment) ─────────────────────────────────────────────────────

function onMergeClick() {
  if (bulk.selectedCount.value !== 2) return
  openDedup()
}

// ── Inline edit — owner ───────────────────────────────────────────────────────

const ownerPopover = ref<InstanceType<typeof Popover> | null>(null)
const inlineEditOwnerId = ref<number | null>(null)
const inlineEditItem = ref<Contact | Company | null>(null)
const inlineEditLoading = ref(false)

function openOwnerInlineEdit(data: Contact | Company) {
  inlineEditItem.value = data
  const owner = getOwner(data)
  inlineEditOwnerId.value = owner?.id ?? null
  ownerPopover.value?.show(
    // Using a synthetic event positioned at center for now; in full impl
    // this would need the click event forwarded from the cell
    new MouseEvent('click'),
  )
}

async function submitOwnerInlineEdit(userId: number | null) {
  if (!inlineEditItem.value) return
  ownerPopover.value?.hide()
  inlineEditLoading.value = true
  try {
    if (entityType.value === 'contact') {
      await contactsApi.update(inlineEditItem.value.id, { owner_id: userId })
    } else {
      await companiesApi.update(inlineEditItem.value.id, { responsible_user_id: userId })
    }
    await load()
  } catch {
    // non-critical inline edit error — just reload
    await load()
  } finally {
    inlineEditLoading.value = false
    inlineEditItem.value = null
  }
}

// ── Row menu ──────────────────────────────────────────────────────────────────

const menuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setMenuRef(id: number, el: unknown) {
  if (el) menuRefs.value.set(id, el as InstanceType<typeof Menu>)
}

function onMenuClick(event: Event, data: Contact | Company) {
  menuRefs.value.get(data.id)?.toggle(event)
}

function getMenuItems(data: Contact | Company) {
  return [
    {
      label: t('common.delete'),
      icon: 'pi pi-trash',
      command: () => {
        confirmDelete(
          data as (Contact | Company) & { full_name?: string; name?: string },
          entityType.value,
        )
      },
    },
  ]
}

function onRowClick(event: { data: Contact | Company }) {
  if (bulk.bulkMode.value) {
    bulk.toggleItem(event.data.id)
    return
  }
  openCard(event.data, entityType.value)
}

// ── Search (debounced) ────────────────────────────────────────────────────────

let searchTimer: ReturnType<typeof setTimeout> | null = null
function onSearch(query: string) {
  filter.value.search = query
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => applyFilter(), 300)
}

// ── Paginator ─────────────────────────────────────────────────────────────────

function onPaginatorChange(event: { page: number }) {
  onPageChange(event.page + 1)
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function getPrimaryCompanyLink(contact: Contact) {
  const links = contact.company_links
  if (!links || links.length === 0) return null
  return links.find((l) => l.is_primary) ?? links[0]
}

function getOwner(data: Contact | Company): { id: number; full_name: string } | null {
  if (entityType.value === 'contact') {
    return (data as Contact).owner ?? null
  }
  return (data as Company).responsible_user ?? (data as Company).owner_user ?? null
}

function rowClass(data: Contact | Company): string {
  if (bulk.bulkMode.value && bulk.selectedIds.value.has(data.id)) {
    return 'contacts-page__row--selected'
  }
  return ''
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }).format(
    new Date(iso),
  )
}

function categorySeverity(code: CategoryCode | null): 'danger' | 'warn' | 'success' | 'info' | 'secondary' {
  switch (code) {
    case 'L': return 'danger'
    case 'M': return 'warn'
    case 'S1': return 'success'
    case 'S2': return 'info'
    default: return 'secondary'
  }
}

const availableTags = computed<string[]>(() => {
  const tags = new Set<string>()
  for (const item of items.value) {
    for (const tag of item.tags ?? []) tags.add(tag)
  }
  return Array.from(tags)
})

// ── Global UI trigger ─────────────────────────────────────────────────────────

const stopDrawerTrigger = watch(
  () => uiTriggers.pendingDrawer,
  (trigger) => {
    if (trigger === 'contact_create') {
      openQuickCreate()
      uiTriggers.clearDrawer()
    }
  },
  { immediate: true },
)

onUnmounted(() => {
  stopDrawerTrigger()
})

onMounted(() => {
  ensureDirectories()
  void loadUsers()
  void load()
  void savedViews.load()
})
</script>

<style lang="scss" scoped>
.contacts-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.contacts-page__body {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.contacts-page__table-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.contacts-page__table {
  flex: 1;
  cursor: pointer;
}

// Name links
.contacts-page__name-link {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  color: var(--p-text-color);
  text-decoration: none;
  font-weight: $font-weight-medium;

  &:hover {
    color: $primary-color;
    text-decoration: underline;
  }
}

.contacts-page__name-icon {
  color: $surface-400;
  font-size: $font-size-sm;
  flex-shrink: 0;
}

.contacts-page__company-link {
  color: $primary-color;
  text-decoration: none;
  font-size: $font-size-sm;

  &:hover {
    text-decoration: underline;
  }
}

.contacts-page__na {
  color: $surface-400;
  font-size: $font-size-sm;
}

.contacts-page__date {
  font-size: $font-size-sm;
  color: $surface-600;

  :global(.app-dark) & {
    color: var(--p-surface-300);
  }
}

.contacts-page__deals-count {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $primary-color;
}

.contacts-page__owner-cell {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  cursor: pointer;
  border-radius: $radius-sm;
  padding: 2px $space-1;
  transition: background 0.15s;

  &:hover {
    background: $surface-100;

    .contacts-page__inline-edit-icon {
      opacity: 1;
    }
  }

  :global(.app-dark) & {
    &:hover { background: var(--p-surface-700); }
  }
}

.contacts-page__inline-edit-icon {
  font-size: 10px;
  color: $surface-400;
  opacity: 0;
  transition: opacity 0.15s;
}

.contacts-page__tags {
  display: flex;
  gap: $space-1;
  flex-wrap: nowrap;
  align-items: center;
}

.contacts-page__tags-more {
  font-size: $font-size-xs;
  color: $surface-400;
  white-space: nowrap;
}

// Row selected highlight
:deep(.contacts-page__row--selected td) {
  background: var(--p-primary-50) !important;

  :global(.app-dark) & {
    background: rgba(23, 39, 71, 0.2) !important;
  }
}

// Empty state
.contacts-page__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 300px;
  text-align: center;
  flex: 1;
}

.contacts-page__empty-icon {
  font-size: 3rem;
  color: $surface-400;

  &--success {
    color: var(--p-green-500);
  }
}

.contacts-page__empty-title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;

  :global(.app-dark) & {
    color: var(--p-surface-200);
  }
}

// Paginator
.contacts-page__paginator {
  border-top: 1px solid $surface-200;
  flex-shrink: 0;

  :global(.app-dark) & {
    border-top-color: var(--p-surface-700);
  }
}

// Quick-create drawer
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

.contacts-page__dedup-hint {
  margin-top: $space-2;
}

.contacts-page__inline-owner {
  padding: $space-2;
}

.w-full {
  width: 100%;
}
</style>
