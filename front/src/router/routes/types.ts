import type { UserRole } from '@/entities/user'
import 'vue-router'

// Расширяем RouteMeta для типобезопасности
declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    roles?: UserRole[]
    title?: string
  }
}
