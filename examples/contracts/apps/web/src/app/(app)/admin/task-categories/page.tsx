"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function TaskCategoriesRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/references?tab=task-categories");
  }, [router]);
  return null;
}
