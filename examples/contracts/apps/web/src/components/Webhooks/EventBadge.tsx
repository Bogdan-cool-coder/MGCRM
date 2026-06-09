"use client";

interface EventBadgeProps {
  event: string;
}

function eventClass(event: string): string {
  if (event === "*") return "bg-primary text-white";
  if (event.startsWith("lead.")) return "bg-info/10 text-info";
  if (event.startsWith("deal.")) return "bg-primary/10 text-primary";
  if (event.startsWith("contract.")) return "bg-warning/10 text-warning";
  if (event.startsWith("subscription.")) return "bg-success/10 text-success";
  if (event.startsWith("counterparty.")) return "bg-gray-100 text-gray-600";
  return "bg-gray-100 text-gray-600";
}

export function EventBadge({ event }: EventBadgeProps) {
  return (
    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ${eventClass(event)}`}>
      {event === "*" ? "Все события" : event}
    </span>
  );
}

interface EventBadgeListProps {
  events: string[];
  max?: number;
}

export function EventBadgeList({ events, max = 3 }: EventBadgeListProps) {
  const visible = events.slice(0, max);
  const rest = events.length - max;
  return (
    <div className="flex flex-wrap gap-1">
      {visible.map((e) => (
        <EventBadge key={e} event={e} />
      ))}
      {rest > 0 && (
        <span className="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-500">
          +{rest} ещё
        </span>
      )}
    </div>
  );
}
