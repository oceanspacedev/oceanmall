import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { Screen } from '@/components/screen';
import { api, errorMessage, type Collection, type Product } from '@/lib/api';
import { useLocalSearchParams } from 'expo-router';
import { CircleAlert } from 'lucide-react-native';
import { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView } from 'react-native';

export default function CollectionScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const [collection, setCollection] = useState<Collection | null>(null);
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!slug) {
      return;
    }
    setError(null);
    try {
      const res = await api<{ data: { collection: Collection; products: Product[] } }>(
        `/catalog/collections/${slug}`
      );
      setCollection(res.data.collection);
      setProducts(res.data.products ?? []);
    } catch (e) {
      setError(errorMessage(e, 'Koleksi tidak ditemukan'));
      setProducts([]);
    } finally {
      setLoading(false);
    }
  }, [slug]);

  useEffect(() => {
    setLoading(true);
    void load();
  }, [load]);

  return (
    <Screen title={collection?.name ?? 'Koleksi'} showCart loading={loading}>
      {error && products.length === 0 ? (
        <EmptyState icon={CircleAlert} message={error} actionLabel="Coba lagi" onAction={() => void load()} />
      ) : (
        <ScrollView
          className="flex-1"
          contentContainerClassName="grow px-3 pb-8 pt-3"
          refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}>
          <ProductGrid products={products} emptyMessage="Belum ada produk di koleksi ini." />
        </ScrollView>
      )}
    </Screen>
  );
}
