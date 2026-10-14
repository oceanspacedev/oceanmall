import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { announce } from '@/lib/a11y';
import { cn } from '@/lib/utils';
import { CircleAlert } from 'lucide-react-native';
import { useEffect } from 'react';
import { Platform, Pressable, View } from 'react-native';

/**
 * Inline error that sits above the content it is about (a failed refresh, a rejected action).
 * Use EmptyState with tone="error" when there is nothing to show at all.
 *
 * Pass `onRetry` only for a failed load. For a failed action (delete address, apply promo) the
 * user's own control is the retry, so leave it off.
 *
 * Android reads the live region when the banner appears; iOS needs the explicit announcement,
 * so the message is spoken once per change on iOS here and callers need no announce() of their own.
 * Inside a StickyBar pass `className="mx-0 mt-0 mb-2.5"` to drop the page margins.
 */
export function ErrorBanner({
  message,
  onRetry,
  className,
}: {
  message: string;
  onRetry?: () => void;
  className?: string;
}) {
  useEffect(() => {
    if (Platform.OS === 'ios') {
      announce(message);
    }
  }, [message]);

  return (
    <View
      accessibilityRole="alert"
      accessibilityLiveRegion="polite"
      className={cn(
        'mx-4 mt-3 flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2.5',
        className
      )}>
      <Icon as={CircleAlert} size={15} className="text-om-sale" />
      <Text className="flex-1 text-[12px] leading-4 text-om-sale-text">{message}</Text>
      {onRetry ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Coba lagi"
          hitSlop={{ top: 14, bottom: 14, left: 12, right: 12 }}
          onPress={onRetry}
          className="active:opacity-60">
          <Text className="text-[12px] font-bold text-om-navy">Coba lagi</Text>
        </Pressable>
      ) : null}
    </View>
  );
}
