import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { HomeHeader } from '@/components/home-header';
import { ProductGrid } from '@/components/product-grid';
import { ProductImage } from '@/components/product-image';
import { ProductRail, SectionHeader } from '@/components/product-rail';
import { ProductGridSkeleton } from '@/components/skeletons';
import { Icon } from '@/components/ui/icon';
import { PressableScale } from '@/components/ui/pressable-scale';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { useReducedMotion } from '@/lib/a11y';
import {
  errorMessage,
  fetchHome,
  type Brand,
  type Category,
  type Collection,
  type Product,
} from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { discountPercent, mediaUrl } from '@/lib/format';
import { useNetwork } from '@/lib/network';
import { getSecureItem, setSecureItem } from '@/lib/storage';
import { COLORS } from '@/lib/theme';
import { useRefresh } from '@/lib/use-refresh';
import { cn } from '@/lib/utils';
import { Link, useFocusEffect, useRouter, useScrollToTop } from 'expo-router';
import { CircleAlert, Grid3x3, Store, UserRound, WifiOff, X } from 'lucide-react-native';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AccessibilityInfo,
  NativeScrollEvent,
  NativeSyntheticEvent,
  Pressable,
  ScrollView,
  StyleSheet,
  useWindowDimensions,
  View,
} from 'react-native';
import { SafeAreaView } from '@/components/safe-area-view';

const BANNER_GAP = 10;
const BANNER_PAD = 12;
const BANNER_HEIGHT = 156;
const BANNER_INTERVAL_MS = 4000;
/** After the user touches the banner, autoplay waits at least this long before moving it. */
const BANNER_IDLE_MS = 2500;
const MAX_CATEGORY_TILES = 8;
const NUDGE_KEY = 'oceanmall.home-nudge-dismissed';

function initials(name: string): string {
  return name.slice(0, 2).toUpperCase();
}

/** True while VoiceOver or TalkBack is running: a self-moving banner is a trap for them. */
function useScreenReader(): boolean {
  const [enabled, setEnabled] = useState(false);

  useEffect(() => {
    let active = true;
    AccessibilityInfo.isScreenReaderEnabled()
      .then((value) => {
        if (active) {
          setEnabled(value);
        }
      })
      .catch(() => undefined);
    const subscription = AccessibilityInfo.addEventListener('screenReaderChanged', setEnabled);

    return () => {
      active = false;
      subscription.remove();
    };
  }, []);

  return enabled;
}

/** Placeholder with the loaded page's silhouette: banner, category grid and a product grid. */
function HomeSkeleton() {
  return (
    <View accessibilityLiveRegion="polite" className="flex-1 overflow-hidden bg-om-surface">
      <Text className="absolute h-px w-px opacity-0">Memuat…</Text>
      <Skeleton className="mx-3 mt-3 rounded-xl" style={{ height: BANNER_HEIGHT }} />
      <View className="mx-3 mt-3 flex-row flex-wrap rounded-lg bg-white p-3">
        {Array.from({ length: MAX_CATEGORY_TILES }, (_, index) => (
          <View key={index} className="w-1/4 items-center py-2">
            <Skeleton className="size-[52px] rounded-xl" />
            <View className="mt-1.5 h-8 items-center">
              <Skeleton className="h-3 w-12" />
            </View>
          </View>
        ))}
      </View>
      <View className="mt-3 px-3">
        <ProductGridSkeleton count={4} />
      </View>
    </View>
  );
}

/** "Masuk untuk ..." strip for signed-out visitors. Dismissing it is remembered. */
function GuestStrip({ onDismiss }: { onDismiss: () => void }) {
  const router = useRouter();

  return (
    <View className="min-h-11 flex-row items-center gap-2 bg-om-navy-soft pl-4 pr-1">
      <Icon as={UserRound} size={16} color={COLORS.navy} />
      <Text className="flex-1 py-1.5 text-[12px] leading-4">
        Masuk untuk lacak pesanan & checkout lebih cepat
      </Text>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Masuk"
        hitSlop={{ top: 4, bottom: 4 }}
        onPress={() => router.push('/login')}
        className="min-h-11 justify-center px-2 active:opacity-70">
        <Text className="text-[12px] font-bold text-om-navy">Masuk</Text>
      </Pressable>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Tutup"
        onPress={onDismiss}
        className="size-11 items-center justify-center active:opacity-70">
        <Icon as={X} size={14} color={COLORS.slate} />
      </Pressable>
    </View>
  );
}

