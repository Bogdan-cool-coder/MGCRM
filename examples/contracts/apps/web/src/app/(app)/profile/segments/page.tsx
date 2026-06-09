"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function ProfileSegmentsRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/profile?tab=segments");
  }, [router]);
  return null;
}
