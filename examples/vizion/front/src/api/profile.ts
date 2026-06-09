import { apiClient } from '@/api/client'
import type { SetHomePathRequest, SetHomePathResponse } from '@/api/types'

export interface ProfileApi {
  /**
   * Persist the current user's home page.
   * `PUT /api/profile/home` body `{ path }` → `{ home_path }`.
   * Backend validates that `path` is a relative route (single leading `/`,
   * not `//`, whitelisted characters); absolute URLs return 422.
   */
  setHomePath(data: SetHomePathRequest): Promise<SetHomePathResponse>
}

export const profileApi: ProfileApi = {
  async setHomePath(data: SetHomePathRequest): Promise<SetHomePathResponse> {
    const response = await apiClient.put<SetHomePathResponse>('/api/profile/home', data)
    return response.data
  },
}

export type { SetHomePathRequest, SetHomePathResponse }
