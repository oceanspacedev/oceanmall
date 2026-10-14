import { useReducedMotion } from '@/lib/a11y';
import { cssInterop } from 'nativewind';
import * as React from 'react';
import {
  Pressable,
  type GestureResponderEvent,
  type PressableProps,
  type StyleProp,
  type View,
  type ViewStyle,
} from 'react-native';
import Animated, {
  interpolate,
  useAnimatedStyle,
  useSharedValue,
  withTiming,
} from 'react-native-reanimated';

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

const PRESS_IN_MS = 80;
const PRESS_OUT_MS = 120;
const PRESSED_SCALE = 0.97;
const PRESSED_OPACITY = 0.9;

type PressableScaleProps = Omit<PressableProps, 'style'> &
  React.RefAttributes<View> & {
    /** Resolved from `className` by NativeWind (see the cssInterop call below). */
    style?: StyleProp<ViewStyle>;
    className?: string;
  };

/**
 * Press feedback for tappable surfaces that are cards, banners or tiles: the
 * surface shrinks to 0.97 and dims slightly while held. Text rows should use
 * `active:opacity-70` or an Android ripple instead.
 *
 * It is a drop-in Pressable: every other prop (including what `<Link asChild>`
 * injects, such as onPress, href and role) is forwarded, and onPressIn/onPressOut
 * are composed with the animation, never replaced. With Reduce Motion on the scale
 * is skipped and the dim is instant, so the press is still acknowledged.
 */
function PressableScale({ style, onPressIn, onPressOut, ...props }: PressableScaleProps) {
  const reduceMotion = useReducedMotion();
  const pressed = useSharedValue(0);

  // Reanimated owns the animated node's transform/opacity; layout, colour and radius
  // arrive through the plain `style` prop.
  const feedback = useAnimatedStyle(() => ({
    opacity: interpolate(pressed.value, [0, 1], [1, PRESSED_OPACITY]),
    transform: [
      { scale: interpolate(pressed.value, [0, 1], [1, reduceMotion ? 1 : PRESSED_SCALE]) },
    ],
  }));

  function handlePressIn(event: GestureResponderEvent) {
    pressed.value = reduceMotion ? 1 : withTiming(1, { duration: PRESS_IN_MS });
    onPressIn?.(event);
  }

  function handlePressOut(event: GestureResponderEvent) {
    pressed.value = reduceMotion ? 0 : withTiming(0, { duration: PRESS_OUT_MS });
    onPressOut?.(event);
  }

  return (
    <AnimatedPressable
      {...props}
      style={[style, feedback]}
      onPressIn={handlePressIn}
      onPressOut={handlePressOut}
    />
  );
}

// `className` becomes the `style` prop of this plain function component, which then
// hands it to the animated Pressable inside.
cssInterop(PressableScale, { className: 'style' });

export { PressableScale };
export type { PressableScaleProps };
