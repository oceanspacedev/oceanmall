import { ProductImage } from '@/components/product-image';
import { SalePrice } from '@/components/sale-price';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { PressableScale } from '@/components/ui/pressable-scale';
import { Text } from '@/components/ui/text';
import type { Product } from '@/lib/api';
import { discountPercent, formatCount, formatIdr, mediaUrl } from '@/lib/format';
import { COLORS } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { Link } from 'expo-router';
import { Star } from 'lucide-react-native';
import { memo } from 'react';
import { View } from 'react-native';

/** Indonesian decimal comma: 4.8 -> "4,8". */
function formatRating(value: number): string {
  return value.toFixed(1).replace('.', ',');
}

/**
 * One product tile. `compact` is the fixed-width card for horizontal rails; the default fills a
 * column of the two-column grid.
 *
 * Cards in a row always end up the same height: the picture is square, the name reserves two
 * lines, the meta line is always present, and the text block stretches to the row.
 */
function ProductCardImpl({ product, compact = false }: { product: Product; compact?: boolean }) {
  const off = discountPercent(product.price, product.compare_price);
  const uri = mediaUrl(product.thumbnail ?? product.images?.[0]?.url);

  const rating =
    typeof product.rating_average === 'number' && (product.rating_count ?? 0) > 0
      ? formatRating(product.rating_average)
      : null;
  const sold = (product.sold_count ?? 0) > 0 ? formatCount(product.sold_count) : null;
  const brand = product.brand?.name ?? null;

  const label = [
    product.name,
    formatIdr(product.price),
    off ? `diskon ${off}% dari ${formatIdr(product.compare_price)}` : null,
    rating ? `rating ${rating}` : null,
    sold ? `${sold} terjual` : null,
    brand,
  ]
    .filter(Boolean)
    .join(', ');

  return (
    <Link href={`/product/${product.slug}`} asChild>
      <PressableScale
        accessibilityRole="link"
        accessibilityLabel={label}
        className={cn(
          'overflow-hidden rounded-lg border border-border bg-white',
          compact ? 'w-[140px]' : 'mb-2.5 w-[48.5%]'
        )}>
        <View className="relative">
          <ProductImage uri={uri} recyclingKey={uri} style={{ width: '100%', aspectRatio: 1 }} />
          {off ? (
            <Badge
              variant="sale"
              className="absolute left-0 top-0 rounded-none rounded-br-md px-1.5 py-0.5">
              <Text className="text-[11px] font-extrabold">-{off}%</Text>
            </Badge>
          ) : null}
        </View>
        {/* grow (not flex-1): stretches to the tallest card in the row, but never collapses when
            the card sits in an auto-height parent. */}
        <View className="grow justify-between gap-1 p-2">
          <View className="gap-0.5">
            <Text
              className="text-[12px] font-medium leading-4"
              numberOfLines={2}
              style={{ minHeight: 32 }}>
              {product.name}
            </Text>
            <SalePrice price={product.price} compare={product.compare_price} />
          </View>
          <View className="min-h-[14px] flex-row items-center gap-1.5">
            {rating || sold ? (
              <>
                {rating ? (
                  <View className="flex-row items-center gap-0.5">
                    <Icon as={Star} size={11} color={COLORS.star} fill={COLORS.star} />
                    <Text className="text-[11px] text-muted-foreground">{rating}</Text>
                  </View>
                ) : null}
                {rating && sold ? <View className="h-2.5 w-px bg-border" /> : null}
                {sold ? (
                  <Text className="shrink text-[11px] text-muted-foreground" numberOfLines={1}>
                    {sold} terjual
                  </Text>
                ) : null}
              </>
            ) : (
              <Text className="text-[11px] text-muted-foreground" numberOfLines={1}>
                {brand ?? 'OceanMall'}
              </Text>
            )}
          </View>
        </View>
      </PressableScale>
    </Link>
  );
}

export const ProductCard = memo(ProductCardImpl);
