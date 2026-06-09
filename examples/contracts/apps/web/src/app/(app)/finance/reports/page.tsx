"use client";

import Link from "next/link";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { useMe } from "@/lib/auth";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;
// Роли с capability view_journal (доступ к raw GL-журналу). Должно совпадать с backend.
const GL_ROLES = ["accountant", "cfo", "admin"] as const;

const REPORT_CARDS = [
  {
    href: "/finance/reports/pnl",
    icon: "bi-graph-up-arrow",
    title: "Отчёт о прибылях и убытках",
    subtitle: "P&L",
    description: "Доходы (4xxx) и расходы (5xxx) за период. Нетто-прибыль в функциональной валюте юрлица.",
    color: "text-success",
    bg: "bg-success/10",
  },
  {
    href: "/finance/reports/trial-balance",
    icon: "bi-table",
    title: "Оборотно-сальдовая ведомость",
    subtitle: "ОСВ",
    description: "Дебетовые и кредитовые обороты по каждому GL-счёту. Индикатор балансировки.",
    color: "text-info",
    bg: "bg-info/10",
  },
  {
    href: "/finance/reports/ar-ap",
    icon: "bi-people",
    title: "Дебиторка и кредиторка",
    subtitle: "AR / AP",
    description: "Сальдо расчётов с контрагентами по AR-счетам (1210/1290) и AP-счетам (2110/2210).",
    color: "text-warning",
    bg: "bg-warning/10",
  },
  {
    href: "/finance/reports/gl",
    icon: "bi-journal-bookmark",
    title: "Главная книга",
    subtitle: "GL",
    description: "Полный листинг проводок со строками Дт/Кт. Фильтрация по счёту, периоду, статусу.",
    color: "text-primary",
    bg: "bg-primary-light/10",
    requiresViewJournal: true,
  },
  {
    href: "/finance/reports/recognition",
    icon: "bi-calendar-check",
    title: "Признание выручки",
    subtitle: "MRR",
    description: "План признания выручки по подпискам помесячно. Прогон признания за период и сторно строк.",
    color: "text-info",
    bg: "bg-info/10",
  },
  {
    href: "/finance/reports/vat",
    icon: "bi-percent",
    title: "Отчёт по НДС",
    subtitle: "НДС",
    description: "Книга продаж и книга покупок за период. НДС начисленный, к вычету и к уплате.",
    color: "text-warning",
    bg: "bg-warning/10",
  },
  {
    href: "/finance/reports/ar-aging",
    icon: "bi-arrow-up-right-circle",
    title: "Дебиторка Aging",
    subtitle: "AR",
    description: "Неоплаченные инвойсы по бакетам возраста долга: current, 0–30, 31–60, 61–90, 90+.",
    color: "text-success",
    bg: "bg-success/10",
  },
  {
    href: "/finance/reports/ap-aging",
    icon: "bi-arrow-down-right-circle",
    title: "Кредиторка Aging",
    subtitle: "AP",
    description: "Неоплаченные счета поставщиков по бакетам возраста долга. Заменяет Ф1 сальдо AP.",
    color: "text-danger",
    bg: "bg-danger/10",
  },
];

export default function ReportsHubPage() {
  const { user } = useMe();
  const canViewJournal = user != null && (GL_ROLES as readonly string[]).includes(user.role);
  const cards = REPORT_CARDS.filter((c) => !c.requiresViewJournal || canViewJournal);

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Финансовые отчёты"
          description="Аналитические срезы по GL: P&L, ОСВ, дебиторка/кредиторка, главная книга"
        />

        <div className="p-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            {cards.map((card) => (
              <Link
                key={card.href}
                href={card.href}
                className="card rounded-2xl p-5 flex flex-col gap-3 lift group"
              >
                <div className={`w-10 h-10 rounded-xl flex items-center justify-center ${card.bg}`}>
                  <i className={`bi ${card.icon} text-xl ${card.color}`} />
                </div>
                <div>
                  <div className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-0.5">
                    {card.subtitle}
                  </div>
                  <h2 className="text-sm font-semibold text-gray-900 dark:text-gray-100 group-hover:text-primary dark:group-hover:text-blue-400 transition-colors leading-tight">
                    {card.title}
                  </h2>
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400 leading-relaxed flex-1">
                  {card.description}
                </p>
                <div className="flex items-center gap-1 text-xs text-primary dark:text-blue-400 group-hover:gap-2 transition-all">
                  Открыть <i className="bi bi-arrow-right" />
                </div>
              </Link>
            ))}
          </div>

          <div className="mt-6 card p-4 flex items-start gap-3 bg-gray-50 dark:bg-gray-900/50 border-gray-200 dark:border-gray-700">
            <i className="bi bi-info-circle text-info mt-0.5" />
            <div className="text-sm text-gray-600 dark:text-gray-400">
              Все отчёты строятся по статусу{" "}
              <span className="font-mono text-xs bg-gray-200 dark:bg-gray-700 px-1 rounded">posted</span>{" "}
              в <strong className="text-gray-700 dark:text-gray-300">функциональной валюте юрлица</strong>.
              Экспорт в Excel — в следующей фазе.
            </div>
          </div>
        </div>
      </div>
    </RoleGate>
  );
}
