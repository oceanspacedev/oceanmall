import { EmptyState } from '@/components/empty-state';
import { ProductGrid } from '@/components/product-grid';
import { Screen } from '@/components/screen';
import { QtyStepper, Section, StickyBar } from '@/components/shop';
import { Stars } from '@/components/stars';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type Cart, type Product } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { discountPercent, formatDate, formatIdr, mediaUrl, stripHtml } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Image } from 'expo-image';
import { useLocalSearchParams, useRouter } from 'expo-router';
import {
  CheckCircle2,
  CircleAlert,
  CreditCard,
  PackageCheck,
  ShoppingCart,
  Star,
  Truck,
} from 'lucide-react-native';
import { useEffect, useMemo, useState } from 'react';
import {
  NativeScrollEvent,
  NativeSyntheticEvent,
  Pressable,
  ScrollView,
  useWindowDimensions,
  View,
} from 'react-native';

/** Mirrors CustomerCart::MAX_LINE_QUANTITY on the API. */
const MAX_LINE_QUANTITY = 10;

export default function ProductScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const { applyCart } = useCart();
  const { user } = useAuth();
  const router = useRouter();
  const { width } = useWindowDimensions();
  const [product, setProduct] = useState<Product | null>(null);
  const [variantId, setVariantId] = useState<number | null>(null);
  const [qty, setQty] = useState(1);
  const [imageIndex, setImageIndex] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<'cart' | 'buy' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [expanded, setExpanded] = useState(false);

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
    setNotice(null);
    try {
      const res = await api<{ data: Cart }>('/cart/items', {
        method: 'POST',
        body: JSON.stringify({
          product_id: product.id,
          ...(variantId ? { variant_id: variantId } : {}),
          quantity: qty,
          // "Beli" buys exactly the chosen quantity instead of adding to the cart line.
          ...(next === 'checkout' ? { replace: true } : {}),
        }),
      });
      applyCart(res.data);
      if (next === 'stay') {
        setNotice(`${qty} barang ditambahkan ke keranjang.`);
      }
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
      <Screen title="Produk" showCart surface>
        <Skeleton className="w-full rounded-none" style={{ height: width }} />
        <View className="gap-2 bg-white px-4 py-4">
          <Skeleton className="h-7 w-40" />
          <Skeleton className="h-5 w-full" />
          <Skeleton className="h-4 w-32" />
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
  const compare = selectedVariant ? selectedVariant.compare_price : product.compare_price;
  const off = discountPercent(price, compare);
  const stock = selectedVariant ? (selectedVariant.stock ?? null) : (product.stock ?? null);
  const soldOut = stock !== null && stock < 1;
  const maxQty = Math.max(1, Math.min(MAX_LINE_QUANTITY, stock ?? MAX_LINE_QUANTITY));
  const reviews = product.reviews;
  const description = stripHtml(product.description);

  return (
    <Screen title={product.name} showCart surface>
      <View className="flex-1">
        <ScrollView className="flex-1" contentContainerClassName="pb-4">
          {/* Gallery */}
          <View style={{ height: width, backgroundColor: '#fff' }}>
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
                    style={{ width, height: width, backgroundColor: '#fff' }}
                    contentFit="contain"
                  />
                ))}
              </ScrollView>
            ) : (
              <View className="flex-1 items-center justify-center">
                <Text className="text-[13px] text-muted-foreground">Tidak ada foto</Text>
              </View>
            )}
            {images.length > 1 ? (
              <View className="absolute bottom-3 right-3 rounded-sm bg-black/50 px-2.5 py-1">
                <Text className="text-[11px] font-semibold text-white">
                  {imageIndex + 1}/{images.length}
                </Text>
              </View>
            ) : null}
          </View>

          {/* Price & title */}
          <View className="bg-white px-4 pb-4 pt-3">
            {off ? (
              <View className="-mx-4 -mt-3 mb-3 flex-row items-center justify-between bg-om-sale px-4 py-2">
                <Text className="text-[13px] font-extrabold text-white">Promo hemat {off}%</Text>
                <Text className="text-[12px] text-white/90">
                  Hemat {formatIdr((compare ?? 0) - (price ?? 0))}
                </Text>
              </View>
            ) : null}
            <Text
              className={cn('text-[24px] font-extrabold', off ? 'text-om-sale' : 'text-om-navy')}>
              {formatIdr(price)}
            </Text>
            {off ? (
              <View className="mt-0.5 flex-row items-center gap-2">
                <Text className="text-[13px] text-muted-foreground line-through">
                  {formatIdr(compare)}
                </Text>
                <View className="rounded bg-om-sale-soft px-1.5 py-0.5">
                  <Text className="text-[11px] font-extrabold text-om-sale">-{off}%</Text>
                </View>
              </View>
            ) : null}
            <Text className="mt-2 text-[16px] font-semibold leading-6">{product.name}</Text>
            <View className="mt-2 flex-row flex-wrap items-center gap-x-3 gap-y-1">
              {reviews && reviews.count > 0 ? (
                <View className="flex-row items-center gap-1">
                  <Icon as={Star} size={13} color="#f59e0b" fill="#f59e0b" />
                  <Text className="text-[12px] font-semibold">{reviews.average.toFixed(1)}</Text>
                  <Text className="text-[12px] text-muted-foreground">
                    ({reviews.count} ulasan)
                  </Text>
                </View>
              ) : null}
              <Text className="text-[12px] text-muted-foreground">
                Merek{' '}
                <Text className="text-[12px] font-semibold text-om-navy">
                  {product.brand?.name ?? 'OceanMall'}
                </Text>
              </Text>
              {soldOut ? (
                <Text className="text-[12px] font-semibold text-destructive">Stok habis</Text>
              ) : stock !== null && stock <= 5 ? (
                <Text className="text-[12px] font-semibold text-om-sale">Sisa {stock} lagi</Text>
              ) : stock !== null ? (
                <Text className="text-[12px] text-muted-foreground">Stok {stock}</Text>
              ) : null}
            </View>
          </View>

          {/* Variants */}
          {(product.variants?.length ?? 0) > 0 ? (
            <Section title={`Pilih varian: ${selectedVariant?.name ?? '-'}`}>
              <View className="flex-row flex-wrap gap-2">
                {product.variants?.map((variant) => {
                  const active = variant.id === variantId;
                  const empty =
                    variant.stock !== null && variant.stock !== undefined && variant.stock < 1;
                  return (
                    <Pressable
                      key={variant.id}
                      disabled={empty}
                      onPress={() => {
                        setVariantId(variant.id);
                        setNotice(null);
                        if (variant.stock != null) {
                          setQty((current) =>
                            Math.max(1, Math.min(current, variant.stock ?? current))
                          );
                        }
                      }}
                      className={cn(
                        'rounded-sm border px-3 py-2',
                        active ? 'border-om-navy bg-om-navy-soft' : 'border-border bg-white',
                        empty && 'opacity-40'
                      )}>
                      <Text
                        className={cn(
                          'text-[13px]',
                          active ? 'font-semibold text-om-navy' : 'text-foreground'
                        )}>
                        {variant.name ?? variant.sku ?? `#${variant.id}`}
                      </Text>
                    </Pressable>
                  );
                })}
              </View>
            </Section>
          ) : null}

          {/* Shipping & trust */}
          <Section>
            <InfoLine
              icon={Truck}
              title="Pengiriman ke seluruh Indonesia"
              text="Ongkir dihitung otomatis saat checkout sesuai alamat & kurir."
            />
            <InfoLine
              icon={CreditCard}
              title="Bayar via Virtual Account & QRIS"
              text="BCA, BNI, BRI, Mandiri, Permata, dan e-wallet."
            />
            <InfoLine
              icon={PackageCheck}
              title="Lacak pesanan dari aplikasi"
              text="Nomor resi dan status pengiriman tampil di halaman pesanan."
              last
            />
          </Section>

          {/* Description */}
          {description ? (
            <Section title="Deskripsi produk">
              <Text
                className="text-[13px] leading-5 text-foreground/80"
                numberOfLines={expanded ? undefined : 5}>
                {description}
              </Text>
              {description.length > 220 ? (
                <Pressable
                  onPress={() => setExpanded((value) => !value)}
                  hitSlop={8}
                  className="mt-2">
                  <Text className="text-[13px] font-semibold text-om-navy">
                    {expanded ? 'Tampilkan lebih sedikit' : 'Lihat selengkapnya'}
                  </Text>
                </Pressable>
              ) : null}
            </Section>
          ) : null}

          {/* Reviews */}
          <Section title="Ulasan pembeli">
            {reviews && reviews.count > 0 ? (
              <>
                <View className="mb-1 flex-row items-center gap-3">
                  <Text className="text-[28px] font-extrabold text-om-navy">
                    {reviews.average.toFixed(1)}
                  </Text>
                  <View>
                    <Stars value={reviews.average} size={14} />
                    <Text className="mt-0.5 text-[12px] text-muted-foreground">
                      {reviews.count} ulasan
                    </Text>
                  </View>
                </View>
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
                    <View key={review.id} className="flex-row gap-2.5 border-t border-border py-3">
                      <Avatar alt={name} className="size-8">
                        <AvatarFallback className="bg-om-navy-soft">
                          <Text className="text-[10px] font-bold text-om-navy">
                            {initials || 'P'}
                          </Text>
                        </AvatarFallback>
                      </Avatar>
                      <View className="min-w-0 flex-1">
                        <View className="flex-row items-center justify-between gap-2">
                          <Text className="flex-1 text-[13px] font-semibold" numberOfLines={1}>
                            {name}
                          </Text>
                          {review.created_at ? (
                            <Text className="text-[11px] text-muted-foreground">
                              {formatDate(review.created_at)}
                            </Text>
                          ) : null}
                        </View>
                        <View className="mt-1">
                          <Stars value={review.rating} size={11} />
                        </View>
                        {review.title ? (
                          <Text className="mt-1 text-[13px] font-medium">{review.title}</Text>
                        ) : null}
                        {review.content ? (
                          <Text className="mt-0.5 text-[13px] leading-5 text-muted-foreground">
                            {review.content}
                          </Text>
                        ) : null}
                      </View>
                    </View>
                  );
                })}
              </>
            ) : (
              <Text className="text-[13px] text-muted-foreground">
                Belum ada ulasan. Ulasan muncul setelah pembeli menerima pesanannya.
              </Text>
            )}
          </Section>

          {/* Related */}
          {(product.related_products?.length ?? 0) > 0 ? (
            <View className="mt-2 px-3 pt-3">
              <Text className="mb-3 px-1 text-[15px] font-bold text-om-navy">Produk terkait</Text>
              <ProductGrid products={product.related_products ?? []} emptyMessage="" />
            </View>
          ) : null}
        </ScrollView>

        <StickyBar>
          {error ? (
            <View className="mb-2.5 flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2">
              <Icon as={CircleAlert} size={15} className="text-om-sale" />
              <Text className="flex-1 text-[12px] text-om-sale">{error}</Text>
            </View>
          ) : notice ? (
            <Pressable
              onPress={() => router.push('/(tabs)/cart')}
              className="mb-2.5 flex-row items-center gap-2 rounded-sm bg-om-success-soft px-3 py-2">
              <Icon as={CheckCircle2} size={15} className="text-om-success" />
              <Text className="flex-1 text-[12px] font-medium text-om-success">{notice}</Text>
              <Text className="text-[12px] font-bold text-om-success">Lihat</Text>
            </Pressable>
          ) : null}
          <View className="flex-row items-center gap-2">
            <QtyStepper
              value={qty}
              max={maxQty}
              onChange={(value) => {
                setNotice(null);
                setQty(value);
              }}
            />
            <Button
              variant="outline"
              disabled={busy !== null || soldOut}
              onPress={() => void addToCart('stay')}
              className="h-10 flex-1 rounded-sm border-om-navy px-2">
              <Icon as={ShoppingCart} size={15} className="text-om-navy" />
              <Text className="text-[13px] font-bold text-om-navy">
                {busy === 'cart' ? '...' : 'Keranjang'}
              </Text>
            </Button>
            <Button
              disabled={busy !== null || soldOut}
              onPress={() => void addToCart('checkout')}
              className="h-10 flex-1 rounded-sm px-2">
              <Text className="text-[13px] font-bold">
                {soldOut ? 'Stok habis' : busy === 'buy' ? 'Memproses...' : 'Beli Sekarang'}
              </Text>
            </Button>
          </View>
        </StickyBar>
      </View>
    </Screen>
  );
}

function InfoLine({
  icon,
  title,
  text,
  last = false,
}: {
  icon: typeof Truck;
  title: string;
  text: string;
  last?: boolean;
}) {
  return (
    <View className={cn('flex-row gap-3', !last && 'mb-3')}>
      <View className="size-8 items-center justify-center rounded-full bg-om-navy-soft">
        <Icon as={icon} size={15} className="text-om-navy" />
      </View>
      <View className="flex-1">
        <Text className="text-[13px] font-semibold">{title}</Text>
        <Text className="text-[12px] leading-4 text-muted-foreground">{text}</Text>
      </View>
    </View>
  );
}
