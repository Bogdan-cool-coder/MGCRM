"use client";

import type { APITokenScope } from "@/lib/types";

interface ScopeBadgeProps {
  scope: APITokenScope | string;
}

function scopeClass(scope: string): string {
  if (scope === "*") return "bg-primary text-white";
  if (scope.startsWith("read:")) return "bg-info/10 text-info";
  if (scope.startsWith("write:")) return "bg-warning/10 text-warning";
  return "bg-gray-100 text-gray-600";
}

function scopeLabel(scope: string): string {
  if (scope === "*") return "Полный доступ";
  if (scope.startsWith("read:")) return scope.slice("read:".length);
  if (scope.startsWith("write:")) return scope.slice("write:".length);
  return scope;
}

export function ScopeBadge({ scope }: ScopeBadgeProps) {
  return (
    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ${scopeClass(scope)}`}>
      {scopeLabel(scope)}
    </span>
  );
}

interface ScopeBadgeListProps {
  scopes: string[];
  max?: number;
}

export function ScopeBadgeList({ scopes, max = 3 }: ScopeBadgeListProps) {
  const visible = scopes.slice(0, max);
  const rest = scopes.length - max;
  return (
    <div className="flex flex-wrap gap-1">
      {visible.map((s) => (
        <ScopeBadge key={s} scope={s} />
      ))}
      {rest > 0 && (
        <span className="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-500">
          +{rest} ещё
        </span>
      )}
    </div>
  );
}
