import { redirect } from "next/navigation";

/**
 * CONTACTS 2.0 Фаза 2a: Список контрагентов перемещён в единый /contacts.
 * Редирект для обратной совместимости. Детальные карточки /counterparties/[id] — НЕ тронуты.
 */
export default function CounterpartiesPage() {
  redirect("/contacts?type=company");
}
