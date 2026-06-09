import { redirect } from "next/navigation";

// Alias: /documents → /contracts
// Роут /contracts является каноническим. Эта страница нужна только для обратной
// совместимости будущих ссылок вида /documents (например, из автоматизаций или API).
export default function DocumentsPage() {
  redirect("/contracts");
}
