<template>
  <div class="templates-page" :class="{ 'templates-page--embedded': embedded }">
    <!-- Toolbar (canon) -->
    <div v-if="!embedded" class="templates-page__toolbar">
      <span class="templates-page__section-icon">
        <i class="pi pi-file-edit" />
      </span>
      <div class="templates-page__title-block">
        <h1 class="templates-page__h1">{{ t('templates.list.title') }}</h1>
        <div class="templates-page__subtitle">
          {{ t('templates.list.subtitle', { count: templates.length }) }}
        </div>
      </div>

      <div class="templates-page__spacer" />

      <!-- Compact filter (pattern А) — search pill + single kind Select -->
      <div class="templates-page__search-wrap">
        <i class="pi pi-search templates-page__search-icon" />
        <InputText
          v-model="searchFilter"
          :placeholder="t('templates.list.search')"
          class="templates-page__search-input"
        />
      </div>
      <Select
        v-model="kindFilter"
        :options="kindOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('documents.list.filters.kind')"
        class="templates-page__kind-select"
      />

      <Button
        v-if="canManage"
        icon="pi pi-plus"
        :label="t('templates.list.create')"
        @click="createDialogRef?.open()"
      />
    </div>

    <!-- Compact canon filter strip — embedded (inside /settings) only.
         Section heading/icon come from the Settings section chrome; the Create
         action stays in the DirTabDocTemplates parent. -->
    <div v-if="embedded" class="templates-page__embedded-strip">
      <div class="templates-page__search-wrap">
        <i class="pi pi-search templates-page__search-icon" />
        <InputText
          v-model="searchFilter"
          :placeholder="t('templates.list.search')"
          class="templates-page__search-input"
        />
      </div>
      <Select
        v-model="kindFilter"
        :options="kindOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('documents.list.filters.kind')"
        class="templates-page__kind-select"
      />
    </div>

    <!-- Table card (canon) -->
    <div class="templates-page__card">
      <DataTable
        :value="templates"
        :loading="loading"
        striped-rows
        row-hover
        class="templates-page__table"
        @row-click="(e) => goToTemplate((e.data as TemplateListItemDto).id)"
      >
        <Column :header="t('templateVariables.key', 'Код')" style="width: 120px">
          <template #body="{ data }">
            <code class="templates-page__code">{{ data.code }}</code>
          </template>
        </Column>
        <Column :header="t('templates.list.columns.name')">
          <template #body="{ data }">{{ data.title }}</template>
        </Column>
        <Column :header="t('documents.list.filters.kind')" style="width: 110px">
          <template #body="{ data }">{{ t(`documents.kinds.${data.kind}`, data.kind) }}</template>
        </Column>
        <Column :header="t('templates.list.columns.products')">
          <template #body="{ data }">{{ data.product_codes.join(', ') || '—' }}</template>
        </Column>
        <Column :header="t('templates.list.columns.aiStatus')" style="width: 140px">
          <template #body="{ data }">
            <Tag
              v-if="data.current_version?.ai_check_status"
              :severity="aiStatusSeverity(data.current_version.ai_check_status)"
              :value="t(`templates.card.aiCheck.statuses.${data.current_version.ai_check_status}`, data.current_version.ai_check_status)"
            />
            <span v-else class="templates-page__muted">—</span>
          </template>
        </Column>
        <Column :header="t('templates.list.columns.version')" style="width: 80px">
          <template #body="{ data }">
            {{ data.current_version != null ? `v${data.current_version.version_number}` : '—' }}
          </template>
        </Column>
        <Column :header="t('templates.list.columns.date')" style="width: 100px">
          <template #body="{ data }">
            {{ new Date(data.created_at).toLocaleDateString('ru-RU') }}
          </template>
        </Column>

        <template #empty>
          <div class="templates-page__empty">
            <template v-if="isFiltered">
              <i class="pi pi-filter-slash templates-page__empty-icon" />
              <p class="templates-page__empty-title">{{ t('templates.list.emptyFiltered') }}</p>
              <Button
                :label="t('templates.list.resetFilters')"
                severity="secondary"
                outlined
                icon="pi pi-filter-slash"
                @click="resetFilters"
              />
            </template>
            <template v-else>
              <i class="pi pi-file-edit templates-page__empty-icon" />
              <p class="templates-page__empty-title">{{ t('templates.list.empty') }}</p>
              <Button
                v-if="canManage"
                :label="t('templates.list.create')"
                icon="pi pi-plus"
                @click="createDialogRef?.open()"
              />
            </template>
          </div>
        </template>
      </DataTable>
    </div>

    <CreateTemplateDialog ref="createDialogRef" @created="onCreated" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import CreateTemplateDialog from './components/CreateTemplateDialog.vue'
