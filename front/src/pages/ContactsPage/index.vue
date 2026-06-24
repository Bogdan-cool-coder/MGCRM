<template>
  <div class="contacts-page">
    <!-- ── Card wrapper ───────────────────────────────────────────────────────── -->
    <div class="contacts-page__card">
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
        :entity-type="entityType"
        :total="total"
        :search="filter.search"
        :active-filter-count="activeFilterCount"
        :density="view.density.value"
        @set-entity-type="entityType = $event"
        @search="onSearch"
        @open-filter="filterOverlayOpen = !filterOverlayOpen"
        @open-columns="columnChooserOpen = true"
        @set-density="view.setDensity($event)"
        @create="openQuickCreate"
        @export="bulk.exportXlsx()"
        @open-dedup="openDedup"
        @enter-bulk="bulk.enterBulk()"
      />

      <!-- ── KPI Bar ─────────────────────────────────────────────────────────── -->
      <ContactsKpiBar
        :entity-type="entityType"
        :stats="kpiStats"
        :loading="kpiLoading"
      />

      <!-- ── Active filter chips ───────────────────────────────────────────── -->
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

      <!-- ── Filter panel (inline) ─────────────────────────────────────────── -->
      <div v-show="filterOverlayOpen">
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
      </div>

      <!-- ── Main content ──────────────────────────────────────────────────── -->
      <div class="contacts-page__body">
        <!-- Empty: no records -->
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

        <!-- Empty: filter has no results -->
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

        <!-- Empty: duplicates not found -->
        <div
          v-else-if="!loading && items.length === 0 && activeView === 'duplicates'"
          class="contacts-page__empty"
        >
          <i class="pi pi-check-circle contacts-page__empty-icon contacts-page__empty-icon--success" />
          <p class="contacts-page__empty-title">{{ t('crm.contacts_page.empty.duplicates') }}</p>
        </div>

        <!-- DataTable -->
        <div v-else class="contacts-page__table-wrap">
          <DataTable
            v-model:selection="selectedRows"
            :value="items"
            :loading="loading"
            :row-class="rowClass"
            data-key="id"
            :selection-mode="bulk.bulkMode.value ? 'multiple' : undefined"
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
            />

            <!-- Render visible columns dynamically -->
            <template v-for="col in visibleColumnDefs" :key="col.field">
              <!-- Company name -->
              <Column
                v-if="col.field === 'name'"
                field="name"
                style="min-width: 200px"
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  <div class="contacts-page__name-cell">
                    <CrmAvatar
                      :name="(data as Company).name"
                      :size="32"
                      square
                    />
                    <RouterLink
                      :to="`/companies/${data.id}`"
                      class="contacts-page__name-link"
                      @click.stop
                    >
                      {{ (data as Company).name }}
                    </RouterLink>
                  </div>
                </template>
              </Column>

              <!-- Contact full_name (2-line: name + position) -->
              <Column
                v-else-if="col.field === 'full_name'"
                field="full_name"
                style="min-width: 200px"
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  <div class="contacts-page__name-cell">
                    <CrmAvatar
                      :name="(data as Contact).full_name"
                      :size="32"
                      :square="false"
                    />
                    <div class="contacts-page__name-cell-text">
                      <RouterLink
                        :to="`/contacts/${data.id}`"
                        class="contacts-page__name-link"
                        @click.stop
                      >
                        {{ (data as Contact).full_name }}
                      </RouterLink>
                      <span
                        v-if="(data as Contact).position"
                        class="contacts-page__name-position"
                      >{{ (data as Contact).position }}</span>
                    </div>
                  </div>
                </template>
              </Column>

              <!-- Engagement tier — dot + text -->
              <Column
                v-else-if="col.field === 'engagement_tier'"
                field="engagement_tier"
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  <span
                    v-if="(data as ContactExtended).engagement_tier"
                    class="contacts-page__engagement"
                    :class="`contacts-page__engagement--${(data as ContactExtended).engagement_tier}`"
                  >
                    <span class="contacts-page__engagement-dot" />
                    <span class="contacts-page__engagement-text">
                      {{ engagementLabel((data as ContactExtended).engagement_tier!) }}
                    </span>
                  </span>
                  <span v-else class="contacts-page__na">—</span>
                </template>
              </Column>

              <!-- Position (contact) -->
              <Column
                v-else-if="col.field === 'position'"
                field="position"
              >
                <template #header>
                  <span class="contacts-page__th">{{ col.header }}</span>
                </template>
                <template #body="{ data }">
                  {{ (data as Contact).position || '—' }}
                </template>
              </Column>

              <!-- Phone (contact) -->
              <Column
                v-else-if="col.field === 'phone'"
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  <span v-if="(data as Contact).phone" class="contacts-page__na">
                    {{ (data as Contact).phone }}
                  </span>
                  <span v-else class="contacts-page__na">—</span>
                </template>
              </Column>

              <!-- Company (contact → company link) -->
              <Column
                v-else-if="col.field === 'company'"
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
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

              <!-- Last activity (with freshness color) -->
              <Column
                v-else-if="col.field === 'last_activity_at'"
                field="last_activity_at"
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  <span
                    v-if="(data as ContactExtended).last_activity_at"
                    v-tooltip="(data as ContactExtended).last_activity_at"
                    :style="{
                      color: touchColor((data as ContactExtended).last_activity_at),
                      fontWeight: touchFreshness((data as ContactExtended).last_activity_at) !== 'n' ? 600 : 400,
                    }"
                  >
                    {{ formatDate((data as ContactExtended).last_activity_at) }}
                  </span>
                  <span v-else class="contacts-page__na">—</span>
                </template>
              </Column>

              <!-- Open deals count — circle badge -->
              <Column
                v-else-if="col.field === 'open_deals_count'"
                header-style="text-align: center"
                body-style="text-align: center"
              >
                <template #header>
                  <span
                    class="contacts-page__th contacts-page__th--center"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  <span
                    v-if="(data as Record<string, unknown>)['open_deals_count']"
                    class="contacts-page__deals-badge"
                  >
                    {{ (data as Record<string, unknown>)['open_deals_count'] }}
                  </span>
                  <span v-else class="contacts-page__na">—</span>
                </template>
              </Column>

              <!-- Category code — centered -->
              <Column
                v-else-if="col.field === 'category_code'"
                header-style="text-align: center"
                body-style="text-align: center"
              >
                <template #header>
                  <span
                    class="contacts-page__th contacts-page__th--center"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
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
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  <span class="contacts-page__na">
                    {{ directoriesStore.getCountryName((data as Company).country_code) || '—' }}
                  </span>
                </template>
              </Column>

              <!-- Owner / Author — avatar + name -->
              <Column
                v-else-if="col.field === 'owner'"
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  <div v-if="getOwner(data)" class="contacts-page__owner-cell">
                    <CrmAvatar
                      :name="getOwner(data)!.full_name"
                      :size="22"
                      :square="false"
                    />
                    <span class="contacts-page__owner-name">{{ getOwner(data)!.full_name }}</span>
                  </div>
                  <span v-else class="contacts-page__na">—</span>
                </template>
              </Column>

              <!-- Tags -->
              <Column
                v-else-if="col.field === 'tags'"
              >
                <template #header>
                  <span class="contacts-page__th">{{ col.header }}</span>
                </template>
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
                    <span v-if="!(data.tags ?? []).length" class="contacts-page__na">—</span>
                  </span>
                </template>
              </Column>

              <!-- Employees count (company) -->
              <Column
                v-else-if="col.field === 'employees_count'"
              >
                <template #header>
                  <span
                    class="contacts-page__th"
                    :class="{ 'contacts-page__th--sortable': col.sortable }"
                    @click="col.sortable ? onSort(col.field) : undefined"
                  >
                    {{ col.header }}
                    <span v-if="col.sortable" class="contacts-page__sort-icon">
                      <i :class="sortIconClass(col.field)" />
                    </span>
                  </span>
                </template>
                <template #body="{ data }">
                  {{ (data as Record<string, unknown>)['employees_count'] ?? '—' }}
                </template>
              </Column>

              <!-- Fallback -->
              <Column
                v-else
                :field="col.field"
              >
                <template #header>
                  <span class="contacts-page__th">{{ col.header }}</span>
                </template>
              </Column>
            </template>
          </DataTable>

          <!-- Custom Paginator -->
          <ContactsPaginator
            v-show="total > 0"
            :page="page"
            :per-page="perPage"
            :total="total"
            @update:page="onPageChange"
            @update:per-page="onPerPageChange"
          />
        </div>
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
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import Message from 'primevue/message'

