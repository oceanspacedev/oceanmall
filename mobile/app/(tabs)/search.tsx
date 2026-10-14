import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { LoadMore } from '@/components/load-more';
import { ProductList } from '@/components/product-grid';
import { TabHeader } from '@/components/screen';
import { SortBar, type SortKey } from '@/components/shop';
import { ProductGridSkeleton } from '@/components/skeletons';
import { PLACEHOLDER_COLOR } from '@/components/ui/input';
import { Icon } from '@/components/ui/icon';
import { Spinner } from '@/components/ui/spinner';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type Category, type Product } from '@/lib/api';
import { useNetwork } from '@/lib/network';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import {
  clearRecentSearches,
  forgetSearch,
  getRecentSearches,
  rememberSearch,
} from '@/lib/recent-searches';
import { consumePendingSearchQuery } from '@/lib/search-query';
import { COLORS } from '@/lib/theme';
import { useRefresh } from '@/lib/use-refresh';
import {
  Link,
  useFocusEffect,
  useLocalSearchParams,
  useNavigation,
  useScrollToTop,
} from 'expo-router';
import { CircleAlert, SearchIcon, SearchX, WifiOff, X } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
  FlatList,
  Keyboard,
  Pressable,
  ScrollView,
  TextInput,
  View,
  type TextInput as TextInputType,
} from 'react-native';
import { SafeAreaView } from '@/components/safe-area-view';

/** A search needs at least this many characters (the API rejects shorter ones). */
const MIN_QUERY = 2;
const DEBOUNCE_MS = 350;

type SearchResponse = { data: Product[]; meta?: PageMeta };

/** The navigation prop of a bottom-tab screen, narrowed to the one event used here. */
type TabNavigation = {
  isFocused(): boolean;
  addListener(type: 'tabPress', callback: () => void): () => void;
};

function CategoryChips({ categories }: { categories: Category[] }) {
  return (
    <View className="flex-row flex-wrap gap-2">
      {categories.map((category) => (
        <Link key={category.id} href={`/category/${category.slug}`} asChild>
          <Pressable
            accessibilityRole="link"
            hitSlop={{ top: 5, bottom: 5 }}
            className="rounded-sm border border-border bg-white px-3.5 py-2 active:opacity-70">
            <Text className="text-[13px]">{category.name}</Text>
          </Pressable>
        </Link>
      ))}
    </View>
  );
}

