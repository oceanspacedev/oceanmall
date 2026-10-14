import { BootScreen } from '@/components/boot-screen';
import { ConfigErrorScreen } from '@/components/config-error-screen';
import { Onboarding } from '@/components/onboarding';
import {
  ApiError,
  GUEST_CART_KEY,
  api,
  setAuthToken,
  setUnauthorizedHandler,
  type User,
} from '@/lib/api';
import { API_CONFIG_ERROR } from '@/lib/config';
import {
  hasSeenOnboarding,
  launchedFromDeepLink,
  markOnboardingSeen,
  setPostOnboardingRoute,
} from '@/lib/first-run';
import { useNetwork } from '@/lib/network';
import { deleteSecureItem, getSecureItem, setSecureItem } from '@/lib/storage';
import * as SplashScreen from 'expo-splash-screen';
import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

const TOKEN_KEY = 'oceanmall.token';

/** A stored session gets this long to be confirmed before the app opens with guest data. */
const HYDRATE_BUDGET_MS = 2500;
/** The native splash covers a normal start; the branded boot screen only appears after this. */
const BOOT_SCREEN_DELAY_MS = 1200;

type AuthContextValue = {
  ready: boolean;
  /** False on a fresh install until the intro is finished or skipped. */
  onboarded: boolean;
  user: User | null;
  login: (email: string, password: string) => Promise<void>;
  register: (payload: {
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    password_confirmation: string;
    phone_number?: string;
  }) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
  /** Marks the intro as seen. `'login'` opens the sign-in screen once the app is mounted. */
  completeOnboarding: (next?: 'login') => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Whether the intro was already shown. A missing marker means a fresh install: the iOS Keychain
 * outlives an uninstall, so anything credential-like it still holds belongs to a previous install
 * and is dropped before the session is hydrated. Links that open the app skip the intro.
 */
async function resolveOnboarded(): Promise<boolean> {
  if (await hasSeenOnboarding()) {
    return true;
  }

  await Promise.all(
    [TOKEN_KEY, GUEST_CART_KEY].map((key) =>
      deleteSecureItem(key).catch(() => {
        // Nothing stored, or the keychain is unavailable: either way there is nothing to reuse.
      })
    )
  );

  if (await launchedFromDeepLink()) {
    await markOnboardingSeen();
    return true;
  }

  return false;
}

/** Resolves when `promise` settles or `ms` passes, whichever is first. Never rejects. */
function settleWithin(promise: Promise<unknown>, ms: number): Promise<void> {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, ms);
    const done = () => {
      clearTimeout(timer);
      resolve();
    };
    promise.then(done, done);
  });
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const { online } = useNetwork();
  const [ready, setReady] = useState(false);
  const [onboarded, setOnboarded] = useState(true);
  const [user, setUser] = useState<User | null>(null);
  const tokenRef = useRef<string | null>(null);
  const hydratingRef = useRef<Promise<void> | null>(null);

  async function applyToken(token: string | null) {
    tokenRef.current = token;
    setAuthToken(token);
    if (token) {
      await setSecureItem(TOKEN_KEY, token);
    } else {
      await deleteSecureItem(TOKEN_KEY);
    }
  }

  async function fetchProfile(): Promise<void> {
    const me = await api<{ data: User }>('/auth/me');
    setUser(me.data);
  }

  // Startup and the "signed in but no profile yet" effect below can both ask for the profile
  // while the first request is still running (it may outlive its time budget): share it.
  function hydrateUser(): Promise<void> {
    if (!hydratingRef.current) {
      hydratingRef.current = fetchProfile().finally(() => {
        hydratingRef.current = null;
      });
    }

    return hydratingRef.current;
  }

  useEffect(() => {
    // A release build without an API host shows the configuration error (AuthGate) and must not
    // talk to a server it does not have.
    if (API_CONFIG_ERROR) {
      setReady(true);
      return;
    }

    (async () => {
      try {
        let seen = true;
        try {
          seen = await resolveOnboarded();
        } catch {
          // Never trap the user in the intro because of a storage fault.
        }
        setOnboarded(seen);

        const stored = await getSecureItem(TOKEN_KEY);
        if (stored) {
          tokenRef.current = stored;
          setAuthToken(stored);
          // Wait for the profile only briefly: on a slow or missing network the app opens with
          // guest data and the profile fills in when it arrives. A 401/403 is still handled
          // after the wait ends, so a revoked token is dropped either way.
          const hydrating = hydrateUser().catch(async (error: unknown) => {
            if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
              try {
                await applyToken(null);
              } catch {
                // The in-memory token is already cleared.
              }
              setUser(null);
            }
          });
          await settleWithin(hydrating, HYDRATE_BUDGET_MS);
        }
      } catch {
        setUser(null);
      } finally {
        setReady(true);
      }
    })();
  }, []);

  // A revoked token (e.g. signed in on another device) signs this device out
  // everywhere instead of leaving screens half-authenticated.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      if (!tokenRef.current) {
        return;
      }
      void applyToken(null);
      setUser(null);
    });
    return () => setUnauthorizedHandler(null);
  }, []);

  useEffect(() => {
    if (!ready || !online || !tokenRef.current || user) {
      return;
    }
    void hydrateUser().catch(() => {
      // Keep the stored token; profile fills in when /me succeeds.
    });
  }, [online, ready, user]);

  const value = useMemo<AuthContextValue>(
    () => ({
      ready,
      onboarded,
      user,
      async login(email, password) {
        const res = await api<{ token: string; data: User }>('/auth/login', {
          method: 'POST',
          body: JSON.stringify({ email, password }),
        });
        await applyToken(res.token);
        setUser(res.data);
      },
      async register(payload) {
        const res = await api<{ token: string; data: User }>('/auth/register', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        await applyToken(res.token);
        setUser(res.data);
      },
      async logout() {
        try {
          await api('/auth/logout', { method: 'POST' });
        } catch {
          // ignore network errors on logout
        }
        await applyToken(null);
        setUser(null);
      },
      async refresh() {
        // Explicit refreshes (after a profile edit) always fetch fresh data.
        await fetchProfile();
      },
      async completeOnboarding(next) {
        setPostOnboardingRoute(next === 'login' ? '/login' : null);
        await markOnboardingSeen();
        setOnboarded(true);
      },
    }),
    [ready, onboarded, user]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

/**
 * Keeps the navigator (and everything that talks to the API) unmounted until the session is
 * known, so deep links resolve against a ready app. The native splash stays on top for a normal
 * start; the branded boot screen only appears when starting is slow. First run shows the intro.
 * A release build without an API host shows a plain configuration error instead of the app.
 */
export function AuthGate({ children }: { children: ReactNode }) {
  const { ready, onboarded, completeOnboarding } = useAuth();
  const [bootTimedOut, setBootTimedOut] = useState(false);

  useEffect(() => {
    const timer = setTimeout(() => setBootTimedOut(true), BOOT_SCREEN_DELAY_MS);
    return () => clearTimeout(timer);
  }, []);

  const stage = API_CONFIG_ERROR
    ? 'config-error'
    : ready
      ? onboarded
        ? 'app'
        : 'onboarding'
      : bootTimedOut
        ? 'boot'
        : 'pending';

  // Lift the native splash once the first real frame (boot screen, intro or the app) is
  // mounted: two animation frames lets it be laid out and painted underneath first.
  useEffect(() => {
    if (stage === 'pending') {
      return;
    }
    let inner: number | undefined;
    const outer = requestAnimationFrame(() => {
      inner = requestAnimationFrame(() => {
        SplashScreen.hideAsync().catch(() => {
          // Already hidden.
        });
      });
    });
    return () => {
      cancelAnimationFrame(outer);
      if (inner !== undefined) {
        cancelAnimationFrame(inner);
      }
    };
  }, [stage]);

  if (stage === 'pending') {
    // Nothing to draw: the native splash is still covering the screen.
    return null;
  }

  if (stage === 'config-error') {
    return <ConfigErrorScreen />;
  }

  if (stage === 'boot') {
    return <BootScreen />;
  }

  if (stage === 'onboarding') {
    return <Onboarding onFinish={(next) => void completeOnboarding(next)} />;
  }

  return children;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return ctx;
}
