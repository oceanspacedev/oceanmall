/**
 * Global toast store. No provider: any module can call `toast.show(...)`, and the
 * ToastHost mounted by NetworkProvider renders whatever is current.
 *
 * One toast is visible at a time. Showing another replaces it (and re-runs the
 * enter animation, because every toast gets a fresh id).
 */

export type ToastTone = 'success' | 'error' | 'info';

export type ToastAction = {
  label: string;
  onPress: () => void;
};

export type ToastOptions = {
  message: string;
  /** Picks the icon. Defaults to 'info'. */
  tone?: ToastTone;
  /** A short link-style action on the right, e.g. { label: 'Lihat', onPress }. */
  action?: ToastAction;
  /** Milliseconds before it hides itself. Defaults to 2500 (4000 with an action or an error). */
  duration?: number;
  /** Defaults to 'bottom'. */
  position?: 'bottom' | 'top';
  /** Extra distance above the safe-area bottom, to clear a sticky bar. Defaults to 72. */
  bottomOffset?: number;
};

export type ToastItem = {
  id: string;
  message: string;
  tone: ToastTone;
  action?: ToastAction;
  position: 'bottom' | 'top';
  bottomOffset?: number;
};

const DEFAULT_DURATION_MS = 2500;
const LONG_DURATION_MS = 4000;

let current: ToastItem | null = null;
let sequence = 0;
let timer: ReturnType<typeof setTimeout> | undefined;
const listeners = new Set<() => void>();

function emit(): void {
  listeners.forEach((listener) => listener());
}

function clearTimer(): void {
  if (timer) {
    clearTimeout(timer);
    timer = undefined;
  }
}

/** For useSyncExternalStore. */
export function subscribeToast(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

/** For useSyncExternalStore. Returns a stable reference until the toast changes. */
export function getToast(): ToastItem | null {
  return current;
}

function hide(id?: string): void {
  if (!current || (id !== undefined && current.id !== id)) {
    return;
  }
  clearTimer();
  current = null;
  emit();
}

function show(options: ToastOptions): string {
  const { message, tone = 'info', action, position = 'bottom', bottomOffset } = options;
  sequence += 1;
  const id = `toast-${sequence}`;
  const duration =
    options.duration ?? (action || tone === 'error' ? LONG_DURATION_MS : DEFAULT_DURATION_MS);

  clearTimer();
  current = { id, message, tone, action, position, bottomOffset };
  timer = setTimeout(() => hide(id), duration);
  emit();

  return id;
}

export const toast = { show, hide };

/**
 * Screen-y of the top edge of each StickyBar on a focused screen (components/shop.tsx reports
 * them). ToastHost keeps bottom toasts above the highest one, so a toast never covers the bar's
 * buttons, its error banner or its hint row, whatever their height (and on top of the keyboard).
 */
const barTops = new Map<string, number>();
let highestBarTop: number | null = null;
const barListeners = new Set<() => void>();

export function setBottomBarTop(key: string, top: number | null): void {
  if (top === null) {
    barTops.delete(key);
  } else {
    barTops.set(key, top);
  }
  const next = barTops.size > 0 ? Math.min(...barTops.values()) : null;
  if (next !== highestBarTop) {
    highestBarTop = next;
    barListeners.forEach((listener) => listener());
  }
}

/** For useSyncExternalStore. */
export function subscribeBottomBar(listener: () => void): () => void {
  barListeners.add(listener);
  return () => {
    barListeners.delete(listener);
  };
}

/** For useSyncExternalStore: the highest StickyBar top on screen, or null. */
export function getBottomBarTop(): number | null {
  return highestBarTop;
}
