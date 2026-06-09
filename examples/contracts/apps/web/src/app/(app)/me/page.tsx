"use client";

import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { MePageHeader } from "@/components/Me/MePageHeader";
import { StatsBar } from "@/components/Me/StatsBar";
import { SummaryTab } from "@/components/Me/tabs/SummaryTab";
import { MotivationalCardTab } from "@/components/Me/tabs/MotivationalCardTab";
import { MetricsTab } from "@/components/Me/tabs/MetricsTab";
import { SubordinatesTab } from "@/components/Me/tabs/SubordinatesTab";
import { ActivityTab } from "@/components/Me/tabs/ActivityTab";
import { useMe } from "@/lib/auth";
import type { Period } from "@/components/Me/StatsBar";

type TabId = "summary" | "mk" | "metrics" | "subordinates" | "activity";

const TABS: { id: TabId; label: string }[] = [
  { id: "summary", label: "Сводка" },
  { id: "mk", label: "МК" },
  { id: "metrics", label: "Метрики" },
  { id: "subordinates", label: "Команда" },
  { id: "activity", label: "Активность" },
];

export default function MePage() {
  const searchParams = useSearchParams();
  const userIdParam = searchParams.get("user_id");
  const userId = userIdParam ? Number(userIdParam) : undefined;
  const tabParam = searchParams.get("tab") as TabId | null;

  const { user } = useMe();
  const [activeTab, setActiveTab] = useState<TabId>(tabParam ?? "summary");
  const [period, setPeriod] = useState<Period>("current_month");

  const isOwnProfile = !userId || userId === user?.id;
  const hasSubordinates = (user?.role === "admin" || user?.role === "director");
  const canTrain = user?.role === "admin" || user?.role === "director" || user?.role === "manager";

  const visibleTabs = TABS.filter((t) => {
    if (t.id === "subordinates" && !hasSubordinates) return false;
    return true;
  });

  return (
    <>
      <PageHeader
        title={isOwnProfile ? "Кабинет" : "Кабинет менеджера"}
        description="личная статистика и активность"
        sticky
        actions={
          isOwnProfile && canTrain ? (
            <a href="/me/training" className="btn-secondary text-sm">
              <i className="bi bi-telephone-fill mr-1" />
              Тренажёр звонков
            </a>
          ) : undefined
        }
      />

      <div className="p-8 space-y-6">
        {/* Header с аватаром */}
        <MePageHeader userId={userId} />

        {/* Stats Bar — KPI-карточки */}
        <StatsBar period={period} onPeriodChange={setPeriod} userId={userId} />

        {/* Tabs navigation */}
        <div className="border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
          <div className="flex whitespace-nowrap">
            {visibleTabs.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={
                  "py-2.5 px-5 text-sm font-medium transition-colors border-b-2 " +
                  (activeTab === tab.id
                    ? "border-primary text-primary"
                    : "border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300")
                }
              >
                {tab.label}
              </button>
            ))}
          </div>
        </div>

        {/* Tab content */}
        <div>
          {activeTab === "summary" && <SummaryTab period={period} userId={userId} />}
          {activeTab === "mk" && <MotivationalCardTab userId={userId} />}
          {activeTab === "metrics" && <MetricsTab userId={userId} />}
          {activeTab === "subordinates" && hasSubordinates && (
            <SubordinatesTab period={period} />
          )}
          {activeTab === "activity" && <ActivityTab userId={userId} />}
        </div>
      </div>
    </>
  );
}
