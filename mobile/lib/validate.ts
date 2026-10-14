/**
 * Small client-side checks for inline field errors. They are never stricter than the server:
 * the point is to catch an obvious typo before the request, not to replace validation.
 */

export const EMAIL_ERROR = 'Email tidak valid';
export const PASSWORD_MISMATCH_ERROR = 'Kata sandi tidak sama';
export const REQUIRED_ERROR = 'Wajib diisi';

/** Lax on purpose: something@something.tld. */
export function isEmail(value: string): boolean {
  return /^\S+@\S+\.\S+$/.test(value.trim());
}

/** The error for an email field, or null. An empty value is "required", not "invalid". */
export function emailError(value: string): string | null {
  if (value.trim() === '') {
    return REQUIRED_ERROR;
  }

  return isEmail(value) ? null : EMAIL_ERROR;
}

/**
 * Error for a phone number field, or null when it is fine or empty (use `required` where the
 * number is mandatory). Spaces, dashes and parentheses are ignored; an optional leading + is
 * allowed; 8 to 15 digits. The server stores up to 20 characters as typed.
 */
export function phoneError(value: string): string | null {
  const raw = value.trim();
  if (raw === '') {
    return null;
  }
  if (raw.length > 20) {
    return 'Nomor HP terlalu panjang';
  }

  const compact = raw.replace(/[\s\-()]/g, '');

  return /^\+?\d{8,15}$/.test(compact) ? null : 'Nomor HP tidak valid';
}

/** 'Wajib diisi' (or `message`) for a blank value, otherwise null. */
export function required(value: string, message = REQUIRED_ERROR): string | null {
  return value.trim() === '' ? message : null;
}
