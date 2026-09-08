import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { SalePrice } from '@/components/sale-price';
import { Screen } from '@/components/screen';
import { Stars } from '@/components/stars';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { api, errorMessage, type Cart, type Product } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { formatDate, mediaUrl, stripHtml } from '@/lib/format';
import { Image } from 'expo-image';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { CircleAlert, Minus, Plus, ShoppingBag } from 'lucide-react-native';

import { useEffect, useMemo, useState } from 'react';
import {
  NativeScrollEvent,
  NativeSyntheticEvent,
  ScrollView,
  useWindowDimensions,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export default function ProductScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const { applyCart } = useCart();
  const { user } = useAuth();
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const [product, setProduct] = useState<Product | null>(null);
  const [variantId, setVariantId] = useState<number | null>(null);
  const [qty, setQty] = useState(1);
  const [imageIndex, setImageIndex] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<'cart' | 'buy' | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!slug) {
      return;
    }
    setLoading(true);
    api<{ data: Product }>(`/catalog/products/${slug}`)
      .then((res) => {
        setProduct(res.data);
        setVariantId(res.data.variants?.[0]?.id ?? null);
        setImageIndex(0);
      })
      .catch((e) => setError(errorMessage(e, 'Produk tidak ditemukan')))
      .finally(() => setLoading(false));
  }, [slug]);

  const images = useMemo(() => {
    if (!product) {
      return [];
    }
    const urls = [product.thumbnail, ...(product.images ?? []).map((image) => image.url)]
      .map((url) => mediaUrl(url))
      .filter((url): url is string => Boolean(url));
    return [...new Set(urls)];
  }, [product]);

  async function addToCart(next: 'stay' | 'checkout') {
    if (!product) {
      return;
    }
    setBusy(next === 'checkout' ? 'buy' : 'cart');
    setError(null);
    try {
      const res = await api<{ data: Cart }>('/cart/items', {
        method: 'POST',
        body: JSON.stringify({
          product_id: product.id,
          ...(variantId ? { variant_id: variantId } : {}),
          quantity: qty,
        }),
      });
      applyCart(res.data);
      if (next === 'checkout') {
        if (!user) {
          router.push({ pathname: '/login', params: { redirect: '/checkout' } });
          return;
        }
        router.push('/checkout');
      }
    } catch (e) {
      setError(errorMessage(e, 'Gagal menambah ke keranjang'));
    } finally {
      setBusy(null);
    }
  }

  function onGalleryScroll(event: NativeSyntheticEvent<NativeScrollEvent>) {
    const next = Math.round(event.nativeEvent.contentOffset.x / width);
    if (next !== imageIndex) {
      setImageIndex(next);
    }
  }

  if (loading) {
    return (
      <Screen title="Produk" showCart>
        <Skeleton className="w-full rounded-none" style={{ height: width }} />
        <View className="gap-2 px-3 pt-3">
          <Skeleton className="h-6 w-36" />
          <Skeleton className="h-5 w-full" />
          <Skeleton className="h-4 w-40" />
        </View>
      </Screen>
    );
  }

  if (!product) {
    return (
      <Screen title="Produk" showCart>
        <EmptyState icon={CircleAlert} message={error ?? 'Produk tidak ditemukan'} />
      </Screen>
    );
  }

  const selectedVariant = product.variants?.find((variant) => variant.id === variantId);
  const price = selectedVariant?.price ?? product.price;
  const reviews = product.reviews;

  return (
    <Screen title={product.name} showCart>
      <View className="flex-1">
        <ScrollView className="flex-1" contentContainerClassName="pb-4">
          <View style={{ height: width, backgroundColor: '#f4f4f5' }}>
            {images.length > 0 ? (
              <ScrollView
                horizontal
                pagingEnabled
                nestedScrollEnabled
                showsHorizontalScrollIndicator={false}
                onMomentumScrollEnd={onGalleryScroll}
                style={{ flex: 1 }}>
                {images.map((uri) => (
                  <Image
                    key={uri}
                    source={{ uri }}
                    style={{ width, height: width, backgroundColor: '#f4f4f5' }}
                    contentFit="contain"
                  />
                ))}
              </ScrollView>
            ) : (
              <View className="flex-1 items-center justify-center">
                <Text className="text-[13px] text-muted-foreground">Tidak ada foto</Text>
              </View>
            )}
          </View>
          {images.length > 1 ? (
            <View className="mt-2 flex-row items-center justify-center gap-1.5">
              {images.map((uri, index) => (
                <View
                  key={uri}
                  className={`h-1.5 rounded-full ${index === imageIndex ? 'w-4 bg-om-navy' : 'w-1.5 bg-om-navy/25'}`}
                />
              ))}
            </View>
          ) : null}

          <View className="px-3 pt-3">
            <SalePrice price={price} compare={product.compare_price} size="detail" showPercent />

            <Text className="mt-2 text-[15px] font-bold leading-5">{product.name}</Text>
            <Text className="mt-0.5 text-[12px] text-muted-foreground">
              {product.brand?.name ?? 'OceanMall'}
            </Text>
            {reviews && reviews.count > 0 ? (
              <View className="mt-1.5 flex-row items-center gap-1.5">
                <Stars value={reviews.average} size={12} />
                <Text variant="muted" className="text-[12px]">
                  {reviews.average.toFixed(1)} · {reviews.count} ulasan
                </Text>
              </View>
            ) : null}

            {(product.variants?.length ?? 0) > 0 ? (
              <View className="mt-4">
                <Text className="mb-2 text-[13px] font-bold text-om-navy">Varian</Text>
                <ToggleGroup
                  type="single"
                  variant="outline"
                  value={variantId ? String(variantId) : ''}
                  onValueChange={(value) => {
                    if (value) {
                      setVariantId(Number(value));
                    }
                  }}
                  className="flex-wrap gap-2">
                  {product.variants?.map((variant, index, list) => (
                    <ToggleGroupItem
                      key={variant.id}
                      value={String(variant.id)}
                      isFirst={index === 0}
                      isLast={index === list.length - 1}
                      className="rounded-full border px-3">
                      <Text className="text-[12px]">{variant.name ?? variant.sku ?? `#${variant.id}`}</Text>
                    </ToggleGroupItem>
                  ))}
                </ToggleGroup>
              </View>
            ) : null}

            {product.description ? (
              <Text variant="muted" className="mt-4 text-[13px] leading-5">
                {stripHtml(product.description)}
              </Text>
            ) : null}

            {reviews && reviews.count > 0 ? (
              <View className="mt-5">
                <Text className="text-[13px] font-bold text-om-navy">Ulasan pembeli</Text>
                <Separator className="mt-3" />
                {reviews.items.slice(0, 3).map((review) => {
                  const name = review.author_name ?? 'Pembeli';
                  const initials = name
                    .trim()
                    .split(/\s+/)
                    .slice(0, 2)
                    .map((part) => part[0])
                    .join('')
                    .toUpperCase();
                  return (
                    <View key={review.id} className="flex-row gap-2.5 border-b border-border py-3">
                      <Avatar alt={name} className="size-8">
                        <AvatarFallback className="bg-muted">
                          <Text className="text-[10px] font-bold">{initials || 'P'}</Text>
                        </AvatarFallback>
                      </Avatar>
                      <View className="min-w-0 flex-1">
                        <View className="flex-row items-center justify-between gap-2">
                          <Text className="flex-1 text-[13px] font-semibold" numberOfLines={1}>
                            {name}
                          </Text>
                          {review.created_at ? (
                            <Text variant="muted" className="text-[11px]">
                              {formatDate(review.created_at)}
                            </Text>
                          ) : null}
                        </View>
                        <View className="mt-1">
                          <Stars value={review.rating} size={11} />
                        </View>
                        {review.title ? (
                          <Text className="mt-1 text-[13px]">{review.title}</Text>
                        ) : null}
                        {review.content ? (
                          <Text variant="muted" className="mt-0.5 text-[13px] leading-5">
                            {review.content}
                          </Text>
                        ) : null}
                      </View>
                    </View>
                  );
                })}
              </View>
            ) : null}

            {(product.related_products?.length ?? 0) > 0 ? (
              <View className="mt-5">
                <Text className="mb-2 text-[13px] font-bold text-om-navy">Produk terkait</Text>
                <ProductGrid products={product.related_products ?? []} emptyMessage="" />
              </View>
            ) : null}

            {error ? (
              <Alert icon={CircleAlert} variant="destructive" className="mt-3">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            ) : null}
          </View>
        </ScrollView>

        <View
          className="flex-row items-center gap-2 border-t border-border bg-white px-3 pt-2.5"
          style={{ paddingBottom: Math.max(insets.bottom, 12) }}>
          <View className="h-11 flex-row items-center rounded-full border border-border">
            <Button
              variant="ghost"
              size="icon"
              className="h-11 w-10"
              onPress={() => setQty((value) => Math.max(1, value - 1))}>
              <Icon as={Minus} size={15} />
            </Button>
            <Text className="min-w-[22px] text-center text-[14px] font-semibold">{qty}</Text>
            <Button
              variant="ghost"
              size="icon"
              className="h-11 w-10"
              onPress={() => setQty((value) => Math.min(10, value + 1))}>
              <Icon as={Plus} size={15} />
            </Button>
          </View>
          <Button
            variant="outline"
            disabled={busy !== null}
            onPress={() => void addToCart('stay')}
            className="h-11 flex-1">
            <Icon as={ShoppingBag} size={16} />
            <Text>{busy === 'cart' ? 'Menambah...' : 'Keranjang'}</Text>
          </Button>
          <Button
            disabled={busy !== null}
            onPress={() => void addToCart('checkout')}
            className="h-11 flex-1">
            <Text>{busy === 'buy' ? 'Memproses...' : 'Beli'}</Text>
          </Button>
        </View>
      </View>
    </Screen>
  );
}
