import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Pull-to-refresh state for a screen's `load` function.
 *
 *   const { refreshing, onRefresh } = useRefresh(load);
 *   <ScrollView refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
 *
 * The spinner stays up until `load` settles, so the data is on screen by the time it goes away
 * (a plain `refreshing={false}` snaps the spinner back before the request even starts).
 * `onRefresh` keeps one identity for the life of the screen and ignores a pull while one is
 * already running. `load` reports its own failures (an error banner or toast), so a rejection
 * here only ends the spinner instead of escaping as an unhandled promise.
 */
export function useRefresh(load: () => Promise<unknown> | unknown): {
  refreshing: boolean;
  onRefresh: () => Promise<void>;
} {
  const [refreshing, setRefreshing] = useState(false);
  const loadRef = useRef(load);
  const runningRef = useRef(false);

  useEffect(() => {
    loadRef.current = load;
  }, [load]);

  const onRefresh = useCallback(async () => {
    if (runningRef.current) {
      return;
    }
    runningRef.current = true;
    setRefreshing(true);
    try {
      await loadRef.current();
    } catch {
      // The screen's own load() surfaces errors; the spinner must still stop.
    } finally {
      runningRef.current = false;
      setRefreshing(false);
    }
  }, []);

  return { refreshing, onRefresh };
}
