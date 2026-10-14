/**
 * One-shot "just registered" flag.
 *
 * register.tsx sets it after a successful sign-up and the Akun tab consumes it the next time it
 * gains focus, to show the welcome banner exactly once. It lives in memory on purpose: a route
 * param can be dropped by a POP_TO navigation, and nothing here needs to survive a restart.
 */

let pending = false;

export function setWelcomePending(value: boolean): void {
  pending = value;
}

/** Returns true once after `setWelcomePending(true)`, then false until it is set again. */
export function consumeWelcomePending(): boolean {
  const value = pending;
  pending = false;

  return value;
}
