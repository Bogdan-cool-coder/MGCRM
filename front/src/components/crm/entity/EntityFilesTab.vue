<template>
  <div class="entity-files-tab">
    <!-- Head -->
    <div class="entity-files-tab__head">
      <span class="entity-files-tab__head-title">{{ headTitle }}</span>
      <div class="entity-files-tab__head-actions">
        <Button
          icon="pi pi-folder-plus"
          :label="t('crm.files.createFolder')"
          severity="secondary"
          size="small"
          outlined
          :loading="createFolderMutation.isPending.value"
          :disabled="foldersLoading"
          @click="showCreateFolderDialog = true"
        />
        <Button
          icon="pi pi-upload"
          :label="t('crm.files.upload')"
          size="small"
          :disabled="!canUpload"
          :loading="uploadMutation.isPending.value"
          @click="triggerUpload"
        />
        <!-- hidden file input -->
        <input
          ref="fileInputRef"
          type="file"
          class="entity-files-tab__upload-input"
          @change="onFileSelected"
        />
      </div>
    </div>

    <!-- Body: two-panel layout per spec §9 -->
    <div class="entity-files-tab__body">
      <!-- LEFT panel: folder list (46%) -->
      <div class="entity-files-tab__folders">
        <!-- Loading skeleton -->
        <template v-if="foldersLoading">
          <div v-for="n in 3" :key="n" class="entity-files-tab__folder-skeleton">
            <Skeleton height="16px" width="60%" />
          </div>
        </template>

        <!-- Empty -->
        <div v-else-if="folders.length === 0" class="entity-files-tab__folders-empty">
          <i class="pi pi-folder entity-files-tab__folders-empty-icon" />
          <p>{{ t('crm.files.emptyFolders') }}</p>
        </div>

        <!-- Folder rows -->
        <template v-else>
          <button
            v-for="folder in folders"
            :key="folder.id"
            type="button"
            class="entity-files-tab__folder-row"
            :class="{ 'entity-files-tab__folder-row--active': selectedFolderId === folder.id }"
            @click="selectFolder(folder.id)"
          >
            <i
              class="pi entity-files-tab__folder-icon"
              :class="selectedFolderId === folder.id ? 'pi-folder-open' : 'pi-folder'"
            />
            <span class="entity-files-tab__folder-name">{{ folder.name }}</span>
            <span
              v-if="fileCountMap[folder.id] !== undefined"
              class="entity-files-tab__folder-count"
            >{{ fileCountMap[folder.id] }}</span>
            <!-- Delete user folder -->
            <button
              v-if="!folder.is_system"
              type="button"
              class="entity-files-tab__folder-del"
              :title="t('common.delete')"
              @click.stop="deleteFolderById(folder.id)"
            >
              <i class="pi pi-times" />
            </button>
            <i v-else class="pi pi-chevron-right entity-files-tab__folder-chevron" />
          </button>
        </template>
      </div>

      <!-- RIGHT panel: file list for selected folder -->
      <div class="entity-files-tab__files">
        <!-- Loading -->
        <template v-if="filesLoading">
          <div v-for="n in 4" :key="n" class="entity-files-tab__file-skeleton">
            <Skeleton height="14px" width="50%" />
            <Skeleton height="12px" width="30%" />
          </div>
        </template>

        <!-- No folder selected -->
        <div v-else-if="selectedFolderId === null" class="entity-files-tab__files-empty">
          <i class="pi pi-folder entity-files-tab__files-empty-icon" />
          <p>{{ t('crm.files.selectFolder') }}</p>
        </div>

        <!-- Empty folder -->
        <div v-else-if="files.length === 0" class="entity-files-tab__files-empty">
          <i class="pi pi-file entity-files-tab__files-empty-icon" />
          <p>{{ t('crm.files.emptyFiles') }}</p>
        </div>

        <!-- File rows.
             Spec §9 mentions a pi-ellipsis-v overflow menu (Скачать / Удалить).
             Decision: kept as inline icons — Скачать must be a native <a> link for
             browser "Save as" to work, and Удалить is a single destructive action;
             an overlay menu for 2 items adds click-overhead for no UX gain.
             System folder names ('Папка менеджера сделки', 'Сканы договоров', 'Папка ОКС')
             come from the backend and are rendered as-is — they match spec strings. -->
        <template v-else>
          <div
            v-for="file in files"
            :key="String(file.id)"
            class="entity-files-tab__file-row"
          >
            <i :class="['pi', mimeIcon(file.mime_type), 'entity-files-tab__file-icon']" />
            <div class="entity-files-tab__file-info">
              <span class="entity-files-tab__file-name">{{ file.original_name }}</span>
              <span class="entity-files-tab__file-meta">
                {{ formatSize(file.file_size) }}
                <template v-if="file.file_size !== null"> · </template>
                {{ formatDate(file.created_at) }}
              </span>
            </div>
            <!-- Download -->
            <a
              :href="file.download_url"
              target="_blank"
              rel="noopener"
              class="entity-files-tab__file-btn"
              :title="t('crm.files.download')"
            >
              <i class="pi pi-download" />
            </a>
            <!-- Delete (only for non-read-only folders and non-document-backed files) -->
            <button
              v-if="!selectedFolderReadOnly && file.source !== 'document' && typeof file.id === 'number'"
              type="button"
              class="entity-files-tab__file-btn entity-files-tab__file-btn--danger"
              :title="t('common.delete')"
              @click="deleteFileById(file.id as number)"
            >
              <i class="pi pi-trash" />
            </button>
            <span
              v-else
              class="entity-files-tab__file-btn entity-files-tab__file-btn--spacer"
            />
          </div>
        </template>
      </div>
    </div>

    <!-- Create folder dialog -->
    <Dialog
      v-model:visible="showCreateFolderDialog"
      :header="t('crm.files.createFolder')"
      modal
      :style="{ width: '360px' }"
    >
      <div class="entity-files-tab__dialog-body">
        <label class="entity-files-tab__dialog-label">{{ t('crm.files.folderName') }}</label>
        <InputText
          v-model="newFolderName"
          :placeholder="t('crm.files.folderNamePlaceholder')"
          class="w-100"
          autofocus
          @keydown.enter="submitCreateFolder"
        />
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          outlined
          @click="showCreateFolderDialog = false"
        />
        <Button
          :label="t('common.create')"
          :disabled="!newFolderName.trim()"
          :loading="createFolderMutation.isPending.value"
          @click="submitCreateFolder"
        />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { filesApi } from '@/api/crm/files'
