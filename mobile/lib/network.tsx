import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { ToastHost } from '@/components/ui/toast';
import { announce } from '@/lib/a11y';
import { API_URL } from '@/lib/config';
import { COLORS } from '@/lib/theme';
import { CircleCheck, WifiOff } from 'lucide-react-native';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState, Pressable, View } from 'react-native';
import { SafeAreaInsetsContext, useSafeAreaInsets } from 'react-native-safe-area-context';

export const OFFLINE_COPY = {
  /** Used by errorMessage() when a request failed because the device is offline. */
  message: 'Kamu sedang offline',
  /** Text of the in-flow strip under the status bar. */
  banner: 'Tidak ada koneksi internet',
  retry: 'Coba lagi',
  retrying: 'Memeriksa…',
  stillOffline: 'Masih belum tersambung',
  recovered: 'Kembali online',
};

/** The server is unreachable: the device is offline, or the connection dropped. */
export class NetworkError extends Error {
  constructor(message = OFFLINE_COPY.message) {
    super(message);
    this.name = 'NetworkError';
  }
}

/**
 * The server did not answer in time. Deliberately NOT a NetworkError: a slow
 * request on a working connection must not flip the app into its offline state.
 */
export class TimeoutError extends Error {
  constructor(message = 'Server lambat merespons. Coba lagi.') {
    super(message);
    this.name = 'TimeoutError';
  }
}

/** True when the failure means "no connection". A timeout is not one (checked first). */
export function isNetworkFailure(error: unknown): boolean {
  if (error instanceof TimeoutError) {
    return false;
  }
  if (error instanceof NetworkError) {
    return true;
  }
  const message = error instanceof Error ? error.message : String(error ?? '');
  return /network request failed|failed to fetch|load failed|network error|internet|offline|econnrefused|enotfound|socket/i.test(
    message
  );
}

type Listener = (online: boolean) => void;

let reachable = true;
const listeners = new Set<Listener>();

function setReachable(next: boolean): void {
  if (reachable === next) {
    return;
  }
  reachable = next;
  listeners.forEach((listener) => listener(reachable));
}

export function reportNetworkSuccess(): void {
  setReachable(true);
}

export function reportNetworkFailure(): void {
  setReachable(false);
}

export function subscribeNetwork(listener: Listener): () => void {
  listeners.add(listener);
  listener(reachable);
  return () => {
    listeners.delete(listener);
  };
}

function serverOrigin(): string {
  return API_URL.replace(/\/api\/v1\/?$/, '');
}

export async function fetchWithTimeout(
  url: string,
  init: RequestInit = {},
  timeoutMs = 8000
): Promise<Response> {
  const controller = new AbortController();
  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => {
      controller.abort();
      reject(new TimeoutError());
    }, timeoutMs);
  });

  try {
    const request = fetch(url, { ...init, signal: controller.signal });
    request.catch(() => {
      // Swallow abort/errors after the timeout already won the race.
    });
    return await Promise.race([request, timeout]);
  } finally {
    if (timer) {
      clearTimeout(timer);
    }
  }
}

/**
 * What one ping of the server's /up endpoint can say:
 * - 'up': it answered.
 * - 'down': the connection failed outright (no route, refused, dropped).
 * - 'slow': it did not answer within the time limit. Proves nothing: like a request TimeoutError,
 *   a slow answer on a weak connection is not "offline".
 */
type ProbeResult = 'up' | 'down' | 'slow';

/**
 * Pings /up once. Never rejects. With no server configured (a release build without an API host)
 * there is nothing to ask: the root gate shows the configuration error, so a made-up "offline"
 * strip must not cover it.
 */
function probeOnce(): Promise<ProbeResult> {
  if (!API_URL) {
    return Promise.resolve('up');
  }

  return new Promise((resolve) => {
    try {
      const xhr = new XMLHttpRequest();
      xhr.timeout = 4000;
      xhr.onload = () => resolve('up');
      xhr.onerror = () => resolve('down');
      xhr.ontimeout = () => resolve('slow');
      xhr.onabort = () => resolve('down');
      xhr.open('GET', `${serverOrigin()}/up`);
      xhr.send();
    } catch {
      resolve('down');
    }
  });
}

/** Pause between the two pings that must both fail before the app calls itself offline. */
const CONFIRM_DELAY_MS = 1500;

/**
 * The background check: true = reachable, false = confirmed unreachable, null = inconclusive.
 *
 * The offline strip is laid out in flow and shifts every screen, so one stray failure must not
 * raise it: a first 'down' is confirmed by a second ping, and only two in a row count. A timeout
 * on either ping is inconclusive and leaves the current state alone.
 */
async function probeReachable(): Promise<boolean | null> {
  const first = await probeOnce();
  if (first === 'up') {
    return true;
  }
  if (first === 'slow') {
    return null;
  }

  await new Promise<void>((resolve) => setTimeout(resolve, CONFIRM_DELAY_MS));
  const second = await probeOnce();
  if (second === 'up') {
    return true;
  }

  return second === 'down' ? false : null;
}

