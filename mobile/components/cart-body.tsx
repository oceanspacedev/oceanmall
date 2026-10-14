import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { ProductImage } from '@/components/product-image';
import { HeaderIconButton } from '@/components/screen';
import { QtyStepper, StickyBar, SummaryRow } from '@/components/shop';
import { CartLineSkeleton, SectionSkeleton } from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type Cart } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { cartItemCount, useCart } from '@/lib/cart';
import { formatIdr, mediaUrl } from '@/lib/format';
import { useNetwork } from '@/lib/network';
import { ripple } from '@/lib/ripple';
import { toast } from '@/lib/toast';
import { useRefresh } from '@/lib/use-refresh';
import { cn } from '@/lib/utils';
import { Link, useFocusEffect, useRouter, useScrollToTop, type Href } from 'expo-router';
import {
  ChevronRight,
  CircleAlert,
  ShieldCheck,
  ShoppingCart,
  Store,
  Ticket,
  Trash2,
  WifiOff,
  X,
} from 'lucide-react-native';
import { useCallback, useRef, useState, type ReactNode } from 'react';
import {
  Alert as RNAlert,
  Keyboard,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  View,
} from 'react-native';

/** Mirrors CustomerCart::MAX_LINE_QUANTITY on the API. */
const MAX_LINE_QUANTITY = 10;

type CartLine = Cart['lines'][number];

/**
 * Asks before emptying the cart, then does it. Returned as a callback so the header button of
 * both cart screens (the tab and the stack page) can share one implementation.
 */
function useClearCart(): () => void {
  const { applyCart } = useCart();

  return useCallback(() => {
    async function clear() {
      try {
        const res = await api<{ data: Cart }>('/cart', { method: 'DELETE' });
        applyCart(res.data);
        toast.show({ tone: 'info', message: 'Keranjang dikosongkan' });
      } catch (e) {
        toast.show({ tone: 'error', message: errorMessage(e, 'Gagal mengosongkan keranjang') });
      }
    }

    RNAlert.alert('Kosongkan keranjang?', 'Semua produk akan dihapus.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Kosongkan', style: 'destructive', onPress: () => void clear() },
    ]);
  }, [applyCart]);
}

/** Trash icon for the header of a cart screen. Renders nothing while the cart is empty. */
export function ClearCartButton() {
  const { itemCount } = useCart();
  const clearCart = useClearCart();

  if (itemCount === 0) {
    return null;
  }

  return <HeaderIconButton icon={Trash2} label="Kosongkan keranjang" onPress={clearCart} />;
}

/**
 * The cart itself: lines, promo code, summary and the checkout bar. Shared by the Keranjang tab
 * (`inTabs`: the tab bar already covers the home-indicator inset, so the bar sits flush on it, and
 * toasts drop in from the top so they never hide behind it) and the stack page /keranjang that the
 * header cart button and the "Lihat Keranjang" toast open.
 */
