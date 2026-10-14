import { Text } from '@/components/ui/text';
import { cn } from '@/lib/utils';
import { useEffect, useRef } from 'react';
import { View } from 'react-native';
import Animated, {
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withSequence,
  withSpring,
} from 'react-native-reanimated';

/**
 * Red count pill for the corner of an icon button. Renders nothing for 0.
 * The parent button carries the spoken count in its accessibilityLabel, so the pill is hidden
 * from screen readers. A growing count gives the pill a small spring bump.
 */
export function CartBadge({ count, className }: { count: number; className?: string }) {
  const reduceMotion = useReducedMotion();
  const scale = useSharedValue(1);
  // Starts at the mount value: opening a screen never bumps.
  const previous = useRef(count);

  useEffect(() => {
    // Only a badge that is already showing bumps. 0 -> N is the first cart load (or the first
    // item), and the pill simply appears.
    if (!reduceMotion && previous.current > 0 && count > previous.current) {
      scale.value = withSequence(withSpring(1.35, { damping: 6, stiffness: 300 }), withSpring(1));
    }
    previous.current = count;
  }, [count, reduceMotion, scale]);

  const bump = useAnimatedStyle(() => ({ transform: [{ scale: scale.value }] }));

  if (count <= 0) {
    return null;
  }

  return (
    <View
      pointerEvents="none"
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      className={cn('absolute right-1 top-1', className)}>
      <Animated.View style={bump}>
        <View className="min-w-[16px] items-center rounded-full bg-om-sale px-1">
          <Text className="text-[10px] font-extrabold text-white" maxFontSizeMultiplier={1.2}>
            {count > 99 ? '99+' : count}
          </Text>
        </View>
      </Animated.View>
    </View>
  );
}
