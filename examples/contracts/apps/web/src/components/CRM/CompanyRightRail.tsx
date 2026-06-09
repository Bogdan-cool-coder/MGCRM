"use client";

import { api, ApiError } from "@/lib/api";
import { UserSelect } from "@/components/UserSelect";
import { CategoryBadge } from "@/components/CategoryBadge";
import type { Company, User } from "@/lib/types";

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  company: Company;
  employeeCount: number;
  dealCount: number;
  editMode: boolean;
  onSaved: () => void;
  users?: User[];
  /** Wave 5: имя типа компании (резолвится на странице из /admin/company-types). */
  companyTypeName?: string | null;
  /** Wave 5: имя источника (резолвится на странице из /sources). */
  sourceName?: string | null;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function CompanyRightRail({ company, employeeCount, dealCount, editMode, onSaved, users, companyTypeName, sourceName }: Props) {
  const ownerName = company.owner_user_id && users
    ? (users.find((u) => u.id === company.owner_user_id)?.full_name ?? `#${company.owner_user_id}`)
    : null;

  async function patchOwner(userId: string) {
    try {
      await api(`/companies/${company.id}`, {
        method: "PATCH",
        body: { owner_user_id: userId ? Number(userId) : null },
      });
      onSaved();
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить";
      alert(msg);
    }
  }

  const displayName = company.short_name ?? company.name ?? company.legal_name;

  return (
    <aside className="hidden lg:flex w-72 shrink-0 border-l border-gray-200 dark:border-gray-700 flex-col">
      <div className="p-5 space-y-5">
        {/* Logo placeholder + name */}
        <div className="flex flex-col items-center gap-2 pb-4 border-b border-gray-200 dark:border-gray-700 text-center">
          <i className="bi bi-building text-5xl text-gray-300 dark:text-gray-600" />
          <div className="font-semibold text-gray-900 dark:text-gray-100">{displayName}</div>
          {company.category_code && (
            <CategoryBadge code={company.category_code} />
          )}
          {company.legal_name && company.legal_name !== displayName && (
            <div className="text-xs text-gray-500 dark:text-gray-400 text-center">{company.legal_name}</div>
          )}
        </div>

        {/* Contacts block */}
        <div className="space-y-2">
          <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Контакты</div>
          {company.phone ? (
            <a href={`tel:${company.phone}`} className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 hover:text-primary">
              <i className="bi bi-telephone shrink-0 text-gray-400" />
              {company.phone}
            </a>
          ) : (
            <div className="flex items-center gap-2 text-sm text-gray-400">
              <i className="bi bi-telephone shrink-0" /> —
            </div>
          )}
          {company.email ? (
            <a href={`mailto:${company.email}`} className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 hover:text-primary truncate">
              <i className="bi bi-envelope shrink-0 text-gray-400" />
              {company.email}
            </a>
          ) : (
            <div className="flex items-center gap-2 text-sm text-gray-400">
              <i className="bi bi-envelope shrink-0" /> —
            </div>
          )}
          {company.website && (
            <a
              href={`https://${company.website.replace(/^https?:\/\//, "")}`}
              target="_blank"
              rel="noreferrer"
              className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 hover:text-primary truncate"
            >
              <i className="bi bi-globe shrink-0 text-gray-400" />
              {company.website}
            </a>
          )}
        </div>

        {/* Responsible */}
        <div className="space-y-1">
          <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ответственный</div>
          {editMode ? (
            <UserSelect
              className="input text-sm"
              value={company.owner_user_id ? String(company.owner_user_id) : ""}
              onChange={(v) => void patchOwner(v)}
              users={users}
            />
          ) : (
            <div className="text-sm text-gray-700 dark:text-gray-300">
              {ownerName ?? "—"}
            </div>
          )}
        </div>

        {/* Company type */}
        {company.company_type_id && (
          <div className="space-y-1">
            <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Тип компании</div>
            <div className="text-sm text-gray-700 dark:text-gray-300">
              {companyTypeName ?? `#${company.company_type_id}`}
            </div>
          </div>
        )}

        {/* Source */}
        {company.source && (
          <div className="space-y-1">
            <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Источник</div>
            <div className="text-sm text-gray-700 dark:text-gray-300">
              {sourceName ?? company.source}
            </div>
          </div>
        )}

        {/* Tags */}
        {company.tags && company.tags.length > 0 && (
          <div className="space-y-1">
            <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Теги</div>
            <div className="flex flex-wrap gap-1">
              {company.tags.map((tag) => (
                <span key={tag} className="badge text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-2 py-0.5 rounded-full">
                  {tag}
                </span>
              ))}
            </div>
          </div>
        )}

        {/* Stats */}
        <div className="space-y-1">
          <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Статистика</div>
          <div className="text-sm text-gray-700 dark:text-gray-300">
            {employeeCount} сотрудников · {dealCount} сделок
          </div>
        </div>
      </div>
    </aside>
  );
}
