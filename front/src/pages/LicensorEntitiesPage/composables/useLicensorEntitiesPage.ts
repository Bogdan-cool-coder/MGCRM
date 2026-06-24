/**
 * LicensorEntitiesPage composable.
 * Restricted to admin | lawyer | director.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { licensorsApi } from '@/api/licensors'
import { useUserStore } from '@/stores/user'
import type {
  LicensorEntityDto,
  PatchLicensorEntityPayload,
  LicensorBankAccountDto,
  StoreLicensorBankAccountPayload,
  PatchLicensorBankAccountPayload,
} from '@/entities/licensor'

export const useLicensorEntitiesPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const userStore = useUserStore()

  // ─── List ──────────────────────────────────────────────────────────────────
  const resource = useAsyncResource<LicensorEntityDto[]>(() => [])
  const licensors = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  async function fetchLicensors() {
    await resource.run(() => licensorsApi.getLicensors())
  }

  void fetchLicensors()

  // ─── Edit entity dialog ────────────────────────────────────────────────────
  const editDialogVisible = ref(false)
  const editingLicensor = ref<LicensorEntityDto | null>(null)

  function openEdit(licensor: LicensorEntityDto) {
    editingLicensor.value = licensor
    editDialogVisible.value = true
  }

  const editMutation = useMutation<LicensorEntityDto>()

  async function saveLicensor(payload: PatchLicensorEntityPayload) {
    if (!editingLicensor.value) return
    await editMutation.run(
      () => licensorsApi.patchLicensor(editingLicensor.value!.id, payload),
      {
        onSuccess: () => {
          editDialogVisible.value = false
          void fetchLicensors()
          toast.add({ severity: 'success', summary: t('common.save'), life: 2000 })
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        },
      },
    )
  }

  // ─── Bank accounts dialog ──────────────────────────────────────────────────
  const bankDialogVisible = ref(false)
  const bankDialogLicensor = ref<LicensorEntityDto | null>(null)
  const editingAccount = ref<LicensorBankAccountDto | null>(null)

  function openBankDialog(licensor: LicensorEntityDto, account: LicensorBankAccountDto | null = null) {
    bankDialogLicensor.value = licensor
    editingAccount.value = account
    bankDialogVisible.value = true
  }

  const bankMutation = useMutation<LicensorBankAccountDto>()

  async function saveAccount(payload: StoreLicensorBankAccountPayload | PatchLicensorBankAccountPayload) {
    if (!bankDialogLicensor.value) return
    await bankMutation.run(
      async () => {
        if (editingAccount.value) {
          return licensorsApi.patchLicensorBankAccount(
            editingAccount.value.id,
            payload as PatchLicensorBankAccountPayload,
          )
        }
        return licensorsApi.createLicensorBankAccount(
          bankDialogLicensor.value!.id,
          payload as StoreLicensorBankAccountPayload,
        )
      },
      {
        onSuccess: () => {
          bankDialogVisible.value = false
          void fetchLicensors()
          toast.add({ severity: 'success', summary: t('common.save'), life: 2000 })
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        },
      },
    )
  }

  async function deleteAccount(account: LicensorBankAccountDto) {
    try {
      await licensorsApi.deleteLicensorBankAccount(account.id)
      void fetchLicensors()
      toast.add({ severity: 'success', summary: t('common.delete', 'Удалено'), life: 2000 })
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  const canWrite = computed(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'lawyer'
  })

  const canDeleteAccount = computed(() => userStore.getUserRole === 'admin')

  return {
    t,
    licensors,
    loading,
    editDialogVisible,
    editingLicensor,
    openEdit,
    editMutation,
    saveLicensor,
    bankDialogVisible,
    bankDialogLicensor,
    editingAccount,
    openBankDialog,
    bankMutation,
    saveAccount,
    deleteAccount,
    canWrite,
    canDeleteAccount,
  }
}