import MergeDialog from '@/components/crm/dedup/MergeDialog.vue'
import CrmAvatar from '@/components/ui/CrmAvatar.vue'

import { useDirectoriesStore } from '@/stores/directories'
import { useUiTriggersStore } from '@/stores/uiTriggers'
import { useUsersCache } from '@/composables/crm/useUsersCache'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useContactsPageData, CONTACT_SORT_MAP, COMPANY_SORT_MAP } from './composables/useContactsPageData'
import { useContactsPageActions } from './composables/useContactsPageActions'
import { useContactsView } from './composables/useContactsView'
import { useContactsBulk } from './composables/useContactsBulk'
import { useSavedViews } from './composables/useSavedViews'
import { contactsApi } from '@/api/crm/contacts'

import ContactsToolbar from './components/ContactsToolbar.vue'
import ContactsBulkToolbar from './components/ContactsBulkToolbar.vue'
import ContactsFilterOverlay from './components/ContactsFilterOverlay.vue'
import ContactsActiveFiltersBar from './components/ContactsActiveFiltersBar.vue'
import ContactsColumnChooser from './components/ContactsColumnChooser.vue'
import ContactsAssignOwnerDialog from './components/ContactsAssignOwnerDialog.vue'
import ContactsAddTagDialog from './components/ContactsAddTagDialog.vue'
import ContactsKpiBar from './components/ContactsKpiBar.vue'
import ContactsPaginator from './components/ContactsPaginator.vue'
import type { KpiStats } from './components/ContactsKpiBar.vue'
import type { ContactsKpiResponse } from '@/api/crm/contacts'

