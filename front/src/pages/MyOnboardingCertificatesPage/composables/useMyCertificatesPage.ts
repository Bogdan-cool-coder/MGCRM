import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { onboardingStudentApi } from '@/api/onboardingStudent'
import type { Certificate } from '@/entities/certificate'
import { useToast } from 'primevue/usetoast'
import { useI18n } from 'vue-i18n'

export function useMyCertificatesPage() {
  const certificates = useAsyncResource<Certificate[]>([])
  const downloadMutation = useMutation<void>()
  const toast = useToast()
  const { t } = useI18n()

  async function load(): Promise<void> {
    await certificates.run(() => onboardingStudentApi.getMyCertificates())
  }

  async function downloadCertificate(assignmentId: number, certNumber: string): Promise<void> {
    await downloadMutation.run(
      async () => {
        const blob = await onboardingStudentApi.downloadCertificate(assignmentId)
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `certificate-${certNumber}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      },
      {
        onError: () => {
          toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
        },
      },
    )
  }

  return {
    loading: certificates.loading,
    error: certificates.error,
    certificates: certificates.data,
    load,
    downloadCertificate,
    downloading: downloadMutation.isPending,
  }
}
