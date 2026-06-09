"use client";

const CHIPS = [
  "Создать задачу",
  "Создать сделку",
  "Создать договор",
  "Горячие сделки",
  "Что по плану?",
];

interface Props {
  onSelect: (text: string) => void;
}

export function AiQuickChips({ onSelect }: Props) {
  return (
    <div className="flex flex-wrap gap-2 px-4 py-2 border-t border-gray-100 dark:border-gray-800">
      {CHIPS.map((chip) => (
        <button
          key={chip}
          onClick={() => onSelect(chip)}
          className="rounded-full border border-gray-200 dark:border-gray-700 text-xs px-3 py-1 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors text-gray-600 dark:text-gray-300"
        >
          {chip}
        </button>
      ))}
    </div>
  );
}
