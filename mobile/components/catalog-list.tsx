import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { Screen } from '@/components/screen';
import { Skeleton } from '@/components/ui/skeleton';
import { api, errorMessage, type Product } from '@/lib/api';
import { useNetwork } from '@/lib/network';
import { CircleAlert, WifiOff } from 'lucide-react-native';
import { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, View } from 'react-native';

export function CatalogList({
  heading,
  query,
}: {
  heading: string;
  query: Record<string, string>;
}) {
  const { offline } = useNetwork();
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const search = new URLSearchParams(query).toString();

  const load = useCallback(async () => {
    setError(null);
    try {
      const path = search === '' ? '/catalog/products' : `/catalog/products?${search}`;
      const res = await api<{ data: Product[] }>(path);
      setProducts(res.data ?? []);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat produk'));
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => {
    setLoading(true);
    void load();
  }, [load]);

  return (
    <Screen title={heading} showCart>
      {loading ? (
        <View className="flex-row flex-wrap justify-between px-3 pt-3">
          <Skeleton className="mb-2.5 h-[210px] w-[48.5%] rounded-lg" />
          <Skeleton className="mb-2.5 h-[210px] w-[48.5%] rounded-lg" />
          <Skeleton className="mb-2.5 h-[210px] w-[48.5%] rounded-lg" />
          <Skeleton className="mb-2.5 h-[210px] w-[48.5%] rounded-lg" />
        </View>
      ) : error && products.length === 0 ? (
        <EmptyState
          icon={offline ? WifiOff : CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      ) : (
        <ScrollView
          className="flex-1"
          contentContainerClassName="grow px-3 pb-8 pt-3"
          refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}>
          <ProductGrid products={products} emptyMessage="Belum ada produk di daftar ini." />
        </ScrollView>
      )}
    </Screen>
  );
}
