import { ProductCard } from '@/components/product-card';
import { Text } from '@/components/ui/text';
import type { Product } from '@/lib/api';
import { Link, type Href } from 'expo-router';
import { Pressable, ScrollView, View } from 'react-native';

export function ProductRail({
  title,
  href,
  products,
  badge,
}: {
  title: string;
  href: Href;
  products: Product[];
  badge?: string;
}) {
  if (products.length === 0) {
    return null;
  }

  return (
    <View className="mb-5">
      <View className="mb-3 flex-row items-center justify-between px-4">
        <View className="flex-row items-center gap-2">
          <Text className="text-[15px] font-bold text-om-navy">{title}</Text>
          {badge ? (
            <View className="rounded-full bg-om-sale-soft px-2 py-0.5">
              <Text className="text-[11px] font-bold text-om-sale">{badge}</Text>
            </View>
          ) : null}
        </View>
        <Link href={href} asChild>
          <Pressable hitSlop={8}>
            <Text className="text-xs font-semibold text-om-navy">Lihat semua</Text>
          </Pressable>
        </Link>
      </View>
      <ScrollView
        horizontal
        nestedScrollEnabled
        showsHorizontalScrollIndicator={false}
        contentContainerClassName="gap-3 px-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} compact />
        ))}
      </ScrollView>
    </View>
  );
}
