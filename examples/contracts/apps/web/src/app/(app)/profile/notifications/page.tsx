"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function ProfileNotificationsRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/profile?tab=notifications");
  }, [router]);
  return null;
}
