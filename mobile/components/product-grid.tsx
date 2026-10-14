import { EmptyState } from '@/components/empty-state';
import { ProductCard } from '@/components/product-card';
import { ProductGridSkeleton } from '@/components/skeletons';
import type { Product } from '@/lib/api';
import type { LucideIcon } from 'lucide-react-native';
import { Package } from 'lucide-react-native';
import type { Ref } from 'react';
import {
  FlatList,
  Platform,
  View,
  type FlatListProps,
  type ListRenderItem,
  type StyleProp,
  type ViewStyle,
} from 'react-native';

/**
 * Two-column grid for short, finite lists that live inside another ScrollView (Beranda's
 * recommendations, the related products on a product page). Long or paged lists use ProductList.
 */
export function ProductGrid({
  products,
  emptyTitle = 'Belum ada produk',
  emptyMessage = 'Cek lagi nanti ya.',
  emptyIcon = Package,
  loading = false,
}: {
  products: Product[];
  emptyTitle?: string;
  /** An empty string hides the empty state entirely (a section that simply disappears). */
  emptyMessage?: string;
  emptyIcon?: LucideIcon;
  loading?: boolean;
}) {
  if (loading && products.length === 0) {
    return <ProductGridSkeleton />;
  }

  if (products.length === 0) {
    return emptyMessage ? (
      <EmptyState icon={emptyIcon} title={emptyTitle} message={emptyMessage} compact />
    ) : null;
  }

  return (
    <View className="flex-row flex-wrap justify-between">
      {products.map((product) => (
        <ProductCard key={product.id} product={product} />
      ))}
    </View>
  );
}

// Two cards per row, 12px from the screen edge. justify-between keeps a lone card in the last row
// on the left, and the cards' 48.5% width leaves the 3% gutter.
const COLUMN_STYLE: StyleProp<ViewStyle> = {
  justifyContent: 'space-between',
  paddingHorizontal: 12,
};

const renderProduct: ListRenderItem<Product> = ({ item }) => <ProductCard product={item} />;
const productKey = (item: Product) => String(item.id);

type ProductListProps = Omit<
  FlatListProps<Product>,
  'data' | 'renderItem' | 'keyExtractor' | 'numColumns' | 'columnWrapperStyle'
> & {
  products: Product[];
  ref?: Ref<FlatList<Product>>;
};

/**
 * The paged, virtualised two-column product list behind search, category, collection, brand,
 * promo and recommendation pages. Rows mount as they scroll into view, so a long browse does not
 * hold hundreds of images in memory. Pass the sort bar as `ListHeaderComponent`, `LoadMore` as
 * `ListFooterComponent`, and a `loadMore` guard in `onEndReached` (which already fires at 60% of
 * a screen from the end).
 */
export function ProductList({ products, ref, ...rest }: ProductListProps) {
  return (
    <FlatList
      ref={ref}
      data={products}
      renderItem={renderProduct}
      keyExtractor={productKey}
      numColumns={2}
      columnWrapperStyle={COLUMN_STYLE}
      onEndReachedThreshold={0.6}
      // Android only: detaching off-screen rows saves memory on low-RAM phones, but on iOS it
      // makes fast scrolling flash blank rows.
      removeClippedSubviews={Platform.OS === 'android'}
      // Counted in rows (two cards each): about one and a half screens up front, then small
      // batches, with a window of five screens around the viewport.
      initialNumToRender={4}
      maxToRenderPerBatch={4}
      windowSize={5}
      {...rest}
    />
  );
}
