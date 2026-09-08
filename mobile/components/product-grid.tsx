import { EmptyState } from '@/components/empty-state';
import { ProductCard } from '@/components/product-card';
import type { Product } from '@/lib/api';
import type { LucideIcon } from 'lucide-react-native';
import { Package } from 'lucide-react-native';
import { View } from 'react-native';

export function ProductGrid({
  products,
  emptyMessage = 'Belum ada produk.',
  emptyIcon = Package,
}: {
  products: Product[];
  emptyMessage?: string;
  emptyIcon?: LucideIcon;
}) {
  if (products.length === 0) {
    return emptyMessage ? <EmptyState icon={emptyIcon} message={emptyMessage} /> : null;
  }

  return (
    <View className="flex-row flex-wrap justify-between">
      {products.map((product) => (
        <ProductCard key={product.id} product={product} />
      ))}
    </View>
  );
}
