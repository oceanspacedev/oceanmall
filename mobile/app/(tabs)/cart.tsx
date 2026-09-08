import { EmptyState } from '@/components/empty-state';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
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
import { CircleAlert, Minus, Plus, ShoppingBag, Ticket, Trash2, WifiOff } from 'lucide-react-native';
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
      <View className="h-[52px] flex-row items-center justify-between bg-om-navy px-3">
        <Text className="text-[15px] font-bold text-white">
          Keranjang{count > 0 ? ` · ${count}` : ''}
        </Text>
        {lines.length > 0 ? (
          <Pressable onPress={confirmClear} hitSlop={8} className="h-10 justify-center px-1">
            <Text className="text-[13px] text-white/80">Kosongkan</Text>
          </Pressable>
        ) : null}
      </View>

      {loading ? (
        <View className="flex-1 bg-background px-3 pt-3">
          <Skeleton className="mb-2 h-24 w-full rounded-lg" />
          <Skeleton className="h-24 w-full rounded-lg" />
        </View>
      ) : error && lines.length === 0 ? (
        <EmptyState
          icon={offline ? WifiOff : CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      ) : lines.length === 0 ? (
        <EmptyState
          icon={ShoppingBag}
          message="Keranjang masih kosong."
          actionLabel="Belanja sekarang"
          onAction={() => router.replace('/(tabs)')}
        />
      ) : (
        <KeyboardAvoidingView
          className="flex-1 bg-muted"
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
          <ScrollView
            className="flex-1"
            keyboardShouldPersistTaps="handled"
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void onRefresh()} />}>
            {error ? (
              <Alert icon={CircleAlert} variant="destructive" className="mx-3 mt-3">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            ) : null}

            <View className="border-b border-border bg-white">
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
          </ScrollView>

          <View className="border-t border-border bg-white">
            <View className="gap-2.5 px-3 pt-3">
              {cart?.coupon_code ? (
                <View className="h-10 flex-row items-center gap-2">
                  <Icon as={Ticket} size={16} className="text-om-navy" />
                  <Text className="min-w-0 flex-1 text-[13px] font-semibold">{cart.coupon_code}</Text>
                  <Pressable
                    hitSlop={8}
                    disabled={couponBusy}
                    onPress={() => void removeCoupon()}
                    className="h-10 justify-center">
                    <Text className="text-[13px] text-muted-foreground">Hapus</Text>
                  </Pressable>
                </View>
              ) : (
                <View className="flex-row items-center gap-2">
                  <Input
                    placeholder="Kode kupon"
                    value={coupon}
                    onChangeText={setCoupon}
                    autoCapitalize="characters"
                    autoCorrect={false}
                    returnKeyType="done"
                    onSubmitEditing={() => void applyCoupon()}
                    className="h-10 flex-1"
                  />
                  <Button
                    variant="outline"
                    className="h-10 rounded-lg px-4"
                    disabled={couponBusy || coupon.trim() === ''}
                    onPress={() => void applyCoupon()}>
                    <Text>{couponBusy ? '...' : 'Pakai'}</Text>
                  </Button>
                </View>
              )}

              <View className="gap-1">
                <SummaryRow label="Subtotal" value={formatIdr(totals?.subtotal)} />
                {discount > 0 ? (
                  <SummaryRow
                    label="Diskon"
                    value={`−${formatIdr(discount)}`}
                    valueClassName="text-om-sale"
                  />
                ) : null}
                {tax > 0 ? <SummaryRow label="Pajak" value={formatIdr(tax)} /> : null}
                <SummaryRow label="Ongkir" value="Di checkout" muted />
              </View>
            </View>

            <Separator className="mt-3" />

            <View className="flex-row items-center gap-3 px-3 pb-2.5 pt-2.5">
              <View className="min-w-0 flex-1">
                <Text variant="muted" className="text-[11px]">
                  {user ? 'Total' : 'Masuk untuk checkout'}
                </Text>
                <Text className="text-[16px] font-extrabold text-om-navy">{formatIdr(totals?.total)}</Text>
              </View>
              <Button onPress={checkout} className="h-11 px-6">
                <Text>{user ? 'Checkout' : 'Masuk'}</Text>
              </Button>
            </View>
          </View>
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
        'flex-row items-center gap-3 px-3 py-3',
        divided && 'border-b border-border',
        busy && 'opacity-50'
      )}>
      <LineThumb uri={mediaUrl(line.thumbnail)} href={href} />
      <View className="min-w-0 flex-1">
        <View className="flex-row items-start gap-3">
          <LineName name={line.name} href={href} />
          <Text className="shrink-0 text-[13px] font-bold text-om-navy">
            {formatIdr(line.unit_price * line.quantity)}
          </Text>
        </View>
        <Text variant="muted" className="mt-0.5 self-start text-[12px]">
          {line.variant ? `${line.variant} · ${formatIdr(line.unit_price)}` : formatIdr(line.unit_price)}
        </Text>
        <View className="mt-2 flex-row items-center gap-1">
          <QtyStepper
            value={line.quantity}
            busy={busy}
            onChange={(quantity) => onQty(quantity)}
          />
          <Pressable
            accessibilityLabel="Hapus"
            disabled={busy}
            hitSlop={8}
            onPress={() => onQty(0)}
            className="h-8 w-8 items-center justify-center">
            <Icon as={Trash2} size={15} className="text-muted-foreground" />
          </Pressable>
        </View>
      </View>
    </View>
  );
}

function QtyStepper({
  value,
  busy,
  onChange,
}: {
  value: number;
  busy: boolean;
  onChange: (quantity: number) => void;
}) {
  return (
    <View className="h-8 flex-row items-center overflow-hidden rounded-md border border-border">
      <Pressable
        accessibilityLabel="Kurangi"
        disabled={busy}
        onPress={() => {
          if (value > 1) {
            onChange(value - 1);
          }
        }}
        className="h-8 w-8 items-center justify-center">
        <Icon as={Minus} size={14} className={value <= 1 ? 'text-muted-foreground' : 'text-foreground'} />
      </Pressable>
      <Text className="min-w-[22px] text-center text-[13px] font-semibold">{value}</Text>
      <Pressable
        accessibilityLabel="Tambah"
        disabled={busy}
        onPress={() => {
          if (value < 10) {
            onChange(value + 1);
          }
        }}
        className="h-8 w-8 items-center justify-center">
        <Icon as={Plus} size={14} className={value >= 10 ? 'text-muted-foreground' : 'text-foreground'} />
      </Pressable>
    </View>
  );
}

function LineThumb({ uri, href }: { uri: string | undefined; href: Href | null }) {
  const image = (
    <Image
      source={{ uri }}
      style={{ width: 72, height: 72, borderRadius: 6, backgroundColor: '#f4f4f5' }}
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
    <Text className="text-[13px] font-medium leading-snug" numberOfLines={2}>
      {name}
    </Text>
  );

  if (!href) {
    return <View className="min-w-0 flex-1">{text}</View>;
  }

  return (
    <Link href={href} asChild>
      <Pressable className="min-w-0 flex-1">{text}</Pressable>
    </Link>
  );
}

function SummaryRow({
  label,
  value,
  muted = false,
  valueClassName,
}: {
  label: string;
  value: string;
  muted?: boolean;
  valueClassName?: string;
}) {
  return (
    <View className="flex-row items-center justify-between">
      <Text variant="muted" className="text-[12px]">
        {label}
      </Text>
      <Text className={cn('text-[12px]', muted ? 'text-muted-foreground' : 'font-medium', valueClassName)}>
        {value}
      </Text>
    </View>
  );
}
