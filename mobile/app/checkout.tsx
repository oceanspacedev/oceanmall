import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { LogoImage, OptionCard, Section, StickyBar, SummaryRow } from '@/components/shop';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import {
  CircleAlert,
  CreditCard,
  Landmark,
  MapPin,
  Plus,
  QrCode,
  Receipt,
  Search,
  ShoppingBag,
  Store,
  Truck,
  User,
} from 'lucide-react-native';
import {
  api,
  errorMessage,
  type CheckoutPayload,
  type Destination,
  type PaymentMethodOption,
  type SavedAddress,
  type ShippingRate,
} from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { formatIdr, mediaUrl } from '@/lib/format';
import { Image } from 'expo-image';
import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  Keyboard,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  TextInput,
  View,
} from 'react-native';

export default function CheckoutScreen() {
  const { user } = useAuth();
  const { applyCart } = useCart();
  const router = useRouter();
  const [checkout, setCheckout] = useState<CheckoutPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [street, setStreet] = useState('');
  const [phone, setPhone] = useState('');
  const [destinationQuery, setDestinationQuery] = useState('');
  const [destinations, setDestinations] = useState<Destination[]>([]);
  const [destination, setDestination] = useState<Destination | null>(null);
  const [destHint, setDestHint] = useState<string | null>(null);
  const [selectedRate, setSelectedRate] = useState<string | null>(null);
  const [selectedPayment, setSelectedPayment] = useState<number | null>(null);
  const [editingAddress, setEditingAddress] = useState(false);
  const [newAddressOpen, setNewAddressOpen] = useState(false);

  const applyCheckout = useCallback(
    (data: CheckoutPayload) => {
      setCheckout(data);
      applyCart(data.cart);
      const address = data.shipping_address;
      if (!address && user) {
        setFirstName((value) => value || user.first_name || '');
        setLastName((value) => value || user.last_name || '');
        setPhone((value) => value || user.phone_number || '');
      }
      if (address) {
        setFirstName(address.first_name ?? '');
        setLastName(address.last_name ?? '');
        setStreet(address.street_address ?? '');
        setPhone(address.phone_number ?? '');
        if (address.rajaongkir_destination_id) {
          setDestination({
            id: address.rajaongkir_destination_id,
            label:
              address.rajaongkir_destination_label ??
              address.city ??
              address.rajaongkir_destination_id,
          });
        }
      }
      setSelectedRate(data.shipping_option?.service_code ?? null);
    },
    [applyCart, user]
  );

  const load = useCallback(async () => {
    if (!user) {
      setLoading(false);
      return;
    }
    setError(null);
    try {
      const res = await api<{ data: CheckoutPayload }>('/checkout');
      const saved = res.data.saved_addresses ?? [];
      if (!res.data.shipping_address && saved.length > 0 && res.data.cart.lines.length > 0) {
        // Like marketplace apps: start from the primary saved address.
        const primary = saved.find((row) => row.shipping_default) ?? saved[0];
        try {
          const applied = await api<{ data: CheckoutPayload }>('/checkout/shipping-address/saved', {
            method: 'POST',
            body: JSON.stringify({ address_id: primary.id }),
          });
          applyCheckout(applied.data);
          return;
        } catch {
          // Fall back to manual selection below.
        }
      }
      applyCheckout(res.data);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat checkout'));
    } finally {
      setLoading(false);
    }
  }, [applyCheckout, user]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    if (destinationQuery.trim().length < 2) {
      setDestinations([]);
      setDestHint(null);
      return;
    }
    let stale = false;
    const handle = setTimeout(() => {
      void api<{ data: Destination[] }>(
        `/checkout/destinations?q=${encodeURIComponent(destinationQuery.trim())}`
      )
        .then((res) => {
          if (stale) {
            return;
          }
          const rows = res.data ?? [];
          setDestinations(rows);
          setDestHint(
            rows.length === 0 ? 'Kecamatan tidak ditemukan. Coba nama lain atau kode pos.' : null
          );
        })
        .catch(() => {
          if (stale) {
            return;
          }
          setDestinations([]);
          setDestHint('Gagal mencari kecamatan. Cek koneksi atau kredensial RajaOngkir.');
        });
    }, 350);
    return () => {
      // Ignore a response for an older query that lands after a newer one.
      stale = true;
      clearTimeout(handle);
    };
  }, [destinationQuery]);

  async function saveAddress() {
    if (!destination) {
      setError('Pilih kecamatan RajaOngkir dulu.');
      return;
    }
    const postal = destination.zip_code ?? checkout?.shipping_address?.postal_code;
    if (!postal) {
      setError('Kecamatan ini belum punya kode pos. Pilih kecamatan lain.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: CheckoutPayload }>('/checkout/shipping-address', {
        method: 'POST',
        body: JSON.stringify({
          first_name: firstName.trim(),
          last_name: lastName.trim(),
          street_address: street.trim(),
          postal_code: postal,
          city: destination.city_name ?? destination.label,
          state: destination.province_name,
          phone_number: phone.trim(),
          rajaongkir_destination_id: destination.id,
          rajaongkir_destination_label: destination.label,
        }),
      });
      applyCheckout(res.data);
      setEditingAddress(false);
    } catch (e) {
      setError(errorMessage(e, 'Gagal menyimpan alamat'));
    } finally {
      setBusy(false);
    }
  }

  async function useSavedAddress(address: SavedAddress) {
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: CheckoutPayload }>('/checkout/shipping-address/saved', {
        method: 'POST',
        body: JSON.stringify({ address_id: address.id }),
      });
      applyCheckout(res.data);
      setEditingAddress(false);
    } catch (e) {
      setError(errorMessage(e, 'Alamat belum bisa dipakai'));
    } finally {
      setBusy(false);
    }
  }

  async function chooseRate(rate: ShippingRate) {
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: CheckoutPayload }>('/checkout/shipping-option', {
        method: 'POST',
        body: JSON.stringify({ service_code: rate.service_code }),
      });
      applyCheckout(res.data);
      setSelectedRate(rate.service_code);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memilih kurir'));
    } finally {
      setBusy(false);
    }
  }

  async function placeOrder() {
    if (!selectedPayment) {
      setError('Pilih metode pembayaran.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: { number: string; payment_error?: string | null } }>(
        '/checkout/place-order',
        {
          method: 'POST',
          body: JSON.stringify({ payment_method_id: selectedPayment }),
        }
      );
      applyCart(null);
      router.replace({
        pathname: '/order/[number]',
        params: {
          number: res.data.number,
          ...(res.data.payment_error ? { notice: res.data.payment_error } : {}),
        },
      });
    } catch (e) {
      setError(errorMessage(e, 'Gagal membuat pesanan'));
    } finally {
      setBusy(false);
    }
  }

  if (!user) {
    return (
      <Screen title="Checkout">
        <EmptyState
          icon={User}
          message="Masuk dulu untuk alamat, ongkir, dan bayar. Keranjang tetap tersimpan."
          actionLabel="Masuk"
          onAction={() => router.push({ pathname: '/login', params: { redirect: '/checkout' } })}
        />
      </Screen>
    );
  }

  if (loading) {
    return <Screen title="Checkout" loading />;
  }

  if (error && !checkout) {
    return (
      <Screen title="Checkout">
        <EmptyState
          icon={CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      </Screen>
    );
  }

  if (!checkout?.cart.lines.length) {
    return (
      <Screen title="Checkout">
        <EmptyState
          icon={ShoppingBag}
          message="Keranjang masih kosong."
          actionLabel="Belanja sekarang"
          onAction={() => router.replace('/(tabs)')}
        />
      </Screen>
    );
  }

  const shippingAmount = checkout.shipping_option?.amount ?? 0;
  const goodsTotal = checkout.cart.totals.total;
  const payable = goodsTotal + shippingAmount;
  const discount = checkout.cart.totals.discount ?? 0;
  const itemCount = checkout.cart.lines.reduce((sum, line) => sum + line.quantity, 0);
  const chosenMethod = checkout.payment_methods?.find((method) => method.id === selectedPayment);
  const canPlace = Boolean(
    selectedRate && chosenMethod && paymentLimitNote(chosenMethod, payable) === null && !busy
  );
  const address = checkout.shipping_address;
  const showAddressEditor = editingAddress || !address;
  const saved = checkout.saved_addresses ?? [];

  return (
    <Screen title="Checkout" surface>
      <KeyboardAvoidingView
        className="flex-1"
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerClassName="pb-4"
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          automaticallyAdjustKeyboardInsets>
          {/* Address */}
          <Section
            title="Alamat pengiriman"
            icon={MapPin}
            action={address && !editingAddress ? 'Ganti' : address ? 'Batal' : undefined}
            onAction={() => setEditingAddress((value) => !value)}>
            {address && !editingAddress ? (
              <View>
                <Text className="text-[14px] font-bold">
                  {address.first_name} {address.last_name}
                  <Text className="text-[13px] font-normal text-muted-foreground">
                    {address.phone_number ? `  ·  ${address.phone_number}` : ''}
                  </Text>
                </Text>
                <Text className="mt-1 text-[13px] leading-5">{address.street_address}</Text>
                <Text className="text-[12px] leading-5 text-muted-foreground">
                  {address.rajaongkir_destination_label ?? address.city}
                </Text>
              </View>
            ) : null}

            {showAddressEditor ? (
              <View>
                {saved.length > 0 ? (
                  <View className="mb-3">
                    <Text className="mb-2 text-[12px] font-semibold text-muted-foreground">
                      ALAMAT TERSIMPAN
                    </Text>
                    {saved.map((row) => {
                      const active =
                        address?.street_address === row.street_address &&
                        address?.city === row.city;
                      return (
                        <OptionCard
                          key={row.id}
                          selected={active}
                          disabled={busy}
                          onPress={() => void useSavedAddress(row)}
                          title={`${row.first_name} ${row.last_name}`}
                          subtitle={`${row.street_address}, ${row.rajaongkir_destination_label ?? row.city}`}
                        />
                      );
                    })}
                  </View>
                ) : null}

                {saved.length > 0 && !newAddressOpen ? (
                  <Pressable
                    onPress={() => setNewAddressOpen(true)}
                    className="flex-row items-center justify-center gap-2 rounded-sm border border-dashed border-om-navy py-3">
                    <Icon as={Plus} size={15} className="text-om-navy" />
                    <Text className="text-[13px] font-bold text-om-navy">Tambah alamat baru</Text>
                  </Pressable>
                ) : null}
                {saved.length === 0 || newAddressOpen ? (
                  <View className="gap-3">
                    <View className="flex-row gap-2.5">
                      <View className="flex-1">
                        <Field label="Nama depan" value={firstName} onChangeText={setFirstName} />
                      </View>
                      <View className="flex-1">
                        <Field label="Nama belakang" value={lastName} onChangeText={setLastName} />
                      </View>
                    </View>
                    <Field
                      label="Nomor HP"
                      value={phone}
                      onChangeText={setPhone}
                      keyboardType="phone-pad"
                    />
                    <Field
                      label="Alamat lengkap"
                      value={street}
                      onChangeText={setStreet}
                      placeholder="Nama jalan, nomor rumah, RT/RW, patokan"
                    />
                    <View className="gap-1">
                      <Text className="text-[12px] text-muted-foreground">
                        Kecamatan / kode pos
                      </Text>
                      <View className="h-11 flex-row items-center rounded-sm border border-border bg-white px-3">
                        <Icon as={Search} size={15} className="text-muted-foreground" />
                        <TextInput
                          placeholder="Cari kecamatan atau kode pos..."
                          placeholderTextColor="#94a3b8"
                          value={destination ? destination.label : destinationQuery}
                          onChangeText={(value) => {
                            setDestination(null);
                            setDestinationQuery(value);
                          }}
                          className="ml-2 flex-1 text-[14px] text-foreground"
                        />
                      </View>
                      {destHint ? (
                        <Text className="text-[12px] text-muted-foreground">{destHint}</Text>
                      ) : null}
                      {destinations.length > 0 ? (
                        <View className="mt-1 overflow-hidden rounded-sm border border-border bg-white">
                          {destinations.map((row, index) => (
                            <Pressable
                              key={row.id}
                              onPress={() => {
                                Keyboard.dismiss();
                                setDestination(row);
                                setDestinationQuery('');
                                setDestinations([]);
                                setDestHint(null);
                              }}
                              className={`flex-row items-start gap-2 px-3 py-2.5 active:bg-om-surface ${
                                index < destinations.length - 1 ? 'border-b border-border' : ''
                              }`}>
                              <Icon
                                as={MapPin}
                                size={14}
                                className="mt-0.5 text-muted-foreground"
                              />
                              <Text className="flex-1 text-[13px] leading-5">{row.label}</Text>
                            </Pressable>
                          ))}
                        </View>
                      ) : null}
                    </View>
                    <Button
                      disabled={
                        busy || !firstName || !lastName || !street || !phone || !destination
                      }
                      onPress={() => void saveAddress()}
                      className="rounded-sm">
                      <Text className="font-bold">
                        {busy ? 'Menyimpan...' : 'Simpan & hitung ongkir'}
                      </Text>
                    </Button>
                  </View>
                ) : null}
              </View>
            ) : null}
          </Section>

          {/* Items */}
          <Section title="OceanMall Official" icon={Store}>
            {checkout.cart.lines.map((line) => (
              <View key={line.id} className="mb-3 flex-row gap-3 last:mb-0">
                <Image
                  source={{ uri: mediaUrl(line.thumbnail) }}
                  style={{ width: 60, height: 60, borderRadius: 4, backgroundColor: '#f4f4f5' }}
                />
                <View className="flex-1">
                  <Text className="text-[13px] leading-5" numberOfLines={2}>
                    {line.name}
                  </Text>
                  {line.variant ? (
                    <Text className="text-[12px] text-muted-foreground">{line.variant}</Text>
                  ) : null}
                  <Text className="mt-0.5 text-[13px] font-bold">
                    {line.quantity} × {formatIdr(line.unit_price)}
                  </Text>
                </View>
              </View>
            ))}
          </Section>

          {/* Courier */}
          <Section title="Pilih pengiriman" icon={Truck}>
            {(checkout.shipping_rates ?? []).length === 0 ? (
              <Text className="text-[13px] text-muted-foreground">
                {address
                  ? 'Ongkir belum tersedia untuk alamat ini. Coba kecamatan lain.'
                  : 'Isi alamat dulu untuk melihat pilihan kurir dan ongkir.'}
              </Text>
            ) : (
              checkout.shipping_rates.map((rate) => (
                <OptionCard
                  key={rate.service_code}
                  selected={selectedRate === rate.service_code}
                  disabled={busy}
                  onPress={() => void chooseRate(rate)}
                  icon={<LogoImage uri={rate.carrier_logo} fallback={Truck} />}
                  title={`${rate.carrier_name ?? rate.carrier_code} ${rate.service_name}`}
                  subtitle={etaLabel(rate.estimated_days)}
                  trailing={<Text className="text-[14px] font-bold">{formatIdr(rate.amount)}</Text>}
                />
              ))
            )}
          </Section>

          {/* Payment */}
          <Section title="Metode pembayaran" icon={CreditCard}>
            {(checkout.payment_methods ?? []).length === 0 ? (
              <Text className="text-[13px] text-muted-foreground">
                Metode pembayaran belum tersedia.
              </Text>
            ) : null}
            {(checkout.payment_methods ?? []).map((method: PaymentMethodOption) => {
              const limit = paymentLimitNote(method, payable);
              const isQris = /qris/i.test(method.title) || method.payment_type === 'qris';
              return (
                <OptionCard
                  key={method.id}
                  selected={selectedPayment === method.id}
                  disabled={limit !== null}
                  onPress={() => setSelectedPayment(method.id)}
                  icon={<LogoImage uri={method.logo} fallback={isQris ? QrCode : Landmark} />}
                  title={method.title}
                  subtitle={
                    limit
                      ? null
                      : isQris
                        ? 'GoPay, OVO, DANA, ShopeePay, m-banking'
                        : 'Dicek otomatis'
                  }
                  note={limit}
                />
              );
            })}
          </Section>

          {/* Breakdown */}
          <Section title="Rincian pembayaran" icon={Receipt}>
            <SummaryRow
              label={`Total harga (${itemCount} barang)`}
              value={formatIdr(checkout.cart.totals.subtotal)}
            />
            {discount > 0 ? (
              <SummaryRow
                label="Diskon promo"
                value={`−${formatIdr(discount)}`}
                valueClassName="text-om-success"
              />
            ) : null}
            <SummaryRow
              label="Ongkos kirim"
              value={checkout.shipping_option ? formatIdr(shippingAmount) : 'Pilih kurir'}
              valueClassName={checkout.shipping_option ? undefined : 'text-muted-foreground'}
            />
            <View className="my-1.5 h-px bg-border" />
            <SummaryRow label="Total tagihan" value={formatIdr(payable)} strong />
          </Section>
        </ScrollView>

        <StickyBar>
          {error ? (
            <View className="mb-2.5 flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2">
              <Icon as={CircleAlert} size={15} className="text-om-sale" />
              <Text className="flex-1 text-[12px] text-om-sale">{error}</Text>
            </View>
          ) : null}
          <View className="flex-row items-center gap-3">
            <View className="min-w-0 flex-1">
              <Text className="text-[12px] text-muted-foreground">Total tagihan</Text>
              <Text className="text-[18px] font-extrabold text-om-navy" numberOfLines={1}>
                {formatIdr(payable)}
              </Text>
            </View>
            <Button
              disabled={!canPlace}
              onPress={() => void placeOrder()}
              className="h-11 rounded-sm px-6">
              <Text className="font-bold">{busy ? 'Memproses...' : 'Buat pesanan'}</Text>
            </Button>
          </View>
          {!canPlace && !busy ? (
            <Text className="mt-1.5 text-[11px] text-muted-foreground">
              {!address
                ? 'Isi alamat pengiriman'
                : !selectedRate
                  ? 'Pilih kurir pengiriman'
                  : 'Pilih metode pembayaran'}{' '}
              untuk melanjutkan.
            </Text>
          ) : null}
        </StickyBar>
      </KeyboardAvoidingView>
    </Screen>
  );
}

function etaLabel(value?: string | number | null): string | null {
  if (value === null || value === undefined || value === '') {
    return 'Estimasi menyusul';
  }
  const text = String(value);
  return /hari|day|jam/i.test(text) ? `Estimasi ${text}` : `Estimasi ${text} hari`;
}

function paymentLimitNote(method: PaymentMethodOption, amount: number): string | null {
  if (method.min_amount && amount < method.min_amount) {
    return `Minimal ${formatIdr(method.min_amount)} untuk metode ini.`;
  }
  if (method.max_amount && amount > method.max_amount) {
    return `Maksimal ${formatIdr(method.max_amount)} untuk metode ini.`;
  }
  return null;
}
