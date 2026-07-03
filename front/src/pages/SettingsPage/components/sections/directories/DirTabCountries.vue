<template>
  <div class="dir-tab-countries">
    <div class="dir-tab-toolbar">
      <div class="dir-tab-toolbar__spacer" />
      <div class="dir-tab-toolbar__actions">
        <!-- Edit-mode toggle (Гэп-2): reveals drag-handle + kebab. Only when
             manageable and there is at least one row. -->
        <Button
          v-if="pageRef?.canManage && (pageRef?.rowCount ?? 0) > 0"
          :label="editing ? t('settings.directories.finishEditing') : t('common.edit')"
          :icon="editing ? 'pi pi-check' : 'pi pi-pencil'"
          :outlined="!editing"
          :severity="editing ? 'primary' : 'secondary'"
          size="small"
          @click="editing = !editing"
        />
        <Button
          v-if="pageRef?.canManage"
          icon="pi pi-plus"
          :label="t('admin.countries.add')"
          @click="pageRef?.openCreate()"
        />
      </div>
    </div>

    <div class="dir-tab-body">
      <CountriesPage ref="pageRef" :embedded="true" :edit-mode="editing" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import CountriesPage from '@/pages/CountriesPage/index.vue'

const { t } = useI18n()

const pageRef = ref<InstanceType<typeof CountriesPage> | null>(null)
const editing = ref(false)
</script>

<style lang="scss" scoped>
.dir-tab-countries {
  display: flex;
  flex-direction: column;
}

.dir-tab-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);
  background: $surface-card;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-200);
  }
}

.dir-tab-toolbar__spacer {
  flex: 1;
}

.dir-tab-toolbar__actions {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.dir-tab-body {
  flex: 1;
  padding: $space-3;
}
</style>
