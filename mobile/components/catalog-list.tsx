import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { LoadMore } from '@/components/load-more';
import { ProductList } from '@/components/product-grid';
import { Screen } from '@/components/screen';
import { SortBar, type SortKey } from '@/components/shop';
import { ProductGridSkeleton } from '@/components/skeletons';
import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { Skeleton } from '@/components/ui/skeleton';
import { api, errorMessage, type Product } from '@/lib/api';
import { useNetwork } from '@/lib/network';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { useRefresh } from '@/lib/use-refresh';
import { cn } from '@/lib/utils';
import { useRouter } from 'expo-router';
import { CircleAlert, Package, WifiOff } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { View } from 'react-native';

/** Placeholder with the same silhouette as the loaded page: the sort bar and a grid of cards. */
function CatalogSkeleton() {
  return (
    <View>
      <View className="h-[84px] border-b border-border bg-white px-4 pt-3">
        <Skeleton className="h-3 w-20" />
        <View className="mt-3 flex-row gap-2">
          <Skeleton className="h-8 w-20 rounded-sm" />
          <Skeleton className="h-8 w-20 rounded-sm" />
          <Skeleton className="h-8 w-20 rounded-sm" />
        </View>
      </View>
      <View className="px-3 pt-3">
        <ProductGridSkeleton count={6} />
      </View>
    </View>
  );
}

/**
 * A titled, sortable, infinitely scrolling grid of products: the body of the category, brand,
 * promo and recommendation pages.
 */
export function CatalogList({
  heading,
  query,
  path = '/catalog/products',
}: {
  heading: string;
  query: Record<string, string>;
  /** List endpoint; category pages pass `/catalog/categories/{slug}`. */
  path?: string;
}) {
  const router = useRouter();
  const { offline } = useNetwork();
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  // `error` is a failed first page or refresh; `loadError` is only a failed NEXT page, so a
  // hiccup at the bottom never replaces the products already on screen.
  const [error, setError] = useState<string | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [sort, setSort] = useState<SortKey>('latest');
  const [title, setTitle] = useState(heading);
  // Bumped by every first-page request: an answer for an older sort or an older pull must not
  // overwrite a newer one, and a next page for the old list must not be appended to the new one.
  const requestRef = useRef(0);

  const search = new URLSearchParams({ ...query, sort }).toString();
  const url = `${path}?${search}`;

  const load = useCallback(async () => {
    const id = ++requestRef.current;
    setError(null);
    setLoadError(false);
    try {
      const res = await api<{ data: Product[]; meta?: PageMeta; category?: { name: string } }>(url);
      if (id !== requestRef.current) {
        return;
      }
      setProducts(res.data ?? []);
      setMeta(res.meta ?? null);
      if (res.category?.name) {
        setTitle(res.category.name);
      }
    } catch (e) {
      if (id !== requestRef.current) {
        return;
      }
      setError(errorMessage(e, 'Gagal memuat produk'));
    } finally {
      if (id === requestRef.current) {
        setLoading(false);
      }
    }
  }, [url]);

  const { refreshing, onRefresh } = useRefresh(load);

  useEffect(() => {
    setLoading(true);
    setLoadingMore(false);
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
      const res = await api<{ data: Product[]; meta?: PageMeta }>(
        withPage(url, meta.current_page + 1)
      );
      if (id !== requestRef.current) {
        return;
      }
      setProducts((current) => appendUnique(current, res.data ?? []));
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
  const initialLoading = loading && !hasProducts;
  const onPromoPage = query.promo === '1';

  return (
    <Screen title={title} showCart surface loading={initialLoading ? <CatalogSkeleton /> : false}>
      {error && !hasProducts ? (
        <EmptyState
          tone="error"
          icon={offline ? WifiOff : CircleAlert}
          title="Yah, ada yang salah"
          message={error}
          actionLabel="Coba lagi"
          onAction={retry}
          className="bg-om-surface"
        />
      ) : (
        // A sort change keeps the current products on screen, dimmed, until the new ones land.
        <View className={cn('flex-1', loading && 'opacity-60')}>
          <ProductList
            products={products}
            contentContainerStyle={{ paddingBottom: 24 }}
            refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
            ListHeaderComponent={
              hasProducts ? (
                <View className="mb-3">
                  <SortBar total={meta?.total ?? products.length} value={sort} onChange={setSort} />
                  {error ? (
                    <ErrorBanner message={error} onRetry={offline ? undefined : retry} />
                  ) : null}
                </View>
              ) : null
            }
            ListEmptyComponent={
              <EmptyState
                compact
                icon={Package}
                title="Belum ada produk di sini"
                message="Cek lagi nanti atau lihat promo hari ini."
                actionLabel={onPromoPage ? undefined : 'Lihat promo'}
                onAction={onPromoPage ? undefined : () => router.push('/promo')}
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
        </View>
      )}
    </Screen>
  );
}
