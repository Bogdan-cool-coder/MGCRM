import clsx from "clsx";

function initials(name: string): string {
  const parts = name.trim().split(/\s+/);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

export function Avatar({
  userId,
  name,
  hasAvatar,
  size = 36,
  cacheBust,
  className,
}: {
  userId: number;
  name: string;
  hasAvatar: boolean;
  size?: number;
  cacheBust?: string | number;
  className?: string;
}) {
  const initialsText = initials(name);
  const src = hasAvatar
    ? `/api/users/${userId}/avatar${cacheBust ? `?v=${cacheBust}` : ""}`
    : null;
  return (
    <div
      className={clsx(
        "shrink-0 rounded-full overflow-hidden bg-primary text-white inline-flex items-center justify-center font-semibold select-none",
        className,
      )}
      style={{ width: size, height: size, fontSize: Math.round(size * 0.4) }}
    >
      {src ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={src} alt={name} className="w-full h-full object-cover" />
      ) : (
        <span>{initialsText}</span>
      )}
    </div>
  );
}
