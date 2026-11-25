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
import { API_CONFIG_ERROR, API_URL } from '@/lib/config';
import {
  hasSeenOnboarding,
  launchedFromDeepLink,
  markOnboardingSeen,
  setPostOnboardingRoute,
} from '@/lib/first-run';
import { fetchWithTimeout, useNetwork } from '@/lib/network';
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
import { AppState } from 'react-native';

const TOKEN_KEY = 'oceanmall.token';

/** A stored session gets this long to be confirmed before the app opens with guest data. */
const HYDRATE_BUDGET_MS = 2500;
/** The native splash covers a normal start; the branded boot screen only appears after this. */
const BOOT_SCREEN_DELAY_MS = 1200;
/** Backoff between profile retries while a stored session has no profile yet (last one repeats). */
const HYDRATE_RETRY_MS = [3000, 10000, 30000, 60000];
/** The server-side revoke after a local sign-out runs in the background; never wait long for it. */
const LOGOUT_TIMEOUT_MS = 5000;

type AuthContextValue = {
  ready: boolean;
  /** False on a fresh install until the intro is finished or skipped. */
  onboarded: boolean;
  user: User | null;
  /**
   * A stored session whose profile has not loaded yet (slow or failing /auth/me). Screens show a
   * loading or retry state instead of the guest UI, which would nudge a needless re-login.
   */
  sessionPending: boolean;
  /** Asks for the profile of a pending session again now (pull-to-refresh, "Coba lagi"). */
  retrySession: () => Promise<void>;
  /** `code`: the two-step verification code (authenticator or recovery), when the account asks for one. */
  login: (email: string, password: string, code?: string) => Promise<void>;
  register: (payload: {
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    password_confirmation: string;
    phone_number?: string;
  }) => Promise<void>;
  logout: () => Promise<void>;
  /** Deletes the account on the server (password re-checked there) and ends the session. */
  deleteAccount: (password: string) => Promise<void>;
  refresh: () => Promise<void>;
  /** Puts a profile the server already returned (e.g. the profile PATCH) in place. */
  setProfile: (user: User) => void;
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

/** The server rejected the stored token itself: drop it instead of retrying. */
function isAuthRejection(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.status === 403);
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
  // Mirrors tokenRef as state so `sessionPending` re-renders when a session starts or ends.
  const [hasToken, setHasToken] = useState(false);
  // Bumped by the backoff timer and on return to the foreground to ask for the profile again.
  const [retryTick, setRetryTick] = useState(0);
  const tokenRef = useRef<string | null>(null);
  const hydratingRef = useRef<Promise<void> | null>(null);
  const hydrateFailuresRef = useRef(0);

  async function applyToken(token: string | null) {
    tokenRef.current = token;
    setHasToken(Boolean(token));
    hydrateFailuresRef.current = 0;
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

  async function dropRejectedToken() {
    try {
      await applyToken(null);
    } catch {
      // The in-memory token is already cleared.
    }
    setUser(null);
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
          setHasToken(true);
          setAuthToken(stored);
          // Wait for the profile only briefly: on a slow or missing network the app opens with
          // guest data and the profile fills in when it arrives. A 401/403 is still handled
          // after the wait ends, so a revoked token is dropped either way.
          const hydrating = hydrateUser().catch(async (error: unknown) => {
            if (isAuthRejection(error)) {
              await dropRejectedToken();
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

  // Signed in but no profile yet (a slow or failing /me at launch): keep asking with a backoff.
  // A timeout or a 5xx changes none of `online`/`ready`/`user`, so without the retry tick the
  // app would stay in the guest UI for the whole session. The stored token is kept throughout.
  useEffect(() => {
    if (!ready || !online || !hasToken || user) {
      return;
    }
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    hydrateUser().then(
      () => {
        hydrateFailuresRef.current = 0;
      },
      (error: unknown) => {
        if (isAuthRejection(error)) {
          if (tokenRef.current) {
            void dropRejectedToken();
          }
          return;
        }
        // Other 4xx answers will not change on a retry; 408/429, 5xx and timeouts can.
        if (
          cancelled ||
          (error instanceof ApiError &&
            error.status >= 400 &&
            error.status < 500 &&
            error.status !== 408 &&
            error.status !== 429)
        ) {
          return;
        }
        const step = Math.min(hydrateFailuresRef.current, HYDRATE_RETRY_MS.length - 1);
        hydrateFailuresRef.current += 1;
        const retryAfterMs =
          error instanceof ApiError && error.retryAfter ? error.retryAfter * 1000 : 0;
        timer = setTimeout(
          () => setRetryTick((tick) => tick + 1),
          Math.max(HYDRATE_RETRY_MS[step], retryAfterMs)
        );
      }
    );
    return () => {
      cancelled = true;
      if (timer !== undefined) {
        clearTimeout(timer);
      }
    };
  }, [online, ready, hasToken, user, retryTick]);

  // Coming back to the app retries a pending session at once instead of waiting out the backoff.
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active' && tokenRef.current) {
        setRetryTick((tick) => tick + 1);
      }
    });
    return () => subscription.remove();
  }, []);

  const sessionPending = ready && hasToken && !user;

  const value = useMemo<AuthContextValue>(
    () => ({
      ready,
      onboarded,
      user,
      sessionPending,
      async retrySession() {
        if (!tokenRef.current) {
          return;
        }
        try {
          await hydrateUser();
        } catch (error) {
          if (isAuthRejection(error)) {
            await dropRejectedToken();
          }
          // Anything else: the backoff effect keeps trying.
        }
      },
      async login(email, password, code) {
        const res = await api<{ token: string; data: User }>('/auth/login', {
          method: 'POST',
          // Always send the key: the server tells builds without a code field to update.
          body: JSON.stringify({ email, password, code: code ?? '' }),
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
        // Sign out on the device first so the UI flips at once, even on a slow connection; the
        // server revoke runs in the background with the old token. It bypasses api(): the token
        // is already cleared there, and its 401 handler must not touch a session started since.
        const token = tokenRef.current;
        try {
          await applyToken(null);
        } catch {
          // The in-memory token is already cleared.
        }
        setUser(null);
        if (token) {
          fetchWithTimeout(
            `${API_URL}/auth/logout`,
            {
              method: 'POST',
              headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            },
            LOGOUT_TIMEOUT_MS
          ).catch(() => {
            // ignore network errors on logout
          });
        }
      },
      async deleteAccount(password) {
        await api('/auth/me', { method: 'DELETE', body: JSON.stringify({ password }) });
        await applyToken(null);
        setUser(null);
      },
      async refresh() {
        // Explicit refreshes (after a profile edit) always fetch fresh data.
        await fetchProfile();
      },
      setProfile(next) {
        if (tokenRef.current) {
          setUser(next);
        }
      },
      async completeOnboarding(next) {
        setPostOnboardingRoute(next === 'login' ? '/login' : null);
        await markOnboardingSeen();
        setOnboarded(true);
      },
    }),
    [ready, onboarded, user, sessionPending]
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
