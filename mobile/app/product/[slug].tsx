import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { ProductGrid } from '@/components/product-grid';
import { ProductImage } from '@/components/product-image';
import { CART_HREF, HeaderIconButton, Screen } from '@/components/screen';
import { QtyStepper, Section, StatusPill, StickyBar } from '@/components/shop';
import { SectionSkeleton } from '@/components/skeletons';
import { formatRating, Stars } from '@/components/stars';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import {
  api,
  ApiError,
  errorMessage,
  reportReview,
  type Cart,
  type Product,
  type ProductReview,
} from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { WEB_URL } from '@/lib/config';
import {
  discountPercent,
  formatCount,
  formatIdr,
  formatRelative,
  mediaUrl,
  stripHtml,
} from '@/lib/format';
import { COLORS } from '@/lib/theme';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { useLocalSearchParams, useRouter } from 'expo-router';
import {
  ChevronRight,
  ChevronUp,
  CircleAlert,
  CreditCard,
  Flag,
  PackageCheck,
  Share2,
  ShieldCheck,
  Star,
  Truck,
} from 'lucide-react-native';
import { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  NativeScrollEvent,
  NativeSyntheticEvent,
  Platform,
  Pressable,
  ScrollView,
  Share,
  useWindowDimensions,
  View,
} from 'react-native';

