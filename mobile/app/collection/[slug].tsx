import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { LoadMore } from '@/components/load-more';
import { ProductGridSkeleton } from '@/components/skeletons';
import { ProductImage } from '@/components/product-image';
import { ProductList } from '@/components/product-grid';
import { Screen } from '@/components/screen';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type Collection, type Product } from '@/lib/api';
import { mediaUrl } from '@/lib/format';
import { useNetwork } from '@/lib/network';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { useRefresh } from '@/lib/use-refresh';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { CircleAlert, Package, WifiOff } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { View } from 'react-native';

/** The hero banner and a grid of cards, so nothing jumps when the collection arrives. */
function CollectionSkeleton() {
  return (
    <View>
      <Skeleton className="h-40 w-full rounded-none" />
      <View className="px-3 pt-3">
        <ProductGridSkeleton count={6} />
      </View>
    </View>
  );
}

export default function CollectionScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const router = useRouter();
  const { offline } = useNetwork();
  const [collection, setCollection] = useState<Collection | null>(null);
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  // `error` is a failed first page or refresh; `loadError` is only a failed NEXT page, so it
  // never replaces the products already on screen.
  const [error, setError] = useState<string | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  // Bumped by every first-page request, so a stale answer never lands on newer content.
  const requestRef = useRef(0);

  const load = useCallback(async () => {
    if (!slug) {
      return;
    }
    const id = ++requestRef.current;
    setError(null);
    setLoadError(false);
    try {
      const res = await api<{
        data: { collection: Collection; products: Product[] };
        meta?: PageMeta;
      }>(`/catalog/collections/${slug}`);
      if (id !== requestRef.current) {
        return;
      }
      setCollection(res.data.collection);
      setProducts(res.data.products ?? []);
      setMeta(res.meta ?? null);
    } catch (e) {
      if (id !== requestRef.current) {
        return;
      }
      setError(errorMessage(e, 'Koleksi tidak ditemukan'));
    } finally {
      if (id === requestRef.current) {
        setLoading(false);
      }
    }
  }, [slug]);

  const { refreshing, onRefresh } = useRefresh(load);

  useEffect(() => {
    // A different collection starts from a clean slate instead of showing the previous one.
    setLoading(true);
    setLoadingMore(false);
    setCollection(null);
    setProducts([]);
    setMeta(null);
    void load();
  }, [load]);

  const retry = useCallback(() => {
    setLoading(true);
    void load();
  }, [load]);

  async function loadMore(isRetry = false) {
    if (!meta || !hasMorePages(meta) || loadingMore || loading || (loadError && !isRetry)) {
      return;
    }
    const id = requestRef.current;
    setLoadError(false);
    setLoadingMore(true);
    try {
      const res = await api<{ data: { products: Product[] }; meta?: PageMeta }>(
        withPage(`/catalog/collections/${slug}`, meta.current_page + 1)
      );
      if (id !== requestRef.current) {
        return;
      }
      setProducts((current) => appendUnique(current, res.data.products ?? []));
      setMeta(res.meta ?? null);
    } catch {
      if (id === requestRef.current) {
        setLoadError(true);
      }
    } finally {
      setLoadingMore(false);
    }
  }

  const hasProducts = products.length > 0;
  const fatal = Boolean(error) && !collection && !hasProducts;

  return (
    <Screen
      title={collection?.name ?? 'Koleksi'}
      showCart
      surface
      loading={loading && !collection ? <CollectionSkeleton /> : false}>
      {fatal ? (
        <EmptyState
          tone="error"
          icon={offline ? WifiOff : CircleAlert}
          title="Yah, ada yang salah"
          message={error ?? ''}
          actionLabel="Coba lagi"
          onAction={retry}
          className="bg-om-surface"
        />
      ) : (
        <ProductList
          products={products}
          contentContainerStyle={{ paddingBottom: 24 }}
          refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
          ListHeaderComponent={
            <View className="mb-3">
              {collection ? (
                <View className="h-40 bg-om-navy">
                  {collection.thumbnail ? (
                    <ProductImage
                      uri={mediaUrl(collection.thumbnail)}
                      plain
                      priority="high"
                      fallback={<View />}
                      style={{ position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 }}
                    />
                  ) : null}
                  <View className="absolute inset-x-0 bottom-0 bg-black/45 px-4 py-3">
                    <Text
                      role="heading"
                      aria-level={2}
                      className="text-[20px] font-extrabold text-white"
                      numberOfLines={2}>
                      {collection.name}
                    </Text>
                    <Text className="text-[12px] text-white/90">
                      {meta?.total ?? products.length} produk pilihan
                    </Text>
                  </View>
                </View>
              ) : null}
              {error ? <ErrorBanner message={error} onRetry={offline ? undefined : retry} /> : null}
            </View>
          }
          ListEmptyComponent={
            <EmptyState
              compact
              icon={Package}
              title="Belum ada produk di sini"
              message="Cek lagi nanti atau lihat promo hari ini."
              actionLabel="Lihat promo"
              onAction={() => router.push('/promo')}
              className="py-16"
            />
          }
          ListFooterComponent={
            <LoadMore
              hasMore={hasMorePages(meta)}
              loading={loadingMore}
              error={loadError ? 'Gagal memuat produk berikutnya' : null}
              onRetry={() => void loadMore(true)}
              count={products.length}
              showEnd
            />
          }
          onEndReached={() => void loadMore()}
        />
      )}
    </Screen>
  );
}
