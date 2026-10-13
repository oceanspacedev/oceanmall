import { LoadMore } from '@/components/load-more';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type Category, type Product } from '@/lib/api';
import { useNetwork } from '@/lib/network';
import { consumePendingSearchQuery } from '@/lib/search-query';
import { clearRecentSearches, getRecentSearches, rememberSearch } from '@/lib/recent-searches';
import { cn } from '@/lib/utils';
import { Link, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { Clock, SearchIcon, SearchX, WifiOff, X } from 'lucide-react-native';
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
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [sort, setSort] = useState<'latest' | 'price_asc' | 'price_desc'>('latest');
  const [recent, setRecent] = useState<string[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loadingMore, setLoadingMore] = useState(false);
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

    // A slow response for an older query must not overwrite newer results.
    let stale = false;
    const handle = setTimeout(() => {
      setLoading(true);
      setError(null);
      api<{ data: Product[]; meta?: PageMeta }>(
        `/catalog/search?q=${encodeURIComponent(query)}&sort=${sort}`
      )
        .then((res) => {
          if (stale) {
            return;
          }
          setProducts(res.data ?? []);
          setMeta(res.meta ?? null);
        })
        .catch((e) => {
          if (stale) {
            return;
          }
          setProducts([]);
          setMeta(null);
          setError(errorMessage(e, 'Gagal mencari produk'));
        })
        .finally(() => {
          if (!stale) {
            setLoading(false);
          }
        });
    }, 350);

    return () => {
      stale = true;
      clearTimeout(handle);
    };
  }, [query, searching, sort]);

  useEffect(() => {
    void getRecentSearches().then(setRecent);
    void api<{ data: { categories?: Category[] } | Category[] }>('/catalog/categories')
      .then((res) =>
        setCategories(Array.isArray(res.data) ? res.data : (res.data.categories ?? []))
      )
      .catch(() => setCategories([]));
  }, []);

  // Remember a query once the user pauses on it with results.
  useEffect(() => {
    if (!searching || products.length === 0) {
      return;
    }
    const t = setTimeout(() => void rememberSearch(query).then(setRecent), 1200);
    return () => clearTimeout(t);
  }, [products.length, query, searching]);

  async function loadMore() {
    if (!meta || !hasMorePages(meta) || loadingMore) {
      return;
    }
    const forQuery = query;
    setLoadingMore(true);
    try {
      const res = await api<{ data: Product[]; meta?: PageMeta }>(
        withPage(
          `/catalog/search?q=${encodeURIComponent(forQuery)}&sort=${sort}`,
          meta.current_page + 1
        )
      );
      if (forQuery === query) {
        setProducts((current) => appendUnique(current, res.data ?? []));
        setMeta(res.meta ?? null);
      }
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat halaman berikutnya'));
    } finally {
      setLoadingMore(false);
    }
  }

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
          <ScrollView
            className="flex-1"
            contentContainerClassName="px-4 pb-8 pt-4"
            keyboardShouldPersistTaps="handled">
            {recent.length > 0 ? (
              <View className="mb-6">
                <View className="mb-3 flex-row items-center justify-between">
                  <Text className="text-[15px] font-bold">Pencarian terakhir</Text>
                  <Pressable
                    hitSlop={8}
                    onPress={() => {
                      void clearRecentSearches();
                      setRecent([]);
                    }}>
                    <Text className="text-[12px] font-semibold text-om-navy">Hapus</Text>
                  </Pressable>
                </View>
                {recent.map((item) => (
                  <Pressable
                    key={item}
                    onPress={() => setQ(item)}
                    className="flex-row items-center gap-3 py-2.5">
                    <Icon as={Clock} size={15} color="#94a3b8" />
                    <Text className="flex-1 text-[14px]">{item}</Text>
                  </Pressable>
                ))}
              </View>
            ) : null}
            {categories.length > 0 ? (
              <View>
                <Text className="mb-3 text-[15px] font-bold">Jelajahi kategori</Text>
                <View className="flex-row flex-wrap gap-2">
                  {categories.map((category) => (
                    <Link key={category.id} href={`/category/${category.slug}`} asChild>
                      <Pressable className="rounded-sm border border-border bg-white px-3.5 py-2">
                        <Text className="text-[13px]">{category.name}</Text>
                      </Pressable>
                    </Link>
                  ))}
                </View>
              </View>
            ) : (
              <EmptyState icon={SearchIcon} message="Cari HP, aksesoris, audio, dan lainnya." />
            )}
          </ScrollView>
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
            className="flex-1 bg-om-surface"
            contentContainerClassName="pb-8"
            keyboardShouldPersistTaps="handled">
            <View className="border-b border-border bg-white px-3 pb-3 pt-3">
              <Text className="mb-2.5 px-1 text-[12px] text-muted-foreground">
                {meta?.total ?? products.length} produk untuk{' '}
                <Text className="text-[12px] font-bold text-foreground">&quot;{query}&quot;</Text>
              </Text>
              <View className="flex-row gap-2">
                {(
                  [
                    ['latest', 'Terbaru'],
                    ['price_asc', 'Termurah'],
                    ['price_desc', 'Termahal'],
                  ] as const
                ).map(([key, label]) => (
                  <Pressable
                    key={key}
                    onPress={() => setSort(key)}
                    className={cn(
                      'rounded-sm border px-3.5 py-1.5',
                      sort === key ? 'border-om-navy bg-om-navy-soft' : 'border-border bg-white'
                    )}>
                    <Text
                      className={cn(
                        'text-[12px]',
                        sort === key ? 'font-bold text-om-navy' : 'text-foreground'
                      )}>
                      {label}
                    </Text>
                  </Pressable>
                ))}
              </View>
            </View>
            <View className="px-3 pt-3">
              <ProductGrid products={products} emptyMessage="" />
            </View>
            <LoadMore
              visible={hasMorePages(meta)}
              loading={loadingMore}
              onPress={() => void loadMore()}
            />
          </ScrollView>
        )}
      </View>
    </SafeAreaView>
  );
}
