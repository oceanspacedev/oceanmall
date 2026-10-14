import { ProductCard } from '@/components/product-card';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import type { Product } from '@/lib/api';
import { Link, type Href } from 'expo-router';
import { ChevronRight } from 'lucide-react-native';
import { Pressable, ScrollView, View } from 'react-native';

/**
 * Section heading for Beranda blocks: the title, an optional red pill ("Hemat s.d. 25%") and one
 * "Lihat semua" link with a chevron. The whole link is a single 44pt-tall target.
 */
export function SectionHeader({
  title,
  href,
  badge,
}: {
  title: string;
  href?: Href;
  badge?: string;
}) {
  return (
    <View className="mb-3 flex-row items-center justify-between px-4">
      <View className="shrink flex-row items-center gap-2">
        <Text
          role="heading"
          aria-level={2}
          className="shrink text-[15px] font-bold text-om-navy"
          numberOfLines={1}>
          {title}
        </Text>
        {badge ? (
          <View className="rounded-full bg-om-sale-soft px-2 py-0.5">
            <Text className="text-[11px] font-bold text-om-sale-text">{badge}</Text>
          </View>
        ) : null}
      </View>
      {href ? (
        <Link href={href} asChild>
          <Pressable
            accessibilityRole="link"
            accessibilityLabel={`Lihat semua ${title}`}
            hitSlop={{ top: 14, bottom: 14, left: 12, right: 12 }}
            className="flex-row items-center active:opacity-70">
            <Text className="text-[12px] font-semibold text-om-navy">Lihat semua</Text>
            <Icon as={ChevronRight} size={14} className="text-om-navy" />
          </Pressable>
        </Link>
      ) : null}
    </View>
  );
}

/** A titled horizontal row of compact product cards. Renders nothing for an empty list. */
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
      <SectionHeader title={title} href={href} badge={badge} />
      <ScrollView
        horizontal
        nestedScrollEnabled
        scrollsToTop={false}
        showsHorizontalScrollIndicator={false}
        contentContainerClassName="gap-3 px-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} compact />
        ))}
      </ScrollView>
    </View>
  );
}
