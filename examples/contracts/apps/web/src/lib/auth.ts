"use client";

import useSWR from "swr";
import type { User } from "./types";
import { fetcher } from "./api";

export function useMe() {
  const { data, error, isLoading, mutate } = useSWR<User>("/auth/me", fetcher, {
    revalidateOnFocus: false,
    shouldRetryOnError: false,
  });
  return { user: data, error, isLoading, mutate };
}