export function CartBody({ inTabs = false }: { inTabs?: boolean }) {
  const { user } = useAuth();
  const { cart, applyCart } = useCart();
  const { offline } = useNetwork();
  const router = useRouter();
  const scrollRef = useRef<ScrollView>(null);
  const [loading, setLoading] = useState(!cart);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [coupon, setCoupon] = useState('');
  const [couponOpen, setCouponOpen] = useState(false);
  const [couponBusy, setCouponBusy] = useState(false);
  const [couponError, setCouponError] = useState<string | null>(null);
  const [lineBusy, setLineBusy] = useState<number | null>(null);

  // On the stack page there is no tab navigator above, so this is a no-op there.
  useScrollToTop(scrollRef);

  const load = useCallback(async () => {
    setLoadError(null);
    try {
      const res = await api<{ data: Cart }>('/cart');
      applyCart(res.data);
    } catch (e) {
      setLoadError(errorMessage(e, 'Gagal memuat keranjang'));
    } finally {
      setLoading(false);
    }
  }, [applyCart]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load])
  );

  const { refreshing, onRefresh } = useRefresh(load);

  async function applyCoupon() {
    const code = coupon.trim();
    if (code === '' || couponBusy) {
      return;
    }
    setCouponBusy(true);
    setCouponError(null);
    try {
      const res = await api<{ data: Cart }>('/cart/coupon', {
        method: 'POST',
        body: JSON.stringify({ code }),
      });
      applyCart(res.data);
      setCoupon('');
      setCouponOpen(false);
      Keyboard.dismiss();
      const saved = res.data.totals?.discount ?? 0;
      const applied = res.data.coupon_code ?? code;
      toast.show({
        tone: 'success',
        message:
          saved > 0
            ? `Kode promo ${applied} terpasang, hemat ${formatIdr(saved)}`
            : `Kode promo ${applied} terpasang`,
        position: 'top',
      });
    } catch (e) {
      setCouponError(errorMessage(e, 'Kode promo tidak valid'));
    } finally {
      setCouponBusy(false);
    }
  }

  async function removeCoupon() {
    setCouponBusy(true);
    setCouponError(null);
    try {
      const res = await api<{ data: Cart }>('/cart/coupon', { method: 'DELETE' });
      applyCart(res.data);
    } catch (e) {
      setCouponError(errorMessage(e, 'Gagal melepas kode promo'));
    } finally {
      setCouponBusy(false);
    }
  }

  // Toasts on the tab drop in from the top; on the stack page the default bottom spot clears the bar.
  const placement = inTabs ? ({ position: 'top' } as const) : {};

  async function changeQty(lineId: number, quantity: number) {
    setLineBusy(lineId);
    try {
      const res = await api<{ data: Cart }>(`/cart/items/${lineId}`, {
        method: 'PATCH',
        body: JSON.stringify({ quantity }),
      });
      applyCart(res.data);
    } catch (e) {
      toast.show({
        tone: 'error',
        message: errorMessage(e, 'Gagal mengubah jumlah'),
        ...placement,
      });
    } finally {
      setLineBusy(null);
    }
  }

  async function removeLine(line: CartLine) {
    setLineBusy(line.id);
    try {
      const res = await api<{ data: Cart }>(`/cart/items/${line.id}`, { method: 'DELETE' });
      applyCart(res.data);
      toast.show({ tone: 'info', message: 'Produk dihapus dari keranjang', ...placement });
    } catch (e) {
      toast.show({
        tone: 'error',
        message: errorMessage(e, 'Gagal menghapus produk'),
        ...placement,
      });
    } finally {
      setLineBusy(null);
    }
  }

  function confirmRemove(line: CartLine) {
    // Keep Batal first: Android ignores the button styles and lists them in order.
    RNAlert.alert('Hapus produk dari keranjang?', line.name, [
      { text: 'Batal', style: 'cancel' },
      { text: 'Hapus', style: 'destructive', onPress: () => void removeLine(line) },
    ]);
  }

  function checkout() {
    if (!user) {
      router.push({ pathname: '/login', params: { redirect: '/checkout' } });
      return;
    }
    router.push('/checkout');
  }

  const lines = cart?.lines ?? [];
  const count = cartItemCount(cart);
  const totals = cart?.totals;
  const discount = totals?.discount ?? 0;
  const tax = totals?.tax ?? 0;
  const updating = lineBusy !== null || couponBusy;

  let content: ReactNode;
  if (loading) {
    content = (
      <View accessibilityLiveRegion="polite" className="flex-1 overflow-hidden">
        <Text className="absolute h-px w-px opacity-0">Memuat…</Text>
        <View className="mt-2">
          <CartLineSkeleton />
          <View className="h-2" />
          <CartLineSkeleton />
        </View>
        <SectionSkeleton />
      </View>
    );
  } else if (loadError && lines.length === 0) {
    content = (
      <EmptyState
        tone="error"
        icon={offline ? WifiOff : CircleAlert}
        message={loadError}
        actionLabel="Coba lagi"
        onAction={() => void load()}
        className="bg-om-surface"
      />
    );
  } else if (lines.length === 0) {
    content = (
      <EmptyState
        icon={ShoppingCart}
        title="Wah, keranjangmu kosong"
        message="Yuk, isi dengan produk incaranmu dan nikmati promo hari ini."
        actionLabel="Mulai belanja"
        // In the tab, Beranda is a sibling tab (navigate); on the stack page it is the screen
        // underneath (dismissTo pops to it instead of mounting a second tab navigator).
        onAction={() => (inTabs ? router.navigate('/(tabs)') : router.dismissTo('/(tabs)'))}
        className="bg-om-surface"
      />
    );
  } else {
    content = (
      <>
        <ScrollView
          ref={scrollRef}
          className="flex-1"
          contentContainerClassName="pb-3"
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          // iOS insets the scroll content itself; Android is handled by the KeyboardAvoidingView
          // around this body. Using both on iOS would adjust twice and leave a blank gap.
          automaticallyAdjustKeyboardInsets
          refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
          {loadError ? <ErrorBanner message={loadError} onRetry={() => void load()} /> : null}

          <View className="mt-2 bg-white">
            <View className="flex-row items-center gap-2 border-b border-border px-4 py-3">
              <Icon as={Store} size={16} className="text-om-navy" />
              <Text className="flex-1 text-[14px] font-bold">OceanMall Official</Text>
              <View className="flex-row items-center gap-1 rounded-sm bg-om-navy-soft px-1.5 py-0.5">
                <Icon as={ShieldCheck} size={12} className="text-om-navy" />
                <Text className="text-[11px] font-bold text-om-navy">Resmi</Text>
              </View>
            </View>
            {lines.map((line, index) => (
              <CartLineRow
                key={line.id}
                line={line}
                busy={lineBusy === line.id}
                divided={index < lines.length - 1}
                onQty={(quantity) => void changeQty(line.id, quantity)}
                onRemove={() => confirmRemove(line)}
              />
            ))}
          </View>

          {/* Promo code */}
          <View className="mt-2 bg-white px-4 py-3">
            {cart?.coupon_code ? (
              <View className="flex-row items-center gap-3 rounded-sm border border-dashed border-om-success bg-om-success-soft py-1.5 pl-3 pr-1">
                <Icon as={Ticket} size={18} className="text-om-success" />
                <View className="flex-1">
                  <Text className="text-[13px] font-bold text-om-success">
                    Kode {cart.coupon_code} dipakai
                  </Text>
                  {discount > 0 ? (
                    <Text className="text-[12px] text-om-success">Hemat {formatIdr(discount)}</Text>
                  ) : null}
                </View>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="Lepas kode promo"
                  disabled={couponBusy}
                  onPress={() => void removeCoupon()}
                  android_ripple={ripple.icon}
                  className="h-10 w-10 items-center justify-center active:opacity-70">
                  <Icon as={X} size={16} className="text-om-success" />
                </Pressable>
              </View>
            ) : couponOpen ? (
              <View className="flex-row items-center gap-2">
                <Input
                  autoFocus
                  accessibilityLabel="Kode promo"
                  placeholder="Masukkan kode promo"
                  value={coupon}
                  onChangeText={(value) => {
                    setCoupon(value);
                    if (couponError) {
                      setCouponError(null);
                    }
                  }}
                  invalid={Boolean(couponError)}
                  autoCapitalize="characters"
                  autoCorrect={false}
                  returnKeyType="done"
                  onSubmitEditing={() => void applyCoupon()}
                  className="flex-1"
                />
                <Button
                  className="min-h-11 rounded-sm px-4"
                  loading={couponBusy}
                  disabled={coupon.trim() === ''}
                  onPress={() => void applyCoupon()}>
                  <Text className="font-bold">Pakai</Text>
                </Button>
              </View>
            ) : (
              <Pressable
                accessibilityRole="button"
                onPress={() => setCouponOpen(true)}
                android_ripple={ripple.row}
                className="min-h-11 flex-row items-center gap-3 active:opacity-70">
                <View className="size-9 items-center justify-center rounded-sm bg-om-navy-soft">
                  <Icon as={Ticket} size={18} className="text-om-navy" />
                </View>
                <Text className="flex-1 text-[14px] font-semibold">Pakai kode promo</Text>
                <Icon as={ChevronRight} size={16} className="text-muted-foreground" />
              </Pressable>
            )}
            {couponError ? <ErrorBanner message={couponError} className="mx-0 mt-2" /> : null}
          </View>

          {/* Summary */}
          <View className="mt-2 bg-white px-4 py-3">
            <Text role="heading" aria-level={2} className="mb-1 text-[14px] font-bold">
              Ringkasan belanja
            </Text>
            <SummaryRow
              label={`Total harga (${count} barang)`}
              value={formatIdr(totals?.subtotal)}
            />
            {discount > 0 ? (
              <SummaryRow
                label="Diskon promo"
                value={`−${formatIdr(discount)}`}
                valueClassName="text-om-success"
              />
            ) : null}
            {tax > 0 ? <SummaryRow label="Pajak" value={formatIdr(tax)} /> : null}
            <SummaryRow
              label="Ongkos kirim"
              value="Dihitung saat checkout"
              valueClassName="text-muted-foreground"
            />
          </View>
        </ScrollView>

        <StickyBar safeBottom={!inTabs}>
          <View className="flex-row items-center gap-3">
            <View className="min-w-0 flex-1">
              <Text className="text-[12px] text-muted-foreground">Total harga</Text>
              <Text className="text-[18px] font-extrabold text-om-navy" numberOfLines={1}>
                {formatIdr(totals?.total)}
              </Text>
            </View>
            <Button onPress={checkout} loading={updating} className="min-h-11 rounded-sm px-6">
              <Text className="font-bold">{user ? `Checkout (${count})` : 'Masuk & checkout'}</Text>
            </Button>
          </View>
        </StickyBar>
      </>
    );
  }

  return Platform.OS === 'android' ? (
    <KeyboardAvoidingView behavior="height" style={{ flex: 1 }} className="bg-om-surface">
      {content}
    </KeyboardAvoidingView>
  ) : (
    <View className="flex-1 bg-om-surface">{content}</View>
  );
}

