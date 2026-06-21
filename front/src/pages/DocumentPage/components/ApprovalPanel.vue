<template>
  <Card class="approval-panel">
    <template #title>{{ t('documents.approval.title') }}</template>
    <template #content>
      <!-- Loading -->
      <Skeleton v-if="loading" height="160px" />

      <!-- No approval yet -->
      <div v-else-if="!approval" class="approval-panel__empty">
        <i class="pi pi-send approval-panel__empty-icon" />
        <p class="text-secondary mb-0">{{ t('documents.approval.noApproval') }}</p>
      </div>

      <template v-else>
        <!-- Header -->
        <div class="approval-panel__header mb-3">
          <span class="fw-medium">
            {{ t('documents.approval.stage', { current: approval.current_stage_order ?? '—', total: approval.total_stages }) }}
          </span>
          <span class="text-secondary ms-2">
            {{ t('documents.approval.attempt', { n: approval.attempt }) }}
          </span>
        </div>

        <!-- Stages -->
        <div class="d-flex flex-column gap-3 mb-3">
          <div
            v-for="stage in approval.stages"
            :key="stage.id"
            class="approval-panel__stage"
            :class="{
              'approval-panel__stage--active': stage.is_active,
              'approval-panel__stage--done': stage.is_done,
            }"
          >
            <div class="approval-panel__stage-header">
              <span class="fw-medium">{{ stage.order }}. {{ stage.name }}</span>
              <span v-if="stage.is_active" class="approval-panel__stage-badge approval-panel__stage-badge--active">
                {{ t('documents.approval.pending') }}
              </span>
              <span v-else-if="stage.is_done" class="approval-panel__stage-badge approval-panel__stage-badge--done">
                ✓
              </span>
            </div>

            <div class="approval-panel__votes mt-1">
              <div
                v-for="vote in stage.approvals"
                :key="vote.user_id"
                class="approval-panel__vote"
              >
                <i :class="voteIcon(vote.decision)" :style="{ color: voteColor(vote.decision) }" />
                <span class="approval-panel__vote-name">{{ vote.user_name }}</span>
                <span class="text-secondary approval-panel__vote-status">
                  {{ voteLabel(vote.decision) }}
                  <span v-if="vote.decided_at" class="ms-1">
                    {{ formatDate(vote.decided_at) }}
                  </span>
                </span>
              </div>
            </div>

            <div v-if="stage.approvals.length > 0" class="approval-panel__progress mt-1">
              <small class="text-secondary">
                {{ approvedCount(stage) }}/{{ stage.total }} {{ t('documents.approval.approved') }}
              </small>
            </div>
          </div>
        </div>

        <!-- Rejection / rework alert -->
        <div v-if="approval.decision === 'rejected'" class="approval-panel__alert approval-panel__alert--danger mb-3">
          <i class="pi pi-times-circle me-2" />
          <div>
            <strong>{{ t('documents.approval.rejectedAlert') }}</strong>
            <p v-if="approval.comment" class="mb-0 mt-1">{{ approval.comment }}</p>
          </div>
        </div>
        <div v-else-if="approval.decision === 'needs_rework'" class="approval-panel__alert approval-panel__alert--warning mb-3">
          <i class="pi pi-undo me-2" />
          <div>
            <strong>{{ t('documents.approval.reworkAlert') }}</strong>
            <p v-if="approval.comment" class="mb-0 mt-1">{{ approval.comment }}</p>
          </div>
        </div>

        <!-- Decision buttons (if current user is approver of active stage) -->
        <div v-if="approval.is_current_user_approver" class="d-flex gap-2 mt-3 flex-wrap">
          <Button
            icon="pi pi-check"
            :label="t('documents.approval.decide.approve')"
            severity="success"
            outlined
            size="small"
            :loading="deciding"
            @click="$emit('approve')"
          />
          <Button
            icon="pi pi-times"
            :label="t('documents.approval.decide.reject')"
            severity="danger"
            outlined
            size="small"
            @click="$emit('openDecide', 'rejected')"
          />
          <Button
            icon="pi pi-undo"
            :label="t('documents.approval.decide.rework')"
            severity="warn"
            outlined
            size="small"
            @click="$emit('openDecide', 'needs_rework')"
          />
        </div>
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import type { ApprovalSummaryDto, ApprovalDecision, ApprovalStageDto } from '@/entities/document'

