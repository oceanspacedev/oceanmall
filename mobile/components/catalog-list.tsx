import { LoadMore } from '@/components/load-more';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { Screen } from '@/components/screen';
import { SortBar, type SortKey } from '@/components/shop';
import { Skeleton } from '@/components/ui/skeleton';
import { api, errorMessage, type Product } from '@/lib/api';
import { useNetwork } from '@/lib/network';
import { CircleAlert, WifiOff } from 'lucide-react-native';
import { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, View } from 'react-native';

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
  const { offline } = useNetwork();
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [sort, setSort] = useState<SortKey>('latest');
  const [title, setTitle] = useState(heading);

  const search = new URLSearchParams({ ...query, sort }).toString();
  const url = `${path}?${search}`;

  const load = useCallback(async () => {
    setError(null);
    try {
      const res = await api<{ data: Product[]; meta?: PageMeta; category?: { name: string } }>(url);
      setProducts(res.data ?? []);
      setMeta(res.meta ?? null);
      if (res.category?.name) {
        setTitle(res.category.name);
      }
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat produk'));
    } finally {
      setLoading(false);
    }
  }, [url]);

  useEffect(() => {
    setLoading(true);
    void load();
  }, [load]);

  async function loadMore() {
    if (!hasMorePages(meta) || loadingMore || !meta) {
      return;
    }
    setLoadingMore(true);
    try {
      const res = await api<{ data: Product[]; meta?: PageMeta }>(
        withPage(url, meta.current_page + 1)
      );
      setProducts((current) => appendUnique(current, res.data ?? []));
      setMeta(res.meta ?? null);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat halaman berikutnya'));
    } finally {
      setLoadingMore(false);
    }
  }

  return (
    <Screen title={title} showCart surface>
      {loading ? (
        <View className="flex-row flex-wrap justify-between px-3 pt-3">
          <Skeleton className="mb-2.5 h-[210px] w-[48.5%] rounded-sm" />
          <Skeleton className="mb-2.5 h-[210px] w-[48.5%] rounded-sm" />
          <Skeleton className="mb-2.5 h-[210px] w-[48.5%] rounded-sm" />
          <Skeleton className="mb-2.5 h-[210px] w-[48.5%] rounded-sm" />
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
          contentContainerClassName="grow pb-8"
          refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}>
          <SortBar total={meta?.total ?? products.length} value={sort} onChange={setSort} />
          <View className="px-3 pt-3">
            <ProductGrid products={products} emptyMessage="Belum ada produk di daftar ini." />
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
