<template>
  <div class="org-node">
    <!-- Card -->
    <div
      class="org-node__card"
      :class="{ 'org-node__card--open': open }"
      role="button"
      tabindex="0"
      @click="toggle"
      @keydown.enter.prevent="toggle"
      @keydown.space.prevent="toggle"
    >
      <!-- Edit pencil (edit-mode only) — opens side-panel, does NOT toggle expand -->
      <Button
        v-if="editMode"
        icon="pi pi-pencil"
        text
        severity="secondary"
        size="small"
        class="org-node__edit-btn"
        :title="t('common.edit')"
        @click.stop="$emit('edit', node)"
      />

      <div class="org-node__name">{{ node.label }}</div>
      <div v-if="node.data.manager_name" class="org-node__manager">
        {{ node.data.manager_name }}
      </div>

      <!-- Members count badge with chevron -->
      <span class="org-node__badge">
        <i class="pi pi-users org-node__badge-icon" />
        {{ node.data.members_count }}
        <i :class="['pi', open ? 'pi-chevron-up' : 'pi-chevron-down', 'org-node__badge-chevron']" />
      </span>

      <!-- Inline members (expanded) -->
      <div v-if="open" class="org-node__members" @click.stop>
        <template v-if="membersLoading">
          <Skeleton v-for="i in skeletonRows" :key="i" height="24px" class="org-node__member-skeleton" />
        </template>
        <template v-else-if="members.length > 0">
          <div v-for="m in members" :key="m.id" class="org-node__member">
            <EntityAvatar :name="m.full_name" :entity-id="m.id" :pixel-size="24" />
            <span class="org-node__member-name">{{ m.full_name }}</span>
          </div>
        </template>
        <span v-else class="org-node__members-empty">
          {{ t('accessControl.departments.noMembers') }}
        </span>
      </div>
    </div>

    <!-- Children (top-down chart) -->
    <template v-if="node.children.length > 0">
      <!-- Vertical connector from parent -->
      <div class="org-node__connector-down" />
      <!-- Row of children with top cross-bar when >1 -->
      <div
        class="org-node__children"
        :class="{ 'org-node__children--multi': node.children.length > 1 }"
      >
        <div
          v-for="child in node.children"
          :key="child.key"
          class="org-node__child"
        >
          <!-- Vertical connector into each child -->
          <div class="org-node__connector-up" />
          <OrgChartNode
            :node="child"
            :edit-mode="editMode"
            :load-members="loadMembers"
            @edit="$emit('edit', $event)"
          />
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import type { DeptTreeNode, DepartmentMemberDto } from '@/entities/accessControl'

const props = defineProps<{
  node: DeptTreeNode
  editMode: boolean
  /** Lazy members loader — fetches members for a department on first expand */
  loadMembers: (deptId: number) => Promise<DepartmentMemberDto[]>
}>()

defineEmits<{
  (e: 'edit', node: DeptTreeNode): void
}>()

const { t } = useI18n()

const open = ref(false)
const members = ref<DepartmentMemberDto[]>([])
const membersLoading = ref(false)
const loaded = ref(false)

// Skeleton row count — cap at members_count (min 1, max 4) for a natural look.
const skeletonRows = computed(() =>
  Math.min(Math.max(props.node.data.members_count, 1), 4),
)

async function toggle() {
  open.value = !open.value
  if (open.value && !loaded.value) {
    membersLoading.value = true
    try {
      members.value = await props.loadMembers(props.node.data.id)
      loaded.value = true
    } finally {
      membersLoading.value = false
    }
  }
}
</script>

<style scoped lang="scss">
// ── Node container: children arranged top-down, centred ────────────────────────
.org-node {
  display: flex;
  flex-direction: column;
  align-items: center;
}

// ── Card ───────────────────────────────────────────────────────────────────────
.org-node__card {
  position: relative;
  width: 210px;
  padding: $space-3 $space-4;
  background-color: $surface-card;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-md;
  box-shadow: $shadow-sm;
  cursor: pointer;
  text-align: center;
  transition: border-color var(--app-transition-fast), box-shadow var(--app-transition-fast);

  &:hover {
    box-shadow: $shadow-card-hover;
  }

  &:focus-visible {
    outline: 2px solid var(--p-primary-color);
    outline-offset: 2px;
  }

  // Expanded card → navy border (primary), reads in both themes from one token.
  &--open {
    border-color: var(--p-primary-color);
  }
}

.org-node__edit-btn {
  position: absolute;
  top: $space-1;
  right: $space-1;
}

.org-node__name {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.org-node__manager {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

// ── Members-count badge ─────────────────────────────────────────────────────────
.org-node__badge {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  margin-top: $space-2;
  padding: 2px $space-2;
  border-radius: $radius-pill;
  background-color: var(--p-primary-50);
  color: var(--p-primary-color);
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;

  // Dark: primary-950 tinted pill (matches sidebar active pattern), inverted scale.
  .app-dark & {
    background-color: var(--p-primary-950);
  }
}

.org-node__badge-icon {
  font-size: $font-size-2xs;
}

.org-node__badge-chevron {
  font-size: $font-size-3xs;
  margin-left: 1px;
}

// ── Inline members list ─────────────────────────────────────────────────────────
.org-node__members {
  margin-top: $space-3;
  padding-top: $space-3;
  border-top: 1px solid var(--p-surface-200);
  display: flex;
  flex-direction: column;
  gap: $space-2;
  text-align: start;
  cursor: default;
}

.org-node__member {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.org-node__member-name {
  flex: 1;
  min-width: 0;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: var(--p-text-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.org-node__member-skeleton {
  width: 100%;
}

.org-node__members-empty {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}

// ── Connectors (pure CSS — no SVG) ───────────────────────────────────────────────
// Vertical drop from a parent card down to the children row.
.org-node__connector-down {
  width: 1px;
  height: $space-5;
  background-color: var(--p-surface-300);
}

// Row of children. When there are 2+ children a horizontal cross-bar spans the
// top; each child adds its own vertical drop (connector-up) into it.
.org-node__children {
  display: flex;
  gap: $space-6;
  align-items: flex-start;

  &--multi {
    border-top: 1px solid var(--p-surface-300);
    padding-top: $space-5;
  }
}

.org-node__child {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
}

// For a single child, the parent connector-down already bridges the gap; the
// child's own connector-up would double it. It stays but overlaps flush — kept
// for the multi-child case where each child needs its drop from the cross-bar.
.org-node__children--multi .org-node__connector-up {
  position: absolute;
  top: calc(-1 * $space-5);
  left: 50%;
  width: 1px;
  height: $space-5;
  background-color: var(--p-surface-300);
}

// Single-child: no cross-bar, so suppress the child's redundant connector.
.org-node__children:not(.org-node__children--multi) .org-node__connector-up {
  display: none;
}
</style>
