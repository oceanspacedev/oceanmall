import { AccessibilityInfo } from 'react-native';

/** True when the OS asks for reduced motion; animated primitives then render statically. */
export { useReducedMotion } from 'react-native-reanimated';

/**
 * Speak a message through VoiceOver / TalkBack. Android also reads
 * `accessibilityLiveRegion` content; iOS needs this explicit call.
 */
export function announce(message: string): void {
  if (!message) {
    return;
  }
  try {
    AccessibilityInfo.announceForAccessibility(message);
  } catch {
    // Announcing is best effort: never let it break the flow that triggered it.
  }
}