import type { FolderDto, CrmFileDto, EntityKind } from '@/api/crm/files'

// ── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
  kind: EntityKind
  entityId: number
  headTitle: string
}>()

// ── i18n ─────────────────────────────────────────────────────────────────────

const { t } = useI18n()

// ── State ─────────────────────────────────────────────────────────────────────

const selectedFolderId = ref<number | null>(null)
const showCreateFolderDialog = ref(false)
const newFolderName = ref('')
const fileInputRef = ref<HTMLInputElement | null>(null)

// ── Async resources ───────────────────────────────────────────────────────────

const foldersResource = useAsyncResource<FolderDto[]>([])
const filesResource = useAsyncResource<CrmFileDto[]>([])

const folders = computed(() => foldersResource.data.value)
const foldersLoading = computed(() => foldersResource.loading.value)
const files = computed(() => filesResource.data.value)
const filesLoading = computed(() => filesResource.loading.value)

// ── Mutations ─────────────────────────────────────────────────────────────────

const createFolderMutation = useMutation<FolderDto>()
const deleteFolderMutation = useMutation<void>()
const uploadMutation = useMutation<CrmFileDto>()
const deleteFileMutation = useMutation<void>()

// ── Computed helpers ──────────────────────────────────────────────────────────

const selectedFolder = computed<FolderDto | null>(
  () => folders.value.find((f) => f.id === selectedFolderId.value) ?? null,
)

const selectedFolderReadOnly = computed(() => selectedFolder.value?.read_only === true)

// Upload is allowed only when a folder is selected and it is not read-only
const canUpload = computed(
  () => selectedFolderId.value !== null && !selectedFolderReadOnly.value && !filesLoading.value,
)

// Count files per folder (populated lazily only for the selected folder for now;
// we track a simple map that grows as folders are opened)
const fileCountMap = ref<Record<number, number>>({})

