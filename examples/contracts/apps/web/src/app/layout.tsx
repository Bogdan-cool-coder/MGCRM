import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "./globals.css";

// next/font/google: self-hosted Inter с subset cyrillic+latin, без внешних запросов в рантайме.
// variable позволяет прокинуть CSS-переменную --font-inter в Tailwind (fontFamily.sans).
const inter = Inter({
  subsets: ["latin", "cyrillic"],
  weight: ["400", "500", "600", "700", "800"],
  display: "swap",
  variable: "--font-inter",
});

export const metadata: Metadata = {
  title: "MACRO CRM",
  description: "CRM-система MACRO Global Technologies",
};

// Anti-flash: runs before hydration to apply stored theme immediately
const ANTI_FLASH_SCRIPT = `(function(){var t=localStorage.getItem('crm_theme');var sys=window.matchMedia('(prefers-color-scheme:dark)').matches;if(t==='dark'||(t!=='light'&&t!=='system'&&sys)||(t==='system'&&sys)){document.documentElement.classList.add('dark')}})()`;

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ru" suppressHydrationWarning className={inter.variable}>
      <head>
        {/* Anti-flash theme script — runs before body renders */}
        {/* eslint-disable-next-line @next/next/no-sync-scripts */}
        <script dangerouslySetInnerHTML={{ __html: ANTI_FLASH_SCRIPT }} />
      </head>
      <body className="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-primary dark:text-gray-100">{children}</body>
    </html>
  );
}