/**
 * Swipeable collection banners with dots on the picture. It advances itself every four seconds
 * while Beranda is on screen, loops back to the first, waits while the user is touching it, and
 * stands still under Reduce Motion or a screen reader. It owns the current-page state so the
 * whole screen does not re-render on every swipe.
 */
function BannerCarousel({ collections }: { collections: Collection[] }) {
  const { width } = useWindowDimensions();
  const reduceMotion = useReducedMotion();
  const screenReader = useScreenReader();
  const bannerWidth = width - BANNER_PAD * 2;
  const step = bannerWidth + BANNER_GAP;
  const count = collections.length;

  const scrollRef = useRef<ScrollView>(null);
  const indexRef = useRef(0);
  const [index, setIndex] = useState(0);
  const dragging = useRef(false);
  const lastTouch = useRef(0);

  const autoplay = count > 1 && !reduceMotion && !screenReader;

  // Only while focused: a Beranda tab sitting behind another screen keeps no timer alive.
  useFocusEffect(
    useCallback(() => {
      if (!autoplay) {
        return;
      }
      const id = setInterval(() => {
        if (dragging.current || Date.now() - lastTouch.current < BANNER_IDLE_MS) {
          return;
        }
        const next = (indexRef.current + 1) % count;
        scrollRef.current?.scrollTo({ x: next * step, animated: true });
      }, BANNER_INTERVAL_MS);

      return () => clearInterval(id);
    }, [autoplay, count, step])
  );

  function onScroll(event: NativeSyntheticEvent<NativeScrollEvent>) {
    // Follows the offset (not only the momentum-end event) so the dots stay right for
    // programmatic scrolls too.
    const next = Math.max(
      0,
      Math.min(count - 1, Math.round(event.nativeEvent.contentOffset.x / step))
    );
    if (next !== indexRef.current) {
      indexRef.current = next;
      setIndex(next);
    }
  }

  return (
    <View style={{ height: BANNER_HEIGHT }} className="mt-3">
      <ScrollView
        ref={scrollRef}
        horizontal
        nestedScrollEnabled
        scrollsToTop={false}
        decelerationRate="fast"
        snapToInterval={step}
        snapToAlignment="start"
        disableIntervalMomentum
        showsHorizontalScrollIndicator={false}
        scrollEventThrottle={32}
        onScroll={onScroll}
        onScrollBeginDrag={() => {
          dragging.current = true;
          lastTouch.current = Date.now();
        }}
        onScrollEndDrag={() => {
          dragging.current = false;
          lastTouch.current = Date.now();
        }}
        onMomentumScrollEnd={() => {
          lastTouch.current = Date.now();
        }}
        style={{ flex: 1 }}
        contentContainerStyle={{ paddingHorizontal: BANNER_PAD, gap: BANNER_GAP }}>
        {collections.map((collection, position) => (
          <Link key={collection.id} href={`/collection/${collection.slug}`} asChild>
            <PressableScale
              accessibilityRole="link"
              accessibilityLabel={`${collection.name}${
                collection.products_count ? `, ${collection.products_count} produk` : ''
              }, banner ${position + 1} dari ${count}`}
              style={{ width: bannerWidth, height: BANNER_HEIGHT }}
              className="overflow-hidden rounded-xl bg-om-navy">
              <ProductImage
                uri={mediaUrl(collection.thumbnail)}
                plain
                priority={position === 0 ? 'high' : undefined}
                fallback={<View />}
                style={StyleSheet.absoluteFill}
              />
              {/* Extra bottom padding keeps the title clear of the dots. */}
              <View className="absolute inset-x-0 bottom-0 bg-black/40 px-4 pb-6 pt-2.5">
                <Text className="text-[17px] font-extrabold text-white" numberOfLines={1}>
                  {collection.name}
                </Text>
                {collection.products_count ? (
                  <Text className="text-[12px] text-white/90">
                    {collection.products_count} produk
                  </Text>
                ) : null}
              </View>
            </PressableScale>
          </Link>
        ))}
      </ScrollView>
      {count > 1 ? (
        <View
          pointerEvents="none"
          accessibilityElementsHidden
          importantForAccessibility="no-hide-descendants"
          className="absolute inset-x-0 bottom-2 flex-row justify-center gap-1.5">
          {collections.map((collection, position) => (
            <View
              key={collection.id}
              className={cn(
                'h-1.5 rounded-full',
                position === index ? 'w-4 bg-white' : 'w-1.5 bg-white/50'
              )}
            />
          ))}
        </View>
      ) : null}
    </View>
  );
}

