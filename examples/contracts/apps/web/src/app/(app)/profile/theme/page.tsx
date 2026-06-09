"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function ProfileThemeRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/profile?tab=theme");
  }, [router]);
  return null;
}
