/**
 * Password rules shown while the customer types.
 *
 * These mirror `Password::defaults()` in app/Providers/AppServiceProvider.php, which in production
 * is min(12) + mixedCase + letters + numbers + symbols + uncompromised. Every check here is a
 * strict subset of what Laravel accepts (ASCII letters, digits and symbols only), so a password
 * that passes on the phone is never rejected by the server for these reasons.
 *
 * `uncompromised()` (the breach check against Have I Been Pwned) cannot be done on the client, so a
 * password that ticks every box can still come back as a validation error. The screens show that
 * server message under the field.
 */

export type PasswordRule = {
  key: 'length' | 'case' | 'number' | 'symbol';
  label: string;
  test: (value: string) => boolean;
};

export const PASSWORD_RULES: PasswordRule[] = [
  {
    key: 'length',
    label: 'Minimal 12 karakter',
    // Laravel counts characters (mb_strlen), not UTF-16 units, so an emoji is one.
    test: (value) => Array.from(value).length >= 12,
  },
  {
    key: 'case',
    label: 'Huruf besar dan kecil',
    test: (value) => /[a-z]/.test(value) && /[A-Z]/.test(value),
  },
  {
    key: 'number',
    label: 'Minimal 1 angka',
    test: (value) => /[0-9]/.test(value),
  },
  {
    key: 'symbol',
    label: 'Minimal 1 simbol (!@#$...)',
    // Every printable ASCII punctuation and symbol character.
    test: (value) => /[!-\/:-@\[-`{-~]/.test(value),
  },
];

export function passwordMeetsRules(value: string): boolean {
  return PASSWORD_RULES.every((rule) => rule.test(value));
}

/**
 * iOS `passwordRules` for the new-password field, so the system strong-password suggestion
 * satisfies the same policy.
 */
export const PASSWORD_RULES_STRING =
  'minlength: 12; required: lower; required: upper; required: digit; required: special;';
