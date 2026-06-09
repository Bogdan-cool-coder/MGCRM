"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function ProfileLocaleRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/profile?tab=locale");
  }, [router]);
  return null;
}
