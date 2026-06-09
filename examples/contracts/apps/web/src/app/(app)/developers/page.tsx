"use client";

import { PageHeader } from "@/components/PageHeader";
import { QuickStartSection } from "@/components/Developers/QuickStartSection";
import { OpenAPIEmbed } from "@/components/Developers/OpenAPIEmbed";
import { CodeTabs } from "@/components/Developers/CodeTabs";
import { WebhooksGuideSection } from "@/components/Developers/WebhooksGuideSection";
import { OAuthGuideSection } from "@/components/Developers/OAuthGuideSection";

export const dynamic = "force-dynamic";

export default function DevelopersPage() {
  return (
    <>
      <PageHeader
        title="MACRO CRM API & Integrations"
        description="Документация для разработчиков интеграций"
        actions={
          <button
            className="btn-secondary"
            onClick={() => window.open("/api/docs", "_blank")}
          >
            <i className="bi bi-box-arrow-up-right mr-1" />
            Открыть Swagger
          </button>
        }
      />
      <div className="px-8 py-6 space-y-6 max-w-6xl">
        <QuickStartSection />
        <OpenAPIEmbed />
        <CodeTabs />
        <WebhooksGuideSection />
        <OAuthGuideSection />
      </div>
    </>
  );
}