import type { Contact, Company, ContactExtended, CategoryCode } from '@/entities/crm'
import type { EntityType } from './composables/useContactsPageData'

type TouchFreshness = 'g' | 'a' | 'r' | 'n'

function touchFreshness(iso: string | null): TouchFreshness {
  if (!iso) return 'n'
  const days = (Date.now() - new Date(iso).getTime()) / 86_400_000
  if (days <= 3) return 'g'
  if (days <= 14) return 'a'
  return 'r'
}

function touchColor(iso: string | null): string {
  const f = touchFreshness(iso)
  switch (f) {
    case 'g': return 'var(--app-green-900)'
    case 'a': return 'var(--app-orange-900)'
    case 'r': return 'var(--app-red-700)'
    default:  return 'var(--p-surface-400)'
  }
}

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
  activeSortBy,
  sortDir,
  load,
  applyFilter,
  applyOverlayFilters,
  resetFilter,
  resetOverlayFilters,
  removeChipFilter,
  onPageChange,
  onSort,
  ensureDirectories,
} = useContactsPageData({ initialType })

// ── KPI Bar ───────────────────────────────────────────────────────────────────

const emptyKpi = (): ContactsKpiResponse => ({
  data: { entity: 'company', total: 0 },
})
const kpiResource = useAsyncResource<ContactsKpiResponse>(emptyKpi)

const kpiLoading = computed(() => kpiResource.loading.value)
const kpiStats = computed<KpiStats>(() => kpiResource.data.value.data)

async function loadKpi() {
  try {
    await kpiResource.run(() => contactsApi.kpi(entityType.value))
  } catch {
    // non-critical — empty stats are fine
  }
}

watch(entityType, () => {
  void loadKpi()
})

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
// The saved-views UI control is not shown in this list (D3 fix: removed from toolbar).
// The composable is kept so that saved-view state (duplicates view, etc.) still works.

const savedViews = useSavedViews({ entityType })
const activeView = ref<string>('default')

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
} = useContactsPageActions({ reload: load, entityType })

const typeOptions = computed(() => [
  { label: t('contacts.page.typeSwitch.contact'), value: 'contact' as EntityType },
  { label: t('contacts.page.typeSwitch.company'), value: 'company' as EntityType },
])

// ── Dedup hint on quick-create ─────────────────────────────────────────────

const hasDuplicateHint = computed(
  () => activeView.value === 'duplicates',
)

// ── Merge (dedup segment) ─────────────────────────────────────────────────────

function onMergeClick() {
  if (bulk.selectedCount.value !== 2) return
  openDedup()
}

// ── Paginator callbacks ───────────────────────────────────────────────────────

function onPerPageChange(newPerPage: number) {
  perPage.value = newPerPage
  page.value = 1
  void load()
}

// ── Row click ─────────────────────────────────────────────────────────────────

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

/**
 * Returns the PrimeIcon class for the sort indicator on a given column field.
 * Shows active direction icon when sorted, neutral arrows-v when sortable but inactive.
 */
