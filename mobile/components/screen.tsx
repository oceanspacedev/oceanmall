import { CartBadge } from '@/components/cart-badge';
import { SectionSkeleton } from '@/components/skeletons';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { useCart } from '@/lib/cart';
import { ripple } from '@/lib/ripple';
import { COLORS } from '@/lib/theme';
import { useRouter } from 'expo-router';
import { ChevronLeft, ShoppingCart, type LucideIcon } from 'lucide-react-native';
import type { ReactNode } from 'react';
import { Pressable, View } from 'react-native';
import { SafeAreaView } from '@/components/safe-area-view';

/**
 * Where the header cart button goes. One constant so the destination changes in one place
 * (the cart stack page replaces the tab once it exists).
 */
export const CART_HREF = '/keranjang' as const;

/**
 * A 44x44 round icon button for a navy header band: white glyph, Android ripple, iOS press dim,
 * and an optional count pill in the corner. Use it for everything that goes in `right` or
 * `rightExtra` so every header action has the same target size and feedback.
 * `label` is the spoken name (include the count there when the badge matters, since the pill
 * itself is hidden from screen readers).
 */
export function HeaderIconButton({
  icon,
  label,
  onPress,
  badge = 0,
  hint,
  disabled,
}: {
  icon: LucideIcon;
  label: string;
  onPress: () => void;
  badge?: number;
  hint?: string;
  disabled?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={hint}
      accessibilityState={{ disabled: Boolean(disabled) }}
      disabled={disabled}
      onPress={onPress}
      android_ripple={ripple.onNavy}
      className="h-11 w-11 items-center justify-center active:opacity-70">
      <Icon as={icon} size={20} color={COLORS.white} />
      {badge > 0 ? <CartBadge count={badge} /> : null}
    </Pressable>
  );
}

/**
 * Screen for pushed pages: a navy header with Back (and, with `showCart`, the live cart) over a
 * white or light-gray body.
 *
 * `loading` takes `true` for a generic skeleton, or a layout-matching skeleton node (see
 * components/skeletons) so the page does not jump when the data lands. `right` replaces the whole
 * right side; `rightExtra` adds an action (a share button) in front of the cart button.
 */
export function Screen({
  title,
  showCart = false,
  loading = false,
  surface = false,
  right,
  rightExtra,
  children,
}: {
  title: string;
  showCart?: boolean;
  loading?: boolean | ReactNode;
  /** Light-gray page so white Section cards stand out (marketplace layout). */
  surface?: boolean;
  /** Custom header action (replaces the cart button). */
  right?: ReactNode;
  /** Extra header action placed before the cart button (needs a 44pt icon button). */
  rightExtra?: ReactNode;
  children?: ReactNode;
}) {
  const router = useRouter();
  const { itemCount } = useCart();

  function goBack() {
    // A cold start from a link (oceanmall://product/...) has no history to go back to.
    if (router.canGoBack()) {
      router.back();
    } else {
      router.replace('/(tabs)');
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <View className="min-h-[52px] flex-row items-center bg-om-navy px-1">
        <HeaderIconButton icon={ChevronLeft} label="Kembali" onPress={goBack} />
        {/* Keeps the title centred when two actions sit on the right. */}
        {!right && rightExtra && showCart ? <View className="w-11" /> : null}
        <Text
          role="heading"
          aria-level={1}
          numberOfLines={1}
          maxFontSizeMultiplier={1.2}
          className="flex-1 text-center text-[16px] font-bold text-white">
          {title}
        </Text>
        {right ? (
          <View className="min-w-11 items-end justify-center pr-2">{right}</View>
        ) : rightExtra || showCart ? (
          <View className="flex-row items-center">
            {rightExtra}
            {showCart ? (
              <HeaderIconButton
                icon={ShoppingCart}
                label={itemCount > 0 ? `Keranjang, ${itemCount} barang` : 'Keranjang'}
                badge={itemCount}
                onPress={() => router.push(CART_HREF)}
              />
            ) : null}
          </View>
        ) : (
          <View className="h-11 w-11" />
        )}
      </View>
      <View className={surface ? 'flex-1 bg-om-surface' : 'flex-1 bg-background'}>
        {loading ? <LoadingBody content={loading} /> : children}
      </View>
    </SafeAreaView>
  );
}

/**
 * Header band for the tab screens (Kategori, Cari, Akun), the same grammar as Screen's header
 * without the Back button: a 56px navy row with a left-aligned heading, or a pill passed as
 * `children` (the search field), and trailing 44pt icon buttons in `right`
 * (use HeaderIconButton). The tab screen wraps it in its own
 * `<SafeAreaView edges={['top']} className="bg-om-navy">` (from components/safe-area-view).
 */
export function TabHeader({
  title,
  right,
  children,
}: {
  title?: string;
  right?: ReactNode;
  children?: ReactNode;
}) {
  return (
    <View className="min-h-[56px] flex-row items-center gap-2 bg-om-navy px-3">
      {title ? (
        <Text
          role="heading"
          aria-level={1}
          numberOfLines={1}
          maxFontSizeMultiplier={1.2}
          className={
            children
              ? 'shrink text-[17px] font-extrabold text-white'
              : 'flex-1 text-[17px] font-extrabold text-white'
          }>
          {title}
        </Text>
      ) : null}
      {children ? <View className="min-w-0 flex-1">{children}</View> : null}
      {right ? <View className="-mr-1 flex-row items-center">{right}</View> : null}
    </View>
  );
}

/**
 * Skeleton area. The skeleton boxes are hidden from screen readers, so one invisible
 * "Memuat…" label (inside a polite live region) tells VoiceOver and TalkBack what is going on.
 * The area clips, so a skeleton taller than the screen never pushes the layout.
 */
function LoadingBody({ content }: { content: ReactNode }) {
  return (
    <View accessibilityLiveRegion="polite" className="flex-1 overflow-hidden">
      <Text className="absolute h-px w-px opacity-0">Memuat…</Text>
      {content === true ? (
        <View className="flex-1 bg-om-surface">
          <SectionSkeleton />
          <SectionSkeleton />
          <SectionSkeleton />
        </View>
      ) : (
        content
      )}
    </View>
  );
}
