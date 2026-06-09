"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function ContactPositionsRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/references?tab=contact-positions");
  }, [router]);
  return null;
}
