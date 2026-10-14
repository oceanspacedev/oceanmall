import { getSecureItem, setSecureItem } from '@/lib/storage';
import { File, Paths } from 'expo-file-system';
import * as Linking from 'expo-linking';
import { Platform } from 'react-native';

// The "intro already shown" marker is a file in the app's document directory, NOT a SecureStore
// entry: the iOS Keychain outlives an uninstall, a document file does not. That is exactly what
// lets AuthProvider tell a fresh install from a relaunch (and drop credentials left by a previous
// install). Every file operation is guarded: when the sandbox cannot be read the user counts as
// already onboarded, so a storage fault can never trap anyone in the intro or sign them out.
const MARKER_FILE = 'oceanmall-onboarded';

// Only written when the marker file cannot be created, so the intro and the credential reset
// do not repeat on every launch of a device with a broken document directory.
// (SecureStore keys may only contain alphanumerics, '.', '-' and '_'.)
const FALLBACK_KEY = 'oceanmall.onboarded';

/** True when the intro was already shown (or the marker cannot be read: never trap the user). */
export async function hasSeenOnboarding(): Promise<boolean> {
  if (Platform.OS === 'web') {
    return true;
  }

  try {
    if (new File(Paths.document, MARKER_FILE).exists) {
      return true;
    }
  } catch {
    return true;
  }

  try {
    return (await getSecureItem(FALLBACK_KEY)) === '1';
  } catch {
    return true;
  }
}

/** Records that the intro was shown. Never throws. */
export async function markOnboardingSeen(): Promise<void> {
  if (Platform.OS === 'web') {
    return;
  }

  try {
    const marker = new File(Paths.document, MARKER_FILE);
    if (!marker.exists) {
      marker.create();
    }
    marker.write('1');
  } catch {
    try {
      await setSecureItem(FALLBACK_KEY, '1');
    } catch {
      // Nothing left to try: the intro may show once more, which is harmless.
    }
  }
}

/**
 * True when this launch was opened by a link (oceanmall://product/..., a web link). Those cold
 * starts skip the intro so the link lands where it points. Development-client launcher URLs do
 * not count.
 */
export async function launchedFromDeepLink(): Promise<boolean> {
  try {
    const url = await Linking.getInitialURL();
    if (!url || /^exp(\+[\w-]+)?:\/\//i.test(url) || url.includes('expo-development-client')) {
      return false;
    }
    const { hostname, path } = Linking.parse(url);

    return Boolean(hostname || path);
  } catch {
    return false;
  }
}

// "Sudah punya akun? Masuk" finishes the intro before the navigator exists, so the login push is
// parked here and performed by the root layout once the navigator is mounted.
let postOnboardingRoute: string | null = null;

export function setPostOnboardingRoute(route: string | null): void {
  postOnboardingRoute = route;
}

/** Returns the parked route once and clears it. */
export function takePostOnboardingRoute(): string | null {
  const route = postOnboardingRoute;
  postOnboardingRoute = null;

  return route;
}
