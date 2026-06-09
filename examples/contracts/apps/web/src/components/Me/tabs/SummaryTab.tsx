"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { ActiveDealsWidget } from "../widgets/ActiveDealsWidget";
import { TodayTasksWidget } from "../widgets/TodayTasksWidget";
import { MonthProgressWidget } from "../widgets/MonthProgressWidget";
import { NotificationsWidget } from "../widgets/NotificationsWidget";
import type { MeDashboard } from "@/lib/types";

interface Props {
  period: string;
  userId?: number;
}

export function SummaryTab({ period, userId }: Props) {
  const key = `/me/dashboard?period=${period}${userId ? `&user_id=${userId}` : ""}`;
  const { data, isLoading } = useSWR<MeDashboard>(key, fetcher);

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
      <ActiveDealsWidget deals={data?.active_deals} isLoading={isLoading} />
      <TodayTasksWidget tasks={data?.today_tasks} isLoading={isLoading} swrKey={key} />
      <MonthProgressWidget userId={userId} />
      <NotificationsWidget />
    </div>
  );
}
