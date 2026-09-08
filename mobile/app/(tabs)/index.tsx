import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { Icon } from '@/components/ui/icon';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { errorMessage, fetchHome, type Brand, type Category, type Collection, type Product } from '@/lib/api';
import { useCart } from '@/lib/cart';
import { mediaUrl } from '@/lib/format';
import { useNetwork } from '@/lib/network';
import { setPendingSearchQuery } from '@/lib/search-query';
import { Image } from 'expo-image';
import { Link, useRouter } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { CircleAlert, SearchIcon, ShoppingBagIcon, WifiOff } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
  NativeScrollEvent,
  NativeSyntheticEvent,
  Pressable,
  RefreshControl,
  ScrollView,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export default function HomeScreen() {
  const router = useRouter();
  const { itemCount } = useCart();
  const { offline } = useNetwork();
  const { width } = useWindowDimensions();
  const [search, setSearch] = useState('');
  const bannerGap = 10;
  const bannerPad = 12;
  const bannerWidth = width - bannerPad * 2;
  const [bannerIndex, setBannerIndex] = useState(0);
  const [featured, setFeatured] = useState<Product[]>([]);
  const [promo, setPromo] = useState<Product[]>([]);
  const [collections, setCollections] = useState<Collection[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [brands, setBrands] = useState<Brand[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
      setRefreshing(false);
    }
  }, []);

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

  function goSearch(value?: string) {
    const q = (value ?? search).trim();
    setSearch(q);
    setPendingSearchQuery(q);
    router.push({ pathname: '/(tabs)/search', params: q ? { q } : {} });
  }

  function onBannerScroll(event: NativeSyntheticEvent<NativeScrollEvent>) {
    const x = event.nativeEvent.contentOffset.x;
    const next = Math.round(x / (bannerWidth + bannerGap));
    if (next !== bannerIndex) {
      setBannerIndex(next);
    }
  }

  if (loading) {
    return (
      <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
        <StatusBar style="light" />
        <View className="h-[52px] bg-om-navy px-3" />
        <View className="flex-1 bg-background px-3 pt-4">
          <View className="flex-row gap-3">
            <Skeleton className="size-11 rounded-full" />
            <Skeleton className="size-11 rounded-full" />
            <Skeleton className="size-11 rounded-full" />
            <Skeleton className="size-11 rounded-full" />
          </View>
          <Skeleton className="mt-4 h-[156px] rounded-2xl" />
          <View className="mt-5 flex-row justify-between">
            <Skeleton className="h-[210px] w-[48.5%] rounded-lg" />
            <Skeleton className="h-[210px] w-[48.5%] rounded-lg" />
          </View>
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <StatusBar style="light" />
      <View className="flex-row items-center gap-2 bg-om-navy px-3 pb-3 pt-1">
        <View className="h-10 flex-1 flex-row items-center rounded-full bg-white px-3">
          <Pressable onPress={() => goSearch()} hitSlop={8}>
            <Icon as={SearchIcon} size={16} color="#64748b" />
          </Pressable>
          <TextInput
            value={search}
            onChangeText={setSearch}
            placeholder="Cari produk..."
            placeholderTextColor="#94a3b8"
            autoCapitalize="none"
            autoCorrect={false}
            returnKeyType="search"
            onSubmitEditing={(event) => goSearch(event.nativeEvent.text)}
            className="ml-2 flex-1 text-[15px] text-foreground"
          />
        </View>
        <Pressable
          onPress={() => router.push('/(tabs)/cart')}
          className="h-10 w-10 items-center justify-center rounded-full bg-white/10"
          hitSlop={8}>
          <Icon as={ShoppingBagIcon} size={20} color="#fff" />
          {itemCount > 0 ? (
            <View className="absolute right-0 top-0 min-w-[16px] items-center rounded-full bg-om-sale px-1">
              <Text className="text-[10px] font-extrabold text-white">
                {itemCount > 99 ? '99+' : itemCount}
              </Text>
            </View>
          ) : null}
        </Pressable>
      </View>

      {error &&
      featured.length === 0 &&
      promo.length === 0 &&
      collections.length === 0 &&
      categories.length === 0 &&
      brands.length === 0 ? (
        <EmptyState
          icon={offline ? WifiOff : CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      ) : (
      <ScrollView
        className="flex-1 bg-background"
        contentContainerClassName="pb-8"
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => {
              setRefreshing(true);
              void load();
            }}
          />
        }>
        {error ? <Text className="px-4 pt-3 text-destructive">{error}</Text> : null}

        {categories.length > 0 ? (
          <ScrollView
            horizontal
            nestedScrollEnabled
            showsHorizontalScrollIndicator={false}
            className="mt-4"
            contentContainerClassName="gap-3 px-4">
            {categories.map((cat) => (
              <Link key={cat.id} href={`/category/${cat.slug}`} asChild>
                <Pressable className="w-[72px] items-center">
                  <View className="mb-1.5 size-11 items-center justify-center overflow-hidden rounded-full bg-muted">
                    {cat.thumbnail ? (
                      <Image
                        source={{ uri: mediaUrl(cat.thumbnail) }}
                        style={{ width: 44, height: 44 }}
                        contentFit="cover"
                      />
                    ) : (
                      <Text className="text-xs font-bold text-om-navy">{cat.name.slice(0, 2).toUpperCase()}</Text>
                    )}
                  </View>
                  <Text className="text-center text-[11px] font-medium leading-tight" numberOfLines={2}>
                    {cat.name}
                  </Text>
                </Pressable>
              </Link>
            ))}
          </ScrollView>
        ) : null}

        {collections.length > 0 ? (
          <View style={{ height: 156, marginTop: 16 }}>
            <ScrollView
              horizontal
              nestedScrollEnabled
              decelerationRate="fast"
              snapToInterval={bannerWidth + bannerGap}
              snapToAlignment="start"
              disableIntervalMomentum
              showsHorizontalScrollIndicator={false}
              onMomentumScrollEnd={onBannerScroll}
              style={{ flex: 1 }}
              contentContainerStyle={{ paddingHorizontal: bannerPad, gap: bannerGap }}>
              {collections.map((collection) => (
                <Link key={collection.id} href={`/collection/${collection.slug}`} asChild>
                  <Pressable
                    style={{ width: bannerWidth, height: 156 }}
                    className="overflow-hidden rounded-2xl bg-om-navy">
                    <Image
                      source={{ uri: mediaUrl(collection.thumbnail) }}
                      style={{ width: bannerWidth, height: 156, backgroundColor: '#0a2a6b' }}
                      contentFit="cover"
                    />
                    <View className="absolute inset-x-0 bottom-0 bg-black/40 px-4 py-3">
                      <Text className="text-[17px] font-extrabold text-white" numberOfLines={1}>
                        {collection.name}
                      </Text>
                      {collection.products_count ? (
                        <Text className="text-[12px] text-white/90">{collection.products_count} produk</Text>
                      ) : null}
                    </View>
                  </Pressable>
                </Link>
              ))}
            </ScrollView>
          </View>
        ) : null}
        {collections.length > 1 ? (
          <View className="mt-2 flex-row items-center justify-center gap-1.5">
            {collections.map((collection, index) => (
              <View
                key={collection.id}
                className={`h-1.5 rounded-full ${index === bannerIndex ? 'w-4 bg-om-navy' : 'w-1.5 bg-om-navy/25'}`}
              />
            ))}
          </View>
        ) : null}

        {brands.length > 0 ? (
          <ScrollView
            horizontal
            nestedScrollEnabled
            showsHorizontalScrollIndicator={false}
            className="mt-4"
            contentContainerClassName="gap-3 px-4">
            {brands.map((brand) => (
              <Link
                key={brand.id}
                href={{ pathname: '/shop', params: { brand: String(brand.id), title: brand.name } }}
                asChild>
                <Pressable className="w-[68px] items-center">
                  <View className="mb-1 size-12 items-center justify-center overflow-hidden rounded-full border border-border bg-card p-1.5">
                    {brand.thumbnail ? (
                      <Image
                        source={{ uri: mediaUrl(brand.thumbnail) }}
                        style={{ width: 36, height: 36 }}
                        contentFit="contain"
                      />
                    ) : (
                      <Text className="text-[11px] font-bold text-om-navy">
                        {brand.name.slice(0, 2).toUpperCase()}
                      </Text>
                    )}
                  </View>
                  <Text className="text-center text-[10px] text-muted-foreground" numberOfLines={1}>
                    {brand.name}
                  </Text>
                </Pressable>
              </Link>
            ))}
          </ScrollView>
        ) : null}

        {promo.length > 0 ? (
          <View className="mt-5 px-3">
            <View className="mb-3 flex-row items-center justify-between px-1">
              <Text className="text-[15px] font-bold text-om-navy">Promo hari ini</Text>
              <Link href="/promo" asChild>
                <Pressable hitSlop={8}>
                  <Text className="text-xs font-semibold text-om-navy">Lihat semua</Text>
                </Pressable>
              </Link>
            </View>
            <ProductGrid products={promo} />
          </View>
        ) : null}

        {featured.length > 0 ? (
          <View className="mt-5 px-3">
            <View className="mb-3 flex-row items-center justify-between px-1">
              <Text className="text-[15px] font-bold text-om-navy">Produk unggulan</Text>
              <Link href="/featured" asChild>
                <Pressable hitSlop={8}>
                  <Text className="text-xs font-semibold text-om-navy">Lihat semua</Text>
                </Pressable>
              </Link>
            </View>
            <ProductGrid products={featured} />
          </View>
        ) : null}
      </ScrollView>
      )}
    </SafeAreaView>
  );
}
