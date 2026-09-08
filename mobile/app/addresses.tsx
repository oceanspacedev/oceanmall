import { EmptyState } from '@/components/empty-state';
import { PillButton } from '@/components/pill-button';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type SavedAddress } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { useFocusEffect, useRouter } from 'expo-router';
import { CircleAlert, MapPin, User } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Alert as RNAlert, Pressable, ScrollView, View } from 'react-native';

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
      <Screen title="Alamat">
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
    return <Screen title="Alamat" loading />;
  }

  if (error && addresses.length === 0) {
    return (
      <Screen title="Alamat">
        <EmptyState icon={CircleAlert} message={error} actionLabel="Coba lagi" onAction={() => void load()} />
      </Screen>
    );
  }

  if (addresses.length === 0) {
    return (
      <Screen title="Alamat">
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
    <Screen title="Alamat">
      <ScrollView className="flex-1" contentContainerClassName="px-3 pb-10 pt-3">
        {error ? <Text className="mb-3 text-[12px] text-destructive">{error}</Text> : null}

        {addresses.map((address) => (
          <View key={address.id} className="mb-2.5 rounded-lg border border-border bg-white p-3">
            <View className="flex-row items-start justify-between gap-2">
              <Text className="flex-1 text-[13px] font-semibold">
                {address.first_name} {address.last_name}
              </Text>
              {address.shipping_default ? (
                <Text className="text-[11px] font-semibold text-om-navy">Utama</Text>
              ) : null}
            </View>
            <Text className="mt-1 text-[12px] leading-5 text-muted-foreground">
              {address.street_address}
              {'\n'}
              {address.city} {address.postal_code}
              {address.phone_number ? `\n${address.phone_number}` : ''}
            </Text>
            <View className="mt-2.5 flex-row gap-4">
              {!address.shipping_default ? (
                <Pressable disabled={busyId === address.id} onPress={() => void setDefault(address.id)}>
                  <Text className="text-[12px] font-semibold text-om-navy">Jadikan utama</Text>
                </Pressable>
              ) : null}
              <Pressable disabled={busyId === address.id} onPress={() => confirmRemove(address.id)}>
                <Text className="text-[12px] text-destructive">Hapus</Text>
              </Pressable>
            </View>
          </View>
        ))}

        <View className="mt-3">
          <PillButton outline label="Tambah lewat checkout" onPress={() => router.push('/checkout')} />
        </View>
      </ScrollView>
    </Screen>
  );
}
