// Публичный layout (Эпик 5): без Sidebar, без useMe-проверки.
// Используется для /f/[slug] — публичные веб-формы (Channel.kind=web_form).
export default function PublicLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <header className="border-b border-gray-200 bg-white py-4 px-6">
        <div className="max-w-4xl mx-auto text-primary font-semibold">MACRO</div>
      </header>
      <main className="flex-1">{children}</main>
      <footer className="text-center text-xs text-gray-500 py-6">
        © MACRO Global Technologies
      </footer>
    </div>
  );
}
