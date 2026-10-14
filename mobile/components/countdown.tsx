import { Text } from '@/components/ui/text';
import { useCountdown, type CountdownState } from '@/lib/countdown';
import { cn } from '@/lib/utils';
import { View } from 'react-native';

/**
 * The 'HH:MM:SS' pill for a payment deadline: navy while there is time, red once under an hour
 * (or over). Digits are tabular, so the pill does not jitter as they change. It is one accessible
 * "timer" whose spoken value only changes by the minute.
 *
 * Use `<Countdown expiry onExpire />` when the pill is all you need, or call `useCountdown`
 * yourself (when the screen also needs `expired`) and pass the result as `state`.
 */
export function Countdown({
  expiry,
  onExpire,
  state,
  size = 'md',
  className,
}: {
  expiry?: string | null;
  onExpire?: () => void;
  /** An already computed countdown (from useCountdown); `expiry` is then ignored. */
  state?: CountdownState | null;
  size?: 'md' | 'lg';
  className?: string;
}) {
  const own = useCountdown(state === undefined ? expiry : null, onExpire);
  const value = state === undefined ? own : state;

  if (!value) {
    return null;
  }

  const alert = value.urgent || value.expired;

  return (
    <View
      accessible
      accessibilityRole="timer"
      accessibilityLabel={`Sisa waktu pembayaran: ${value.spoken}`}
      className={cn(
        'self-start rounded-sm',
        size === 'lg' ? 'px-3 py-1.5' : 'px-2 py-0.5',
        alert ? 'bg-om-sale-soft' : 'bg-om-navy-soft',
        className
      )}>
      <Text
        maxFontSizeMultiplier={1.2}
        style={{ fontVariant: ['tabular-nums'] }}
        className={cn(
          'font-extrabold',
          size === 'lg' ? 'text-[20px]' : 'text-[14px]',
          alert ? 'text-om-sale-text' : 'text-om-navy'
        )}>
        {value.text}
      </Text>
    </View>
  );
}
