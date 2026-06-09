"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { DeliveriesTab } from "@/components/Webhooks/DeliveriesTab";
import type { Webhook } from "@/lib/types";

export function WebhookDeliveriesTab() {
  const { data: webhooks } = useSWR<Webhook[]>("/webhooks", fetcher);
  return <DeliveriesTab webhooks={webhooks ?? []} />;
}