function CartLineRow({
  line,
  busy,
  divided,
  onQty,
  onRemove,
}: {
  line: CartLine;
  busy: boolean;
  divided?: boolean;
  onQty: (quantity: number) => void;
  onRemove: () => void;
}) {
  const href: Href | null = line.slug ? `/product/${line.slug}` : null;

  return (
    <View
      className={cn(
        'flex-row gap-3 px-4 py-3.5',
        divided && 'border-b border-border',
        busy && 'opacity-50'
      )}>
      <LineThumb uri={mediaUrl(line.thumbnail)} href={href} />
      <View className="min-w-0 flex-1">
        <LineName name={line.name} href={href} />
        {line.variant ? (
          <View className="mt-1 self-start rounded-sm bg-om-surface px-1.5 py-0.5">
            <Text className="text-[12px] text-muted-foreground">{line.variant}</Text>
          </View>
        ) : null}
        <Text className="mt-1.5 text-[15px] font-extrabold text-foreground">
          {formatIdr(line.unit_price)}
        </Text>
        <View className="mt-1 flex-row items-center justify-end gap-1">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`Hapus ${line.name} dari keranjang`}
            disabled={busy}
            onPress={onRemove}
            android_ripple={ripple.icon}
            className="h-11 w-11 items-center justify-center active:opacity-70">
            <Icon as={Trash2} size={18} className="text-muted-foreground" />
          </Pressable>
          <QtyStepper
            size="sm"
            value={line.quantity}
            max={MAX_LINE_QUANTITY}
            busy={busy}
            itemLabel={line.name}
            onChange={onQty}
          />
        </View>
        {line.quantity > 1 ? (
          <Text className="mt-1 self-end text-[12px] text-muted-foreground">
            Subtotal {formatIdr(line.unit_price * line.quantity)}
          </Text>
        ) : null}
      </View>
    </View>
  );
}

function LineThumb({ uri, href }: { uri: string | undefined; href: Href | null }) {
  const image = <ProductImage uri={uri} width={84} height={84} radius={6} contentFit="cover" />;

  if (!href) {
    return image;
  }

  // The product name next to it is the link for screen readers; the picture only needs the tap.
  return (
    <Link href={href} asChild>
      <Pressable
        accessibilityElementsHidden
        importantForAccessibility="no-hide-descendants"
        className="active:opacity-80">
        {image}
      </Pressable>
    </Link>
  );
}

function LineName({ name, href }: { name: string; href: Href | null }) {
  const text = (
    <Text className="text-[14px] leading-5" numberOfLines={2}>
      {name}
    </Text>
  );

  if (!href) {
    return text;
  }

  return (
    <Link href={href} asChild>
      <Pressable accessibilityRole="link" className="active:opacity-60">
        {text}
      </Pressable>
    </Link>
  );
}