// ── Load folders on mount ─────────────────────────────────────────────────────

async function loadFolders(): Promise<void> {
  await foldersResource.run(() =>
    filesApi.getFolders(props.kind, props.entityId),
  )
  // Auto-select first folder
  const firstFolder = folders.value[0]
  if (firstFolder !== undefined && selectedFolderId.value === null) {
    await selectFolder(firstFolder.id)
  }
}

onMounted(loadFolders)

// ── Load files when folder changes ───────────────────────────────────────────

async function selectFolder(folderId: number): Promise<void> {
  selectedFolderId.value = folderId
  filesResource.reset([])
  await filesResource.run(() =>
    filesApi.getFiles(props.kind, props.entityId, folderId),
  )
  fileCountMap.value = {
    ...fileCountMap.value,
    [folderId]: filesResource.data.value.length,
  }
}

// Re-load files when entity changes (e.g. tab re-use)
watch(
  () => props.entityId,
  () => {
    selectedFolderId.value = null
    filesResource.reset([])
    fileCountMap.value = {}
    loadFolders()
  },
)

// ── Create folder ─────────────────────────────────────────────────────────────

async function submitCreateFolder(): Promise<void> {
  const name = newFolderName.value.trim()
  if (!name) return
  await createFolderMutation.run(
    () => filesApi.createFolder(props.kind, props.entityId, { name }),
    {
      onSuccess: async (created) => {
        showCreateFolderDialog.value = false
        newFolderName.value = ''
        await loadFolders()
        // Auto-select the newly created folder
        await selectFolder(created.id)
      },
    },
  )
}

// ── Delete folder ─────────────────────────────────────────────────────────────

async function deleteFolderById(folderId: number): Promise<void> {
  await deleteFolderMutation.run(
    () => filesApi.deleteFolder(props.kind, props.entityId, folderId),
    {
      onSuccess: async () => {
        if (selectedFolderId.value === folderId) {
          selectedFolderId.value = null
          filesResource.reset([])
        }
        await loadFolders()
      },
    },
  )
}

// ── Upload file ───────────────────────────────────────────────────────────────

function triggerUpload(): void {
  fileInputRef.value?.click()
}

async function onFileSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || selectedFolderId.value === null) return

  const folderId = selectedFolderId.value
  await uploadMutation.run(
    () => filesApi.uploadFile(props.kind, props.entityId, folderId, file),
    {
      onSuccess: async () => {
        // Reset input so the same file can be re-uploaded if needed
        if (fileInputRef.value) fileInputRef.value.value = ''
        await selectFolder(folderId)
      },
    },
  )
}

// ── Delete file ───────────────────────────────────────────────────────────────

async function deleteFileById(fileId: number): Promise<void> {
  await deleteFileMutation.run(
    () => filesApi.deleteFile(props.kind, props.entityId, fileId),
    {
      onSuccess: async () => {
        if (selectedFolderId.value !== null) {
          await selectFolder(selectedFolderId.value)
        }
      },
    },
  )
}

// ── Formatters ────────────────────────────────────────────────────────────────

function formatSize(bytes: number | null): string {
  if (bytes === null) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}

function formatDate(iso: string): string {
  if (!iso) return ''
  const d = new Date(iso)
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const yyyy = d.getFullYear()
  return `${dd}.${mm}.${yyyy}`
}

function mimeIcon(mime: string | null | undefined): string {
  if (!mime) return 'pi-file'
  if (mime.startsWith('image/')) return 'pi-image'
  if (mime === 'application/pdf') return 'pi-file-pdf'
  if (
    mime.includes('spreadsheet') ||
    mime.includes('excel') ||
    mime.endsWith('.sheet')
  ) return 'pi-file-excel'
  if (
    mime.includes('word') ||
    mime.includes('document') ||
    mime.endsWith('.document')
  ) return 'pi-file-word'
  return 'pi-file'
}
</script>

<style lang="scss" scoped>
.entity-files-tab {
  display: flex;
  flex-direction: column;
  min-height: 300px;
}

// ── Head ──────────────────────────────────────────────────────────────────────

