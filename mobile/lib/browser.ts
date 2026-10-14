import { COLORS } from '@/lib/theme';
import { Linking, Platform } from 'react-native';

type WebBrowserModule = typeof import('expo-web-browser');

/**
 * expo-web-browser is a native module, and a development build installed before it was added does
 * not contain it (importing it at the top would crash the whole app on start). So it is required
 * lazily, and anything missing or failing falls back to the system browser.
 */
function loadWebBrowser(): WebBrowserModule | null {
  try {
    return require('expo-web-browser') as WebBrowserModule;
  } catch {
    return null;
  }
}

/**
 * 'closed'  the in-app sheet was shown and has been dismissed (iOS reports this).
 * 'opened'  a page was opened but there is no close signal: Android's Custom Tab resolves as soon
 *           as it opens, and the system-browser fallback never reports back. Callers refresh when
 *           the app returns to the foreground instead.
 */
export type PaymentPageResult = 'closed' | 'opened';

/**
 * Opens the gateway's payment page in an in-app sheet (SFSafariViewController on iOS, a Chrome
 * Custom Tab on Android) with the navy toolbar, so the customer never leaves the app. On the web,
 * or when the native module is not available, it opens the system browser instead. Rejects only
 * when nothing could open the link.
 */
export async function openPaymentPage(url: string): Promise<PaymentPageResult> {
  if (!/^https?:\/\//i.test(url)) {
    throw new Error('Invalid payment URL');
  }

  if (Platform.OS !== 'web') {
    const WebBrowser = loadWebBrowser();
    if (WebBrowser) {
      try {
        const result = await WebBrowser.openBrowserAsync(url, {
          presentationStyle: WebBrowser.WebBrowserPresentationStyle.PAGE_SHEET,
          toolbarColor: COLORS.navy,
          controlsColor: COLORS.white,
          dismissButtonStyle: 'close',
          showTitle: true,
          createTask: false,
        });

        return result.type === 'cancel' || result.type === 'dismiss' ? 'closed' : 'opened';
      } catch {
        // Fall through to the system browser.
      }
    }
  }

  await Linking.openURL(url);

  return 'opened';
}
