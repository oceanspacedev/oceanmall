import { EmptyState } from '@/components/empty-state';
import { Screen } from '@/components/screen';
import { StatusPill, StickyBar } from '@/components/shop';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type SavedAddress } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { useFocusEffect, useRouter } from 'expo-router';
import { CircleAlert, MapPin, Plus, Trash2, User } from 'lucide-react-native';
import { cn } from '@/lib/utils';
import { useCallback, useState } from 'react';
import { Alert as RNAlert, ScrollView, View } from 'react-native';

export default function AddressesScreen() {
  const { user } = useAuth();
  const { itemCount } = useCart();
  const router = useRouter();
  const [addresses, setAddresses] = useState<SavedAddress[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    if (!user) {
      setAddresses([]);
      setLoading(false);
      return;
    }
    try {
      const res = await api<{ data: SavedAddress[] }>('/addresses');
      setAddresses(res.data ?? []);
      setError(null);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat alamat'));
    } finally {
      setLoading(false);
    }
  }, [user]);

  useFocusEffect(
    useCallback(() => {
      setLoading(true);
      void load();
    }, [load])
  );

  async function setDefault(id: number) {
    setBusyId(id);
    try {
      await api(`/addresses/${id}/default-shipping`, { method: 'PATCH' });
      await load();
    } catch (e) {
      setError(errorMessage(e, 'Gagal menjadikan default'));
    } finally {
      setBusyId(null);
    }
  }

  async function remove(id: number) {
    setBusyId(id);
    try {
      await api(`/addresses/${id}`, { method: 'DELETE' });
      setAddresses((current) => current.filter((row) => row.id !== id));
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

  if (!user) {
    return (
      <Screen title="Daftar alamat">
        <EmptyState
          icon={User}
          message="Masuk untuk melihat alamat."
          actionLabel="Masuk"
          onAction={() => router.push({ pathname: '/login', params: { redirect: '/addresses' } })}
        />
      </Screen>
    );
  }

  if (loading) {
    return <Screen title="Daftar alamat" loading />;
  }

  if (error && addresses.length === 0) {
    return (
      <Screen title="Daftar alamat">
        <EmptyState
          icon={CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      </Screen>
    );
  }

  if (addresses.length === 0) {
    return (
      <Screen title="Daftar alamat">
        <EmptyState
          icon={MapPin}
          message="Belum ada alamat. Isi saat checkout setelah ada barang di keranjang."
          actionLabel={itemCount > 0 ? 'Ke checkout' : 'Belanja sekarang'}
          onAction={() => router.push(itemCount > 0 ? '/checkout' : '/(tabs)')}
        />
      </Screen>
    );
  }

  return (
    <Screen title="Daftar alamat" surface>
      <ScrollView className="flex-1" contentContainerClassName="pb-10 pt-1">
        {error ? <Text className="mx-4 mt-3 text-[12px] text-destructive">{error}</Text> : null}

        {addresses.map((address) => (
          <View
            key={address.id}
            className={cn(
              'mx-3 mt-2.5 rounded-sm border bg-white p-4',
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
                  disabled={busyId === address.id}
                  onPress={() => void setDefault(address.id)}
                  className="flex-1 rounded-sm border-om-navy">
                  <Text className="text-[12px] font-bold text-om-navy">Jadikan utama</Text>
                </Button>
              ) : null}
              <Button
                size="sm"
                variant="outline"
                disabled={busyId === address.id}
                onPress={() => confirmRemove(address.id)}
                className="flex-1 rounded-sm">
                <Icon as={Trash2} size={13} className="text-destructive" />
                <Text className="text-[12px] font-semibold text-destructive">Hapus</Text>
              </Button>
            </View>
          </View>
        ))}
      </ScrollView>
      <StickyBar>
        <Button onPress={() => router.push('/checkout')} className="h-11 rounded-sm">
          <Icon as={Plus} size={16} color="#fff" />
          <Text className="font-bold">Tambah alamat saat checkout</Text>
        </Button>
      </StickyBar>
    </Screen>
  );
}