import type { TemplateListItemDto, AiCheckStatus } from '@/entities/template'
import { useTemplatesPage } from './composables/useTemplatesPage'
import { useUserStore } from '@/stores/user'

type TagSeverity = 'secondary' | 'info' | 'success' | 'warn' | 'danger' | 'contrast'

const { t } = useI18n()

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

const userStore = useUserStore()
// TemplatePolicy::create requires contracts.admin permission (admin only).
// IAM-1 debt: switch to can('contracts.admin') once permissions are exposed on /me.
const canManage = computed(() => userStore.getUserRole === 'admin')

const { kindFilter, searchFilter, templates, loading, goToTemplate, kindOptions, fetchTemplates } =
  useTemplatesPage()

// Active-filter state drives the filtered empty variant. Filtering happens inside the
// composable (kindFilter/searchFilter); this only tells the empty-state which branch to show.
const isFiltered = computed(() => !!kindFilter.value || !!searchFilter.value.trim())

function resetFilters() {
  kindFilter.value = null
  searchFilter.value = ''
}

const createDialogRef = ref<InstanceType<typeof CreateTemplateDialog> | null>(null)

function openCreate() {
  createDialogRef.value?.open()
}

function onCreated() {
  // router.push already handled inside the dialog; refresh list in background
  void fetchTemplates()
}

defineExpose({ canManage, openCreate })

function aiStatusSeverity(status: AiCheckStatus): TagSeverity {
  const map: Record<AiCheckStatus, TagSeverity> = {
    pending: 'warn',
    checking: 'info',
    checked: 'success',
    failed: 'danger',
  }
  return map[status] ?? 'secondary'
}
</script>

<style lang="scss" scoped>
.templates-page {
  padding: $space-3;

  &--embedded {
    padding: 0;
  }
}

// ── Toolbar (canon) ──────────────────────────────────────────────────────────
.templates-page__toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 14px 0 $space-4; // toolbar vertical rhythm — matches DealsToolbar 14px; no exact token
  flex-wrap: wrap;
}

.templates-page__section-icon {
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  background: $primary-100;
  border-radius: $radius-md;
  display: inline-flex;
  align-items: center;
  justify-content: center;

  .app-dark & {
    background: color-mix(in srgb, $primary-900 35%, transparent);
  }

  i {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    font-size: 17px; // icon tile — canon DealsToolbar; no exact token between icon-sm/md
    color: $primary-900;

    .app-dark & {
      color: var(--p-primary-200);
    }
  }
}

.templates-page__title-block {
  display: flex;
  flex-direction: column;
}

.templates-page__h1 {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 19px; // page h1 — canon DealsToolbar; no exact token between lg/xl
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: 1.1;

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.templates-page__subtitle {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 12px; // subtitle-counter — canon 12px; $font-size-xs is 10.5px
  color: $surface-500;
  margin-top: 2px;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.templates-page__spacer {
  flex: 1;
}

// ── Embedded compact filter strip (inside /settings) ───────────────────────
.templates-page__embedded-strip {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4;
  flex-wrap: wrap;
}

// ── Compact filter (pattern А) ──────────────────────────────────────────────
.templates-page__search-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  min-width: 200px;
}

.templates-page__search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  font-size: $font-size-sm;
  color: $surface-400;
  pointer-events: none;
}

.templates-page__search-input {
  width: 100%;
  padding-left: 34px !important;
}

.templates-page__kind-select {
  width: 170px;
}

// ── Table card (canon) ──────────────────────────────────────────────────────
.templates-page__card {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.templates-page__table {
  cursor: pointer;

  :deep(th) {
    text-transform: uppercase;
    font-size: $font-size-xs;
    letter-spacing: 0.03em;
  }
}

.templates-page__code {
  font-family: $font-family-mono;
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.templates-page__muted {
  color: var(--p-text-muted-color);
}

.templates-page__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8;
  min-height: 260px;
  color: var(--p-text-muted-color);
  text-align: center;
}

.templates-page__empty-icon {
  font-size: $font-size-icon-xl;
  opacity: 0.4;
}

.templates-page__empty-title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;

  .app-dark & {
    color: var(--p-surface-300);
  }
}
</style>
