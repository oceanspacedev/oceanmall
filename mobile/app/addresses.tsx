import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { Field } from '@/components/field';
import { KeyboardAvoider } from '@/components/keyboard-avoider';
import { LoginPrompt } from '@/components/login-prompt';
import { Screen } from '@/components/screen';
import { StatusPill, StickyBar } from '@/components/shop';
import { AddressCardSkeleton } from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { destinationSearchErrorMessage, validateSavedAddressForm } from '@/lib/address-helpers';
import {
  LEGACY_CAPABILITIES,
  api,
  apiFieldErrors,
  destinationRegion,
  errorMessage,
  type Destination,
  type SavedAddress,
  type ShippingCapabilities,
} from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { confirmAlert } from '@/lib/confirm';
import { COLORS } from '@/lib/theme';
import { toast } from '@/lib/toast';
import { useRefresh } from '@/lib/use-refresh';
import { cn } from '@/lib/utils';
import { useFocusEffect, useRouter } from 'expo-router';
import { CircleAlert, MapPin, Pencil, Plus, Trash2, X } from 'lucide-react-native';
import { useCallback, useEffect, useState } from 'react';
import { Modal, Platform, Pressable, ScrollView, View } from 'react-native';
import { useBottomPad } from '@/components/safe-area-view';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

/** Toasts clear the sticky bar by this much. */
const BAR_OFFSET = 96;

type CountryOption = { id: number; name: string; cca2: string };

