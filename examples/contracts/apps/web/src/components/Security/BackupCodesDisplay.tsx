"use client";

interface Props {
  codes: string[];
}

export function BackupCodesDisplay({ codes }: Props) {
  function downloadCodes() {
    const text = codes.join("\n");
    const blob = new Blob([text], { type: "text/plain" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "macro-crm-backup-codes.txt";
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div>
      <div className="flex items-start gap-2 rounded-md bg-danger/10 text-danger px-3 py-2 text-sm mb-4">
        <i className="bi bi-exclamation-triangle mt-0.5 shrink-0" />
        <span>
          Эти коды показываются только один раз. Сохрани их в безопасном месте. Каждый код можно использовать только один раз.
        </span>
      </div>

      <div className="grid grid-cols-2 gap-2 my-4">
        {codes.map((code) => (
          <span
            key={code}
            className="font-mono text-sm bg-gray-50 rounded px-3 py-1.5 text-center select-all block border border-gray-200"
          >
            {code}
          </span>
        ))}
      </div>

      <button type="button" className="btn-secondary" onClick={downloadCodes}>
        <i className="bi bi-download mr-1" />
        Скачать .txt
      </button>
    </div>
  );
}
