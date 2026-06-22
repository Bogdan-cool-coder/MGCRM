<template>
  <div class="company-files-tab">
    <!-- TabHead -->
    <div class="company-files-tab__head">
      <span class="company-files-tab__head-title">{{ t('crm.company.tabs.files') }}</span>
      <div class="company-files-tab__head-actions">
        <!-- TODO B-4: wire to POST /api/companies/{id}/folders when backend adds the endpoint -->
        <Button
          icon="pi pi-folder-plus"
          :label="t('crm.files.createFolder')"
          severity="secondary"
          size="small"
          outlined
          disabled
        />
        <!-- TODO B-4: wire to POST /api/companies/{id}/files when backend adds the endpoint -->
        <Button
          icon="pi pi-upload"
          :label="t('crm.files.upload')"
          size="small"
          disabled
        />
      </div>
    </div>

    <!--
      Two-panel layout per spec §9.
      TODO B-4: stub data; real API endpoints (GET /api/companies/{id}/files) are MISSING.
      When the backend is ready, replace STUB_FOLDERS with an API call.
    -->
    <div class="company-files-tab__body">
      <!-- Left panel: folder list (46%) -->
      <div class="company-files-tab__folders">
        <button
          v-for="folder in STUB_FOLDERS"
          :key="folder.id"
          type="button"
          class="company-files-tab__folder-row"
          :class="{ 'company-files-tab__folder-row--active': selectedFolderId === folder.id }"
          @click="selectedFolderId = folder.id"
        >
          <i
            class="pi company-files-tab__folder-icon"
            :class="selectedFolderId === folder.id ? 'pi-folder-open' : 'pi-folder'"
          />
          <span class="company-files-tab__folder-name">{{ folder.name }}</span>
          <span class="company-files-tab__folder-count">{{ folder.count }}</span>
          <i class="pi pi-chevron-right company-files-tab__folder-chevron" />
        </button>
      </div>

      <!-- Right panel: file list for selected folder -->
      <div class="company-files-tab__files">
        <template v-if="selectedFolder">
          <div
            v-for="file in selectedFolder.files"
            :key="file.name"
            class="company-files-tab__file-row"
          >
            <i :class="['pi', file.icon, 'company-files-tab__file-icon']" />
            <div class="company-files-tab__file-info">
              <span class="company-files-tab__file-name">{{ file.name }}</span>
              <span class="company-files-tab__file-meta">{{ file.size }} · {{ file.date }}</span>
            </div>
            <button type="button" class="company-files-tab__file-btn" disabled>
              <i class="pi pi-download" />
            </button>
            <button type="button" class="company-files-tab__file-btn" disabled>
              <i class="pi pi-ellipsis-v" />
            </button>
          </div>
        </template>
        <div v-else class="company-files-tab__files-empty">
          <i class="pi pi-file company-files-tab__files-empty-icon" />
          <p>{{ t('crm.files.selectFolder', 'Выберите папку') }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'

const { t } = useI18n()

// ── Stub data (TODO B-4: replace with API when GET /api/companies/{id}/files is added) ──

interface StubFile { name: string; size: string; date: string; icon: string }
interface StubFolder { id: string; name: string; count: number; files: StubFile[] }

const STUB_FOLDERS: StubFolder[] = [
  {
    id: 'manager',
    name: t('crm.files.folders.manager', 'Папка менеджера сделки'),
    count: 0,
    files: [],
  },
  {
    id: 'scans',
    name: t('crm.files.folders.contractScans', 'Сканы договоров / ДС'),
    count: 0,
    files: [],
  },
  {
    id: 'oks',
    name: t('crm.files.folders.oks', 'Папка менеджера ОКС'),
    count: 0,
    files: [],
  },
]

const selectedFolderId = ref<string>(STUB_FOLDERS[0]?.id ?? 'manager')

const selectedFolder = computed(() =>
  STUB_FOLDERS.find((f) => f.id === selectedFolderId.value) ?? null,
)
</script>

<style lang="scss" scoped>
.company-files-tab {
  display: flex;
  flex-direction: column;
  min-height: 300px;
}

// ── TabHead ──────────────────────────────────────────────────────────────────

.company-files-tab__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

.company-files-tab__head-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;
}

.company-files-tab__head-actions {
  display: flex;
  gap: $space-2;
}

// ── Two-panel body (spec §9) ──────────────────────────────────────────────────

.company-files-tab__body {
  display: flex;
  flex: 1;
  min-height: 240px;
}

// Left panel: 46%, spec §9
.company-files-tab__folders {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 46%; // spec §9: left 46%
  border-right: 1px solid var(--p-surface-200);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }

  .app-dark & {
    border-right-color: var(--p-surface-600);
  }
}

.company-files-tab__folder-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  background: transparent;
  border: none;
  cursor: pointer;
  text-align: left;
  width: 100%;
  transition: background var(--app-transition-fast);
  border-bottom: 1px solid var(--p-surface-100);

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  &--active {
    background: $primary-100;

    .app-dark & {
      background: var(--p-primary-900);
    }

    .company-files-tab__folder-icon {
      color: var(--p-primary-color);
    }

    .company-files-tab__folder-name {
      font-weight: $font-weight-semibold;
      color: $primary-900;

      .app-dark & {
        color: var(--p-primary-200);
      }
    }
  }
}

.company-files-tab__folder-icon {
  font-size: $font-size-sm;
  color: $surface-400;
  flex-shrink: 0;
}

.company-files-tab__folder-name {
  flex: 1;
  font-size: $font-size-sm;
  color: $surface-700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.company-files-tab__folder-count {
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  color: $surface-500;
  background: var(--p-surface-100);
  border-radius: $radius-circle;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 1px 6px;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-200);
    color: var(--p-surface-400);
  }
}

.company-files-tab__folder-chevron {
  font-size: $font-size-xs;
  color: $surface-300;
  flex-shrink: 0;
}

// Right panel: files list
.company-files-tab__files {
  flex: 1;
  overflow-y: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

.company-files-tab__file-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-100);
  transition: background var(--app-transition-fast);

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }
}

.company-files-tab__file-icon {
  font-size: $font-size-base;
  color: $surface-400;
  flex-shrink: 0;
}

.company-files-tab__file-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.company-files-tab__file-name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.company-files-tab__file-meta {
  font-size: $font-size-2xs;
  color: $surface-400;
}

.company-files-tab__file-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 26px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 26px;
  border-radius: $radius-sm;
  border: none;
  background: transparent;
  color: $surface-400;
  cursor: pointer;
  flex-shrink: 0;

  i {
    font-size: $font-size-xs;
  }

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
}

.company-files-tab__files-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  height: 100%;
  padding: $space-6;
  text-align: center;

  p {
    font-size: $font-size-sm;
    color: $surface-400;
    margin: 0;
  }
}

.company-files-tab__files-empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-300;
}
</style>