export default function AddressesScreen() {
  const bottomPad = useBottomPad(40);
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const { itemCount } = useCart();
  const router = useRouter();
  const [addresses, setAddresses] = useState<SavedAddress[]>([]);
  const [countries, setCountries] = useState<CountryOption[]>([]);
  const [capabilities, setCapabilities] = useState<ShippingCapabilities>(LEGACY_CAPABILITIES);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  // Create / Edit modal state
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<SavedAddress | null>(null);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [phone, setPhone] = useState('');
  const [street, setStreet] = useState('');
  const [streetPlus, setStreetPlus] = useState('');
  const [city, setCity] = useState('');
  const [stateName, setStateName] = useState('');
  const [postalCode, setPostalCode] = useState('');
  const [pinPoint, setPinPoint] = useState('');
  const [destinationId, setDestinationId] = useState<string | null>(null);
  const [destinationLabel, setDestinationLabel] = useState<string | null>(null);
  const [destQuery, setDestQuery] = useState('');
  const [destResults, setDestResults] = useState<Destination[]>([]);
  const [destSearching, setDestSearching] = useState(false);
  const [destIssue, setDestIssue] = useState<{ error: boolean; text: string } | null>(null);
  const [regionEditable, setRegionEditable] = useState(false);

  const userId = user?.id ?? null;

  const load = useCallback(async () => {
    if (userId === null) {
      setAddresses([]);
      setLoading(false);
      return;
    }
    try {
      const res = await api<{
        data: SavedAddress[];
        countries?: CountryOption[];
        shipping_capabilities?: ShippingCapabilities;
      }>('/addresses');
      setAddresses(res.data ?? []);
      setCountries(res.countries ?? []);
      if (res.shipping_capabilities) {
        setCapabilities(res.shipping_capabilities);
      }
      setLoadError(null);
    } catch (e) {
      setLoadError(errorMessage(e, 'Gagal memuat alamat'));
    } finally {
      setLoading(false);
    }
  }, [userId]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load])
  );

  const { refreshing, onRefresh } = useRefresh(load);

  useEffect(() => {
    if (!formOpen || !capabilities.destination_search || destQuery.trim().length < 2) {
      setDestResults([]);
      setDestIssue(null);
      setDestSearching(false);
      return;
    }
    if (destinationId && destQuery === (destinationLabel ?? destinationId)) {
      setDestResults([]);
      setDestIssue(null);
      setDestSearching(false);
      return;
    }
    let cancelled = false;
    const handle = setTimeout(() => {
      setDestSearching(true);
      setDestIssue(null);
      api<{ data: Destination[] }>(
        `/checkout/destinations?q=${encodeURIComponent(destQuery.trim())}`
      )
        .then((res) => {
          if (cancelled) {
            return;
          }
          const rows = res.data ?? [];
          setDestResults(rows);
          setDestIssue(
            rows.length === 0
              ? { error: false, text: 'Kecamatan tidak ditemukan. Coba nama lain atau kode pos.' }
              : null
          );
        })
        .catch((e) => {
          if (cancelled) {
            return;
          }
          setDestResults([]);
          setDestIssue({
            error: true,
            text: destinationSearchErrorMessage(e, errorMessage),
          });
        })
        .finally(() => {
          if (!cancelled) {
            setDestSearching(false);
          }
        });
    }, 350);
    return () => {
      cancelled = true;
      clearTimeout(handle);
    };
  }, [formOpen, destQuery, destinationId, destinationLabel, capabilities.destination_search]);

  function clearFieldError(field: string) {
    setFieldErrors((current) => {
      if (!current[field]) {
        return current;
      }
      const next = { ...current };
      delete next[field];
      return next;
    });
  }

  function openCreate() {
    setEditing(null);
    setFirstName(user?.first_name ?? '');
    setLastName(user?.last_name ?? '');
    setPhone(user?.phone_number ?? '');
    setStreet('');
    setStreetPlus('');
    setCity('');
    setStateName('');
    setPostalCode('');
    setPinPoint('');
    setDestinationId(null);
    setDestinationLabel(null);
    setDestQuery('');
    setDestResults([]);
    setDestSearching(false);
    setDestIssue(null);
    setRegionEditable(false);
    setFormError(null);
    setFieldErrors({});
    setFormOpen(true);
  }

  function openEdit(address: SavedAddress) {
    setEditing(address);
    setFirstName(address.first_name ?? '');
    setLastName(address.last_name ?? '');
    setPhone(address.phone_number ?? '');
    setStreet(address.street_address ?? '');
    setStreetPlus(address.street_address_plus ?? '');
    setCity(address.city ?? '');
    setStateName(address.state ?? '');
    setPostalCode(address.postal_code ?? '');
    setPinPoint(address.pin_point ?? '');
    const id = address.destination_id ?? address.rajaongkir_destination_id ?? null;
    const validId = id && !String(id).startsWith('text:') ? String(id) : null;
    const label = address.destination_label ?? address.rajaongkir_destination_label ?? null;
    setDestinationId(validId);
    setDestinationLabel(label);
    setDestQuery(label ?? validId ?? '');
    setDestResults([]);
    setDestSearching(false);
    setDestIssue(null);
    setRegionEditable(!address.city || !address.postal_code);
    setFormError(null);
    setFieldErrors({});
    setFormOpen(true);
  }

  function pickDestination(row: Destination) {
    const region = destinationRegion(row);
    setDestinationId(row.id);
    setDestinationLabel(row.label);
    setDestQuery(row.label);
    if (capabilities.requires_destination) {
      setCity(region.city ?? '');
      setStateName(region.state ?? '');
      setPostalCode(region.postal_code ?? '');
      setRegionEditable(!region.city || !region.postal_code);
    } else {
      if (region.city) setCity(region.city);
      if (region.state) setStateName(region.state);
      if (region.postal_code) setPostalCode(region.postal_code);
    }
    setDestResults([]);
    setDestIssue(null);
    clearFieldError('destination_id');
    clearFieldError('rajaongkir_destination_id');
    clearFieldError('city');
    clearFieldError('state');
    clearFieldError('postal_code');
  }

  async function submitAddress() {
    if (saving) return;
    const validation = validateSavedAddressForm(
      {
        firstName,
        lastName,
        phone,
        street,
        city,
        postalCode,
        destinationId,
        pinPoint,
      },
      capabilities
    );
    if (validation.formError || Object.keys(validation.fieldErrors).length > 0) {
      if (
        validation.fieldErrors.city ||
        validation.fieldErrors.postal_code ||
        validation.fieldErrors.state
      ) {
        setRegionEditable(true);
      }
      setFieldErrors(validation.fieldErrors);
      setFormError(validation.formError);
      return;
    }
    const countryId = countries[0]?.id;
    if (!countryId) {
      setFormError('Wilayah pengiriman belum tersedia.');
      return;
    }
    setSaving(true);
    setFormError(null);
    setFieldErrors({});
    try {
      const payload = {
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        phone_number: phone.trim(),
        street_address: street.trim(),
        street_address_plus: streetPlus.trim() || null,
        city: city.trim(),
        state: stateName.trim() || null,
        postal_code: postalCode.trim(),
        country_id: countryId,
        type: 'shipping',
        ...(capabilities.destination_search && destinationId
          ? { destination_id: destinationId, destination_label: destinationLabel }
          : {}),
        ...(capabilities.requires_coordinates ? { pin_point: pinPoint.trim() || null } : {}),
      };
      if (editing) {
        await api(`/addresses/${editing.id}`, { method: 'PATCH', body: JSON.stringify(payload) });
      } else {
        await api('/addresses', { method: 'POST', body: JSON.stringify(payload) });
      }
      setFormOpen(false);
      await load();
      toast.show({
        tone: 'success',
        message: editing ? 'Alamat diperbarui' : 'Alamat disimpan',
        bottomOffset: BAR_OFFSET,
      });
    } catch (e) {
      setFieldErrors(apiFieldErrors(e));
      setFormError(errorMessage(e, 'Gagal menyimpan alamat'));
    } finally {
      setSaving(false);
    }
  }

  async function setDefault(id: number) {
    setBusyId(id);
    setError(null);
    try {
      await api(`/addresses/${id}/default-shipping`, { method: 'PATCH' });
      await load();
      toast.show({ tone: 'success', message: 'Alamat utama diperbarui', bottomOffset: BAR_OFFSET });
    } catch (e) {
      setError(errorMessage(e, 'Gagal menjadikan alamat utama'));
    } finally {
      setBusyId(null);
    }
  }

  async function remove(id: number) {
    setBusyId(id);
    setError(null);
    try {
      await api(`/addresses/${id}`, { method: 'DELETE' });
      setAddresses((current) => current.filter((row) => row.id !== id));
      toast.show({ tone: 'success', message: 'Alamat dihapus', bottomOffset: BAR_OFFSET });
    } catch (e) {
      setError(errorMessage(e, 'Gagal menghapus alamat'));
    } finally {
      setBusyId(null);
    }
  }

  function confirmRemove(id: number) {
    confirmAlert('Hapus alamat?', 'Alamat ini akan dihapus dari buku alamat.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Hapus', style: 'destructive', onPress: () => void remove(id) },
    ]);
  }

  if (!user) {
    return (
      <Screen title="Daftar alamat">
        <LoginPrompt
          icon={MapPin}
          title="Simpan alamat biar checkout cepat"
          body="Masuk untuk mengelola alamat pengiriman."
          redirect="/addresses"
        />
      </Screen>
    );
  }

  if (loading && addresses.length === 0) {
    return (
      <Screen
        title="Daftar alamat"
        loading={
          <>
            <AddressCardSkeleton />
            <AddressCardSkeleton />
            <AddressCardSkeleton />
          </>
        }
      />
    );
  }

  if (loadError && addresses.length === 0) {
    return (
      <Screen title="Daftar alamat">
        <EmptyState
          tone="error"
          icon={CircleAlert}
          title="Alamat belum bisa dimuat"
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

  const formModal = (
    <Modal visible={formOpen} animationType="slide" onRequestClose={() => setFormOpen(false)}>
      <View
        className="flex-1 bg-white"
        style={{ paddingTop: insets.top, paddingBottom: insets.bottom }}>
        <View className="flex-row items-center justify-between border-b border-border px-4 py-3">
          <Text className="text-[16px] font-extrabold">
            {editing ? 'Ubah alamat' : 'Tambah alamat'}
          </Text>
          <Pressable onPress={() => setFormOpen(false)} className="p-2" accessibilityLabel="Tutup">
            <Icon as={X} size={20} className="text-foreground" />
          </Pressable>
        </View>
        <KeyboardAvoider>
          <ScrollView className="flex-1" contentContainerClassName="gap-3 p-4">
            {formError ? <ErrorBanner message={formError} className="mx-0 mt-0" /> : null}
            <View className="flex-row gap-2.5">
              <View className="flex-1">
                <Field
                  label="Nama depan"
                  value={firstName}
                  onChangeText={(val) => {
                    setFirstName(val);
                    clearFieldError('first_name');
                  }}
                  error={fieldErrors.first_name?.[0]}
                />
              </View>
              <View className="flex-1">
                <Field
                  label="Nama belakang"
                  value={lastName}
                  onChangeText={(val) => {
                    setLastName(val);
                    clearFieldError('last_name');
                  }}
                  error={fieldErrors.last_name?.[0]}
                />
              </View>
            </View>
            <Field
              label="Nomor HP"
              value={phone}
              onChangeText={(val) => {
                setPhone(val);
                clearFieldError('phone_number');
              }}
              keyboardType="phone-pad"
              placeholder="08xxxxxxxxxx"
              error={fieldErrors.phone_number?.[0]}
            />
            <Field
              label="Alamat lengkap"
              value={street}
              onChangeText={(val) => {
                setStreet(val);
                clearFieldError('street_address');
              }}
              error={fieldErrors.street_address?.[0]}
            />
            <Field
              label="Detail alamat (opsional)"
              value={streetPlus}
              onChangeText={setStreetPlus}
              placeholder="Blok, patokan, lantai..."
            />
            {capabilities.destination_search ? (
              <View>
                <Field
                  label="Kecamatan / Kode Pos tujuan"
                  value={destQuery}
                  onChangeText={(val) => {
                    setDestQuery(val);
                    if (val !== (destinationLabel ?? '')) {
                      setDestinationId(null);
                      setDestinationLabel(null);
                    }
                    clearFieldError('destination_id');
                    clearFieldError('rajaongkir_destination_id');
                  }}
                  placeholder="Cari kecamatan..."
                  hint={
                    destSearching
                      ? 'Mencari kecamatan…'
                      : destIssue && !destIssue.error
                        ? destIssue.text
                        : undefined
                  }
                  error={
                    fieldErrors.destination_id?.[0] ??
                    fieldErrors.rajaongkir_destination_id?.[0] ??
                    (destIssue?.error ? destIssue.text : undefined)
                  }
                />
                {destResults.length > 0 ? (
                  <View className="mt-1 rounded-sm border border-border bg-om-surface">
                    {destResults.map((row) => (
                      <Pressable
                        key={row.id}
                        onPress={() => pickDestination(row)}
                        className="border-b border-border px-3 py-2.5 last:border-b-0 active:bg-white">
                        <Text className="text-[13px]">{row.label}</Text>
                      </Pressable>
                    ))}
                  </View>
                ) : null}
              </View>
            ) : null}
            <View className="flex-row gap-2.5">
              <View className="flex-1">
                <Field
                  label="Kota / Kabupaten"
                  value={city}
                  editable={!capabilities.requires_destination || regionEditable}
                  onChangeText={(val) => {
                    setCity(val);
                    clearFieldError('city');
                  }}
                  error={fieldErrors.city?.[0]}
                />
              </View>
              <View className="w-28">
                <Field
                  label="Kode pos"
                  value={postalCode}
                  editable={!capabilities.requires_destination || regionEditable}
                  onChangeText={(val) => {
                    setPostalCode(val.replace(/\D/g, '').slice(0, 5));
                    clearFieldError('postal_code');
                  }}
                  keyboardType="number-pad"
                  error={fieldErrors.postal_code?.[0]}
                />
              </View>
            </View>
            <Field
              label="Provinsi"
              value={stateName}
              editable={!capabilities.requires_destination || regionEditable}
              onChangeText={(val) => {
                setStateName(val);
                clearFieldError('state');
              }}
              error={fieldErrors.state?.[0]}
            />
            {capabilities.requires_coordinates ? (
              <Field
                label="Koordinat Pin Point (opsional)"
                value={pinPoint}
                onChangeText={(val) => {
                  setPinPoint(val);
                  clearFieldError('pin_point');
                  clearFieldError('rajaongkir_pin_point');
                }}
                placeholder="-6.200000,106.816666"
                error={fieldErrors.pin_point?.[0] ?? fieldErrors.rajaongkir_pin_point?.[0]}
              />
            ) : null}
            <Button
              loading={saving}
              onPress={() => void submitAddress()}
              className="mt-2 min-h-12 rounded-sm">
              <Text className="font-bold">Simpan alamat</Text>
            </Button>
          </ScrollView>
        </KeyboardAvoider>
      </View>
    </Modal>
  );

  if (addresses.length === 0) {
    return (
      <Screen title="Daftar alamat">
        <EmptyState
          icon={MapPin}
          title="Belum ada alamat tersimpan"
          message="Tambahkan alamat pengiriman agar checkout lebih cepat."
          actionLabel="Tambah alamat baru"
          onAction={openCreate}
        />
        {formModal}
      </Screen>
    );
  }

  return (
    <Screen title="Daftar alamat" surface>
      <ScrollView
        className="flex-1"
        contentContainerClassName="pt-1"
        contentContainerStyle={{ paddingBottom: bottomPad }}
        refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
        {loadError ? <ErrorBanner message={loadError} onRetry={() => void load()} /> : null}
        {error ? <ErrorBanner message={error} /> : null}

        {addresses.map((address) => {
          const busy = busyId === address.id;
          return (
            <View
              key={address.id}
              className={cn(
                'mx-3 mt-2.5 rounded-lg border bg-white p-4',
                address.shipping_default ? 'border-om-navy' : 'border-transparent'
              )}>
              <View className="flex-row items-center gap-2">
                <Icon as={MapPin} size={15} className="text-om-navy" />
                <Text className="flex-1 text-[14px] font-bold">
                  {address.first_name} {address.last_name}
                </Text>
                {address.shipping_default ? <StatusPill label="Utama" tone="navy" /> : null}
              </View>
              {address.phone_number ? (
                <Text className="mt-1.5 text-[13px]">{address.phone_number}</Text>
              ) : null}
              <Text className="mt-0.5 text-[13px] leading-5 text-muted-foreground">
                {address.street_address}
                {'\n'}
                {address.destination_label ??
                  address.rajaongkir_destination_label ??
                  `${address.city} ${address.postal_code}`}
              </Text>
              <View className="mt-3 flex-row flex-wrap gap-2 border-t border-border pt-3">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={busyId !== null}
                  onPress={() => openEdit(address)}
                  className="min-h-11 rounded-sm px-3">
                  <Icon as={Pencil} size={13} className="text-om-navy" />
                  <Text className="text-[12px] font-semibold text-om-navy">Ubah</Text>
                </Button>
                {!address.shipping_default ? (
                  <Button
                    size="sm"
                    variant="outline"
                    loading={busy}
                    disabled={busyId !== null}
                    onPress={() => void setDefault(address.id)}
                    accessibilityLabel={`Jadikan alamat ${address.first_name} ${address.last_name} sebagai alamat utama`}
                    className="min-h-11 flex-1 rounded-sm border-om-navy">
                    <Text className="text-[12px] font-bold text-om-navy">Jadikan utama</Text>
                  </Button>
                ) : null}
                <Button
                  size="sm"
                  variant="outline"
                  disabled={busyId !== null}
                  onPress={() => confirmRemove(address.id)}
                  accessibilityLabel={`Hapus alamat ${address.first_name} ${address.last_name}`}
                  className="min-h-11 rounded-sm px-3">
                  <Icon as={Trash2} size={13} className="text-destructive" />
                  <Text className="text-[12px] font-semibold text-destructive">Hapus</Text>
                </Button>
              </View>
            </View>
          );
        })}
      </ScrollView>
      <StickyBar>
        <Button onPress={openCreate} className="min-h-11 rounded-sm">
          <Icon as={Plus} size={16} color={COLORS.white} />
          <Text className="font-bold">Tambah alamat baru</Text>
        </Button>
      </StickyBar>
      {formModal}
    </Screen>
  );
}
