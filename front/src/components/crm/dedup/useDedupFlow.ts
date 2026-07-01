import { ref, watch, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { dedupApi, type DedupGroup } from '@/api/crm/dedup'
import { useMutation } from '@/composables/async/useMutation'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getApiErrorMessage } from '@/utils/errors'
import type { DedupScope, DedupCandidate } from '@/entities/crm'

export type DedupStep = 'scan' | 'candidates' | 'merge'

// Per-scope field whitelists (match backend contract)
const CONTACT_FIELD_KEYS: string[] = [
  'full_name',
  'position',
  'email',
  'phone',
  'tg_username',
  'notes',
  'source',
]

const COMPANY_FIELD_KEYS: string[] = [
  'name',
  'legal_name',
  'short_name',
  'tax_id',
  'city',
  'address',
  'website',
  'phone',
  'email',
  'notes',
  'source',
]

export const useDedupFlow = (opts: {
  onMerged?: () => void
  mode?: 'dedup' | 'bulk'
  bulkEntities?: Ref<DedupCandidate[]>
  entityType?: Ref<DedupScope>
} = {}) => {
  const { t } = useI18n()
  const toast = useToast()

  const isBulk = opts.mode === 'bulk'

  const step = ref<DedupStep>(isBulk ? 'merge' : 'scan')
  const scope = ref<DedupScope>(
    isBulk && opts.entityType ? opts.entityType.value : 'company',
  )

  const scanResource = useAsyncResource<DedupGroup[]>([])
  const selectedGroup = ref<DedupGroup | null>(null)
  const masterId = ref<number | null>(null)

  // Per-field source selection: key → source entity id
  const fieldOverrides = ref<Record<string, number>>({})

  const mergeMutation = useMutation<void>()
  const dismissMutation = useMutation<void>()

  const mergeError = ref<string>('')

  // ── Bulk init ─────────────────────────────────────────────────────────────────

  if (isBulk && opts.bulkEntities) {
    watch(
      opts.bulkEntities,
      (entities) => {
        if (entities.length >= 2) {
          const entityType = opts.entityType?.value ?? 'contact'
          scope.value = entityType
          selectedGroup.value = {
            key: '__bulk__',
            entities,
          }
          masterId.value = entities[0]?.id ?? null
          resetFieldOverridesToMaster(masterId.value ?? 0, entityType)
        }
      },
      { immediate: true },
    )

    if (opts.entityType) {
      watch(opts.entityType, (et) => {
        scope.value = et
      })
    }
  }

  // ── Scan (dedup mode only) ────────────────────────────────────────────────────

  async function scan() {
    mergeError.value = ''
    try {
      await scanResource.run(() => dedupApi.scan(scope.value))
      step.value = 'candidates'
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('dedup.dialog.scan.error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  // ── Field keys & labels ───────────────────────────────────────────────────────

  function getFieldKeys(candidate: DedupCandidate): string[] {
    if (candidate.type === 'contact') return CONTACT_FIELD_KEYS
    return COMPANY_FIELD_KEYS
  }

  function getFieldLabel(key: string): string {
    const map: Record<string, string> = {
      full_name: 'ФИО',
      position: 'Должность',
      name: 'Название',
      legal_name: 'Юр. наименование',
      short_name: 'Краткое название',
      tax_id: 'БИН/ИНН',
      city: 'Город',
      address: 'Адрес',
      website: 'Сайт',
      email: 'E-mail',
      phone: 'Телефон',
      tg_username: 'Telegram',
      notes: 'Примечания',
      source: 'Источник',
      status: 'Статус',
    }
    return map[key] ?? key
  }

  function getCandidateFieldValue(candidate: DedupCandidate, key: string): string {
    const val = (candidate as unknown as Record<string, unknown>)[key]
    if (val === null || val === undefined || val === '') return ''
    return String(val)
  }

  // ── Field overrides ───────────────────────────────────────────────────────────

  function resetFieldOverridesToMaster(masterEntityId: number, scopeHint?: DedupScope) {
    const resolvedScope = scopeHint ?? scope.value
    const keys = resolvedScope === 'contact' ? CONTACT_FIELD_KEYS : COMPANY_FIELD_KEYS
    const overrides: Record<string, number> = {}
    for (const key of keys) {
      overrides[key] = masterEntityId
    }
    fieldOverrides.value = overrides
  }

  function setFieldOverride(key: string, entityId: number) {
    fieldOverrides.value = { ...fieldOverrides.value, [key]: entityId }
  }

  // Watch masterId changes to reset per-field overrides
  watch(masterId, (newMasterId) => {
    if (newMasterId !== null) {
      resetFieldOverridesToMaster(newMasterId)
    }
  })

  // ── Select group (dedup → merge) ──────────────────────────────────────────────

  function selectGroup(group: DedupGroup) {
    selectedGroup.value = group
    const firstId = group.entities[0]?.id ?? null
    masterId.value = firstId
    if (firstId !== null) resetFieldOverridesToMaster(firstId)
    step.value = 'merge'
  }

  // ── Merge submit ──────────────────────────────────────────────────────────────

  async function submitMerge() {
    if (!selectedGroup.value || !masterId.value) return
    const duplicateIds = selectedGroup.value.entities
      .filter((e) => e.id !== masterId.value)
      .map((e) => e.id)
    if (duplicateIds.length === 0) return
    mergeError.value = ''

    await mergeMutation.run(
      () =>
        dedupApi.merge({
          scope: scope.value,
          master_id: masterId.value!,
          duplicate_ids: duplicateIds,
          field_overrides: fieldOverrides.value,
        }),
      {
        onSuccess() {
          toast.add({
            severity: 'success',
            summary: t('dedup.dialog.merge.success'),
            life: 4000,
          })
          if (!isBulk) {
            scanResource.data.value = scanResource.data.value.filter(
              (g) => g.key !== selectedGroup.value!.key,
            )
            step.value = 'candidates'
            selectedGroup.value = null
          }
          opts.onMerged?.()
        },
        onError(err) {
          mergeError.value = getApiErrorMessage(err, t('dedup.dialog.merge.error'))
        },
      },
    )
  }

  // ── Per-pair dismiss ──────────────────────────────────────────────────────────

  async function dismissPair(entityAId: number, entityBId: number, groupKey: string) {
    await dismissMutation.run(
      () =>
        dedupApi.dismiss({
          scope: scope.value,
          entity_a_id: entityAId,
          entity_b_id: entityBId,
        }),
      {
        onSuccess() {
          toast.add({
            severity: 'success',
            summary: t('dedup.dialog.merge.dismissSuccess'),
            life: 4000,
          })

          const groupIdx = scanResource.data.value.findIndex((g) => g.key === groupKey)
          if (groupIdx === -1) return

          const group = scanResource.data.value[groupIdx]!
          if (group.entities.length <= 2) {
            // Remove the whole group
            scanResource.data.value = scanResource.data.value.filter(
              (g) => g.key !== groupKey,
            )
          } else {
            // Remove entity B from the group (entity B is "not a dup of A")
            scanResource.data.value = scanResource.data.value.map((g) =>
              g.key === groupKey
                ? { ...g, entities: g.entities.filter((e) => e.id !== entityBId) }
                : g,
            )
          }
        },
        onError(err) {
          toast.add({
            severity: 'error',
            summary: t('dedup.dialog.merge.error'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        },
      },
    )
  }

  // ── Navigation ────────────────────────────────────────────────────────────────

  function goBack() {
    if (step.value === 'merge') {
      step.value = 'candidates'
      selectedGroup.value = null
    } else {
      step.value = 'scan'
      scanResource.reset([])
    }
  }

  function reset() {
    mergeError.value = ''
    if (isBulk) {
      // Bulk: reset to initial merge step but keep selectedGroup/masterId populated
      // so re-opening with same bulkEntities doesn't show a blank skeleton.
      // Re-initialise from bulkEntities in case watch didn't re-fire (same ref value).
      step.value = 'merge'
      const entities = opts.bulkEntities?.value ?? []
      if (entities.length >= 2) {
        const entityType = opts.entityType?.value ?? 'contact'
        scope.value = entityType
        selectedGroup.value = { key: '__bulk__', entities }
        masterId.value = entities[0]?.id ?? null
        resetFieldOverridesToMaster(masterId.value ?? 0, entityType)
      } else {
        selectedGroup.value = null
        masterId.value = null
        fieldOverrides.value = {}
      }
    } else {
      step.value = 'scan'
      scanResource.reset([])
      selectedGroup.value = null
      masterId.value = null
      fieldOverrides.value = {}
    }
  }

  return {
    step,
    scope,
    groups: scanResource.data,
    scanning: scanResource.loading,
    selectedGroup,
    masterId,
    fieldOverrides,
    isMerging: mergeMutation.isPending,
    isDismissing: dismissMutation.isPending,
    mergeError,
    scan,
    selectGroup,
    submitMerge,
    dismissPair,
    goBack,
    reset,
    getFieldKeys,
    getFieldLabel,
    getCandidateFieldValue,
    setFieldOverride,
    resetFieldOverridesToMaster,
  }
}
