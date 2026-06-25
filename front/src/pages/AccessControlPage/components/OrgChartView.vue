<template>
  <div class="org-chart">
    <div
      v-for="node in nodes"
      :key="node.key"
      class="org-chart__level"
    >
      <OrgChartNode
        :node="node"
        :depth="0"
        @select="$emit('select', $event)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { DeptTreeNode } from '@/entities/accessControl'
import OrgChartNode from './OrgChartNode.vue'

defineProps<{
  nodes: DeptTreeNode[]
}>()

defineEmits<{
  (e: 'select', node: DeptTreeNode): void
}>()
</script>

<style scoped lang="scss">
.org-chart {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-4;
  overflow: auto;
}

.org-chart__level {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}
</style>
