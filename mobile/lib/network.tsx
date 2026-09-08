import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { API_URL } from '@/lib/config';
import { StatusBar } from 'expo-status-bar';
import { WifiOff } from 'lucide-react-native';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { AppState, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export const OFFLINE_COPY = {
  message: 'Kamu sedang offline',
};

export class NetworkError extends Error {
  constructor(message = OFFLINE_COPY.message) {
    super(message);
    this.name = 'NetworkError';
  }
}

export function isNetworkFailure(error: unknown): boolean {
  if (error instanceof NetworkError) {
    return true;
  }
  const message = error instanceof Error ? error.message : String(error ?? '');
  return /network request failed|failed to fetch|load failed|network error|internet|offline|timed out|timeout|econnrefused|enotfound|socket/i.test(
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

export async function fetchWithTimeout(url: string, init: RequestInit = {}, timeoutMs = 8000): Promise<Response> {
  const controller = new AbortController();
  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => {
      controller.abort();
      reject(new NetworkError());
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

function probeServer(): Promise<boolean> {
  return new Promise((resolve) => {
    try {
      const xhr = new XMLHttpRequest();
      xhr.timeout = 4000;
      xhr.onload = () => resolve(true);
      xhr.onerror = () => resolve(false);
      xhr.ontimeout = () => resolve(false);
      xhr.onabort = () => resolve(false);
      xhr.open('GET', `${serverOrigin()}/up`);
      xhr.send();
    } catch {
      resolve(false);
    }
  });
}

export function useNetwork(): { online: boolean; offline: boolean } {
  const [online, setOnline] = useState(reachable);
  useEffect(() => subscribeNetwork(setOnline), []);
  return useMemo(() => ({ online, offline: !online }), [online]);
}

export function NetworkProvider({ children }: { children: ReactNode }) {
  const { online, offline } = useNetwork();
  const insets = useSafeAreaInsets();

  useEffect(() => {
    let cancelled = false;

    async function probe(): Promise<void> {
      const ok = await probeServer();
      if (!cancelled) {
        setReachable(ok);
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
    const id = setInterval(() => {
      void probeServer().then(setReachable);
    }, offline ? 3000 : 8000);
    return () => clearInterval(id);
  }, [offline]);

  return (
    <View style={{ flex: 1 }}>
      <View style={{ flex: 1 }}>{children}</View>
      {offline ? (
        <View
          pointerEvents="none"
          accessibilityRole="alert"
          accessibilityLabel={OFFLINE_COPY.message}
          style={{
            position: 'absolute',
            top: 0,
            left: 0,
            right: 0,
            zIndex: 999,
            elevation: 999,
            backgroundColor: '#9a3412',
            paddingTop: Math.max(insets.top, 8),
            paddingBottom: 8,
            paddingHorizontal: 16,
          }}>
          <StatusBar style="light" />
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            <Icon as={WifiOff} size={16} color="#fff" />
            <Text className="ml-2.5 text-[13px] font-bold text-white">{OFFLINE_COPY.message}</Text>
          </View>
        </View>
      ) : null}
    </View>
  );
}