/** Four-across icon grid. Past eight categories the last tile becomes "Lainnya". */
function CategoryGrid({ categories }: { categories: Category[] }) {
  const overflow = categories.length > MAX_CATEGORY_TILES;
  const shown = overflow ? categories.slice(0, MAX_CATEGORY_TILES - 1) : categories;

  return (
    <View className="mx-3 mt-3 flex-row flex-wrap rounded-lg bg-white p-3">
      {shown.map((category) => (
        <Link key={category.id} href={`/category/${category.slug}`} asChild>
          <PressableScale accessibilityRole="link" className="w-1/4 items-center py-2">
            <View className="size-[52px] items-center justify-center overflow-hidden rounded-xl bg-om-navy-soft">
              {category.thumbnail ? (
                <ProductImage
                  uri={mediaUrl(category.thumbnail)}
                  plain
                  width={32}
                  height={32}
                  contentFit="contain"
                  fallback={
                    <Text className="text-[14px] font-bold text-om-navy">
                      {initials(category.name)}
                    </Text>
                  }
                />
              ) : (
                <Text className="text-[14px] font-bold text-om-navy">
                  {initials(category.name)}
                </Text>
              )}
            </View>
            <Text
              className="mt-1.5 px-0.5 text-center text-[12px] font-medium leading-4"
              numberOfLines={2}
              style={{ minHeight: 32 }}>
              {category.name}
            </Text>
          </PressableScale>
        </Link>
      ))}
      {overflow ? (
        <Link href="/(tabs)/search" asChild>
          <PressableScale accessibilityRole="link" className="w-1/4 items-center py-2">
            <View className="size-[52px] items-center justify-center rounded-xl bg-om-navy-soft">
              <Icon as={Grid3x3} size={24} color={COLORS.navy} />
            </View>
            <Text
              className="mt-1.5 px-0.5 text-center text-[12px] font-medium leading-4"
              numberOfLines={2}
              style={{ minHeight: 32 }}>
              Lainnya
            </Text>
          </PressableScale>
        </Link>
      ) : null}
    </View>
  );
}

function BrandRail({ brands }: { brands: Brand[] }) {
  return (
    <View className="mt-3 bg-white pb-4 pt-4">
      <SectionHeader title="Brand pilihan" />
      <ScrollView
        horizontal
        nestedScrollEnabled
        scrollsToTop={false}
        showsHorizontalScrollIndicator={false}
        contentContainerClassName="gap-3 px-4">
        {brands.map((brand) => (
          <Link
            key={brand.id}
            href={{ pathname: '/shop', params: { brand: String(brand.id), title: brand.name } }}
            asChild>
            <PressableScale accessibilityRole="link" className="w-[72px] items-center">
              <View className="mb-1.5 size-12 items-center justify-center overflow-hidden rounded-full border border-border bg-card">
                {brand.thumbnail ? (
                  <ProductImage
                    uri={mediaUrl(brand.thumbnail)}
                    plain
                    width={36}
                    height={36}
                    contentFit="contain"
                    fallback={
                      <Text className="text-[12px] font-bold text-om-navy">
                        {initials(brand.name)}
                      </Text>
                    }
                  />
                ) : (
                  <Text className="text-[12px] font-bold text-om-navy">{initials(brand.name)}</Text>
                )}
              </View>
              <Text className="text-center text-[11px] text-muted-foreground" numberOfLines={1}>
                {brand.name}
              </Text>
            </PressableScale>
          </Link>
        ))}
      </ScrollView>
    </View>
  );
}

