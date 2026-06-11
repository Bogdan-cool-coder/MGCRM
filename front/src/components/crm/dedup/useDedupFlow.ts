import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { dedupApi, type DedupGroup } from '@/api/crm/dedup'
import { useMutation } from '@/composables/async/useMutation'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getApiErrorMessage } from '@/utils/errors'
import type { DedupScope, DedupCandidate } from '@/entities/crm'

export type DedupStep = 'scan' | 'candidates' | 'merge'

export const useDedupFlow = (opts: { onMerged?: () => void } = {}) => {
  const { t } = useI18n()
  const toast = useToast()

  const step = ref<DedupStep>('scan')
  const scope = ref<DedupScope>('company')

  const scanResource = useAsyncResource<DedupGroup[]>([])
  const selectedGroup = ref<DedupGroup | null>(null)
  const masterId = ref<number | null>(null)

  const mergeMutation = useMutation<void>()
  const dismissMutation = useMutation<void>()

  const mergeError = ref<string>('')

  async function scan() {
    mergeError.value = ''
    try {
      await scanResource.run(() => dedupApi.scan(scope.value))
      step.value = 'candidates'
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('dedup.dialog.scan.error', 'Ошибка сканирования'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  function selectGroup(group: DedupGroup) {
    selectedGroup.value = group
    // Default master = first entity (newest / highest id or first in list)
    masterId.value = group.entities[0]?.id ?? null
    step.value = 'merge'
  }

  function getFieldKeys(candidate: DedupCandidate): string[] {
    const base = ['email', 'phone', 'source']
    if (candidate.type === 'contact') {
      return ['full_name', ...base, 'status']
    }
    return ['name', 'legal_name', 'tax_id', ...base]
  }

  function getFieldLabel(key: string): string {
    const map: Record<string, string> = {
      full_name: 'ФИО',
      name: 'Название',
      legal_name: 'Юр. наименование',
      tax_id: 'БИН/ИНН',
      email: 'E-mail',
      phone: 'Телефон',
      source: 'Источник',
      status: 'Статус',
    }
    return map[key] ?? key
  }

  function getCandidateFieldValue(candidate: DedupCandidate, key: string): string {
    const val = (candidate as unknown as Record<string, unknown>)[key]
    if (val === null || val === undefined || val === '') return '—'
    return String(val)
  }

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
        }),
      {
        onSuccess() {
          toast.add({
            severity: 'success',
            summary: t('dedup.dialog.merge.success'),
            life: 4000,
          })
          scanResource.data.value = scanResource.data.value.filter(
            (g) => g.key !== selectedGroup.value!.key,
          )
          opts.onMerged?.()
          step.value = 'candidates'
          selectedGroup.value = null
        },
        onError(err) {
          mergeError.value = getApiErrorMessage(err, t('dedup.dialog.merge.error'))
        },
      },
    )
  }

  async function dismissGroup(group: DedupGroup) {
    if (group.entities.length < 2) return

    // For groups > 2: dismiss first pair. Backend handles pairwise dismissal.
    const entityA = group.entities[0]
    const entityB = group.entities[1]
    if (!entityA || !entityB) return
    const entityAId = entityA.id
    const entityBId = entityB.id

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
          scanResource.data.value = scanResource.data.value.filter(
            (g) => g.key !== group.key,
          )
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
    step.value = 'scan'
    scanResource.reset([])
    selectedGroup.value = null
    masterId.value = null
    mergeError.value = ''
  }

  return {
    step,
    scope,
    groups: scanResource.data,
    scanning: scanResource.loading,
    selectedGroup,
    masterId,
    isMerging: mergeMutation.isPending,
    isDismissing: dismissMutation.isPending,
    mergeError,
    scan,
    selectGroup,
    submitMerge,
    dismissGroup,
    goBack,
    reset,
    getFieldKeys,
    getFieldLabel,
    getCandidateFieldValue,
  }
}
