import type { UserRole } from '@/entities/user'
import { hasRoleAccess } from '@/shared/auth/capabilities'

export type { UserRole }
export { hasRoleAccess }
