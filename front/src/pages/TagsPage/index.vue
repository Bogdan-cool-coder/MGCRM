<template>
  <div class="tags-page" :class="{ 'tags-page--embedded': embedded }">
    <PageHeader
      v-if="!embedded"
      :title="t('admin.tags.title')"
      icon="pi pi-tags"
    >
      <template #actions>
        <Button
          v-if="canManage"
          icon="pi pi-plus"
          :label="t('admin.tags.add')"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <Card>
      <template #content>
        <DataTable
          :value="tagsList"
          :loading="loading"
          row-hover
          size="small"
        >
          <!-- Name -->
          <Column :header="t('admin.tags.columns.name')">
            <template #body="{ data }">{{ data.name }}</template>
          </Column>

          <!-- Color swatch -->
          <Column :header="t('admin.tags.columns.color')" style="width: 120px">
            <template #body="{ data }">
              <div v-if="data.color" class="tags-page__color-cell">
                <span
                  class="tags-page__color-swatch"
                  :style="{ background: data.color }"
                  :title="data.color"
                />
                <span class="tags-page__color-hex">{{ data.color }}</span>
              </div>
              <span v-else class="tags-page__color-empty">—</span>
            </template>
          </Column>

          <!-- Scope -->
          <Column :header="t('admin.tags.columns.scope')" style="width: 130px">
            <template #body="{ data }">
              <span class="tags-page__scope-badge" :data-scope="data.scope ?? 'all'">
                {{ scopeLabel(data.scope) }}
              </span>
            </template>
          </Column>

          <!-- Sort order -->
          <Column :header="t('admin.tags.columns.sortOrder')" style="width: 100px">
            <template #body="{ data }">{{ data.sort_order }}</template>
          </Column>

          <!-- Is active -->
          <Column :header="t('admin.tags.columns.isActive')" style="width: 100px">
            <template #body="{ data }">
              <ToggleSwitch
                v-if="canManage"
                :model-value="data.is_active"
                @update:model-value="() => toggleActive(data)"
              />
              <i
                v-else
                :class="data.is_active ? 'pi pi-check text-success' : 'pi pi-times text-secondary'"
              />
            </template>
          </Column>

          <!-- Actions -->
          <Column v-if="canManage" style="width: 90px">
            <template #body="{ data }">
              <span class="d-flex gap-1">
                <Button
                  icon="pi pi-pencil"
                  text
                  severity="secondary"
                  size="small"
                  :title="t('common.edit')"
                  @click="openEdit(data)"
                />
                <Button
                  icon="pi pi-trash"
                  text
                  severity="danger"
                  size="small"
                  :title="t('common.delete')"
                  @click="deleteTag(data)"
                />
              </span>
            </template>
          </Column>

          <template #empty>
            <div class="dir-page__empty">
              <i class="pi pi-tags" />
              <span>{{ t('admin.tags.empty') }}</span>
              <Button
                v-if="canManage"
                :label="t('admin.tags.add')"
                icon="pi pi-plus"
                size="small"
                text
                severity="secondary"
                @click="openCreate"
              />
            </div>
          </template>
        </DataTable>
      </template>
    </Card>

    <TagDialog
      v-model="dialogVisible"
      :editing="editingTag"
      :loading="saveMutation.isPending.value"
      @save="save"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import TagDialog from './components/TagDialog.vue'
import { useTagsPage } from './composables/useTagsPage'
import type { TagScope } from '@/entities/crm'

const { t } = useI18n()

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

const {
  tagsList,
  loading,
  dialogVisible,
  editingTag,
  canManage,
  saveMutation,
  openCreate,
  openEdit,
  save,
  toggleActive,
  deleteTag,
} = useTagsPage()

const scopeLabelsMap = computed((): Record<string, string> => ({
  all: t('admin.tags.scopes.all'),
  deal: t('admin.tags.scopes.deal'),
  contact: t('admin.tags.scopes.contact'),
  company: t('admin.tags.scopes.company'),
}))

function scopeLabel(scope: TagScope | null): string {
  return scopeLabelsMap.value[scope ?? 'all'] ?? scope ?? '—'
}

defineExpose({ canManage, openCreate })
</script>

<style lang="scss" scoped>
.tags-page {
  padding: $space-3;

  &--embedded {
    padding: 0;
  }
}

// ── Color cell ────────────────────────────────────────────────────────────────
.tags-page__color-cell {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.tags-page__color-swatch {
  display: inline-block;
  width: 18px;
  height: 18px;
  border-radius: $radius-sm;
  border: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    border-color: var(--p-surface-600);
  }
}

.tags-page__color-hex {
  font-family: $font-family-mono;
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}

.tags-page__color-empty {
  color: var(--p-text-muted-color);
}

// ── Scope badge ───────────────────────────────────────────────────────────────
.tags-page__scope-badge {
  display: inline-flex;
  align-items: center;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  padding: 2px $space-2;
  border-radius: $radius-pill;

  // Universal (null scope)
  &[data-scope="all"] {
    background: var(--p-surface-100);
    color: $surface-600;

    .app-dark & {
      background: var(--p-surface-700);
      color: var(--p-surface-300);
    }
  }

  // Deal
  &[data-scope="deal"] {
    background: var(--p-blue-50);
    color: var(--p-blue-700);

    .app-dark & {
      background: var(--p-blue-950);
      color: var(--p-blue-300);
    }
  }

  // Contact
  &[data-scope="contact"] {
    background: var(--p-green-50);
    color: var(--p-green-700);

    .app-dark & {
      background: var(--p-green-950);
      color: var(--p-green-300);
    }
  }

  // Company
  &[data-scope="company"] {
    background: var(--p-orange-50);
    color: var(--p-orange-700);

    .app-dark & {
      background: var(--p-orange-950);
      color: var(--p-orange-300);
    }
  }
}

// ── Empty state ───────────────────────────────────────────────────────────────
.dir-page__empty {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: $space-2;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2.5rem;
  color: var(--p-text-muted-color);

  i {
    font-size: $font-size-2xl;
    opacity: 0.4;
  }
}
</style>
