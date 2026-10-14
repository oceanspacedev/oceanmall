import { useReducedMotion } from '@/lib/a11y';
import { COLORS } from '@/lib/theme';
import { cn } from '@/lib/utils';
import * as React from 'react';
import { StyleSheet, View, type LayoutChangeEvent } from 'react-native';
import Animated, {
  cancelAnimation,
  Easing,
  interpolate,
  makeMutable,
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withTiming,
} from 'react-native-reanimated';

const SHIMMER_DURATION_MS = 1100;
/** Width of the highlight band, as a fraction of the skeleton's own width. */
const BAND = 0.4;

/**
 * One shimmer clock for every skeleton on screen (0 -> 1, linear, repeating), so
 * a screen full of placeholders runs a single animation instead of one per box and
 * all the highlights sweep in step. It runs only while at least one is mounted.
 */
const shimmerClock = makeMutable(0);
let mounted = 0;

function retainClock(): void {
  mounted += 1;
  if (mounted === 1) {
    shimmerClock.value = 0;
    shimmerClock.value = withRepeat(
      withTiming(1, { duration: SHIMMER_DURATION_MS, easing: Easing.linear }),
      -1,
      false
    );
  }
}

function releaseClock(): void {
  mounted = Math.max(0, mounted - 1);
  if (mounted === 0) {
    cancelAnimation(shimmerClock);
  }
}

function Skeleton({
  className,
  onLayout,
  children,
  ...props
}: React.ComponentProps<typeof View> & React.RefAttributes<View>) {
  const reduceMotion = useReducedMotion();
  const width = useSharedValue(0);

  React.useEffect(() => {
    if (reduceMotion) {
      return;
    }
    retainClock();
    return releaseClock;
  }, [reduceMotion]);

  // Layout, colour and radius of an animated node live in a plain style object
  // (NativeWind className on Reanimated components is fragile).
  const bandStyle = useAnimatedStyle(() => ({
    transform: [
      {
        translateX: interpolate(shimmerClock.value, [0, 1], [-width.value * BAND, width.value]),
      },
    ],
  }));

  function handleLayout(event: LayoutChangeEvent) {
    width.value = event.nativeEvent.layout.width;
    onLayout?.(event);
  }

  return (
    <View
      // Empty boxes carry no information: keep screen readers from stepping through them.
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      className={cn('overflow-hidden rounded-md bg-accent', className)}
      onLayout={handleLayout}
      {...props}>
      {reduceMotion ? null : (
        <Animated.View pointerEvents="none" style={[styles.band, bandStyle]} />
      )}
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  band: {
    position: 'absolute',
    top: 0,
    bottom: 0,
    left: 0,
    width: `${BAND * 100}%`,
    backgroundColor: COLORS.white,
    opacity: 0.6,
  },
});

export { Skeleton };
