<template>
  <Card class="ai-check-card">
    <template #title>{{ t('templates.card.aiCheck.title') }}</template>
    <template #content>
      <div v-if="!version" class="ai-check-card__empty text-secondary">
        {{ t('templates.card.versions.empty') }}
      </div>

      <template v-else>
        <!-- Status -->
        <div class="d-flex align-items-center gap-3 mb-3">
          <Tag
            :severity="statusSeverity"
            :value="statusLabel"
          >
            <template v-if="version.ai_check_status === 'checking'" #default>
              <ProgressSpinner style="width: 14px; height: 14px;" stroke-width="6" class="me-1" />
              {{ statusLabel }}
            </template>
          </Tag>

          <!-- PDF ok -->
          <Tag
            v-if="version.pdf_ok === true"
            severity="success"
            icon="pi pi-check"
            :value="t('templates.card.aiCheck.pdfOk')"
          />
          <Tag
            v-else-if="version.pdf_ok === false"
            severity="danger"
            icon="pi pi-times"
            :value="t('templates.card.aiCheck.pdfFail')"
          />
          <Tag
            v-else
            severity="secondary"
            :value="t('templates.card.aiCheck.pdfUnknown')"
          />
        </div>

        <!-- AI remarks -->
        <div v-if="version.ai_remarks && version.ai_remarks.length > 0" class="ai-check-card__remarks mb-3">
          <div
            v-for="(remark, i) in version.ai_remarks"
            :key="i"
            class="ai-check-card__remark"
          >
            <Tag
              :severity="remarkSeverity(remark)"
              :value="remarkLabel(remark)"
              class="ai-check-card__remark-tag"
            />
            <span class="ai-check-card__remark-text">{{ remark.text }}</span>
          </div>
        </div>
        <div
          v-else-if="version.ai_check_status === 'checked'"
          class="ai-check-card__no-remarks mb-3"
        >
          <i class="pi pi-check-circle text-success me-1" />
          {{ t('templates.card.aiCheck.noRemarks') }}
        </div>

        <!-- Actions -->
        <div class="d-flex gap-2 flex-wrap">
          <Button
            v-if="version.ai_check_status === 'checked' || version.ai_check_status === 'failed'"
            icon="pi pi-refresh"
            :label="t('templates.card.aiCheck.recheck')"
            severity="secondary"
            outlined
            size="small"
            :loading="rechecking"
            @click="$emit('recheck')"
          />
          <Button
            v-if="version.ai_check_status === 'checked' && version.ai_remarks && version.ai_remarks.length > 0"
            icon="pi pi-exclamation-triangle"
            :label="t('templates.card.aiCheck.override')"
            severity="warn"
            outlined
            size="small"
            :loading="overriding"
            @click="$emit('override')"
          />
        </div>
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import ProgressSpinner from 'primevue/progressspinner'
import type { TemplateVersionDto, AiCheckStatus, AiRemarkDto } from '@/entities/template'

type TagSeverity = 'secondary' | 'info' | 'success' | 'warn' | 'danger' | 'contrast'

const props = defineProps<{
  version: TemplateVersionDto | null
  rechecking?: boolean
  overriding?: boolean
}>()

defineEmits<{
  recheck: []
  override: []
}>()

const { t } = useI18n()

const statusSeverity = computed<TagSeverity>(() => {
  const map: Record<AiCheckStatus, TagSeverity> = {
    pending: 'warn',
    checking: 'info',
    checked: 'success',
    failed: 'danger',
  }
  return props.version ? (map[props.version.ai_check_status] ?? 'secondary') : 'secondary'
})

const statusLabel = computed(() => {
  if (!props.version) return ''
  return t(`templates.card.aiCheck.statuses.${props.version.ai_check_status}`, props.version.ai_check_status)
})

function remarkSeverity(remark: AiRemarkDto): TagSeverity {
  if (remark.type === 'error') {
    return remark.severity === 'high' ? 'danger' : 'warn'
  }
  return 'info'
}

function remarkLabel(remark: AiRemarkDto): string {
  if (remark.type === 'error') {
    return remark.severity === 'high'
      ? t('templates.card.aiCheck.remarkTypes.error')
      : t('templates.card.aiCheck.remarkTypes.warning')
  }
  return t('templates.card.aiCheck.remarkTypes.warning')
}
</script>

<style lang="scss" scoped>
.ai-check-card {
  &__empty {
    font-size: $font-size-sm;
  }

  &__remarks {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    background: var(--p-surface-50);
    border-radius: $radius-md;
    padding: 0.75rem;
  }

  &__remark {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    font-size: $font-size-sm;
  }

  &__remark-tag {
    flex-shrink: 0;
    font-size: $font-size-xs;
  }

  &__remark-text {
    color: var(--p-text-color);
    line-height: 1.4;
  }

  &__no-remarks {
    font-size: $font-size-sm;
    color: var(--p-green-600);
  }
}
</style>
