import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Spinner } from '@/components/ui/spinner';
import { Text } from '@/components/ui/text';
import { announce } from '@/lib/a11y';
import { CircleAlert } from 'lucide-react-native';
import { useEffect } from 'react';
import { Platform, Pressable, View } from 'react-native';

const DEFAULT_ERROR = 'Gagal memuat produk berikutnya';
const DEFAULT_END = 'Kamu sudah melihat semua produk';

/**
 * Footer for paged lists (use it as `ListFooterComponent`). One row at a time:
 *
 * - `error`   a failed next page: message plus a "Coba lagi" link wired to `onRetry`. Pass a
 *             string to replace the default message, or `true` for the default.
 * - `loading` the next page is on its way: a small spinner and "Memuat…".
 * - `onPress` (legacy lists without infinite scroll) a manual "Muat lebih banyak" button.
 * - end       with `showEnd` and `hasMore === false` (and `count` above 0): a quiet
 *             "Kamu sudah melihat semua produk" between two hairlines.
 *
 * `hasMore` is the current name; `visible` is the old one and is only read when `hasMore` is not
 * given, so lists that have not moved over yet keep working.
 */
export function LoadMore({
  hasMore,
  visible,
  loading = false,
  onPress,
  count,
  error,
  onRetry,
  endLabel,
  showEnd = false,
}: {
  hasMore?: boolean;
  /** @deprecated Use `hasMore`. */
  visible?: boolean;
  loading?: boolean;
  /** Manual "Muat lebih banyak" button, for lists that do not scroll infinitely. */
  onPress?: () => void;
  /** Rows currently shown; the end marker needs at least one. */
  count?: number;
  error?: boolean | string | null;
  onRetry?: () => void;
  endLabel?: string;
  showEnd?: boolean;
}) {
  const more = hasMore ?? visible ?? false;
  const failed = Boolean(error) && more;
  const message = typeof error === 'string' && error !== '' ? error : DEFAULT_ERROR;

  useEffect(() => {
    // Android reads the live region below; iOS needs the explicit announcement.
    if (failed && Platform.OS === 'ios') {
      announce(message);
    }
  }, [failed, message]);

  if (failed) {
    return (
      <View
        accessibilityRole="alert"
        accessibilityLiveRegion="polite"
        className="min-h-14 flex-row items-center justify-center gap-2 px-4">
        <Icon as={CircleAlert} size={14} className="text-om-sale" />
        <Text className="shrink text-[12px] text-muted-foreground">{message}</Text>
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

  if (more && loading) {
    return (
      <View
        accessibilityLiveRegion="polite"
        className="h-14 flex-row items-center justify-center gap-2">
        <Spinner
          size="small"
          accessibilityElementsHidden
          importantForAccessibility="no-hide-descendants"
        />
        <Text className="text-[12px] text-muted-foreground">Memuat…</Text>
      </View>
    );
  }

  if (more && onPress) {
    return (
      <View className="mt-2 items-center">
        <Button variant="outline" onPress={onPress}>
          <Text>Muat lebih banyak</Text>
        </Button>
      </View>
    );
  }

  if (hasMore === false && showEnd && (count ?? 1) > 0) {
    return (
      <View className="h-14 flex-row items-center justify-center gap-3 px-4">
        <View className="h-px w-10 bg-border" />
        <Text className="text-[12px] text-muted-foreground">{endLabel ?? DEFAULT_END}</Text>
        <View className="h-px w-10 bg-border" />
      </View>
    );
  }

  return null;
}
