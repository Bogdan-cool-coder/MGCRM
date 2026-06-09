import { usersApi } from '@/api/users'
import { profileApi } from '@/api/profile'
import { mapUserDtoToUser, normalizeHomePath, type User } from '@/entities/user'
import type {
  CreateUserRequest,
  UpdateUserRequest,
  UserIframeLinkResponse,
} from '@/api/types'

export class UserService {
  async fetchCurrentUser(): Promise<User> {
    return mapUserDtoToUser(await usersApi.fetchUser())
  }

  async fetchCompanyUsers(): Promise<User[]> {
    return (await usersApi.fetchUsers()).map(mapUserDtoToUser)
  }

  async createUser(data: CreateUserRequest): Promise<User> {
    return mapUserDtoToUser(await usersApi.createUser(data))
  }

  async updateUserById(id: number, data: UpdateUserRequest): Promise<User> {
    return mapUserDtoToUser(await usersApi.updateUserById(id, data))
  }

  async deleteUserById(id: number): Promise<void> {
    await usersApi.deleteUserById(id)
  }

  async fetchIframeLink(id: number): Promise<UserIframeLinkResponse> {
    return await usersApi.fetchIframeLink(id)
  }

  async regenerateIframeLink(id: number): Promise<UserIframeLinkResponse> {
    return await usersApi.regenerateIframeLink(id)
  }

  async updateCurrentUserLocale(locale: User['locale']): Promise<User> {
    return mapUserDtoToUser(await usersApi.updateUser({ locale }))
  }

  async setHomePath(path: string): Promise<string> {
    const response = await profileApi.setHomePath({ path })
    return normalizeHomePath(response.home_path)
  }
}
