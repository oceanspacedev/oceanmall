import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useNetwork } from '@/lib/network';
import { useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';

type UnreadState = { userId: number | null; count: number };

/**
 * Unread notification count for the signed-in customer, for bell badges.
 * Refreshes whenever the screen gains focus and when the account (or connectivity) changes.
 * Guests always get 0 and never trigger a request; request errors keep the last known value.
 */
export function useUnreadCount(): { unread: number; refresh: () => Promise<void> } {
  const { user } = useAuth();
  const { online } = useNetwork();
  const userId = user?.id ?? null;
  const [state, setState] = useState<UnreadState>({ userId: null, count: 0 });
  const requestId = useRef(0);

  const refresh = useCallback(async () => {
    if (userId === null || !online) {
      return;
    }
    const current = ++requestId.current;
    try {
      const res = await api<{ meta?: { unread?: number } }>('/notifications');
      const count = res.meta?.unread;
      // Ignore answers that arrive out of order.
      if (current === requestId.current && typeof count === 'number') {
        setState({ userId, count });
      }
    } catch {
      // Keep the last value; a blip must not clear or invent a badge.
    }
  }, [online, userId]);

  useFocusEffect(
    useCallback(() => {
      void refresh();
    }, [refresh])
  );

  // A count fetched for another account (or before signing out) never leaks across.
  const unread = userId !== null && state.userId === userId ? state.count : 0;

  return { unread, refresh };
}
