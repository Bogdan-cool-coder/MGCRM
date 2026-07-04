<template>
  <div v-if="total > 0" class="paginator-caption">
    {{ t('common.paginator.showing', { from, to, total }) }}
  </div>
</template>

<script setup lang="ts">
/**
 * Shared «Показано {from}–{to} из {total}» caption — Periphery Uplift §0 canon.
 * Sits under a DataTable/Paginator to provide the unified counter formulation
 * across list screens. Derives the visible range from page/perPage/total.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  /** Current page, 1-based. */
  page?: number
  /** Rows per page. */
  perPage?: number
  /** Total records across all pages. */
  total: number
}>()

const currentPage = computed(() => props.page ?? 1)
const rows = computed(() => props.perPage ?? 25)

const from = computed(() => (props.total === 0 ? 0 : (currentPage.value - 1) * rows.value + 1))
const to = computed(() => Math.min(currentPage.value * rows.value, props.total))
</script>

<style lang="scss" scoped>
.paginator-caption {
  padding: $space-2 $space-4;
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
  text-align: right;
}
</style>
