"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function ProfileSignatureRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/profile?tab=signature");
  }, [router]);
  return null;
}
