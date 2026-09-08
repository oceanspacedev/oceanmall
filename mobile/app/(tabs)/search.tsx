import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { Icon } from '@/components/ui/icon';
import { api, errorMessage, type Product } from '@/lib/api';
import { useNetwork } from '@/lib/network';
import { consumePendingSearchQuery } from '@/lib/search-query';
import { useFocusEffect, useLocalSearchParams } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SearchIcon, SearchX, WifiOff, X } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export default function SearchScreen() {
  const inputRef = useRef<TextInput>(null);
  const { offline } = useNetwork();
  const params = useLocalSearchParams<{ q?: string }>();
  const [q, setQ] = useState(() => (typeof params.q === 'string' ? params.q : ''));
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const query = q.trim();
  const searching = query.length >= 2;

  const appliedQuery = useRef<string | null>(null);

  useFocusEffect(
    useCallback(() => {
      const pending = consumePendingSearchQuery();
      const fromParams = typeof params.q === 'string' ? params.q : '';
      if (pending !== '') {
        setQ(pending);
        appliedQuery.current = pending;
        return undefined;
      }
      if (fromParams !== '' && fromParams !== appliedQuery.current) {
        setQ(fromParams);
        appliedQuery.current = fromParams;
        return undefined;
      }
      if (!appliedQuery.current) {
        const t = setTimeout(() => inputRef.current?.focus(), 250);
        return () => clearTimeout(t);
      }
      return undefined;
    }, [params.q])
  );

  useEffect(() => {
    if (!searching) {
      setProducts([]);
      setLoading(false);
      setError(null);
      return;
    }

    const handle = setTimeout(() => {
      setLoading(true);
      setError(null);
      api<{ data: Product[] }>(`/catalog/search?q=${encodeURIComponent(query)}`)
        .then((res) => setProducts(res.data ?? []))
        .catch((e) => {
          setProducts([]);
          setError(errorMessage(e, 'Gagal mencari produk'));
        })
        .finally(() => setLoading(false));
    }, 350);

    return () => clearTimeout(handle);
  }, [query, searching]);

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <StatusBar style="light" />
      <View className="bg-om-navy px-3 pb-3 pt-1">
        <View className="h-10 flex-row items-center rounded-full bg-white px-3">
          <Icon as={SearchIcon} size={16} color="#64748b" />
          <TextInput
            ref={inputRef}
            value={q}
            onChangeText={setQ}
            placeholder="Cari produk..."
            placeholderTextColor="#94a3b8"
            autoCapitalize="none"
            autoCorrect={false}
            returnKeyType="search"
            className="ml-2 flex-1 text-[15px] text-foreground"
          />
          {q.length > 0 ? (
            <Pressable onPress={() => setQ('')} hitSlop={8} className="pl-2">
              <Icon as={X} size={16} color="#64748b" />
            </Pressable>
          ) : null}
        </View>
      </View>

      <View className="flex-1 bg-background">
        {!searching ? (
          <EmptyState icon={SearchIcon} message="Ketik minimal 2 karakter untuk mencari." />
        ) : loading ? (
          <View className="flex-1 items-center justify-center">
            <ActivityIndicator />
          </View>
        ) : error ? (
          <EmptyState icon={offline ? WifiOff : SearchX} message={error} />
        ) : products.length === 0 ? (
          <EmptyState icon={SearchX} message="Tidak ada hasil. Coba kata kunci lain." />
        ) : (
          <ScrollView
            className="flex-1"
            contentContainerClassName="px-3 pb-8 pt-4"
            keyboardShouldPersistTaps="handled">
            <ProductGrid products={products} emptyMessage="" />
          </ScrollView>
        )}
      </View>
    </SafeAreaView>
  );
}
