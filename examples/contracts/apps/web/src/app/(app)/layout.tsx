"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Sidebar } from "@/components/Sidebar";
import { SearchModal } from "@/components/Search/SearchModal";
import { SearchContext } from "@/components/Search/SearchContext";
import { WelcomeWizard } from "@/components/Onboarding/WelcomeWizard";
import { AiAssistantButton } from "@/components/AI/AiAssistantButton";
import { ThemeProvider } from "@/contexts/ThemeContext";
import { ToastProvider } from "@/components/ui/Toast";
import { TooltipProvider } from "@/components/ui/Tooltip";
import { useMe } from "@/lib/auth";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, isLoading, error } = useMe();
  const [searchOpen, setSearchOpen] = useState(false);
  const [aiOpen, setAiOpen] = useState(false);

  const openSearch = useCallback(() => setSearchOpen(true), []);

  // Cmd+K / Ctrl+K listener + custom event from Sidebar
  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") {
        e.preventDefault();
        setSearchOpen(true);
      }
    }
    function onOpenSearch() {
      setSearchOpen(true);
    }
    function onOpenAi() {
      setAiOpen(true);
    }
    window.addEventListener("keydown", onKeyDown);
    window.addEventListener("crm:open-search", onOpenSearch);
    window.addEventListener("crm:open-ai", onOpenAi);
    return () => {
      window.removeEventListener("keydown", onKeyDown);
      window.removeEventListener("crm:open-search", onOpenSearch);
      window.removeEventListener("crm:open-ai", onOpenAi);
    };
  }, []);

  useEffect(() => {
    if (!isLoading && (error || !user)) {
      router.push("/login");
    }
  }, [isLoading, error, user, router]);

  if (isLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-100">
        <div className="text-gray-500 text-sm">Загрузка…</div>
      </div>
    );
  }

  return (
    <ThemeProvider>
      <ToastProvider>
        <TooltipProvider>
          <SearchContext.Provider value={{ openSearch }}>
            <div className="flex min-h-screen bg-gray-100 dark:bg-gray-900">
              <Sidebar />
              <main className="flex-1 min-w-0">{children}</main>
            </div>
            <SearchModal open={searchOpen} onClose={() => setSearchOpen(false)} />
            <WelcomeWizard />
            <AiAssistantButton forceOpen={aiOpen} onForceOpenChange={setAiOpen} />
          </SearchContext.Provider>
        </TooltipProvider>
      </ToastProvider>
    </ThemeProvider>
  );
}
