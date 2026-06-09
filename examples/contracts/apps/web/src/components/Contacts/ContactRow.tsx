"use client";

import React from "react";
import type { Contact } from "@/lib/types";

interface ContactRowProps {
  contact: Contact;
  companyName: string;
  ownerName: string;
  /** Переход на детальную страницу контакта */
  onView: (contact: Contact) => void;
  /** Открыть модалку редактирования */
  onEdit: (contact: Contact) => void;
  onDelete: (contact: Contact) => void;
  canDelete: boolean;
}

function ContactRowBase({
  contact, companyName, ownerName, onView, onEdit, onDelete, canDelete,
}: ContactRowProps) {
  return (
    <tr
      className="border-t border-gray-200 hover:bg-gray-50 cursor-pointer"
      onClick={() => onView(contact)}
    >
      <td className="px-4 py-3 font-medium">
        <div className="flex items-center gap-2">
          <span>{contact.full_name}</span>
          {contact.is_primary && (
            <span className="text-[10px] uppercase tracking-wide bg-primary/10 text-primary px-1.5 py-0.5 rounded">
              основной
            </span>
          )}
        </div>
        {contact.position && (
          <div className="text-xs text-gray-500">{contact.position}</div>
        )}
      </td>
      <td className="px-4 py-3 text-sm text-gray-700">{companyName || "—"}</td>
      <td className="px-4 py-3 text-sm">
        <div className="flex flex-col leading-tight">
          {contact.email && (
            <span className="text-gray-800 truncate" title={contact.email}>
              {contact.email}
            </span>
          )}
          {contact.phone && (
            <span className="text-gray-500 text-xs">{contact.phone}</span>
          )}
          {!contact.email && !contact.phone && <span className="text-gray-400">—</span>}
        </div>
      </td>
      <td className="px-4 py-3 text-sm text-gray-700">{ownerName || "—"}</td>
      <td className="px-4 py-3 text-right text-primary whitespace-nowrap">
        <button
          onClick={(e) => { e.stopPropagation(); onEdit(contact); }}
          title="Редактировать"
        >
          <i className="bi bi-pencil" />
        </button>
        {canDelete && (
          <button
            onClick={(e) => { e.stopPropagation(); onDelete(contact); }}
            title="Удалить контакт"
            className="ml-3 text-danger"
          >
            <i className="bi bi-trash" />
          </button>
        )}
      </td>
    </tr>
  );
}

export const ContactRow = React.memo(ContactRowBase);
