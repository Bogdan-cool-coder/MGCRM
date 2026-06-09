import { redirect } from "next/navigation";

// Alias: /documents/[id] → /contracts/[id]
// Роут /contracts/[id] является каноническим. Эта страница нужна только для
// обратной совместимости будущих ссылок вида /documents/123.
export default function DocumentPage({ params }: { params: { id: string } }) {
  redirect(`/contracts/${params.id}`);
}