export function useNetwork(): { online: boolean; offline: boolean } {
  const [online, setOnline] = useState(reachable);
  useEffect(() => subscribeNetwork(setOnline), []);
  return useMemo(() => ({ online, offline: !online }), [online]);
}

export function NetworkProvider({ children }: { children: ReactNode }) {
  const { offline } = useNetwork();
  const insets = useSafeAreaInsets();
  const [recovered, setRecovered] = useState(false);
  const [retrying, setRetrying] = useState(false);
  const wasOffline = useRef(false);

  useEffect(() => {
    let cancelled = false;

    async function probe(): Promise<void> {
      const reachableNow = await probeReachable();
      if (!cancelled && reachableNow !== null) {
        setReachable(reachableNow);
      }
    }

    void probe();

    const app = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        void probe();
      }
    });

    const target = typeof window !== 'undefined' ? window : undefined;
    const onOnline = () => {
      void probe();
    };
    const onOffline = () => setReachable(false);
    if (target && typeof target.addEventListener === 'function') {
      target.addEventListener('online', onOnline);
      target.addEventListener('offline', onOffline);
    }

    return () => {
      cancelled = true;
      app.remove();
      if (target && typeof target.removeEventListener === 'function') {
        target.removeEventListener('online', onOnline);
        target.removeEventListener('offline', onOffline);
      }
    };
  }, []);

  useEffect(() => {
    let cancelled = false;
    const id = setInterval(
      () => {
        void probeReachable().then((reachableNow) => {
          if (!cancelled && reachableNow !== null) {
            setReachable(reachableNow);
          }
        });
      },
      offline ? 3000 : 8000
    );
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, [offline]);

  // Offline -> online shows a green "Kembali online" strip for 2 s, then it unmounts.
  useEffect(() => {
    if (offline) {
      wasOffline.current = true;
      setRecovered(false);
      announce(OFFLINE_COPY.message);
      return;
    }
    if (!wasOffline.current) {
      return;
    }
    wasOffline.current = false;
    setRecovered(true);
    announce(OFFLINE_COPY.recovered);
    const id = setTimeout(() => setRecovered(false), 2000);
    return () => clearTimeout(id);
  }, [offline]);

  const retry = useCallback(async () => {
    if (retrying) {
      return;
    }
    setRetrying(true);
    // A tap is an explicit question, so one ping is enough: only an answer brings the app back.
    const ok = (await probeOnce()) === 'up';
    if (ok) {
      setReachable(true);
    }
    setRetrying(false);
    if (!ok) {
      announce(OFFLINE_COPY.stillOffline);
    }
  }, [retrying]);

  const stripVisible = offline || recovered;
  const stripTop = Math.max(insets.top, 8);

  // The strip sits in flow under the status bar, so it already covers the top inset.
  // Hand the screens below a zero top inset while it shows, so nothing pads for the status bar
  // a second time. Every screen must read this context (useSafeAreaInsets, or the
  // components/safe-area-view wrapper): the native SafeAreaView ignores it and grew each header
  // by a second status-bar height whenever the strip appeared.
  const childInsets = useMemo(
    () => (stripVisible ? { ...insets, top: 0 } : insets),
    [insets, stripVisible]
  );

  return (
    <View style={{ flex: 1 }}>
      {offline ? (
        <View
          accessibilityLiveRegion="polite"
          style={{
            backgroundColor: COLORS.warning,
            paddingTop: stripTop,
            paddingBottom: 8,
            paddingHorizontal: 16,
          }}>
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            <Icon as={WifiOff} size={16} color={COLORS.white} />
            <Text
              className="ml-2.5 flex-1 text-[13px] font-bold text-white"
              maxFontSizeMultiplier={1.2}>
              {OFFLINE_COPY.banner}
            </Text>
            <Pressable
              role="button"
              disabled={retrying}
              hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
              android_ripple={{ color: 'rgba(255,255,255,0.25)' }}
              onPress={() => void retry()}
              className="ml-3 overflow-hidden rounded-full border border-white/70 px-3 py-1 active:opacity-70">
              <Text className="text-[13px] font-bold text-white" maxFontSizeMultiplier={1.2}>
                {retrying ? OFFLINE_COPY.retrying : OFFLINE_COPY.retry}
              </Text>
            </Pressable>
          </View>
        </View>
      ) : recovered ? (
        <View
          accessibilityLiveRegion="polite"
          style={{
            backgroundColor: COLORS.success,
            paddingTop: stripTop,
            paddingBottom: 8,
            paddingHorizontal: 16,
          }}>
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            <Icon as={CircleCheck} size={16} color={COLORS.white} />
            <Text
              className="ml-2.5 flex-1 text-[13px] font-bold text-white"
              maxFontSizeMultiplier={1.2}>
              {OFFLINE_COPY.recovered}
            </Text>
          </View>
        </View>
      ) : null}
      <SafeAreaInsetsContext.Provider value={childInsets}>
        <View style={{ flex: 1 }}>{children}</View>
      </SafeAreaInsetsContext.Provider>
      <ToastHost />
    </View>
  );
}
