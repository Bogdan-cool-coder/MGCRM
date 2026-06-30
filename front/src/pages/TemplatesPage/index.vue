<template>
  <div class="templates-page" :class="{ 'templates-page--embedded': embedded }">
    <PageHeader v-if="!embedded" :title="t('templates.list.title')" icon="pi pi-file-edit" />

    <!-- Filters -->
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <Select
        v-model="kindFilter"
        :options="kindOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('documents.list.filters.kind')"
        class="templates-page__filter"
      />
      <IconField class="flex-1">
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="searchFilter"
          :placeholder="t('common.search')"
          class="w-100"
        />
      </IconField>
    </div>

    <!-- Table -->
    <Card>
      <template #content>
        <DataTable
          :value="templates"
          :loading="loading"
          row-hover
          @row-click="(e) => goToTemplate((e.data as TemplateListItemDto).id)"
        >
          <Column :header="t('templateVariables.key', 'Код')" style="width: 120px">
            <template #body="{ data }">
              <code>{{ data.code }}</code>
            </template>
          </Column>
          <Column header="Название">
            <template #body="{ data }">{{ data.title }}</template>
          </Column>
          <Column :header="t('documents.list.filters.kind')" style="width: 110px">
            <template #body="{ data }">{{ t(`documents.kinds.${data.kind}`, data.kind) }}</template>
          </Column>
          <Column header="Продукты">
            <template #body="{ data }">{{ data.product_codes.join(', ') || '—' }}</template>
          </Column>
          <Column header="AI-статус" style="width: 140px">
            <template #body="{ data }">
              <Tag
                v-if="data.current_version?.ai_check_status"
                :severity="aiStatusSeverity(data.current_version.ai_check_status)"
                :value="t(`templates.card.aiCheck.statuses.${data.current_version.ai_check_status}`, data.current_version.ai_check_status)"
              />
              <span v-else class="text-secondary">—</span>
            </template>
          </Column>
          <Column header="Версия" style="width: 80px">
            <template #body="{ data }">
              {{ data.current_version != null ? `v${data.current_version.version_number}` : '—' }}
            </template>
          </Column>
          <Column header="Дата" style="width: 100px">
            <template #body="{ data }">
              {{ new Date(data.created_at).toLocaleDateString('ru-RU') }}
            </template>
          </Column>

          <template #empty>
            <div class="templates-page__empty">
              <i class="pi pi-file-edit" />
              <span>{{ t('templates.list.empty') }}</span>
            </div>
          </template>
        </DataTable>
      </template>
    </Card>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Tag from 'primevue/tag'
import type { TemplateListItemDto, AiCheckStatus } from '@/entities/template'
import { useTemplatesPage } from './composables/useTemplatesPage'

type TagSeverity = 'secondary' | 'info' | 'success' | 'warn' | 'danger' | 'contrast'

const { t } = useI18n()

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

const { kindFilter, searchFilter, templates, loading, goToTemplate, kindOptions } = useTemplatesPage()

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

  &__filter {
    width: 160px;
  }

  &__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: $space-2;
    padding: $space-8;
    color: var(--p-text-muted-color);
  }
}
</style>
