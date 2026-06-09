"use client";

/** Бейдж типа контакта: «Физ. лицо» (info) или «Компания» (primary). */
export function ContactTypeTag({ kind }: { kind: "person" | "company" }) {
  if (kind === "person") {
    return (
      <span className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-info/10 text-info font-medium">
        <i className="bi bi-person text-[11px]" />
        Физ. лицо
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-primary/10 text-primary font-medium">
      <i className="bi bi-building text-[11px]" />
      Компания
    </span>
  );
}
