"use client";

import { useState } from "react";
import { api, fetcher } from "@/lib/api";
import useSWR from "swr";
import { Avatar } from "@/components/Avatar";

interface Comment {
  id: number;
  user_id: number;
  user_name: string;
  body: string;
  created_at: string;
}

interface Props {
  activityId: number;
}

export function TaskChatTab({ activityId }: Props) {
  const { data: comments, mutate } = useSWR<Comment[]>(
    `/activities/${activityId}/comments`,
    fetcher
  );

  const [text, setText] = useState("");
  const [sending, setSending] = useState(false);

  async function sendComment() {
    if (!text.trim()) return;
    setSending(true);
    try {
      await api(`/activities/${activityId}/comments`, {
        method: "POST",
        body: { body: text.trim() },
      });
      setText("");
      mutate();
    } finally {
      setSending(false);
    }
  }

  const list = comments ?? [];

  return (
    <div className="flex flex-col h-full p-6 space-y-4">
      {/* Messages */}
      <div className="flex-1 space-y-4">
        {list.length === 0 ? (
          <div className="text-center py-8">
            <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Пока нет сообщений</p>
            <p className="text-xs text-gray-400 mt-1">Напиши первый комментарий</p>
          </div>
        ) : (
          list.map((c) => (
            <div key={c.id} className="flex items-start gap-3">
              <Avatar userId={c.user_id} name={c.user_name} hasAvatar={false} size={32} />
              <div>
                <div className="flex items-center gap-2 mb-0.5">
                  <span className="text-sm font-medium text-gray-800 dark:text-gray-200">{c.user_name}</span>
                  <span className="text-xs text-gray-400">
                    {new Date(c.created_at).toLocaleString("ru-RU", {
                      day: "numeric",
                      month: "short",
                      hour: "2-digit",
                      minute: "2-digit",
                    })}
                  </span>
                </div>
                <p className="text-sm text-gray-700 dark:text-gray-300">{c.body}</p>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Input */}
      <div className="flex gap-2 border-t border-gray-100 dark:border-gray-700 pt-4">
        <textarea
          className="input flex-1 min-h-[60px] resize-none"
          placeholder="Написать комментарий..."
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) {
              e.preventDefault();
              sendComment();
            }
          }}
        />
        <button
          className="btn-primary self-end"
          disabled={!text.trim() || sending}
          onClick={sendComment}
        >
          {sending ? "..." : "Отправить"}
        </button>
      </div>
    </div>
  );
}
