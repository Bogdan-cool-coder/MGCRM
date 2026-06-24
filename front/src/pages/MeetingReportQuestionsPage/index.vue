<template>
  <div class="meeting-report-questions-page">
    <PageHeader
      :title="t('admin.meetingReportQuestions.title')"
      :subtitle="t('admin.meetingReportQuestions.subtitle')"
      icon="pi pi-comments"
    >
      <template #actions>
        <Button
          v-if="canManage"
          icon="pi pi-plus"
          :label="t('admin.meetingReportQuestions.add')"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <Card>
      <template #content>
        <DataTable
          :value="questions"
          :loading="loading"
          row-hover
          size="small"
        >
          <!-- Text -->
          <Column :header="t('admin.meetingReportQuestions.columns.text')">
            <template #body="{ data }">{{ data.text }}</template>
          </Column>

          <!-- Pipeline scope -->
          <Column :header="t('admin.meetingReportQuestions.columns.pipeline')" style="width: 180px">
            <template #body="{ data }">
              <Tag
                :value="pipelineName(data.pipeline_id)"
                :severity="data.pipeline_id == null ? 'secondary' : 'info'"
              />
            </template>
          </Column>

          <!-- Kind -->
          <Column :header="t('admin.meetingReportQuestions.columns.kind')" style="width: 120px">
            <template #body="{ data }">
              {{ t(`admin.meetingReportQuestions.kinds.${data.kind}`) }}
            </template>
          </Column>

          <!-- Required -->
          <Column :header="t('admin.meetingReportQuestions.columns.isRequired')" style="width: 110px">
            <template #body="{ data }">
              <i
                :class="data.is_required ? 'pi pi-check text-success' : 'pi pi-minus text-secondary'"
              />
            </template>
          </Column>

          <!-- Sort order -->
          <Column :header="t('admin.meetingReportQuestions.columns.sortOrder')" style="width: 100px">
            <template #body="{ data }">{{ data.sort_order }}</template>
          </Column>

          <!-- Active -->
          <Column :header="t('admin.meetingReportQuestions.columns.isActive')" style="width: 100px">
            <template #body="{ data }">
              <ToggleSwitch
                v-if="canManage"
                :model-value="data.is_active ?? true"
                @update:model-value="() => toggleActive(data)"
              />
              <i
                v-else
                :class="(data.is_active ?? true) ? 'pi pi-check text-success' : 'pi pi-times text-secondary'"
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
                  @click="deleteQuestion(data)"
                />
              </span>
            </template>
          </Column>

          <template #empty>
            <div class="dir-page__empty">
              <i class="pi pi-comments" />
              <span>{{ t('admin.meetingReportQuestions.empty') }}</span>
              <Button
                v-if="canManage"
                :label="t('admin.meetingReportQuestions.add')"
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

    <QuestionDialog
      v-model="dialogVisible"
      :editing="editingQuestion"
      :pipelines="pipelines"
      :loading="saveMutation.isPending.value"
      @save="save"
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
import ToggleSwitch from 'primevue/toggleswitch'
import QuestionDialog from './components/QuestionDialog.vue'
import { useMeetingReportQuestionsPage } from './composables/useMeetingReportQuestionsPage'

const { t } = useI18n()

const {
  questions,
  pipelines,
  loading,
  canManage,
  dialogVisible,
  editingQuestion,
  saveMutation,
  pipelineName,
  openCreate,
  openEdit,
  save,
  toggleActive,
  deleteQuestion,
} = useMeetingReportQuestionsPage()
</script>

<style lang="scss" scoped>
.meeting-report-questions-page {
  padding: $space-3;
}

.dir-page__empty {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: $space-2;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2.5rem; // no exact $space token for 2.5rem; nearest $space-8 (2rem) is 8px short
  color: var(--p-text-muted-color);

  i {
    font-size: $font-size-2xl;
    opacity: 0.4;
  }
}
</style>
