import { LoadMore } from '@/components/load-more';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { Text } from '@/components/ui/text';
import { mediaUrl } from '@/lib/format';
import { Image } from 'expo-image';
import { Screen } from '@/components/screen';
import { api, errorMessage, type Collection, type Product } from '@/lib/api';
import { useLocalSearchParams } from 'expo-router';
import { CircleAlert } from 'lucide-react-native';
import { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, View } from 'react-native';

export default function CollectionScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const [collection, setCollection] = useState<Collection | null>(null);
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);

  const load = useCallback(async () => {
    if (!slug) {
      return;
    }
    setError(null);
    try {
      const res = await api<{
        data: { collection: Collection; products: Product[] };
        meta?: PageMeta;
      }>(`/catalog/collections/${slug}`);
      setCollection(res.data.collection);
      setProducts(res.data.products ?? []);
      setMeta(res.meta ?? null);
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

  async function loadMore() {
    if (!meta || !hasMorePages(meta) || loadingMore) {
      return;
    }
    setLoadingMore(true);
    try {
      const res = await api<{ data: { products: Product[] }; meta?: PageMeta }>(
        withPage(`/catalog/collections/${slug}`, meta.current_page + 1)
      );
      setProducts((current) => appendUnique(current, res.data.products ?? []));
      setMeta(res.meta ?? null);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat halaman berikutnya'));
    } finally {
      setLoadingMore(false);
    }
  }

  return (
    <Screen title={collection?.name ?? 'Koleksi'} showCart loading={loading} surface>
      {error && products.length === 0 ? (
        <EmptyState
          icon={CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      ) : (
        <ScrollView
          className="flex-1"
          contentContainerClassName="grow pb-8"
          refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}>
          {collection ? (
            <View className="h-40 bg-om-navy">
              {collection.thumbnail ? (
                <Image
                  source={{ uri: mediaUrl(collection.thumbnail) }}
                  style={{ width: '100%', height: 160 }}
                  contentFit="cover"
                />
              ) : null}
              <View className="absolute inset-x-0 bottom-0 bg-black/45 px-4 py-3">
                <Text className="text-[20px] font-extrabold text-white">{collection.name}</Text>
                <Text className="text-[12px] text-white/90">
                  {meta?.total ?? products.length} produk pilihan
                </Text>
              </View>
            </View>
          ) : null}
          <View className="px-3 pt-3">
            <ProductGrid products={products} emptyMessage="Belum ada produk di koleksi ini." />
          </View>
          <LoadMore
            visible={hasMorePages(meta)}
            loading={loadingMore}
            onPress={() => void loadMore()}
          />
        </ScrollView>
      )}
    </Screen>
  );
}
