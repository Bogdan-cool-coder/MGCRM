"use client";

export function LocalePanel() {
  return (
    <div className="p-6 max-w-lg">
      <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6">
        <div className="py-12 text-center">
          <i className="bi bi-translate text-5xl text-gray-300 dark:text-gray-600 block mb-4" />
          <h3 className="text-h5 dark:text-gray-100 mb-2">Локализация — скоро</h3>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-xs mx-auto">
            Сейчас интерфейс MACRO CRM только на русском. Поддержка других языков появится
            в ближайших обновлениях.
          </p>
        </div>

        <div className="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-2">
          <label className="flex items-center gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700 opacity-50 cursor-not-allowed">
            <input type="radio" name="locale" value="ru" defaultChecked disabled className="accent-primary" />
            <span className="text-sm font-medium text-primary dark:text-gray-100">Русский</span>
          </label>
          <label className="flex items-center gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700 opacity-50 cursor-not-allowed">
            <input type="radio" name="locale" value="en" disabled className="accent-primary" />
            <span className="text-sm text-gray-400 dark:text-gray-500">English (скоро)</span>
          </label>
        </div>

        <p className="text-xs text-gray-400 dark:text-gray-500 mt-3">
          Скоро будет английская локализация. Сейчас интерфейс только на русском.
        </p>
      </div>
    </div>
  );
}
