"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { Avatar } from "@/components/Avatar";
import { useMe } from "@/lib/auth";
import type { MeProfile } from "@/lib/types";

interface Props {
  userId?: number;
}

export function MePageHeader({ userId }: Props) {
  const { user: me } = useMe();
  const isOwnProfile = !userId || userId === me?.id;
  const swrKey = userId && !isOwnProfile ? `/users/${userId}/profile` : "/me/profile";

  const { data: profile, isLoading } = useSWR<MeProfile>(swrKey, fetcher);

  if (isLoading) {
    return (
      <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-6 animate-pulse">
        <div className="flex items-center gap-5">
          <div className="w-20 h-20 rounded-full bg-gray-200 dark:bg-gray-700 shrink-0" />
          <div className="space-y-2 flex-1">
            <div className="h-6 bg-gray-200 dark:bg-gray-700 rounded w-48" />
            <div className="h-4 bg-gray-100 dark:bg-gray-600 rounded w-32" />
            <div className="h-4 bg-gray-100 dark:bg-gray-600 rounded w-24" />
          </div>
        </div>
      </div>
    );
  }

  if (!profile) return null;

  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center gap-5">
        <Avatar
          userId={profile.id}
          name={profile.full_name}
          hasAvatar={!!profile.avatar_path}
          size={80}
          className="shrink-0"
        />

        <div className="flex-1 min-w-0">
          <h2 className="text-xl font-bold text-gray-900 dark:text-white leading-tight">
            {profile.full_name}
          </h2>
          {profile.job_title && (
            <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{profile.job_title}</p>
          )}
          <div className="flex flex-wrap gap-3 mt-2">
            {profile.department_name && (
              <span className="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-700/60 px-2.5 py-1 rounded-full">
                <i className="bi bi-diagram-3-fill" aria-hidden="true" />
                {profile.department_name}
              </span>
            )}
            {profile.manager_name && profile.manager_id && (
              <Link
                href={`/me?user_id=${profile.manager_id}`}
                className="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-700/60 hover:bg-primary-light hover:text-primary px-2.5 py-1 rounded-full transition-colors"
              >
                <i className="bi bi-person-fill" aria-hidden="true" />
                {profile.manager_name}
              </Link>
            )}
          </div>
        </div>

        {isOwnProfile && (
          <Link href="/profile" className="btn-ghost shrink-0 self-start" title="Редактировать профиль">
            <i className="bi bi-pencil" aria-hidden="true" />
          </Link>
        )}
      </div>
    </div>
  );
}