function sortIconClass(field: string): string {
  const sortMap = entityType.value === 'contact' ? CONTACT_SORT_MAP : COMPANY_SORT_MAP
  const backendKey = sortMap[field]
  if (!backendKey) return ''
  if (activeSortBy.value === backendKey) {
    return sortDir.value === 'asc' ? 'pi pi-arrow-up' : 'pi pi-arrow-down'
  }
  return 'pi pi-sort-alt'
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

function engagementLabel(tier: string): string {
  switch (tier) {
    case 'fresh': return t('crm.entity.engagement.fresh')
    case 'cooling': return t('crm.entity.engagement.cooling')
    case 'cold': return t('crm.entity.engagement.cold')
    default: return tier
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
  void loadKpi()
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

.contacts-page__card {
  flex: 1;
  display: flex;
  flex-direction: column;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  overflow: hidden;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-700);
  }
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
  min-height: 0;
  overflow: hidden;
}

.contacts-page__table {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  cursor: pointer;

  // DataTable scroll wrapper must fill remaining space and scroll internally
  :deep(.p-datatable-table-container) {
    flex: 1;
    min-height: 0;
    overflow: auto;
  }

  // Override DataTable styles per spec
  :deep(.p-datatable-thead > tr > th) {
    padding: 10px 14px;
    font-size: $font-size-2xs;
    font-weight: $font-weight-bold;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--p-text-color);
    border-bottom: 1px solid $surface-200;
    white-space: nowrap;
    background: $surface-card;
    // Remove PrimeVue built-in sort cursor (we handle it ourselves)
    cursor: default;

    .app-dark & {
      border-bottom-color: var(--p-surface-700);
      color: var(--p-text-color);
      background: var(--p-surface-100);
    }
  }

  :deep(.p-datatable-tbody > tr > td) {
    padding: 10px 14px;
    font-size: $font-size-sm;
    border-bottom: 1px solid $surface-200;
    white-space: nowrap;

    .app-dark & {
      border-bottom-color: var(--p-surface-700);
    }
  }

  :deep(.p-datatable-tbody > tr:hover > td) {
    background: $surface-50;

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  // Remove striped rows
  :deep(.p-datatable-tbody > tr.p-row-odd > td) {
    background: transparent;
  }
}

// Custom sort header cell — wraps header text + sort icon
.contacts-page__th {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--p-text-color);
  white-space: nowrap;
  user-select: none;

  &--sortable {
    cursor: pointer;

    &:hover {
      color: $primary-900;

      .app-dark & {
        color: var(--p-primary-color);
      }

      .contacts-page__sort-icon {
        opacity: 1;
      }
    }
  }

  &--center {
    justify-content: center;
    width: 100%;
  }
}

.contacts-page__sort-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  opacity: 0.6;
  transition: opacity 0.15s;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-400);
  }

  // When sort is active on this column, always show at full opacity
  .contacts-page__th--sortable:has(.pi-arrow-up) &,
  .contacts-page__th--sortable:has(.pi-arrow-down) & {
    opacity: 1;
    color: $primary-900;

    .app-dark & {
      color: var(--p-primary-color);
    }
  }
}

// Name cell
.contacts-page__name-cell {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
}

.contacts-page__name-cell-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.contacts-page__name-link {
  color: $primary-900;
  text-decoration: none;
  font-weight: $font-weight-semibold;
  font-size: $font-size-sm;

  &:hover {
    text-decoration: underline;
  }
}

.contacts-page__name-position {
  font-size: $font-size-2xs;
  color: $surface-400;
  line-height: 1.3;
}

.contacts-page__company-link {
  color: $primary-900;
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

// Engagement — dot + text
.contacts-page__engagement {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
}

.contacts-page__engagement-dot {
  width: 8px;
  height: 8px;
  border-radius: $radius-circle;
  flex-shrink: 0;
}

.contacts-page__engagement-text {
  font-size: $font-size-sm;
}

.contacts-page__engagement--fresh {
  .contacts-page__engagement-dot {
    background: $green-500;
  }
  .contacts-page__engagement-text {
    color: $green-900;
  }
}

.contacts-page__engagement--cooling {
  .contacts-page__engagement-dot {
    background: $orange-500;
  }
  .contacts-page__engagement-text {
    color: $orange-900;
  }
}

.contacts-page__engagement--cold {
  .contacts-page__engagement-dot {
    background: $surface-300;
  }
  .contacts-page__engagement-text {
    color: $surface-400;
  }
}

// Deals badge — circle
.contacts-page__deals-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 22px;
  height: 20px;
  border-radius: $radius-badge;
  background: $primary-100;
  color: $primary-900;
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  padding: 0 $space-1;
  box-sizing: border-box;
}

// Owner cell
.contacts-page__owner-cell {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
}

.contacts-page__owner-name {
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-300);
  }
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

  .app-dark & {
    background: rgba($primary-900, 0.2) !important;
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
  font-size: $font-size-icon-2xl;
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

  .app-dark & {
    color: var(--p-surface-200);
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
  color: $red-500;
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

.w-full {
  width: 100%;
}
</style>
