import { CartBody, ClearCartButton } from '@/components/cart-body';
import { Screen } from '@/components/screen';
import { useCart } from '@/lib/cart';

/**
 * The cart as a pushed page: opened by the header cart button and the "Lihat Keranjang" toast, so
 * Back returns to the product page instead of jumping to the tab. It is named /keranjang because
 * the tab already owns /cart.
 */
export default function KeranjangScreen() {
  const { itemCount } = useCart();

  return (
    <Screen
      title={itemCount > 0 ? `Keranjang (${itemCount})` : 'Keranjang'}
      surface
      right={<ClearCartButton />}>
      <CartBody />
    </Screen>
  );
}