.entity-files-tab__head {
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

.entity-files-tab__head-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;
}

.entity-files-tab__head-actions {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.entity-files-tab__upload-input {
  display: none;
}

// ── Body: two-panel spec §9 ────────────────────────────────────────────────────

.entity-files-tab__body {
  display: flex;
  flex: 1;
  min-height: 240px;
}

// Left panel: 46%
.entity-files-tab__folders {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 46%;
  border-right: 1px solid var(--p-surface-200);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    display: none;
  }

  .app-dark & {
    border-right-color: var(--p-surface-600);
  }
}

// ── Folder rows ───────────────────────────────────────────────────────────────

.entity-files-tab__folder-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  background: transparent;
  border: none;
  border-bottom: 1px solid var(--p-surface-100);
  cursor: pointer;
  text-align: left;
  width: 100%;
  transition: background var(--app-transition-fast);

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

    .entity-files-tab__folder-icon {
      color: var(--p-primary-color);
    }

    .entity-files-tab__folder-name {
      font-weight: $font-weight-semibold;
      color: $primary-900;

      .app-dark & {
        color: var(--p-primary-200);
      }
    }
  }
}

.entity-files-tab__folder-icon {
  font-size: $font-size-sm;
  color: $surface-400;
  flex-shrink: 0;
}

.entity-files-tab__folder-name {
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

.entity-files-tab__folder-count {
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

.entity-files-tab__folder-chevron {
  font-size: $font-size-xs;
  color: $surface-300;
  flex-shrink: 0;
}

.entity-files-tab__folder-del {
  display: flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 20px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 20px;
  border: none;
  background: transparent;
  color: $surface-300;
  cursor: pointer;
  flex-shrink: 0;
  border-radius: $radius-sm;
  transition: color var(--app-transition-fast), background var(--app-transition-fast);

  &:hover {
    color: var(--p-red-500);
    background: var(--p-red-50);

    .app-dark & {
      color: var(--p-red-300);
      background: transparent;
    }
  }

  i {
    font-size: $font-size-2xs;
  }
}

// Skeleton rows
.entity-files-tab__folder-skeleton {
  padding: $space-3 $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-1;
  border-bottom: 1px solid var(--p-surface-100);
}

.entity-files-tab__folders-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  padding: $space-6;
  text-align: center;

  p {
    font-size: $font-size-sm;
    color: $surface-400;
    margin: 0;
  }
}

.entity-files-tab__folders-empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-300;
}

// ── Right panel: files ────────────────────────────────────────────────────────

.entity-files-tab__files {
  flex: 1;
  overflow-y: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

.entity-files-tab__file-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-100);
  transition: background var(--app-transition-fast);

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }
}

.entity-files-tab__file-icon {
  font-size: $font-size-base;
  color: $surface-400;
  flex-shrink: 0;
}

.entity-files-tab__file-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px; // stylelint-disable-line scale-unlimited/declaration-strict-value
  min-width: 0;
}

.entity-files-tab__file-name {
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

.entity-files-tab__file-meta {
  font-size: $font-size-2xs;
  color: $surface-400;
}

.entity-files-tab__file-btn {
  display: inline-flex;
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
  text-decoration: none;
  transition: color var(--app-transition-fast), background var(--app-transition-fast);

  i {
    font-size: $font-size-xs;
  }

  &:hover {
    color: $surface-600;
    background: var(--p-surface-100);

    .app-dark & {
      color: var(--p-surface-200);
      background: var(--p-surface-300);
    }
  }

  &--danger:hover {
    color: var(--p-red-500);
    background: var(--p-red-50);

    .app-dark & {
      color: var(--p-red-300);
      background: transparent;
    }
  }

  &--spacer {
    pointer-events: none;
    visibility: hidden;
  }
}

// Skeleton file rows
.entity-files-tab__file-skeleton {
  padding: $space-2 $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-1;
  border-bottom: 1px solid var(--p-surface-100);
}

.entity-files-tab__files-empty {
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

.entity-files-tab__files-empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-300;
}

// ── Create folder dialog ──────────────────────────────────────────────────────

.entity-files-tab__dialog-body {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding-top: $space-2;
}

.entity-files-tab__dialog-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}
</style>
