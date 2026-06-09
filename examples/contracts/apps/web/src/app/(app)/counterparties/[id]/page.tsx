"use client";

/**
 * CONTACTS 2.0 Ф3-C: Редирект /counterparties/[id] → /companies/[company_id].
 *
 * Резолв: GET /api/companies/by-counterparty/{counterparty_id}.
 * Если company не найдена (нет зеркала) — показываем сообщение с fallback.
 * Ф4 (финальная) удалит этот файл полностью.
 */

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import type { Company } from "@/lib/types";

export default function CounterpartyRedirectPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const [notFound, setNotFound] = useState(false);

  useEffect(() => {
    if (!id) return;
    api<Company>(`/companies/by-counterparty/${id}`)
      .then((company) => {
        router.replace(`/companies/${company.id}`);
      })
      .catch((err) => {
        if (err instanceof ApiError && err.status === 404) {
          setNotFound(true);
        } else {
          // Неизвестная ошибка — всё равно показываем not found
          setNotFound(true);
        }
      });
  }, [id, router]);

  if (notFound) {
    return (
      <div className="p-8 text-center">
        <div className="text-gray-400 text-4xl mb-3">
          <i className="bi bi-building-slash" />
        </div>
        <h2 className="text-lg font-semibold text-gray-700 mb-1">Карточка компании не найдена</h2>
        <p className="text-sm text-gray-500 mb-4">
          Контрагент #{id} не связан ни с одной компанией в новом разделе «Контакты».
        </p>
        <a href="/contacts?type=company" className="btn-secondary text-sm">
          <i className="bi bi-arrow-left mr-1" />
          К списку компаний
        </a>
      </div>
    );
  }

  return (
    <div className="p-8 text-gray-400 text-sm flex items-center gap-2">
      <i className="bi bi-arrow-repeat animate-spin" />
      Перенаправление…
    </div>
  );
}
