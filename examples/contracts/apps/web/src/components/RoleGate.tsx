"use client";

import { useMe } from "@/lib/auth";
import type { UserRole } from "@/lib/types";

interface RoleGateProps {
  /** Роли, которым разрешено видеть children */
  allowed: UserRole[];
  /** Что показать если роль не разрешена (по умолчанию — ничего) */
  fallback?: React.ReactNode;
  children: React.ReactNode;
}

export function RoleGate({ allowed, fallback = null, children }: RoleGateProps) {
  const { user } = useMe();

  if (!user) return null;
  if (!allowed.includes(user.role)) return <>{fallback}</>;

  return <>{children}</>;
}
