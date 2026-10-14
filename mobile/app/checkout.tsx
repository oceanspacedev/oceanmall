import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { Field, FieldFrame, inputA11yProps } from '@/components/field';
import { LoginPrompt } from '@/components/login-prompt';
import { ProductImage } from '@/components/product-image';
import { Screen } from '@/components/screen';
import { LogoImage, OptionCard, Section, StickyBar, SummaryRow } from '@/components/shop';
import { SectionSkeleton } from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { PLACEHOLDER_COLOR } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Text } from '@/components/ui/text';
import {
  PROVIDER_TIMEOUT_MS,
  api,
  apiFieldErrors,
  errorMessage,
  type Cart,
  type CheckoutPayload,
  type Destination,
  type OrderSummary,
  type PaymentMethodOption,
  type SavedAddress,
  type ShippingRate,
} from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { formatIdr, mediaUrl } from '@/lib/format';
import { isNetworkFailure, OFFLINE_COPY, TimeoutError } from '@/lib/network';
import { ripple } from '@/lib/ripple';
import { COLORS } from '@/lib/theme';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { useRouter } from 'expo-router';
import {
  Check,
  CircleAlert,
  CreditCard,
  Landmark,
  MapPin,
  Plus,
  QrCode,
  Receipt,
  Search,
  ShieldCheck,
  ShoppingCart,
  Store,
  Truck,
} from 'lucide-react-native';
import { useCallback, useEffect, useId, useRef, useState, type RefObject } from 'react';
import {
  Keyboard,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  TextInput,
  View,
  type LayoutChangeEvent,
} from 'react-native';

/** What the checkout is waiting for. One value instead of a shared boolean, so each control shows its own progress. */
type Busy = 'address' | 'saved' | 'rate' | 'order' | null;

type AddressField = 'first_name' | 'last_name' | 'phone_number' | 'street_address' | 'destination';

/** The sticky bar here is taller than the product page's (it carries a trust line), so lift toasts clear of it. */
const TOAST_OFFSET = 104;