/** Mirrors CustomerCart::MAX_LINE_QUANTITY on the API. */
const MAX_LINE_QUANTITY = 10;
/** Reviews shown before "Lihat semua ulasan". */
const REVIEW_PREVIEW_COUNT = 3;
/** Past this many photos the dots turn into noise, so only the "1/N" counter is shown. */
const MAX_GALLERY_DOTS = 8;
/** The toast's default distance above the bottom edge, which clears this page's bar. */
const TOAST_OFFSET = 72;
/** Extra distance for the add-to-cart toast when the bar carries its "Maks." hint row. */
const HINT_ROW_OFFSET = 24;

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
  const [attempt, setAttempt] = useState(0);
  const [loadError, setLoadError] = useState<{ message: string; missing: boolean } | null>(null);
  const [busy, setBusy] = useState<'cart' | 'buy' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [expanded, setExpanded] = useState(false);
  const [showAllReviews, setShowAllReviews] = useState(false);
  const [reporting, setReporting] = useState<number | null>(null);

  /** "Laporkan" on a review: needs an account, asks for a reason, then hides the review here. */
  function askToReport(review: ProductReview) {
    if (!user) {
      router.push({ pathname: '/login', params: { redirect: `/product/${slug}` } });
      return;
    }
    const send = (reason: string) => {
      setReporting(review.id);
      reportReview(review.id, reason)
        .then(() => {
          setProduct((current) => {
            if (!current?.reviews) {
              return current;
            }
            return {
              ...current,
              reviews: {
                ...current.reviews,
                items: current.reviews.items.filter((item) => item.id !== review.id),
                count: Math.max(0, current.reviews.count - 1),
              },
            };
          });
          toast.show({ tone: 'success', message: 'Terima kasih, laporanmu sudah kami terima' });
        })
        .catch((e) => toast.show({ tone: 'error', message: errorMessage(e) }))
        .finally(() => setReporting(null));
    };
    Alert.alert('Laporkan ulasan ini?', 'Ulasan disembunyikan sampai tim OceanMall meninjaunya.', [
      { text: 'Kata-kata tidak pantas', onPress: () => send('Kata-kata tidak pantas') },
      { text: 'Spam atau iklan', onPress: () => send('Spam atau iklan') },
      { text: 'Tidak sesuai produk', onPress: () => send('Tidak sesuai produk') },
      { text: 'Batal', style: 'cancel' },
    ]);
  }

  useEffect(() => {
    if (!slug) {
      return;
    }
    // A slow answer for a previous slug (or an earlier attempt) must not overwrite a newer one.
    let active = true;
    setLoading(true);
    setLoadError(null);
    api<{ data: Product }>(`/catalog/products/${slug}`)
      .then((res) => {
        if (!active) {
          return;
        }
        setProduct(res.data);
        setVariantId(res.data.variants?.[0]?.id ?? null);
        setImageIndex(0);
        setQty(1);
      })
      .catch((e) => {
        if (!active) {
          return;
        }
        setLoadError({
          message: errorMessage(e, 'Produk tidak ditemukan'),
          missing: e instanceof ApiError && e.status === 404,
        });
      })
      .finally(() => {
        if (active) {
          setLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [slug, attempt]);

  const images = useMemo(() => {
    if (!product) {
      return [];
    }
    const urls = [product.thumbnail, ...(product.images ?? []).map((image) => image.url)]
      .map((url) => mediaUrl(url))
      .filter((url): url is string => Boolean(url));
    return [...new Set(urls)];
  }, [product]);

  async function addToCart(next: 'stay' | 'checkout', toastOffset?: number) {
    if (!product || busy !== null) {
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
          // "Beli" buys exactly the chosen quantity instead of adding to the cart line.
          ...(next === 'checkout' ? { replace: true } : {}),
        }),
      });
      applyCart(res.data);
      if (next === 'stay') {
        toast.show({
          tone: 'success',
          message: `${qty} barang ditambahkan ke keranjang`,
          action: { label: 'Lihat Keranjang', onPress: () => router.push(CART_HREF) },
          duration: 4000,
          ...(toastOffset ? { bottomOffset: toastOffset } : {}),
        });
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
    return <Screen title="Produk" showCart surface loading={<ProductSkeleton width={width} />} />;
  }

  if (!product) {
    return (
      <Screen title="Produk" showCart>
        {loadError?.missing ? (
          <EmptyState
            icon={CircleAlert}
            title="Produk tidak ditemukan"
            message="Produk ini sudah tidak tersedia atau tautannya salah."
            actionLabel="Kembali belanja"
            onAction={() => router.dismissTo('/(tabs)')}
          />
        ) : (
          <EmptyState
            tone="error"
            icon={CircleAlert}
            message={loadError?.message ?? 'Produk tidak ditemukan'}
            actionLabel="Coba lagi"
            onAction={() => setAttempt((value) => value + 1)}
          />
        )}
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
  const atMaxQty = !soldOut && qty >= maxQty;
  const reviews = product.reviews;
  const reviewItems = reviews?.items ?? [];
  const description = stripHtml(product.description);

  // The shared link opens the web storefront (universal links are a follow-up). Without a
  // configured web host the message goes out as text only rather than with a made-up address.
  const url = WEB_URL ? `${WEB_URL}/shop/${encodeURIComponent(product.slug)}` : '';
  const text =
    price != null
      ? `${product.name} - ${formatIdr(price)} di OceanMall`
      : `${product.name} di OceanMall`;

  const share = async () => {
    try {
      // The link goes in exactly one field: iOS appends `url` to the message by itself.
      await Share.share(
        Platform.OS === 'ios'
          ? { message: text, ...(url ? { url } : {}) }
          : { title: product.name, message: url ? `${text}\n${url}` : text }
      );
    } catch {
      // The sheet failed to open: nothing useful to tell the shopper.
    }
  };

  return (
    <Screen
      title={product.name}
      showCart
      surface
      rightExtra={
        <HeaderIconButton icon={Share2} label="Bagikan produk" onPress={() => void share()} />
      }>
      <View className="flex-1">
        <ScrollView className="flex-1" contentContainerClassName="pb-4">
          {/* Gallery */}
          <View style={{ height: width, backgroundColor: COLORS.white }}>
            {images.length > 0 ? (
              <ScrollView
                horizontal
                pagingEnabled
                nestedScrollEnabled
                scrollsToTop={false}
                showsHorizontalScrollIndicator={false}
                onMomentumScrollEnd={onGalleryScroll}
                style={{ flex: 1 }}>
                {images.map((uri, index) => (
                  <ProductImage
                    key={uri}
                    uri={uri}
                    width={width}
                    height={width}
                    contentFit="contain"
                    recyclingKey={uri}
                    priority={index === 0 ? 'high' : undefined}
                    alt={`Foto ${index + 1} dari ${images.length}: ${product.name}`}
                  />
                ))}
              </ScrollView>
            ) : (
              <View className="flex-1 items-center justify-center">
                <Text className="text-[13px] text-muted-foreground">Tidak ada foto</Text>
              </View>
            )}
            {images.length > 1 ? (
              <>
                {images.length <= MAX_GALLERY_DOTS ? (
                  <View
                    pointerEvents="none"
                    accessibilityElementsHidden
                    importantForAccessibility="no-hide-descendants"
                    className="absolute inset-x-0 bottom-3 flex-row justify-center gap-1.5">
                    {images.map((uri, index) => (
                      <View
                        key={uri}
                        className={cn(
                          'size-1.5 rounded-full',
                          index === imageIndex ? 'bg-om-navy' : 'bg-om-navy/25'
                        )}
                      />
                    ))}
                  </View>
                ) : null}
                <View
                  accessibilityElementsHidden
                  importantForAccessibility="no-hide-descendants"
                  className="absolute bottom-3 right-3 rounded-sm bg-black/50 px-2.5 py-1">
                  <Text className="text-[12px] font-semibold text-white">
                    {imageIndex + 1}/{images.length}
                  </Text>
                </View>
              </>
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
                <View className="rounded-sm bg-om-sale-soft px-1.5 py-0.5">
                  <Text className="text-[12px] font-extrabold text-om-sale-text">-{off}%</Text>
                </View>
              </View>
            ) : null}
            <Text className="mt-2 text-[16px] font-semibold leading-6">{product.name}</Text>
            <View className="mt-2 flex-row flex-wrap items-center gap-x-3 gap-y-1">
              {reviews && reviews.count > 0 ? (
                <View
                  accessible
                  accessibilityLabel={`Rating ${formatRating(reviews.average)} dari 5, ${reviews.count} ulasan`}
                  className="flex-row items-center gap-1">
                  <Icon as={Star} size={13} color={COLORS.star} fill={COLORS.star} />
                  <Text className="text-[12px] font-semibold">{formatRating(reviews.average)}</Text>
                  <Text className="text-[12px] text-muted-foreground">
                    ({reviews.count} ulasan)
                  </Text>
                </View>
              ) : null}
              {(product.sold_count ?? 0) > 0 ? (
                <Text className="text-[12px] text-muted-foreground">
                  Terjual {formatCount(product.sold_count)}
                </Text>
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
                <Text className="text-[12px] font-semibold text-om-sale-text">Tersisa {stock}</Text>
              ) : stock !== null ? (
                <Text className="text-[12px] text-muted-foreground">Stok {stock}</Text>
              ) : null}
            </View>
            <View className="mt-2 flex-row items-center gap-1.5">
              <Icon as={ShieldCheck} size={14} className="text-om-navy" />
              <Text className="text-[12px] font-semibold text-om-navy">OceanMall Resmi</Text>
            </View>
          </View>

          {/* Variants */}
          {(product.variants?.length ?? 0) > 0 ? (
            <Section
              title={
                selectedVariant?.name ? `Pilih varian: ${selectedVariant.name}` : 'Pilih varian'
              }>
              <View className="flex-row flex-wrap gap-2">
                {product.variants?.map((variant) => {
                  const active = variant.id === variantId;
                  const empty =
                    variant.stock !== null && variant.stock !== undefined && variant.stock < 1;
                  const name = variant.name ?? variant.sku ?? `#${variant.id}`;
                  return (
                    <Pressable
                      key={variant.id}
                      // Not `disabled`: a sold-out variant stays reachable so a screen reader can
                      // hear that it is sold out instead of skipping over it.
                      accessibilityRole="button"
                      accessibilityLabel={empty ? `${name}, habis` : name}
                      accessibilityState={{ selected: active, disabled: empty }}
                      hitSlop={{ top: 6, bottom: 6 }}
                      onPress={() => {
                        if (empty) {
                          toast.show({ tone: 'info', message: `${name} sedang habis` });
                          return;
                        }
                        setVariantId(variant.id);
                        setError(null);
                        if (variant.stock != null) {
                          setQty((current) =>
                            Math.max(1, Math.min(current, variant.stock ?? current))
                          );
                        }
                      }}
                      className={cn(
                        'flex-row items-center gap-1.5 rounded-sm border px-3 py-2 active:opacity-70',
                        empty
                          ? 'border-dashed border-border bg-white'
                          : active
                            ? 'border-om-navy bg-om-navy-soft'
                            : 'border-border bg-white'
                      )}>
                      <Text
                        className={cn(
                          'text-[13px]',
                          empty
                            ? 'text-muted-foreground line-through'
                            : active
                              ? 'font-semibold text-om-navy'
                              : 'text-foreground'
                        )}>
                        {name}
                      </Text>
                      {empty ? (
                        <Text className="text-[12px] text-muted-foreground">Habis</Text>
                      ) : null}
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
              text="Ongkir & estimasi tiba dihitung saat checkout."
            />
            <InfoLine
              icon={CreditCard}
              title="Pembayaran aman"
              text="Transfer bank, virtual account & QRIS."
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
                  accessibilityRole="button"
                  onPress={() => setExpanded((value) => !value)}
                  className="mt-1 min-h-11 justify-center self-start active:opacity-60">
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
                    {formatRating(reviews.average)}
                  </Text>
                  <View>
                    <Stars value={reviews.average} size={14} />
                    <Text className="mt-0.5 text-[12px] text-muted-foreground">
                      {reviews.count} ulasan
                    </Text>
                  </View>
                </View>
                {reviewItems
                  .slice(0, showAllReviews ? reviewItems.length : REVIEW_PREVIEW_COUNT)
                  .map((review) => {
                    const name = review.author_name ?? 'Pembeli';
                    const initials = name
                      .trim()
                      .split(/\s+/)
                      .slice(0, 2)
                      .map((part) => part[0])
                      .join('')
                      .toUpperCase();
                    return (
                      <View
                        key={review.id}
                        className="flex-row gap-2.5 border-t border-border py-3">
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
                              <Text className="text-[12px] text-muted-foreground">
                                {formatRelative(review.created_at)}
                              </Text>
                            ) : null}
                          </View>
                          <View className="mt-1 flex-row flex-wrap items-center gap-x-2 gap-y-1">
                            <Stars value={review.rating} size={11} />
                            {review.is_recommended ? (
                              <StatusPill tone="success" label="Merekomendasikan" />
                            ) : null}
                          </View>
                          {review.title ? (
                            <Text className="mt-1 text-[13px] font-medium">{review.title}</Text>
                          ) : null}
                          {review.content ? (
                            <Text className="mt-0.5 text-[13px] leading-5 text-muted-foreground">
                              {review.content}
                            </Text>
                          ) : null}
                          <Pressable
                            accessibilityRole="button"
                            accessibilityLabel={`Laporkan ulasan dari ${name}`}
                            disabled={reporting === review.id}
                            onPress={() => askToReport(review)}
                            className="mt-1 min-h-8 flex-row items-center gap-1 self-start active:opacity-60">
                            <Icon as={Flag} size={11} className="text-muted-foreground" />
                            <Text className="text-[11px] font-semibold text-muted-foreground">
                              {reporting === review.id ? 'Mengirim…' : 'Laporkan'}
                            </Text>
                          </Pressable>
                        </View>
                      </View>
                    );
                  })}
                {reviewItems.length > REVIEW_PREVIEW_COUNT ? (
                  <Pressable
                    accessibilityRole="button"
                    onPress={() => setShowAllReviews((value) => !value)}
                    className="min-h-11 flex-row items-center justify-center gap-1 border-t border-border active:opacity-60">
                    <Text className="text-[13px] font-semibold text-om-navy">
                      {showAllReviews
                        ? 'Tampilkan lebih sedikit'
                        : `Lihat semua ulasan (${reviewItems.length})`}
                    </Text>
                    <Icon
                      as={showAllReviews ? ChevronUp : ChevronRight}
                      size={16}
                      className="text-om-navy"
                    />
                  </Pressable>
                ) : null}
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
              <Text
                role="heading"
                aria-level={2}
                className="mb-3 px-1 text-[15px] font-bold text-om-navy">
                Produk terkait
              </Text>
              <ProductGrid products={product.related_products ?? []} emptyMessage="" />
            </View>
          ) : null}
        </ScrollView>

        <StickyBar>
          {error ? (
            <ErrorBanner message={error} className="mx-0 mb-2.5 mt-0" />
          ) : atMaxQty ? (
            <Text className="mb-2 text-[12px] text-muted-foreground">
              Maks. {maxQty} per pesanan
            </Text>
          ) : null}
          <View className="flex-row items-center gap-2">
            <QtyStepper
              value={qty}
              max={maxQty}
              itemLabel={product.name}
              onChange={(value) => {
                setError(null);
                setQty(value);
              }}
            />
            <Button
              variant="outline"
              disabled={soldOut}
              loading={busy === 'cart'}
              onPress={() =>
                void addToCart('stay', atMaxQty ? TOAST_OFFSET + HINT_ROW_OFFSET : undefined)
              }
              className="min-h-11 flex-1 rounded-sm border-om-navy px-2">
              <Text className="text-[13px] font-bold text-om-navy">+ Keranjang</Text>
            </Button>
            <Button
              disabled={soldOut}
              loading={busy === 'buy'}
              onPress={() => void addToCart('checkout')}
              className="min-h-11 flex-1 rounded-sm px-2">
              <Text className="text-[13px] font-bold">
                {soldOut ? 'Stok habis' : 'Beli sekarang'}
              </Text>
            </Button>
          </View>
        </StickyBar>
      </View>
    </Screen>
  );
}

/** Loading placeholder shaped like the page: gallery, price and title, variant chips, a section. */
function ProductSkeleton({ width }: { width: number }) {
  return (
    <View>
      <Skeleton className="w-full rounded-none" style={{ height: width }} />
      <View className="gap-2 bg-white px-4 py-4">
        <Skeleton className="h-7 w-40" />
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-4 w-2/3" />
        <Skeleton className="mt-1 h-3 w-32" />
      </View>
      <View className="mt-2 gap-3 bg-white px-4 py-3.5">
        <Skeleton className="h-4 w-28" />
        <View className="flex-row gap-2">
          <Skeleton className="h-9 w-20 rounded-sm" />
          <Skeleton className="h-9 w-24 rounded-sm" />
          <Skeleton className="h-9 w-16 rounded-sm" />
        </View>
      </View>
      <SectionSkeleton />
    </View>
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
