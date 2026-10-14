import { Platform } from 'react-native';

/**
 * Build-time configuration. Every value comes from an EXPO_PUBLIC_* variable, which Metro inlines
 * into the bundle (so the literal `process.env.EXPO_PUBLIC_X` spelling below must be kept).
 *
 * - Dev (`expo start`, __DEV__): reads `.env`, falls back to the simulator/emulator loopback host.
 * - Release (`eas build`, Xcode Release, `gradlew bundleRelease`): reads `.env.production` only and
 *   needs an https host. metro.config.js refuses to build with a missing or CHANGE-ME host, so the
 *   runtime guard below is only a second layer: it never throws at import (an import-time throw
 *   cannot be caught by any error boundary) and instead exposes API_CONFIG_ERROR for the root
 *   layout to render a plain screen.
 */

export const API_CONFIG_ERROR_MESSAGE = 'Konfigurasi server belum diatur';

function stripTrailingSlash(value: string): string {
  return value.replace(/\/+$/, '');
}

function isUsableHttpsUrl(value: string): boolean {
  return /^https:\/\/[^/\s]+/i.test(value) && !/change-me/i.test(value);
}

function resolveApiUrl(): { url: string; error: string | null } {
  const configured = (process.env.EXPO_PUBLIC_API_URL ?? '').trim();

  if (__DEV__) {
    if (configured) {
      return { url: stripTrailingSlash(configured), error: null };
    }

    const host = Platform.OS === 'android' ? '10.0.2.2' : '127.0.0.1';

    return { url: `http://${host}:8000/api/v1`, error: null };
  }

  if (!isUsableHttpsUrl(configured)) {
    return { url: '', error: API_CONFIG_ERROR_MESSAGE };
  }

  return { url: stripTrailingSlash(configured), error: null };
}

const resolved = resolveApiUrl();

/** Laravel API base, e.g. https://host/api/v1 (no trailing slash). Empty when API_CONFIG_ERROR is set. */
export const API_URL: string = resolved.url;

/** Indonesian message when a release build has no usable API host, otherwise null. */
export const API_CONFIG_ERROR: string | null = resolved.error;

function resolveWebUrl(): string {
  const configured = stripTrailingSlash((process.env.EXPO_PUBLIC_WEB_URL ?? '').trim());

  if (configured && (__DEV__ || isUsableHttpsUrl(configured))) {
    return configured;
  }

  return stripTrailingSlash(API_URL.replace(/\/api\/v1\/?$/, ''));
}

/** Public storefront origin (product share links). Empty when unknown. */
export const WEB_URL: string = resolveWebUrl();

function resolveSupportWhatsapp(): string {
  const digits = (process.env.EXPO_PUBLIC_SUPPORT_WHATSAPP ?? '').replace(/\D/g, '');

  // 0812... (national format) -> 62812...; wa.me needs the international form without '+'.
  return digits.startsWith('0') ? `62${digits.slice(1)}` : digits;
}

/** Support WhatsApp number as digits in international format without '+' (e.g. 62812...). Empty when unset. */
export const SUPPORT_WHATSAPP: string = resolveSupportWhatsapp();

function resolveSupportEmail(): string {
  const email = (process.env.EXPO_PUBLIC_SUPPORT_EMAIL ?? '').trim();

  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && !/change-me/i.test(email) ? email : '';
}

/** Support mailbox. Empty when unset. */
export const SUPPORT_EMAIL: string = resolveSupportEmail();
