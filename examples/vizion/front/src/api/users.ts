import { apiClient } from '@/api/client'
import type {
  CreateUserRequest,
  UpdateUserRequest,
  UserDto,
  UserIframeLinkResponse,
} from '@/api/types'

export interface UsersApi {
  fetchUser(): Promise<UserDto>
  updateUser(user: UpdateUserRequest): Promise<UserDto>
  deleteUser(): Promise<void>
  /**
   * Active company is resolved by backend middleware ResolveActiveCompany —
   * no client-side `company_id` parameter is needed. Reactive scope-keying
   * for `useScopedResource` lives in the composable's `scope:` ref, not in
   * the API signature.
   */
  fetchUsers(): Promise<UserDto[]>
  createUser(data: CreateUserRequest): Promise<UserDto>
  updateUserById(id: number, data: UpdateUserRequest): Promise<UserDto>
  deleteUserById(id: number): Promise<void>
  fetchIframeLink(id: number): Promise<UserIframeLinkResponse>
  regenerateIframeLink(id: number): Promise<UserIframeLinkResponse>
}

export const usersApi: UsersApi = {
  async fetchUser(): Promise<UserDto> {
    const response = await apiClient.get<UserDto>('/api/user')
    return response.data
  },

  async updateUser(user: UpdateUserRequest): Promise<UserDto> {
    const response = await apiClient.put<UserDto>('/api/user', user)
    return response.data
  },

  async deleteUser(): Promise<void> {
    await apiClient.delete('/api/user')
  },

  async fetchUsers(): Promise<UserDto[]> {
    const response = await apiClient.get<UserDto[]>('/api/users')
    return response.data
  },

  async createUser(data: CreateUserRequest): Promise<UserDto> {
    const response = await apiClient.post<UserDto>('/api/users', data)
    return response.data
  },

  async updateUserById(id: number, data: UpdateUserRequest): Promise<UserDto> {
    const response = await apiClient.put<UserDto>(`/api/users/${id}`, data)
    return response.data
  },

  async deleteUserById(id: number): Promise<void> {
    await apiClient.delete(`/api/users/${id}`)
  },

  async fetchIframeLink(id: number): Promise<UserIframeLinkResponse> {
    const response = await apiClient.get<UserIframeLinkResponse>(`/api/users/${id}/iframe-link`)
    return response.data
  },

  async regenerateIframeLink(id: number): Promise<UserIframeLinkResponse> {
    const response = await apiClient.post<UserIframeLinkResponse>(
      `/api/users/${id}/iframe-link/regenerate`,
    )
    return response.data
  },
}

export type { CreateUserRequest, UpdateUserRequest, UserDto, UserIframeLinkResponse }
