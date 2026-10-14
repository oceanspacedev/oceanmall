import { getSecureItem, setSecureItem } from '@/lib/storage';

const KEY = 'oceanmall.recent-searches';
const LIMIT = 8;

export async function getRecentSearches(): Promise<string[]> {
  try {
    const raw = await getSecureItem(KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed)
      ? parsed.filter((item): item is string => typeof item === 'string')
      : [];
  } catch {
    return [];
  }
}

export async function rememberSearch(query: string): Promise<string[]> {
  const q = query.trim();
  if (q.length < 2) {
    return getRecentSearches();
  }
  const next = [
    q,
    ...(await getRecentSearches()).filter((item) => item.toLowerCase() !== q.toLowerCase()),
  ].slice(0, LIMIT);
  try {
    await setSecureItem(KEY, JSON.stringify(next));
  } catch {
    // Recent searches are a convenience; ignore storage failures.
  }
  return next;
}

/** Remove one entry (the X on a recent-search pill) and return what is left. */
export async function forgetSearch(query: string): Promise<string[]> {
  const q = query.trim().toLowerCase();
  const next = (await getRecentSearches()).filter((item) => item.toLowerCase() !== q);
  try {
    await setSecureItem(KEY, JSON.stringify(next));
  } catch {
    // ignore
  }
  return next;
}

export async function clearRecentSearches(): Promise<void> {
  try {
    await setSecureItem(KEY, '[]');
  } catch {
    // ignore
  }
}
