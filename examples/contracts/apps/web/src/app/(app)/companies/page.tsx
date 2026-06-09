import { redirect } from "next/navigation";

/**
 * CONTACTS 2.0 Фаза 2a: Список компаний перемещён в единый /contacts.
 * Редирект для обратной совместимости.
 */
export default function CompaniesPage() {
  redirect("/contacts?type=company");
}
