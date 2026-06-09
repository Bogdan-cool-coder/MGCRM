<template>
  <div class="reports-page">
    <div class="reports-card">
      <!-- Header: title -->
      <div class="reports-header">
        <div class="reports-heading">
          <h1 class="reports-title">{{ t('title') }}</h1>
          <Button
            v-if="canUseAi"
            icon="pi pi-plus"
            rounded
            text
            :aria-label="t('openAiConstructor')"
            :title="t('openAiConstructor')"
            @click="openGenerationModal"
          />
        </div>
      </div>

      <div class="reports-content">
        <LoadingState v-if="loading" />

        <div v-else-if="reports.length > 0" class="reports-grid-wrap">
          <!-- Single draggable list: any report can be dragged anywhere.
               The new order is persisted per-user via PUT /api/reports/order
               (optimistic). The "generate" tile sits outside the draggable so
               it always stays last and is never reorderable. -->
          <draggable
            :model-value="localizedReports"
            item-key="id"
            :animation="160"
            ghost-class="report-card--ghost"
            chosen-class="report-card--chosen"
            class="reports-grid"
            @change="onReorderChange"
          >
            <template #item="{ element }">
              <ReportCard
                :title="element.localizedTitle"
                :description="element.localizedDescription"
                :type="element.type"
                :is-published="element.is_published"
                @click="openReport(element.id)"
              />
            </template>
          </draggable>
          <GenerateReportTile
            v-if="canUseAi"
            class="reports-grid__generate-tile"
            :label="t('generateCustomTile')"
            @click="generateCustomReport"
          />
        </div>

        <EmptyState v-else :message="t('empty')" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import draggable from 'vuedraggable'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import ReportCard from '@/components/cards/ReportCard'
import GenerateReportTile from '@/components/cards/GenerateReportTile'
import { useReportsPage } from './composables/useReportsPage'

const {
  t,
  loading,
  reports,
  canUseAi,
  localizedReports,
  openReport,
  openGenerationModal,
  generateCustomReport,
  reorderReports,
} = useReportsPage()

/**
 * vuedraggable @change shape — single flat sortable list, so only `moved`
 * is possible (no add/remove between lists).
 */
interface DraggableChangeEvent {
  moved?: { oldIndex: number; newIndex: number }
}

/**
 * Translate a single drag-drop into the full ordered id list, then hand it to
 * `reorderReports` (optimistic local reorder + PUT). We compute the new order
 * off the current `localizedReports` snapshot rather than mutating it directly
 * — the composable owns the reactive `reports` source of truth.
 */
const onReorderChange = (event: DraggableChangeEvent): void => {
  if (!event.moved) return
  const { oldIndex, newIndex } = event.moved
  if (oldIndex === newIndex) return

  const ids = localizedReports.value.map((report) => report.id)
  const [moved] = ids.splice(oldIndex, 1)
  if (moved === undefined) return
  ids.splice(newIndex, 0, moved)

  void reorderReports(ids)
}
</script>

<style lang="scss" scoped>
.reports-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0.75rem;

  .reports-card {
    background: $surface-0;
    border-radius: $card-border-radius;
    padding: 1rem;
    box-shadow: $shadow-md;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;

    .reports-header {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
      flex-shrink: 0;

      .reports-heading {
        display: flex;
        align-items: center;
        gap: 0.25rem;

        .reports-title {
          margin: 0;
          font-size: $font-size-2xl;
          font-weight: $font-weight-semibold;
          color: $surface-900;
        }
      }
    }

    .reports-content {
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px solid $surface-200;
      flex: 1;
      min-height: 0;
      overflow: auto;

      // The wrap is the real grid; the draggable list uses `display: contents`
      // so its <ReportCard> children participate in this grid alongside the
      // (non-draggable) generate tile, which always renders last.
      .reports-grid-wrap {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 1.5rem;
      }

      .reports-grid {
        display: contents;
      }

      // Drag affordances on the cards. The whole card is draggable (no
      // dedicated handle), so hint the grab cursor. Click-to-open still works
      // — sortable only intercepts an actual drag gesture, not a plain click.
      .reports-grid :deep(.report-card) {
        cursor: grab;

        &:active {
          cursor: grabbing;
        }
      }

      .report-card--ghost {
        opacity: 0.4;
      }

      .report-card--chosen {
        // Subtle lift while dragging. Inline literal shadow — the project's
        // token set exposes only md / lg and this is intentionally lighter.
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
      }
    }
  }
}
</style>