export default function SearchScreen() {
  const inputRef = useRef<TextInputType>(null);
  const idleListRef = useRef<ScrollView>(null);
  const resultsRef = useRef<FlatList<Product>>(null);
  const navigation = useNavigation();
  const { offline } = useNetwork();
  const params = useLocalSearchParams<{ q?: string }>();
  const [q, setQ] = useState(() => (typeof params.q === 'string' ? params.q : ''));
  const [products, setProducts] = useState<Product[]>([]);
  // `error` is a failed search or refresh; `loadError` is only a failed NEXT page, so it never
  // replaces the results already on screen.
  const [error, setError] = useState<string | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [sort, setSort] = useState<SortKey>('latest');
  const [recent, setRecent] = useState<string[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loadingMore, setLoadingMore] = useState(false);
  const [retryKey, setRetryKey] = useState(0);
  const query = q.trim();
  const searching = query.length >= MIN_QUERY;
  const tooShort = query.length > 0 && !searching;

  // A search is "settled" once the answer for the current query, sort and retry has arrived. The
  // moment any of them changes the screen is loading again, so a new keystroke never flashes the
  // previous query's "no results".
  const key = `${query}\n${sort}\n${retryKey}`;
  const [settledKey, setSettledKey] = useState<string | null>(null);
  const loading = searching && settledKey !== key;

  const requestRef = useRef(0);
  const qRef = useRef(q);
  qRef.current = q;
  const appliedQuery = useRef<string | null>(null);

  useScrollToTop(idleListRef);
  useScrollToTop(resultsRef);

  // Pressing the Cari tab while already on it jumps back into the field.
  useEffect(() => {
    const tabs = navigation as unknown as TabNavigation;
    return tabs.addListener('tabPress', () => {
      if (tabs.isFocused()) {
        inputRef.current?.focus();
      }
    });
  }, [navigation]);

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
      // Opening the tab with nothing typed puts the keyboard up straight away.
      if (qRef.current.trim() === '') {
        const t = setTimeout(() => inputRef.current?.focus(), 250);
        return () => clearTimeout(t);
      }
      return undefined;
    }, [params.q])
  );

  /**
   * Fetches page one. `keepResults` (pull to refresh) leaves the current rows in place if it
   * fails; a fresh query clears them so the failure shows as the full-screen state.
   */
  const runSearch = useCallback(
    async (forQuery: string, forSort: SortKey, forKey: string, keepResults: boolean) => {
      // Bumped by every search: a slow answer for an older query must not overwrite newer results.
      const id = ++requestRef.current;
      try {
        const res = await api<SearchResponse>(
          `/catalog/search?q=${encodeURIComponent(forQuery)}&sort=${forSort}`
        );
        if (id !== requestRef.current) {
          return;
        }
        setProducts(res.data ?? []);
        setMeta(res.meta ?? null);
        setError(null);
        setLoadError(false);
        setSettledKey(forKey);
      } catch (e) {
        if (id !== requestRef.current) {
          return;
        }
        if (!keepResults) {
          setProducts([]);
          setMeta(null);
        }
        setError(errorMessage(e, 'Gagal mencari produk'));
        setSettledKey(forKey);
      }
    },
    []
  );

  useEffect(() => {
    if (!searching) {
      requestRef.current += 1;
      setProducts([]);
      setMeta(null);
      setError(null);
      setLoadError(false);
      setLoadingMore(false);
      setSettledKey(null);
      return;
    }

    setError(null);
    setLoadError(false);
    setLoadingMore(false);
    const handle = setTimeout(() => void runSearch(query, sort, key, false), DEBOUNCE_MS);

    return () => {
      clearTimeout(handle);
      // Also drops an answer that is still in flight for the query being replaced.
      requestRef.current += 1;
    };
  }, [searching, query, sort, key, runSearch]);

  const { refreshing, onRefresh } = useRefresh(() => runSearch(query, sort, key, true));

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

  async function loadMore(isRetry = false) {
    if (!meta || !hasMorePages(meta) || loadingMore || loading || (loadError && !isRetry)) {
      return;
    }
    const id = requestRef.current;
    setLoadError(false);
    setLoadingMore(true);
    try {
      const res = await api<SearchResponse>(
        withPage(
          `/catalog/search?q=${encodeURIComponent(query)}&sort=${sort}`,
          meta.current_page + 1
        )
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

  function submit() {
    Keyboard.dismiss();
    if (searching) {
      void rememberSearch(query).then(setRecent);
    }
  }

  function forget(item: string) {
    setRecent((current) => current.filter((entry) => entry !== item));
    void forgetSearch(item).then(setRecent);
  }

  const hasProducts = products.length > 0;

  function renderBody() {
    if (!searching) {
      return (
        <ScrollView
          ref={idleListRef}
          className="flex-1"
          contentContainerClassName="px-4 pb-8 pt-4"
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          automaticallyAdjustKeyboardInsets>
          {tooShort ? (
            <Text
              accessibilityLiveRegion="polite"
              className="mb-4 text-[12px] text-muted-foreground">
              Ketik minimal 2 huruf untuk mencari
            </Text>
          ) : null}

          {recent.length > 0 ? (
            <View className="mb-6">
              <View className="mb-1 flex-row items-center justify-between">
                <Text role="heading" aria-level={2} className="text-[15px] font-bold">
                  Pencarian terakhir
                </Text>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="Hapus semua pencarian terakhir"
                  hitSlop={{ top: 14, bottom: 14, left: 12, right: 12 }}
                  onPress={() => {
                    void clearRecentSearches();
                    setRecent([]);
                  }}
                  className="min-h-11 justify-center active:opacity-70">
                  <Text className="text-[12px] font-semibold text-om-navy">Hapus</Text>
                </Pressable>
              </View>
              <View className="flex-row flex-wrap gap-2.5">
                {recent.map((item) => (
                  <View
                    key={item}
                    className="flex-row items-center rounded-full border border-border bg-white">
                    <Pressable
                      accessibilityRole="button"
                      accessibilityLabel={`Cari ${item}`}
                      hitSlop={{ top: 8, bottom: 8, left: 4 }}
                      onPress={() => setQ(item)}
                      className="py-1.5 pl-3 pr-1.5 active:opacity-70">
                      <Text className="text-[12px]" numberOfLines={1}>
                        {item}
                      </Text>
                    </Pressable>
                    <Pressable
                      accessibilityRole="button"
                      accessibilityLabel={`Hapus ${item} dari pencarian terakhir`}
                      hitSlop={{ top: 8, bottom: 8, left: 6, right: 8 }}
                      onPress={() => forget(item)}
                      className="py-1.5 pl-1 pr-2.5 active:opacity-70">
                      <Icon as={X} size={12} color={COLORS.slate} />
                    </Pressable>
                  </View>
                ))}
              </View>
            </View>
          ) : null}

          {categories.length > 0 ? (
            <View>
              <Text role="heading" aria-level={2} className="mb-3 text-[15px] font-bold">
                Jelajahi kategori
              </Text>
              <CategoryChips categories={categories} />
            </View>
          ) : recent.length === 0 ? (
            <EmptyState
              compact
              icon={SearchIcon}
              title="Cari produk"
              message="Cari HP, aksesoris, audio, dan lainnya."
            />
          ) : null}
        </ScrollView>
      );
    }

    if (loading && !hasProducts) {
      return (
        <View accessibilityLiveRegion="polite" className="flex-1 overflow-hidden bg-om-surface">
          <Text className="absolute h-px w-px opacity-0">Mencari…</Text>
          <View className="px-3 pt-3">
            <ProductGridSkeleton count={6} />
          </View>
        </View>
      );
    }

    if (error && !hasProducts) {
      return (
        <EmptyState
          tone="error"
          icon={offline ? WifiOff : CircleAlert}
          title="Yah, ada yang salah"
          message={error}
          actionLabel="Coba lagi"
          onAction={() => setRetryKey((value) => value + 1)}
        />
      );
    }

    if (!hasProducts) {
      return (
        <ScrollView
          className="flex-1"
          contentContainerClassName="grow"
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag">
          <EmptyState
            icon={SearchX}
            title="Produk tidak ditemukan"
            message="Coba kata kunci lain atau cek ejaannya.">
            {categories.length > 0 ? (
              <View className="mt-6 w-full items-center">
                <Text className="mb-3 text-[12px] text-muted-foreground">
                  Atau jelajahi kategori
                </Text>
                <View className="items-center">
                  <CategoryChips categories={categories} />
                </View>
              </View>
            ) : null}
          </EmptyState>
        </ScrollView>
      );
    }

    return (
      <View className="flex-1 bg-om-surface">
        {/* New typing keeps the last results on screen, dimmed, instead of a spinner that
            flickers on every keystroke. */}
        <View className={loading ? 'flex-1 opacity-60' : 'flex-1'}>
          <ProductList
            ref={resultsRef}
            products={products}
            contentContainerStyle={{ paddingBottom: 24 }}
            keyboardShouldPersistTaps="handled"
            keyboardDismissMode="on-drag"
            automaticallyAdjustKeyboardInsets
            refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
            ListHeaderComponent={
              <View className="mb-3">
                <SortBar
                  value={sort}
                  onChange={setSort}
                  label={
                    <>
                      {meta?.total ?? products.length} produk untuk{' '}
                      <Text className="text-[12px] font-bold text-foreground">
                        {'“'}
                        {query}
                        {'”'}
                      </Text>
                    </>
                  }
                />
                {error ? (
                  <ErrorBanner
                    message={error}
                    onRetry={offline ? undefined : () => void onRefresh()}
                  />
                ) : null}
              </View>
            }
            ListFooterComponent={
              <LoadMore
                hasMore={hasMorePages(meta)}
                loading={loadingMore}
                error={loadError ? 'Gagal memuat produk berikutnya' : null}
                onRetry={() => void loadMore(true)}
                count={products.length}
                showEnd
                endLabel="Kamu sudah melihat semua produk"
              />
            }
            onEndReached={() => void loadMore()}
          />
        </View>
        {loading ? (
          <View
            pointerEvents="none"
            accessibilityLiveRegion="polite"
            className="absolute inset-x-0 top-0 z-10 h-9 flex-row items-center justify-center gap-2 bg-white/90">
            <Spinner
              size="small"
              accessibilityElementsHidden
              importantForAccessibility="no-hide-descendants"
            />
            <Text className="shrink text-[12px] text-muted-foreground" numberOfLines={1}>
              Mencari {'“'}
              {query}
              {'”'}…
            </Text>
          </View>
        ) : null}
      </View>
    );
  }

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <TabHeader>
        <View className="h-10 flex-row items-center rounded-full bg-white pl-3 pr-0.5">
          <Icon as={SearchIcon} size={16} color={COLORS.slate} />
          <TextInput
            ref={inputRef}
            value={q}
            onChangeText={setQ}
            placeholder="Cari di OceanMall"
            placeholderTextColor={PLACEHOLDER_COLOR}
            selectionColor={COLORS.navy}
            cursorColor={COLORS.navy}
            accessibilityLabel="Cari produk"
            maxFontSizeMultiplier={1.3}
            autoCapitalize="none"
            autoCorrect={false}
            returnKeyType="search"
            onSubmitEditing={submit}
            className="ml-2 h-full flex-1 py-0 text-[15px] text-foreground"
          />
          {q.length > 0 ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Hapus kata kunci"
              hitSlop={{ top: 2, bottom: 2, left: 4 }}
              onPress={() => {
                setQ('');
                inputRef.current?.focus();
              }}
              className="h-10 w-10 items-center justify-center active:opacity-60">
              <Icon as={X} size={16} color={COLORS.slate} />
            </Pressable>
          ) : null}
        </View>
      </TabHeader>

      <View className="flex-1 bg-background">{renderBody()}</View>
    </SafeAreaView>
  );
}
