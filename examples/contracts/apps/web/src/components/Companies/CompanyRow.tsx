"use client";

import React from "react";
import { CategoryBadge } from "@/components/CategoryBadge";
import type { Company } from "@/lib/types";

interface CompanyRowProps {
  company: Company;
  groupName: string;
  onOpen: (company: Company) => void;
  onDelete: (company: Company) => void;
  canDelete: boolean;
}

function CompanyRowBase({ company, groupName, onOpen, onDelete, canDelete }: CompanyRowProps) {
  return (
    <tr
      className="border-t border-gray-200 hover:bg-gray-50 cursor-pointer"
      onClick={() => onOpen(company)}
    >
      <td className="px-4 py-3 font-medium">
        <div>{company.legal_name}</div>
        {company.short_name && (
          <div className="text-xs text-gray-500">{company.short_name}</div>
        )}
      </td>
      <td className="px-4 py-3 uppercase text-sm text-gray-700">
        {company.country ?? "—"}
      </td>
      <td className="px-4 py-3 text-sm text-gray-700">
        {company.city ?? "—"}
      </td>
      <td className="px-4 py-3 text-sm text-gray-700">{company.tax_id ?? "—"}</td>
      <td className="px-4 py-3">
        <CategoryBadge code={company.category_code} />
      </td>
      <td className="px-4 py-3 text-sm text-gray-700">{groupName || "—"}</td>
      <td className="px-4 py-3 text-right text-primary whitespace-nowrap">
        <button
          onClick={(e) => { e.stopPropagation(); onOpen(company); }}
          title="Открыть карточку"
        >
          <i className="bi bi-arrow-up-right-square" />
        </button>
        {canDelete && (
          <button
            onClick={(e) => { e.stopPropagation(); onDelete(company); }}
            title="Удалить компанию"
            className="ml-3 text-danger"
          >
            <i className="bi bi-trash" />
          </button>
        )}
      </td>
    </tr>
  );
}

export const CompanyRow = React.memo(CompanyRowBase);
