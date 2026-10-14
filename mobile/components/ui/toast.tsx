import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { announce } from '@/lib/a11y';
import { SHADOW } from '@/lib/theme';
import { getToast, subscribeToast, toast, type ToastAction, type ToastTone } from '@/lib/toast';
import { CircleAlert, CircleCheckBig, Info, type LucideIcon } from 'lucide-react-native';
import * as React from 'react';
import { Platform, Pressable, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown, FadeInUp, FadeOutDown, FadeOutUp } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

const TONE_ICON: Record<ToastTone, LucideIcon> = {
  success: CircleCheckBig,
  error: CircleAlert,
  info: Info,
};

/** Default gap above the safe-area bottom: clears a 56px tab bar plus breathing room. */
const DEFAULT_BOTTOM_OFFSET = 72;
/** Header height (52) plus a gap, for toasts anchored to the top. */
const TOP_OFFSET = 52 + 8;

/**
 * Renders the current toast from lib/toast. Mounted once by NetworkProvider.
 *
 * The animated node carries only position in a plain style object; colour, radius
 * and padding live on the non-animated pill inside it (NativeWind className on
 * Reanimated components is fragile). Each toast is absolutely positioned inside a
 * full-screen, touch-transparent wrapper, so a replacement cross-fades in place
 * instead of stacking, and only the pill itself takes touches.
 */
function ToastHost() {
  const current = React.useSyncExternalStore(subscribeToast, getToast, getToast);
  const insets = useSafeAreaInsets();

  // Keep the last placement while the exit animation runs after `current` becomes null.
  const placement = React.useRef({ position: 'bottom' as 'bottom' | 'top', bottomOffset: 0 });
  if (current) {
    placement.current = {
      position: current.position,
      bottomOffset: current.bottomOffset ?? DEFAULT_BOTTOM_OFFSET,
    };
  }
  const { position, bottomOffset } = placement.current;

  const id = current?.id;
  const message = current?.message;
  React.useEffect(() => {
    // Android reads the live region below; iOS needs an explicit announcement.
    if (id && message && Platform.OS === 'ios') {
      announce(message);
    }
  }, [id, message]);

  const top = position === 'top';
  const placementStyle = top
    ? { top: insets.top + TOP_OFFSET }
    : { bottom: insets.bottom + bottomOffset };

  return (
    <View pointerEvents="box-none" accessibilityLiveRegion="polite" style={styles.wrapper}>
      {current ? (
        <Animated.View
          key={current.id}
          entering={top ? FadeInUp.duration(180) : FadeInDown.duration(180)}
          exiting={top ? FadeOutUp.duration(150) : FadeOutDown.duration(150)}
          pointerEvents="box-none"
          style={[styles.toast, placementStyle]}>
          <View
            className="flex-row items-center gap-3 rounded-lg bg-foreground/95 px-4 py-3"
            style={SHADOW.bar}>
            <Icon as={TONE_ICON[current.tone]} size={18} className="text-background" />
            <Text
              className="flex-1 text-[13px] leading-[18px] text-background"
              maxFontSizeMultiplier={1.3}>
              {current.message}
            </Text>
            {current.action ? <ToastActionLink action={current.action} id={current.id} /> : null}
          </View>
        </Animated.View>
      ) : null}
    </View>
  );
}

function ToastActionLink({ action, id }: { action: ToastAction; id: string }) {
  return (
    <Pressable
      role="button"
      hitSlop={{ top: 12, bottom: 12, left: 8, right: 8 }}
      onPress={() => {
        toast.hide(id);
        action.onPress();
      }}
      className="active:opacity-70">
      <Text className="text-[13px] font-bold text-om-navy-soft" maxFontSizeMultiplier={1.2}>
        {action.label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  wrapper: {
    position: 'absolute',
    top: 0,
    bottom: 0,
    left: 0,
    right: 0,
    zIndex: 1000,
  },
  toast: {
    position: 'absolute',
    left: 16,
    right: 16,
  },
});

export { ToastHost };
