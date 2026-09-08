import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import { CircleAlert, ShoppingBag, User } from 'lucide-react-native';
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
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export default function CheckoutScreen() {
  const { user } = useAuth();
  const { applyCart } = useCart();
  const router = useRouter();
  const insets = useSafeAreaInsets();
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

  const applyCheckout = useCallback((data: CheckoutPayload) => {
    setCheckout(data);
    applyCart(data.cart);
    const address = data.shipping_address;
    if (address) {
      setFirstName(address.first_name ?? '');
      setLastName(address.last_name ?? '');
      setStreet(address.street_address ?? '');
      setPhone(address.phone_number ?? '');
      if (address.rajaongkir_destination_id) {
        setDestination({
          id: address.rajaongkir_destination_id,
          label: address.rajaongkir_destination_label ?? address.city ?? address.rajaongkir_destination_id,
        });
      }
    }
    setSelectedRate(data.shipping_option?.service_code ?? null);
  }, [applyCart]);

  const load = useCallback(async () => {
    if (!user) {
      setLoading(false);
      return;
    }
    setError(null);
    try {
      const res = await api<{ data: CheckoutPayload }>('/checkout');
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
    const handle = setTimeout(() => {
      void api<{ data: Destination[] }>(
        `/checkout/destinations?q=${encodeURIComponent(destinationQuery.trim())}`
      )
        .then((res) => {
          const rows = res.data ?? [];
          setDestinations(rows);
          setDestHint(
            rows.length === 0 ? 'Kecamatan tidak ditemukan. Coba nama lain atau kode pos.' : null
          );
        })
        .catch(() => {
          setDestinations([]);
          setDestHint('Gagal mencari kecamatan. Cek koneksi atau kredensial RajaOngkir.');
        });
    }, 350);
    return () => clearTimeout(handle);
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
      const res = await api<{ data: { number: string } }>('/checkout/place-order', {
        method: 'POST',
        body: JSON.stringify({ payment_method_id: selectedPayment }),
      });
      applyCart(null);
      router.replace(`/order/${res.data.number}`);
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
        <EmptyState icon={CircleAlert} message={error} actionLabel="Coba lagi" onAction={() => void load()} />
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
  const canPlace = Boolean(selectedRate && selectedPayment && !busy);

  return (
    <Screen title="Checkout">
    <KeyboardAvoidingView
      className="flex-1"
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerClassName="px-3 pb-8 pt-3" keyboardShouldPersistTaps="handled">
        {error ? (
          <Alert icon={CircleAlert} variant="destructive" className="mb-3">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        ) : null}

        <Section title="Barang">
          {checkout.cart.lines.map((line) => (
            <View key={line.id} className="mb-3 flex-row gap-3 last:mb-0">
              <Image
                source={{ uri: mediaUrl(line.thumbnail) }}
                style={{ width: 56, height: 56, borderRadius: 8, backgroundColor: '#f4f4f5' }}
              />
              <View className="flex-1">
                <Text className="text-[13px] font-medium" numberOfLines={2}>
                  {line.name}
                </Text>
                <Text className="mt-0.5 text-[12px] text-muted-foreground">
                  {line.variant ? `${line.variant} · ` : ''}× {line.quantity}
                </Text>
              </View>
              <Text className="text-[13px] font-semibold">{formatIdr(line.unit_price * line.quantity)}</Text>
            </View>
          ))}
          <Text className="mt-1 text-right text-[13px] text-muted-foreground">
            Subtotal {formatIdr(goodsTotal)}
          </Text>
        </Section>

        {(checkout.saved_addresses ?? []).length > 0 ? (
          <Section title="Alamat tersimpan">
            {checkout.saved_addresses.map((address) => {
              const active =
                checkout.shipping_address?.street_address === address.street_address &&
                checkout.shipping_address?.city === address.city;
              return (
                <Pressable
                  key={address.id}
                  onPress={() => void useSavedAddress(address)}
                  className={`mb-2 rounded-lg border p-3 last:mb-0 ${
                    active ? 'border-om-navy bg-accent' : 'border-border bg-white'
                  }`}>
                  <Text className="font-medium">
                    {address.first_name} {address.last_name}
                  </Text>
                  <Text className="mt-0.5 text-[12px] text-muted-foreground">
                    {address.street_address}, {address.city}
                  </Text>
                </Pressable>
              );
            })}
          </Section>
        ) : null}

        <Section title="Alamat pengiriman">
          <View className="gap-3">
            <View className="flex-row gap-2.5">
              <View className="flex-1">
                <Field label="Nama depan" value={firstName} onChangeText={setFirstName} />
              </View>
              <View className="flex-1">
                <Field label="Nama belakang" value={lastName} onChangeText={setLastName} />
              </View>
            </View>
            <Field label="Alamat" value={street} onChangeText={setStreet} />
            <Field label="Nomor HP" value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
            <View className="gap-1">
              <Text className="text-[12px] text-muted-foreground">Kecamatan</Text>
              <TextInput
                placeholder="Cari kecamatan / kode pos..."
                placeholderTextColor="#94a3b8"
                value={destination ? destination.label : destinationQuery}
                onChangeText={(value) => {
                  setDestination(null);
                  setDestinationQuery(value);
                }}
                className="h-10 rounded-lg border border-border bg-white px-3 text-[14px] text-foreground"
              />
              {destHint ? (
                <Text className="text-[12px] text-muted-foreground">{destHint}</Text>
              ) : null}
              {destinations.map((row) => (
                <Pressable
                  key={row.id}
                  onPress={() => {
                    setDestination(row);
                    setDestinationQuery('');
                    setDestinations([]);
                    setDestHint(null);
                  }}
                  className="rounded-lg border border-border bg-white p-2.5">
                  <Text className="text-[13px]">{row.label}</Text>
                </Pressable>
              ))}
            </View>
            <Button
              variant="outline"
              disabled={busy || !firstName || !lastName || !street || !phone || !destination}
              onPress={() => void saveAddress()}>
              <Text>Simpan alamat & hitung ongkir</Text>
            </Button>
          </View>
        </Section>

        <Section title="Kurir">
          {(checkout.shipping_rates ?? []).length === 0 ? (
            <Text className="text-[13px] text-muted-foreground">
              {checkout.shipping_address
                ? 'Ongkir belum tersedia untuk alamat ini. Cek kecamatan atau kredensial RajaOngkir.'
                : 'Simpan alamat dulu untuk melihat ongkir.'}
            </Text>
          ) : (
            checkout.shipping_rates.map((rate) => (
              <Pressable
                key={rate.service_code}
                onPress={() => void chooseRate(rate)}
                className={`mb-2 rounded-lg border p-3 last:mb-0 ${
                  selectedRate === rate.service_code ? 'border-om-navy bg-accent' : 'border-border bg-white'
                }`}>
                <View className="flex-row items-start justify-between gap-3">
                  <View className="flex-1">
                    <Text className="font-medium">
                      {rate.carrier_name ?? rate.carrier_code} · {rate.service_name}
                    </Text>
                    {rate.estimated_days ? (
                      <Text className="mt-0.5 text-[12px] text-muted-foreground">
                        {String(rate.estimated_days)}
                        {/hari|day|jam/i.test(String(rate.estimated_days)) ? '' : ' hari'}
                      </Text>
                    ) : null}
                  </View>
                  <Text className="font-semibold">{formatIdr(rate.amount)}</Text>
                </View>
              </Pressable>
            ))
          )}
        </Section>

        <Section title="Pembayaran">
          {(checkout.payment_methods ?? []).length === 0 ? (
            <Text className="text-[13px] text-muted-foreground">
              Metode pembayaran belum tersedia. Cek pengaturan toko atau kredensial Komerce.
            </Text>
          ) : null}
          {(checkout.payment_methods ?? []).map((method: PaymentMethodOption) => (
            <Pressable
              key={method.id}
              onPress={() => setSelectedPayment(method.id)}
              className={`mb-2 rounded-lg border p-3 last:mb-0 ${
                selectedPayment === method.id ? 'border-om-navy bg-accent' : 'border-border bg-white'
              }`}>
              <Text className="font-medium">{method.title}</Text>
              {method.description ? (
                <Text className="mt-0.5 text-[12px] text-muted-foreground">{method.description}</Text>
              ) : null}
            </Pressable>
          ))}
        </Section>
      </ScrollView>

      <View
        className="flex-row items-center border-t border-border bg-white px-3 pt-2.5"
        style={{ paddingBottom: Math.max(insets.bottom, 10) }}>
        <View className="flex-1 pr-3">
          <Text className="text-[11px] text-muted-foreground">
            {shippingAmount > 0 ? `Termasuk ongkir ${formatIdr(shippingAmount)}` : 'Total'}
          </Text>
          <Text className="text-[16px] font-extrabold text-om-navy">{formatIdr(payable)}</Text>
        </View>
        <Button disabled={!canPlace} onPress={() => void placeOrder()}>
          <Text>{busy ? 'Memproses...' : 'Buat pesanan'}</Text>
        </Button>
      </View>
    </KeyboardAvoidingView>
    </Screen>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <View className="mb-4">
      <Text className="mb-2 text-[13px] font-bold text-om-navy">{title}</Text>
      {children}
    </View>
  );
}
