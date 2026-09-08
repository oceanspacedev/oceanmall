import { Badge } from '@/components/ui/badge';
import { SalePrice } from '@/components/sale-price';
import { Text } from '@/components/ui/text';
import type { Product } from '@/lib/api';
import { discountPercent, mediaUrl } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Image } from 'expo-image';
import { Link } from 'expo-router';
import { Pressable, View } from 'react-native';

export function ProductCard({
  product,
  compact = false,
}: {
  product: Product;
  compact?: boolean;
}) {
  const off = discountPercent(product.price, product.compare_price);

  return (
    <Link href={`/product/${product.slug}`} asChild>
      <Pressable
        className={cn(
          'overflow-hidden rounded-lg border border-border bg-white',
          compact ? 'w-[128px]' : 'mb-2.5 w-[48.5%]'
        )}>
        <View className="relative">
          <Image
            source={{ uri: mediaUrl(product.thumbnail ?? product.images?.[0]?.url) }}
            style={{ width: '100%', height: compact ? 108 : 148, backgroundColor: '#f4f4f5' }}
            contentFit="cover"
          />
          {off ? (
            <Badge variant="sale" className="absolute left-0 top-0 rounded-none rounded-br-md px-1.5 py-0.5">
              <Text className="text-[11px] font-extrabold">-{off}%</Text>
            </Badge>
          ) : null}
        </View>
        <View className="gap-0.5 p-2">
          <Text className="text-[12px] font-medium leading-tight" numberOfLines={compact ? 1 : 2}>
            {product.name}
          </Text>
          <SalePrice price={product.price} compare={product.compare_price} />
          {!off ? (
            <Text className="text-[10px] text-muted-foreground" numberOfLines={1}>
              {product.brand?.name ?? 'OceanMall'}
            </Text>
          ) : null}
        </View>
      </Pressable>
    </Link>
  );
}