defineProps<{
  approval: ApprovalSummaryDto | null
  loading: boolean
  deciding?: boolean
}>()

defineEmits<{
  approve: []
  openDecide: [action: 'rejected' | 'needs_rework']
}>()

const { t } = useI18n()

function voteIcon(decision: ApprovalDecision): string {
  const map: Record<ApprovalDecision, string> = {
    approved: 'pi pi-check',
    rejected: 'pi pi-times',
    needs_rework: 'pi pi-undo',
    pending: 'pi pi-clock',
  }
  return map[decision] ?? 'pi pi-circle'
}

function voteColor(decision: ApprovalDecision): string {
  const map: Record<ApprovalDecision, string> = {
    approved: 'var(--p-green-500)',
    rejected: 'var(--p-red-500)',
    needs_rework: 'var(--p-orange-500)',
    pending: 'var(--p-surface-400)',
  }
  return map[decision] ?? 'var(--p-surface-400)'
}

function voteLabel(decision: ApprovalDecision): string {
  const { t: $t } = useI18n()
  const map: Record<ApprovalDecision, string> = {
    approved: $t('documents.approval.approved'),
    rejected: $t('documents.approval.rejected'),
    needs_rework: $t('documents.approval.needs_rework'),
    pending: $t('documents.approval.pending'),
  }
  return map[decision] ?? decision
}

function approvedCount(stage: ApprovalStageDto): number {
  return stage.approvals.filter((v) => v.decision === 'approved').length
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}
</script>

<style lang="scss" scoped>
.approval-panel {
  &__header {
    font-size: $font-size-sm;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 0;
    text-align: center;

    &-icon {
      font-size: $font-size-2xl;
      opacity: 0.3;
    }
  }

  &__stage {
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;
    padding: 0.75rem;

    &--active {
      border-color: var(--p-orange-400);
      background: var(--p-orange-50);

      .app-dark & {
        background: transparent;
        border-color: var(--p-orange-500);
      }
    }

    &--done {
      border-color: var(--p-green-400);
      background: var(--p-green-50);

      .app-dark & {
        background: transparent;
        border-color: var(--p-green-600);
      }
    }
  }

  &__stage-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: $font-size-sm;
  }

  &__stage-badge {
    font-size: $font-size-xs;
    padding: 2px 6px;
    border-radius: $radius-sm;

    &--active {
      background: var(--p-orange-100);
      color: var(--p-orange-700);
    }

    &--done {
      background: var(--p-green-100);
      color: var(--p-green-700);
    }
  }

  &__votes {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  &__vote {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: $font-size-sm;

    i {
      flex-shrink: 0;
      font-size: $font-size-sm;
    }
  }

  &__vote-name {
    font-weight: $font-weight-medium;
    flex: 1;
  }

  &__vote-status {
    font-size: $font-size-xs;
    white-space: nowrap;
  }

  &__progress {
    font-size: $font-size-xs;
  }

  &__alert {
    border-radius: $radius-md;
    padding: 0.75rem;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    font-size: $font-size-sm;

    &--danger {
      background: var(--p-red-50);
      color: var(--p-red-700);
      border: 1px solid var(--p-red-200);

      .app-dark & {
        background: transparent;
        border-color: var(--p-red-500);
        color: var(--p-red-400);
      }
    }

    &--warning {
      background: var(--p-orange-50);
      color: var(--p-orange-700);
      border: 1px solid var(--p-orange-200);

      .app-dark & {
        background: transparent;
        border-color: var(--p-orange-500);
        color: var(--p-orange-400);
      }
    }
  }
}
</style>