export default function CheckoutScreen() {
  const { user } = useAuth();
  const { applyCart } = useCart();
  const router = useRouter();
  const scrollRef = useRef<ScrollView>(null);
  const lastNameRef = useRef<TextInput>(null);
  const phoneRef = useRef<TextInput>(null);
  const streetRef = useRef<TextInput>(null);
  const firstNameRef = useRef<TextInput>(null);
  const destinationRef = useRef<TextInput>(null);
  const courierY = useRef(0);
  const courierScrollPending = useRef(false);
  const destinationLabelId = useId();
  const [checkout, setCheckout] = useState<CheckoutPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busy, setBusy] = useState<Busy>(null);
  /** The courier card being applied, so only that card shows a spinner. */
  const [busyRate, setBusyRate] = useState<string | null>(null);
  /** The saved address being applied, for the same reason. */
  const [busyAddress, setBusyAddress] = useState<number | null>(null);
  /** Errors that belong to the address section: shown above "Simpan & hitung ongkir". */
  const [addressError, setAddressError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<AddressField, string>>>({});
  /** Errors for the sticky bar: courier and place-order failures. */
  const [orderError, setOrderError] = useState<string | null>(null);
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [street, setStreet] = useState('');
  const [phone, setPhone] = useState('');
  const [destinationQuery, setDestinationQuery] = useState('');
  const [destinations, setDestinations] = useState<Destination[]>([]);
  const [destination, setDestination] = useState<Destination | null>(null);
  const [destIssue, setDestIssue] = useState<{ error: boolean; text: string } | null>(null);
  const [destFocused, setDestFocused] = useState(false);
  const [destTouched, setDestTouched] = useState(false);
  const [searching, setSearching] = useState(false);
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
    setLoadError(null);
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
            timeoutMs: PROVIDER_TIMEOUT_MS,
          });
          applyCheckout(applied.data);
          return;
        } catch {
          // Fall back to manual selection below.
        }
      }
      applyCheckout(res.data);
    } catch (e) {
      setLoadError(errorMessage(e, 'Gagal memuat checkout'));
    } finally {
      setLoading(false);
    }
  }, [applyCheckout, user]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    const query = destinationQuery.trim();
    if (query.length < 2) {
      setDestinations([]);
      setDestIssue(null);
      setSearching(false);
      return;
    }
    let stale = false;
    const handle = setTimeout(() => {
      setSearching(true);
      void api<{ data: Destination[] }>(`/checkout/destinations?q=${encodeURIComponent(query)}`)
        .then((res) => {
          if (stale) {
            return;
          }
          const rows = res.data ?? [];
          setDestinations(rows);
          setDestIssue(
            rows.length === 0
              ? { error: false, text: 'Kecamatan tidak ditemukan. Coba nama lain atau kode pos.' }
              : null
          );
        })
        .catch((e) => {
          if (stale) {
            return;
          }
          setDestinations([]);
          setDestIssue({
            error: true,
            text: isNetworkFailure(e)
              ? OFFLINE_COPY.message
              : e instanceof TimeoutError
                ? errorMessage(e)
                : 'Pencarian kecamatan sedang gangguan. Coba lagi beberapa menit.',
          });
        })
        .finally(() => {
          if (!stale) {
            setSearching(false);
          }
        });
    }, 350);
    return () => {
      // Ignore a response for an older query that lands after a newer one.
      stale = true;
      clearTimeout(handle);
    };
  }, [destinationQuery]);

  function clearFieldError(field: AddressField) {
    setFieldErrors((current) => {
      if (!current[field]) {
        return current;
      }
      const next = { ...current };
      delete next[field];
      return next;
    });
  }

  /** Scrolls the courier section to the top once the address form has collapsed above it. */
  function scrollToCourier() {
    courierScrollPending.current = true;
    // onLayout does it as soon as the section moves; this is the fallback when it does not.
    setTimeout(flushCourierScroll, 350);
  }

  function flushCourierScroll() {
    if (!courierScrollPending.current) {
      return;
    }
    courierScrollPending.current = false;
    scrollRef.current?.scrollTo({ y: Math.max(0, courierY.current), animated: true });
  }

  function onCourierLayout(event: LayoutChangeEvent) {
    courierY.current = event.nativeEvent.layout.y;
    flushCourierScroll();
  }

  /** Client-side checks that name the field, so nothing is silently disabled. */
  function validateAddress(): Partial<Record<AddressField, string>> {
    const errors: Partial<Record<AddressField, string>> = {};
    const digits = phone.replace(/\D/g, '').length;
    if (!firstName.trim()) {
      errors.first_name = 'Isi nama depan';
    }
    if (!lastName.trim()) {
      errors.last_name = 'Isi nama belakang';
    }
    if (!phone.trim()) {
      errors.phone_number = 'Isi nomor HP';
    } else if (digits < 8 || digits > 15) {
      errors.phone_number = 'Nomor HP tidak valid';
    }
    if (!street.trim()) {
      errors.street_address = 'Isi alamat lengkap';
    }
    if (!destination) {
      errors.destination = 'Pilih kecamatan dari daftar';
    }
    return errors;
  }

  function focusFirstInvalid(errors: Partial<Record<AddressField, string>>) {
    const order: [AddressField, RefObject<TextInput | null>][] = [
      ['first_name', firstNameRef],
      ['last_name', lastNameRef],
      ['phone_number', phoneRef],
      ['street_address', streetRef],
      ['destination', destinationRef],
    ];
    const first = order.find(([field]) => errors[field]);
    first?.[1].current?.focus();
  }

  async function saveAddress() {
    if (busy !== null) {
      return;
    }
    const errors = validateAddress();
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      setDestTouched(true);
      setAddressError(null);
      focusFirstInvalid(errors);
      return;
    }
    const picked = destination as Destination;
    const postal = picked.zip_code ?? checkout?.shipping_address?.postal_code;
    if (!postal) {
      setFieldErrors({ destination: 'Kecamatan ini belum punya kode pos. Pilih kecamatan lain.' });
      destinationRef.current?.focus();
      return;
    }
    Keyboard.dismiss();
    setBusy('address');
    setAddressError(null);
    setFieldErrors({});
    try {
      const res = await api<{ data: CheckoutPayload }>('/checkout/shipping-address', {
        method: 'POST',
        timeoutMs: PROVIDER_TIMEOUT_MS,
        body: JSON.stringify({
          first_name: firstName.trim(),
          last_name: lastName.trim(),
          street_address: street.trim(),
          postal_code: postal,
          city: picked.city_name ?? picked.label,
          state: picked.province_name,
          phone_number: phone.trim(),
          rajaongkir_destination_id: picked.id,
          rajaongkir_destination_label: picked.label,
        }),
      });
      applyCheckout(res.data);
      setEditingAddress(false);
      scrollToCourier();
      toast.show({
        tone: 'success',
        message: 'Alamat disimpan, pilih kurir',
        bottomOffset: TOAST_OFFSET,
      });
    } catch (e) {
      const server = apiFieldErrors(e);
      const mapped: Partial<Record<AddressField, string>> = {};
      const leftover: string[] = [];
      for (const [key, messages] of Object.entries(server)) {
        const message = messages[0];
        switch (key) {
          case 'first_name':
          case 'last_name':
          case 'phone_number':
          case 'street_address':
            mapped[key] = message;
            break;
          case 'postal_code':
          case 'city':
          case 'state':
          case 'rajaongkir_destination_id':
            mapped.destination = message;
            break;
          default:
            leftover.push(message);
        }
      }
      if (Object.keys(mapped).length > 0) {
        setFieldErrors(mapped);
        setDestTouched(true);
        focusFirstInvalid(mapped);
      }
      if (leftover.length > 0) {
        setAddressError(leftover[0]);
      } else if (Object.keys(mapped).length === 0) {
        setAddressError(errorMessage(e, 'Gagal menyimpan alamat'));
      }
    } finally {
      setBusy(null);
    }
  }

  async function useSavedAddress(address: SavedAddress) {
    if (busy !== null) {
      return;
    }
    setBusy('saved');
    setBusyAddress(address.id);
    setAddressError(null);
    try {
      const res = await api<{ data: CheckoutPayload }>('/checkout/shipping-address/saved', {
        method: 'POST',
        body: JSON.stringify({ address_id: address.id }),
        timeoutMs: PROVIDER_TIMEOUT_MS,
      });
      applyCheckout(res.data);
      setEditingAddress(false);
    } catch (e) {
      setAddressError(errorMessage(e, 'Alamat belum bisa dipakai'));
    } finally {
      setBusy(null);
      setBusyAddress(null);
    }
  }

  async function chooseRate(rate: ShippingRate) {
    if (busy !== null) {
      return;
    }
    setBusy('rate');
    setBusyRate(rate.service_code);
    setOrderError(null);
    try {
      const res = await api<{ data: CheckoutPayload }>('/checkout/shipping-option', {
        method: 'POST',
        body: JSON.stringify({ service_code: rate.service_code }),
        timeoutMs: PROVIDER_TIMEOUT_MS,
      });
      applyCheckout(res.data);
      setSelectedRate(rate.service_code);
    } catch (e) {
      setOrderError(errorMessage(e, 'Gagal memilih kurir'));
    } finally {
      setBusy(null);
      setBusyRate(null);
    }
  }

  /**
   * After a place-order that timed out or lost its connection: did the server create the order
   * anyway? The server completes the cart the moment the order exists, so an empty cart plus a
   * new unpaid order is the answer. 'none' = the cart is intact (nothing was created, retry is
   * safe); 'unknown' = the check itself failed, so a blind retry might create a second order.
   */
  async function findPlacedOrder(): Promise<
    { state: 'placed'; number: string } | { state: 'none' } | { state: 'unknown' }
  > {
    try {
      const cart = await api<{ data: Cart }>('/cart');
      if ((cart.data?.lines ?? []).length > 0) {
        return { state: 'none' };
      }
      const orders = await api<{ data: OrderSummary[] }>('/orders?status=unpaid');
      const latest = orders.data?.[0];

      return latest ? { state: 'placed', number: latest.number } : { state: 'unknown' };
    } catch {
      return { state: 'unknown' };
    }
  }

  async function placeOrder() {
    if (busy !== null) {
      return;
    }
    if (!selectedPayment) {
      setOrderError('Pilih metode pembayaran.');
      return;
    }
    setBusy('order');
    setOrderError(null);
    try {
      const res = await api<{ data: { number: string; payment_error?: string | null } }>(
        '/checkout/place-order',
        {
          method: 'POST',
          body: JSON.stringify({ payment_method_id: selectedPayment }),
          timeoutMs: PROVIDER_TIMEOUT_MS,
        }
      );
      applyCart(null);
      // The success screen replaces this page, so Back never returns to an emptied checkout.
      router.replace({
        pathname: '/order-placed',
        params: {
          number: res.data.number,
          ...(res.data.payment_error ? { notice: res.data.payment_error } : {}),
        },
      });
    } catch (e) {
      if (e instanceof TimeoutError || isNetworkFailure(e)) {
        // The order may exist even though the answer never arrived: look before offering a retry.
        const found = await findPlacedOrder();
        if (found.state === 'placed') {
          applyCart(null);
          router.replace({ pathname: '/order-placed', params: { number: found.number } });
          return;
        }
        if (found.state === 'unknown') {
          setOrderError(
            'Kami belum bisa memastikan pesananmu sudah dibuat atau belum. Cek Pesanan saya dulu sebelum mencoba lagi, supaya tidak ada pesanan ganda.'
          );
          return;
        }
      }
      setOrderError(errorMessage(e, 'Gagal membuat pesanan'));
    } finally {
      setBusy(null);
    }
  }

  if (!user) {
    return (
      <Screen title="Checkout">
        <LoginPrompt
          icon={ShoppingCart}
          title="Masuk untuk lanjut checkout"
          body="Keranjangmu tetap tersimpan. Masuk atau daftar sebentar, lalu lanjut ke alamat dan pembayaran."
          redirect="/checkout"
        />
      </Screen>
    );
  }

  if (loading) {
    return (
      <Screen
        title="Checkout"
        surface
        loading={
          <>
            <SectionSkeleton />
            <SectionSkeleton />
            <SectionSkeleton />
            <SectionSkeleton />
          </>
        }
      />
    );
  }

  if (loadError && !checkout) {
    return (
      <Screen title="Checkout">
        <EmptyState
          tone="error"
          icon={CircleAlert}
          message={loadError}
          actionLabel="Coba lagi"
          onAction={() => {
            setLoading(true);
            void load();
          }}
        />
      </Screen>
    );
  }

  if (!checkout?.cart.lines.length) {
    return (
      <Screen title="Checkout">
        <EmptyState
          icon={ShoppingCart}
          title="Keranjang masih kosong"
          message="Pilih produk dulu, lalu kembali ke sini."
          actionLabel="Belanja sekarang"
          // dismissTo, not replace: replace would mount a second (tabs) navigator on top.
          onAction={() => router.dismissTo('/(tabs)')}
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
    selectedRate &&
    chosenMethod &&
    paymentLimitNote(chosenMethod, payable) === null &&
    busy === null
  );
  const address = checkout.shipping_address;
  const showAddressEditor = editingAddress || !address;
  const saved = checkout.saved_addresses ?? [];
  const showForm = showAddressEditor && (saved.length === 0 || newAddressOpen);
  const ratesLoading = busy === 'address' || busy === 'saved';
  const priceLoading = busy === 'rate';

  const destinationMessage =
    fieldErrors.destination ??
    (destIssue?.error ? destIssue.text : null) ??
    (destTouched && !destFocused && !destination ? 'Pilih kecamatan dari daftar' : null);
  const destinationState = destFocused
    ? 'focused'
    : destinationMessage
      ? 'error'
      : destination
        ? 'chosen'
        : 'idle';

  const addressBanner = addressError ? (
    <ErrorBanner message={addressError} className="mx-0 mb-3 mt-0" />
  ) : null;

  const body = (
    <>
      <ScrollView
        ref={scrollRef}
        contentContainerClassName="pb-4"
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        // iOS insets the scroll content itself and the bar stays under the keyboard; Android is
        // handled by the KeyboardAvoidingView around this body. Using both on iOS would adjust
        // twice and leave a blank gap.
        automaticallyAdjustKeyboardInsets>
        {/* Address */}
        <Section
          title="Alamat pengiriman"
          icon={MapPin}
          action={address && !editingAddress ? 'Ganti' : address ? 'Batal' : undefined}
          onAction={() => {
            setEditingAddress((value) => !value);
            setAddressError(null);
            setFieldErrors({});
          }}>
          {address && !editingAddress ? (
            <View>
              <Text className="text-[14px] font-bold">
                {address.first_name} {address.last_name}
                <Text className="text-[13px] font-normal text-muted-foreground">
                  {address.phone_number ? ` · ${address.phone_number}` : ''}
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
                  <Text className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-muted-foreground">
                    Alamat tersimpan
                  </Text>
                  {saved.map((row) => {
                    const active =
                      address?.street_address === row.street_address && address?.city === row.city;
                    return (
                      <OptionCard
                        key={row.id}
                        selected={active}
                        disabled={busy !== null}
                        loading={busy === 'saved' && busyAddress === row.id}
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
                  accessibilityRole="button"
                  onPress={() => setNewAddressOpen(true)}
                  android_ripple={ripple.row}
                  className="min-h-11 flex-row items-center justify-center gap-2 overflow-hidden rounded-sm border border-dashed border-om-navy py-3 active:opacity-70">
                  <Icon as={Plus} size={15} className="text-om-navy" />
                  <Text className="text-[13px] font-bold text-om-navy">Tambah alamat baru</Text>
                </Pressable>
              ) : null}
              {!showForm ? addressBanner : null}
              {showForm ? (
                <View className="gap-3">
                  <View className="flex-row gap-2.5">
                    <View className="flex-1">
                      <Field
                        ref={firstNameRef}
                        label="Nama depan"
                        value={firstName}
                        onChangeText={(value) => {
                          setFirstName(value);
                          clearFieldError('first_name');
                        }}
                        error={fieldErrors.first_name}
                        autoComplete="given-name"
                        autoCapitalize="words"
                        returnKeyType="next"
                        submitBehavior="submit"
                        onSubmitEditing={() => lastNameRef.current?.focus()}
                      />
                    </View>
                    <View className="flex-1">
                      <Field
                        ref={lastNameRef}
                        label="Nama belakang"
                        value={lastName}
                        onChangeText={(value) => {
                          setLastName(value);
                          clearFieldError('last_name');
                        }}
                        error={fieldErrors.last_name}
                        autoComplete="family-name"
                        autoCapitalize="words"
                        returnKeyType="next"
                        submitBehavior="submit"
                        onSubmitEditing={() => phoneRef.current?.focus()}
                      />
                    </View>
                  </View>
                  <Field
                    ref={phoneRef}
                    label="Nomor HP"
                    value={phone}
                    onChangeText={(value) => {
                      setPhone(value);
                      clearFieldError('phone_number');
                    }}
                    error={fieldErrors.phone_number}
                    keyboardType="phone-pad"
                    autoComplete="tel"
                    returnKeyType="next"
                    onSubmitEditing={() => streetRef.current?.focus()}
                  />
                  <Field
                    ref={streetRef}
                    multiline
                    label="Alamat lengkap"
                    value={street}
                    onChangeText={(value) => {
                      setStreet(value);
                      clearFieldError('street_address');
                    }}
                    error={fieldErrors.street_address}
                    placeholder="Nama jalan, nomor rumah, RT/RW, patokan"
                    autoComplete="street-address"
                    autoCapitalize="words"
                    returnKeyType="next"
                    submitBehavior="submit"
                    onSubmitEditing={() => destinationRef.current?.focus()}
                  />
                  <FieldFrame
                    label="Kecamatan atau kode pos"
                    labelId={destinationLabelId}
                    error={destinationMessage}
                    hint={destIssue && !destIssue.error ? destIssue.text : undefined}>
                    <View
                      className={cn(
                        'min-h-11 flex-row items-center rounded-sm bg-white',
                        destinationState === 'focused' && 'border-2 border-ring px-[11px]',
                        destinationState === 'error' && 'border-2 border-destructive px-[11px]',
                        destinationState === 'chosen' && 'border-2 border-om-success px-[11px]',
                        destinationState === 'idle' && 'border border-input px-3'
                      )}>
                      <Icon as={Search} size={16} color={COLORS.slate} />
                      <TextInput
                        ref={destinationRef}
                        placeholder="Contoh: Kebayoran Baru atau 12120"
                        placeholderTextColor={PLACEHOLDER_COLOR}
                        selectionColor={COLORS.navy}
                        cursorColor={COLORS.navy}
                        maxFontSizeMultiplier={1.3}
                        value={destination ? destination.label : destinationQuery}
                        onChangeText={(value) => {
                          setDestination(null);
                          setDestinationQuery(value);
                          clearFieldError('destination');
                        }}
                        onFocus={() => setDestFocused(true)}
                        onBlur={() => {
                          setDestFocused(false);
                          setDestTouched(true);
                        }}
                        returnKeyType="search"
                        onSubmitEditing={() => Keyboard.dismiss()}
                        autoComplete="postal-code"
                        autoCorrect={false}
                        {...inputA11yProps(
                          'Kecamatan atau kode pos',
                          destinationLabelId,
                          destinationMessage
                        )}
                        className="ml-2 min-h-11 flex-1 py-1 text-[15px] leading-5 text-foreground"
                      />
                      {searching ? <Spinner size="small" /> : null}
                      {destination && !searching ? (
                        <Icon as={Check} size={18} color={COLORS.success} />
                      ) : null}
                    </View>
                  </FieldFrame>
                  {destinations.length > 0 ? (
                    <View className="-mt-1 overflow-hidden rounded-sm border border-border bg-white">
                      {destinations.map((row, index) => (
                        <Pressable
                          key={row.id}
                          accessibilityRole="button"
                          onPress={() => {
                            Keyboard.dismiss();
                            setDestination(row);
                            setDestinationQuery('');
                            setDestinations([]);
                            setDestIssue(null);
                            clearFieldError('destination');
                          }}
                          android_ripple={ripple.row}
                          className={cn(
                            'min-h-11 flex-row items-start gap-2 px-3 py-2.5 active:bg-om-surface',
                            index < destinations.length - 1 && 'border-b border-border'
                          )}>
                          <Icon as={MapPin} size={14} className="mt-0.5 text-muted-foreground" />
                          <Text className="flex-1 text-[13px] leading-5">{row.label}</Text>
                        </Pressable>
                      ))}
                    </View>
                  ) : null}
                  {addressBanner}
                  <Button
                    loading={busy === 'address'}
                    onPress={() => void saveAddress()}
                    className="min-h-11 rounded-sm">
                    <Text className="font-bold">Simpan & hitung ongkir</Text>
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
              <ProductImage
                uri={mediaUrl(line.thumbnail)}
                width={60}
                height={60}
                radius={6}
                contentFit="cover"
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
        <View onLayout={onCourierLayout}>
          <Section title="Pilih pengiriman" icon={Truck}>
            {ratesLoading ? (
              <CourierSkeleton />
            ) : (checkout.shipping_rates ?? []).length === 0 ? (
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
                  disabled={busy !== null}
                  loading={busy === 'rate' && busyRate === rate.service_code}
                  onPress={() => void chooseRate(rate)}
                  icon={<LogoImage uri={rate.carrier_logo} fallback={Truck} />}
                  title={`${rate.carrier_name ?? rate.carrier_code} ${rate.service_name}`}
                  subtitle={etaLabel(rate.estimated_days)}
                  trailing={<Text className="text-[14px] font-bold">{formatIdr(rate.amount)}</Text>}
                />
              ))
            )}
          </Section>
        </View>

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
                disabled={limit !== null || busy !== null}
                // Only a method that is out of range for good is dimmed; a busy list is not.
                disabledDim={limit !== null}
                onPress={() => setSelectedPayment(method.id)}
                icon={<LogoImage uri={method.logo} fallback={isQris ? QrCode : Landmark} />}
                title={method.title}
                subtitle={
                  limit
                    ? null
                    : isQris
                      ? 'Scan dari aplikasi bank atau e-wallet mana saja'
                      : 'Verifikasi otomatis setelah transfer'
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
          {priceLoading ? (
            <SummaryRowSkeleton label="Ongkos kirim" />
          ) : (
            <SummaryRow
              label="Ongkos kirim"
              value={checkout.shipping_option ? formatIdr(shippingAmount) : 'Pilih kurir'}
              valueClassName={checkout.shipping_option ? undefined : 'text-muted-foreground'}
            />
          )}
          <View className="my-1.5 h-px bg-border" />
          {priceLoading ? (
            <SummaryRowSkeleton label="Total tagihan" strong />
          ) : (
            <SummaryRow label="Total tagihan" value={formatIdr(payable)} strong />
          )}
        </Section>
      </ScrollView>

      <StickyBar>
        {orderError ? <ErrorBanner message={orderError} className="mx-0 mb-2.5 mt-0" /> : null}
        <View className="flex-row items-center gap-3">
          <View className="min-w-0 flex-1">
            <Text className="text-[12px] text-muted-foreground">Total tagihan</Text>
            {priceLoading ? (
              <Skeleton className="mt-0.5 h-[22px] w-28" />
            ) : (
              <Text className="text-[18px] font-extrabold text-om-navy" numberOfLines={1}>
                {formatIdr(payable)}
              </Text>
            )}
          </View>
          <Button
            disabled={!canPlace}
            loading={busy === 'order'}
            onPress={() => void placeOrder()}
            className="min-h-11 rounded-sm px-6">
            <Text className="font-bold">Buat pesanan</Text>
          </Button>
        </View>
        {/* One slot for the hint and the trust line, so the bar never changes height. */}
        <View className="mt-1.5 min-h-4 flex-row items-center gap-1">
          {!canPlace && busy === null ? (
            <Text className="text-[12px] text-muted-foreground">
              {!address
                ? 'Isi alamat pengiriman'
                : !selectedRate
                  ? 'Pilih kurir pengiriman'
                  : 'Pilih metode pembayaran'}{' '}
              untuk melanjutkan.
            </Text>
          ) : (
            <>
              <Icon as={ShieldCheck} size={11} className="text-muted-foreground" />
              <Text className="text-[12px] text-muted-foreground">
                Pembayaran aman via bank & QRIS
              </Text>
            </>
          )}
        </View>
      </StickyBar>
    </>
  );

  return (
    <Screen title="Checkout" surface>
      {Platform.OS === 'android' ? (
        <KeyboardAvoidingView behavior="height" style={{ flex: 1 }}>
          {body}
        </KeyboardAvoidingView>
      ) : (
        <View style={{ flex: 1 }}>{body}</View>
      )}
    </Screen>
  );
}

/** Two courier-shaped placeholders, shown while the quotes for a new address are fetched. */
function CourierSkeleton() {
  return (
    <View accessibilityLiveRegion="polite">
      <Text className="absolute h-px w-px opacity-0">Memuat pilihan kurir…</Text>
      {[0, 1].map((index) => (
        <View
          key={index}
          className="mb-2 flex-row items-center gap-3 rounded-sm border border-border p-3 last:mb-0">
          <Skeleton className="size-10 rounded-sm" />
          <View className="flex-1 gap-1.5">
            <Skeleton className="h-4 w-32" />
            <Skeleton className="h-3 w-24" />
          </View>
          <Skeleton className="h-4 w-16" />
        </View>
      ))}
    </View>
  );
}

/** SummaryRow with a placeholder where the amount goes, while a courier is being applied. */
function SummaryRowSkeleton({ label, strong = false }: { label: string; strong?: boolean }) {
  return (
    <View className="flex-row items-center justify-between py-1">
      <Text
        className={cn('flex-1 pr-3 text-[13px]', strong ? 'font-bold' : 'text-muted-foreground')}>
        {label}
      </Text>
      <Skeleton className="h-4 w-20" />
    </View>
  );
}

function etaLabel(value?: string | number | null): string | null {
  if (value === null || value === undefined || value === '') {
    return 'Estimasi belum tersedia';
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
