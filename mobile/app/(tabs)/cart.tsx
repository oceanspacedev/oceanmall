import { EmptyState } from '@/components/empty-state';
import { QtyStepper, StickyBar, SummaryRow } from '@/components/shop';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type Cart } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { cartItemCount, useCart } from '@/lib/cart';
import { useNetwork } from '@/lib/network';
import { StatusBar } from 'expo-status-bar';
import { formatIdr, mediaUrl } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Image } from 'expo-image';
import { Link, useFocusEffect, useRouter, type Href } from 'expo-router';
import {
  ChevronRight,
  CircleAlert,
  ShieldCheck,
  ShoppingBag,
  Store,
  Ticket,
  Trash2,
  WifiOff,
  X,
} from 'lucide-react-native';
import { useCallback, useState } from 'react';
import {
  Alert as RNAlert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export default function CartScreen() {
  const { user } = useAuth();
  const { cart, applyCart } = useCart();
  const { offline } = useNetwork();
  const router = useRouter();
  const [loading, setLoading] = useState(!cart);
  const [error, setError] = useState<string | null>(null);
  const [coupon, setCoupon] = useState('');
  const [couponBusy, setCouponBusy] = useState(false);
  const [lineBusy, setLineBusy] = useState<number | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [couponOpen, setCouponOpen] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const res = await api<{ data: Cart }>('/cart');
      applyCart(res.data);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat keranjang'));
    } finally {
      setLoading(false);
    }
  }, [applyCart]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load])
  );

  async function onRefresh() {
    setRefreshing(true);
    try {
      await load();
    } finally {
      setRefreshing(false);
    }
  }

  async function applyCoupon() {
    const code = coupon.trim();
    if (code === '') {
      return;
    }
    setCouponBusy(true);
    setError(null);
    try {
      const res = await api<{ data: Cart }>('/cart/coupon', {
        method: 'POST',
        body: JSON.stringify({ code }),
      });
      applyCart(res.data);
      setCoupon('');
      setCouponOpen(false);
    } catch (e) {
      setError(errorMessage(e, 'Kupon tidak valid'));
    } finally {
      setCouponBusy(false);
    }
  }

  async function removeCoupon() {
    setCouponBusy(true);
    setError(null);
    try {
      const res = await api<{ data: Cart }>('/cart/coupon', { method: 'DELETE' });
      applyCart(res.data);
    } catch (e) {
      setError(errorMessage(e, 'Gagal menghapus kupon'));
    } finally {
      setCouponBusy(false);
    }
  }

  async function changeQty(lineId: number, quantity: number) {
    setLineBusy(lineId);
    setError(null);
    try {
      if (quantity < 1) {
        const res = await api<{ data: Cart }>(`/cart/items/${lineId}`, { method: 'DELETE' });
        applyCart(res.data);
        return;
      }
      const res = await api<{ data: Cart }>(`/cart/items/${lineId}`, {
        method: 'PATCH',
        body: JSON.stringify({ quantity }),
      });
      applyCart(res.data);
    } catch (e) {
      setError(errorMessage(e, 'Gagal mengubah jumlah'));
    } finally {
      setLineBusy(null);
    }
  }

  async function clearCart() {
    setError(null);
    try {
      const res = await api<{ data: Cart }>('/cart', { method: 'DELETE' });
      applyCart(res.data);
    } catch (e) {
      setError(errorMessage(e, 'Gagal mengosongkan keranjang'));
    }
  }

  function confirmClear() {
    RNAlert.alert('Kosongkan keranjang?', 'Semua produk akan dihapus.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Kosongkan', style: 'destructive', onPress: () => void clearCart() },
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

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <StatusBar style="light" />
      <View className="h-[52px] flex-row items-center justify-between bg-om-navy px-4">
        <Text className="text-[17px] font-extrabold text-white">
          Keranjang
          {count > 0 ? (
            <Text className="text-[14px] font-semibold text-white/80"> ({count})</Text>
          ) : null}
        </Text>
        {lines.length > 0 ? (
          <Pressable
            onPress={confirmClear}
            hitSlop={8}
            className="h-10 flex-row items-center gap-1">
            <Icon as={Trash2} size={14} color="rgba(255,255,255,0.85)" />
            <Text className="text-[13px] text-white/85">Hapus semua</Text>
          </Pressable>
        ) : null}
      </View>

      {loading ? (
        <View className="flex-1 bg-om-surface pt-2">
          <Skeleton className="mb-2 h-28 w-full rounded-none" />
          <Skeleton className="h-28 w-full rounded-none" />
        </View>
      ) : error && lines.length === 0 ? (
        <EmptyState
          icon={offline ? WifiOff : CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      ) : lines.length === 0 ? (
        <View className="flex-1 items-center justify-center bg-om-surface px-10">
          <View className="mb-4 size-24 items-center justify-center rounded-full bg-om-navy-soft">
            <Icon as={ShoppingBag} size={40} className="text-om-navy" />
          </View>
          <Text className="text-center text-[16px] font-bold">Wah, keranjangmu kosong</Text>
          <Text className="mt-1 text-center text-[13px] text-muted-foreground">
            Yuk, isi dengan produk incaranmu dan nikmati promo hari ini.
          </Text>
          <Button className="mt-5 rounded-sm px-8" onPress={() => router.replace('/(tabs)')}>
            <Text className="font-bold">Mulai belanja</Text>
          </Button>
        </View>
      ) : (
        <KeyboardAvoidingView
          className="flex-1 bg-om-surface"
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
          <ScrollView
            className="flex-1"
            contentContainerClassName="pb-3"
            keyboardShouldPersistTaps="handled"
            refreshControl={
              <RefreshControl refreshing={refreshing} onRefresh={() => void onRefresh()} />
            }>
            <View className="mt-2 bg-white">
              <View className="flex-row items-center gap-2 border-b border-border px-4 py-3">
                <Icon as={Store} size={16} className="text-om-navy" />
                <Text className="flex-1 text-[14px] font-bold">OceanMall Official</Text>
                <View className="flex-row items-center gap-1 rounded bg-om-navy-soft px-1.5 py-0.5">
                  <Icon as={ShieldCheck} size={11} className="text-om-navy" />
                  <Text className="text-[10px] font-bold text-om-navy">Resmi</Text>
                </View>
              </View>
              {lines.map((line, index) => (
                <CartLineRow
                  key={line.id}
                  line={line}
                  busy={lineBusy === line.id}
                  divided={index < lines.length - 1}
                  onQty={(quantity) => void changeQty(line.id, quantity)}
                />
              ))}
            </View>

            {/* Voucher */}
            <View className="mt-2 bg-white px-4 py-3">
              {cart?.coupon_code ? (
                <View className="flex-row items-center gap-3 rounded-sm border border-dashed border-om-success bg-om-success-soft px-3 py-2.5">
                  <Icon as={Ticket} size={18} className="text-om-success" />
                  <View className="flex-1">
                    <Text className="text-[13px] font-bold text-om-success">
                      Kode {cart.coupon_code} dipakai
                    </Text>
                    {discount > 0 ? (
                      <Text className="text-[12px] text-om-success">
                        Hemat {formatIdr(discount)}
                      </Text>
                    ) : null}
                  </View>
                  <Pressable hitSlop={8} disabled={couponBusy} onPress={() => void removeCoupon()}>
                    <Icon as={X} size={16} className="text-om-success" />
                  </Pressable>
                </View>
              ) : couponOpen ? (
                <View className="flex-row items-center gap-2">
                  <Input
                    autoFocus
                    placeholder="Masukkan kode promo"
                    value={coupon}
                    onChangeText={setCoupon}
                    autoCapitalize="characters"
                    autoCorrect={false}
                    returnKeyType="done"
                    onSubmitEditing={() => void applyCoupon()}
                    className="h-10 flex-1"
                  />
                  <Button
                    className="h-10 rounded-sm px-4"
                    disabled={couponBusy || coupon.trim() === ''}
                    onPress={() => void applyCoupon()}>
                    <Text className="font-bold">{couponBusy ? '...' : 'Pakai'}</Text>
                  </Button>
                </View>
              ) : (
                <Pressable
                  onPress={() => setCouponOpen(true)}
                  className="flex-row items-center gap-3">
                  <View className="size-9 items-center justify-center rounded-sm bg-om-navy-soft">
                    <Icon as={Ticket} size={18} className="text-om-navy" />
                  </View>
                  <Text className="flex-1 text-[14px] font-semibold">Pakai kode promo</Text>
                  <Icon as={ChevronRight} size={16} className="text-muted-foreground" />
                </Pressable>
              )}
              {error ? (
                <View className="mt-2 flex-row items-center gap-2">
                  <Icon as={CircleAlert} size={14} className="text-om-sale" />
                  <Text className="flex-1 text-[12px] text-om-sale">{error}</Text>
                </View>
              ) : null}
            </View>

            {/* Summary */}
            <View className="mt-2 bg-white px-4 py-3">
              <Text className="mb-1 text-[14px] font-bold">Ringkasan belanja</Text>
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

          <StickyBar>
            <View className="flex-row items-center gap-3">
              <View className="min-w-0 flex-1">
                <Text className="text-[12px] text-muted-foreground">Total harga</Text>
                <Text className="text-[18px] font-extrabold text-om-navy" numberOfLines={1}>
                  {formatIdr(totals?.total)}
                </Text>
              </View>
              <Button onPress={checkout} className="h-11 rounded-sm px-6">
                <Text className="font-bold">
                  {user ? `Checkout (${count})` : 'Masuk & checkout'}
                </Text>
              </Button>
            </View>
          </StickyBar>
        </KeyboardAvoidingView>
      )}
    </SafeAreaView>
  );
}

