/**
 * Licensor admin API — restricted to admin | lawyer | director.
 */
import { apiClient } from '@/api/client'
import type {
  LicensorEntityDto,
  PatchLicensorEntityPayload,
  LicensorBankAccountDto,
  StoreLicensorBankAccountPayload,
  PatchLicensorBankAccountPayload,
} from '@/entities/licensor'

// ─── Licensor entities ────────────────────────────────────────────────────────

export async function getLicensors(): Promise<LicensorEntityDto[]> {
  const response = await apiClient.get<{ data: LicensorEntityDto[] }>('/api/admin/licensor-entities')
  return response.data.data
}

export async function getLicensor(id: number): Promise<LicensorEntityDto> {
  const response = await apiClient.get<{ data: LicensorEntityDto }>(`/api/admin/licensor-entities/${id}`)
  return response.data.data
}

export async function patchLicensor(
  id: number,
  payload: PatchLicensorEntityPayload,
): Promise<LicensorEntityDto> {
  const response = await apiClient.patch<{ data: LicensorEntityDto }>(
    `/api/admin/licensor-entities/${id}`,
    payload,
  )
  return response.data.data
}

// ─── Bank accounts ────────────────────────────────────────────────────────────

export async function getLicensorBankAccounts(
  licensorId: number,
): Promise<LicensorBankAccountDto[]> {
  const response = await apiClient.get<{ data: LicensorBankAccountDto[] }>(
    `/api/admin/licensor-entities/${licensorId}/bank-accounts`,
  )
  return response.data.data
}

export async function createLicensorBankAccount(
  licensorId: number,
  payload: StoreLicensorBankAccountPayload,
): Promise<LicensorBankAccountDto> {
  const response = await apiClient.post<{ data: LicensorBankAccountDto }>(
    `/api/admin/licensor-entities/${licensorId}/bank-accounts`,
    payload,
  )
  return response.data.data
}

export async function patchLicensorBankAccount(
  accountId: number,
  payload: PatchLicensorBankAccountPayload,
): Promise<LicensorBankAccountDto> {
  const response = await apiClient.patch<{ data: LicensorBankAccountDto }>(
    `/api/admin/bank-accounts/${accountId}`,
    payload,
  )
  return response.data.data
}

export async function deleteLicensorBankAccount(accountId: number): Promise<void> {
  await apiClient.delete(`/api/admin/bank-accounts/${accountId}`)
}

export const licensorsApi = {
  getLicensors,
  getLicensor,
  patchLicensor,
  getLicensorBankAccounts,
  createLicensorBankAccount,
  patchLicensorBankAccount,
  deleteLicensorBankAccount,
}