export default function HomeScreen() {
  const { user } = useAuth();
  const { offline } = useNetwork();
  const scrollRef = useRef<ScrollView>(null);
  const [featured, setFeatured] = useState<Product[]>([]);
  const [promo, setPromo] = useState<Product[]>([]);
  const [collections, setCollections] = useState<Collection[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [brands, setBrands] = useState<Brand[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // null until the stored choice has been read, so the strip never flashes before it is hidden.
  const [nudgeDismissed, setNudgeDismissed] = useState<boolean | null>(null);

  const load = useCallback(async () => {
    try {
      const home = await fetchHome();
      setFeatured(home.featured_products ?? []);
      setPromo(home.promo_products ?? []);
      setCollections(home.featured_collections ?? home.collections ?? []);
      setCategories(home.categories ?? []);
      setBrands(home.brands ?? []);
      setError(null);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat katalog'));
    } finally {
      setLoading(false);
    }
  }, []);

  const { refreshing, onRefresh } = useRefresh(load);

  const retry = useCallback(() => {
    setLoading(true);
    void load();
  }, [load]);

  useScrollToTop(scrollRef);

  useEffect(() => {
    void load();
  }, [load]);

  const wasOffline = useRef(offline);
  useEffect(() => {
    const recovered = wasOffline.current && !offline;
    wasOffline.current = offline;
    if (recovered) {
      void load();
    }
  }, [offline, load]);

  useEffect(() => {
    let active = true;
    getSecureItem(NUDGE_KEY)
      .then((value) => {
        if (active) {
          setNudgeDismissed(value === '1');
        }
      })
      .catch(() => {
        if (active) {
          setNudgeDismissed(false);
        }
      });

    return () => {
      active = false;
    };
  }, []);

  function dismissNudge() {
    setNudgeDismissed(true);
    void setSecureItem(NUDGE_KEY, '1').catch(() => undefined);
  }

  // The search pill rotates through hints taken from the real categories.
  const hints = useMemo(
    () => [
      'Cari di OceanMall',
      ...categories.slice(0, 5).map((category) => `Cari ${category.name.toLowerCase()}`),
    ],
    [categories]
  );

  const empty =
    featured.length === 0 &&
    promo.length === 0 &&
    collections.length === 0 &&
    categories.length === 0 &&
    brands.length === 0;
  const showNudge = !user && nudgeDismissed === false;

  // maxOff is the best discount among today's promo products (the API has no promo end time, so
  // there is deliberately no countdown).
  const maxOff = promo.reduce(
    (best, product) => Math.max(best, discountPercent(product.price, product.compare_price) ?? 0),
    0
  );

  function renderBody() {
    if (loading) {
      return <HomeSkeleton />;
    }

    return (
      <ScrollView
        ref={scrollRef}
        className="flex-1 bg-om-surface"
        contentContainerClassName={empty ? 'grow' : 'pb-6'}
        refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
        {showNudge ? <GuestStrip onDismiss={dismissNudge} /> : null}

        {empty && error ? (
          <EmptyState
            tone="error"
            icon={offline ? WifiOff : CircleAlert}
            title="Yah, ada yang salah"
            message={error}
            actionLabel="Coba lagi"
            onAction={retry}
          />
        ) : empty ? (
          <EmptyState
            icon={Store}
            title="Katalog sedang disiapkan"
            message="Produk akan segera tampil di sini. Tarik ke bawah untuk memuat ulang."
            actionLabel="Muat ulang"
            onAction={retry}
          />
        ) : (
          <>
            {error && !offline ? <ErrorBanner message={error} onRetry={() => void load()} /> : null}

            {collections.length > 0 ? <BannerCarousel collections={collections} /> : null}

            {categories.length > 0 ? <CategoryGrid categories={categories} /> : null}

            {promo.length > 0 ? (
              <View className="mt-3 bg-white pt-4">
                <ProductRail
                  title="Promo hari ini"
                  badge={maxOff > 0 ? `Hemat s.d. ${maxOff}%` : undefined}
                  href="/promo"
                  products={promo}
                />
              </View>
            ) : null}

            {brands.length > 0 ? <BrandRail brands={brands} /> : null}

            {featured.length > 0 ? (
              <View className="mt-3 pt-3">
                <SectionHeader title="Rekomendasi untukmu" href="/featured" />
                <View className="px-3">
                  <ProductGrid products={featured} emptyMessage="" />
                </View>
              </View>
            ) : null}
          </>
        )}
      </ScrollView>
    );
  }

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      {/* The header is the same 56px band in every state, so nothing shifts when data arrives. */}
      <HomeHeader hints={hints} />
      {renderBody()}
    </SafeAreaView>
  );
}
