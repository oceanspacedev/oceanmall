import { CartBody, ClearCartButton } from '@/components/cart-body';
import { TabHeader } from '@/components/screen';
import { useCart } from '@/lib/cart';
import { SafeAreaView } from '@/components/safe-area-view';

/**
 * The Keranjang tab. The same cart is also pushed as a stack page (/keranjang) from the header
 * cart button, so that Back returns to the product the shopper came from.
 */
export default function CartScreen() {
  const { itemCount } = useCart();

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <TabHeader
        title={itemCount > 0 ? `Keranjang (${itemCount})` : 'Keranjang'}
        right={<ClearCartButton />}
      />
      <CartBody inTabs />
    </SafeAreaView>
  );
}