function CartLineRow({
  line,
  busy,
  divided,
  onQty,
}: {
  line: Cart['lines'][number];
  busy: boolean;
  divided?: boolean;
  onQty: (quantity: number) => void;
}) {
  const href = line.slug ? (`/product/${line.slug}` as Href) : null;

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
          <View className="mt-1 self-start rounded bg-om-surface px-1.5 py-0.5">
            <Text className="text-[11px] text-muted-foreground">{line.variant}</Text>
          </View>
        ) : null}
        <Text className="mt-1.5 text-[15px] font-extrabold text-foreground">
          {formatIdr(line.unit_price)}
        </Text>
        <View className="mt-2 flex-row items-center justify-end gap-3">
          <Pressable
            accessibilityLabel="Hapus"
            disabled={busy}
            hitSlop={8}
            onPress={() => onQty(0)}
            className="h-8 w-8 items-center justify-center">
            <Icon as={Trash2} size={17} className="text-muted-foreground" />
          </Pressable>
          <QtyStepper size="sm" value={line.quantity} max={10} busy={busy} onChange={onQty} />
        </View>
      </View>
    </View>
  );
}

function LineThumb({ uri, href }: { uri: string | undefined; href: Href | null }) {
  const image = (
    <Image
      source={{ uri }}
      style={{ width: 84, height: 84, borderRadius: 4, backgroundColor: '#f4f4f5' }}
      contentFit="cover"
    />
  );

  if (!href) {
    return image;
  }

  return (
    <Link href={href} asChild>
      <Pressable>{image}</Pressable>
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
      <Pressable>{text}</Pressable>
    </Link>
  );
}
