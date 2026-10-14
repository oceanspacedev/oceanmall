import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { LoginPrompt } from '@/components/login-prompt';
import { Screen } from '@/components/screen';
import { StatusPill, StickyBar } from '@/components/shop';
import { AddressCardSkeleton } from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type SavedAddress } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { COLORS } from '@/lib/theme';
import { toast } from '@/lib/toast';
import { useRefresh } from '@/lib/use-refresh';
import { cn } from '@/lib/utils';
import { useFocusEffect, useRouter } from 'expo-router';
import { CircleAlert, MapPin, Plus, Trash2 } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Alert as RNAlert, ScrollView, View } from 'react-native';

/** Toasts clear the sticky bar by this much. */
const BAR_OFFSET = 96;

export default function AddressesScreen() {
  const { user } = useAuth();
  const { itemCount } = useCart();
  const router = useRouter();
  const [addresses, setAddresses] = useState<SavedAddress[]>([]);
  // True only until the first answer: later focus reloads keep the list on screen.
  const [loading, setLoading] = useState(true);
  // A failed load or refresh (offers a retry), and a failed action on one address (does not).
  const [loadError, setLoadError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const userId = user?.id ?? null;

  const load = useCallback(async () => {
    if (userId === null) {
      setAddresses([]);
      setLoading(false);
      return;
    }
    try {
      const res = await api<{ data: SavedAddress[] }>('/addresses');
      setAddresses(res.data ?? []);
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
    RNAlert.alert('Hapus alamat?', 'Alamat ini akan dihapus dari buku alamat.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Hapus', style: 'destructive', onPress: () => void remove(id) },
    ]);
  }

  function goShopping() {
    if (router.canDismiss()) {
      router.dismissTo('/(tabs)');
    } else {
      router.replace('/(tabs)');
    }
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

  if (addresses.length === 0) {
    return (
      <Screen title="Daftar alamat">
        <EmptyState
          icon={MapPin}
          title="Belum ada alamat tersimpan"
          message="Alamat yang kamu isi saat checkout akan tersimpan di sini."
          actionLabel={itemCount > 0 ? 'Lanjut ke checkout' : 'Belanja sekarang'}
          onAction={() => (itemCount > 0 ? router.push('/checkout') : goShopping())}
        />
      </Screen>
    );
  }

  return (
    <Screen title="Daftar alamat" surface>
      <ScrollView
        className="flex-1"
        contentContainerClassName="pb-10 pt-1"
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
                {address.rajaongkir_destination_label ?? `${address.city} ${address.postal_code}`}
              </Text>
              <View className="mt-3 flex-row gap-2 border-t border-border pt-3">
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
                  className="min-h-11 flex-1 rounded-sm">
                  <Icon as={Trash2} size={13} className="text-destructive" />
                  <Text className="text-[12px] font-semibold text-destructive">Hapus</Text>
                </Button>
              </View>
            </View>
          );
        })}
      </ScrollView>
      <StickyBar>
        {itemCount > 0 ? (
          <Button onPress={() => router.push('/checkout')} className="min-h-11 rounded-sm">
            <Icon as={Plus} size={16} color={COLORS.white} />
            <Text className="font-bold">Tambah alamat di checkout</Text>
          </Button>
        ) : (
          <Text className="py-1 text-center text-[12px] leading-4 text-muted-foreground">
            Alamat baru bisa ditambahkan saat checkout.
          </Text>
        )}
      </StickyBar>
    </Screen>
  );
}
