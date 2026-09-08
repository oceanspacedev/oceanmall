import { CatalogList } from '@/components/catalog-list';

export default function FeaturedScreen() {
  return <CatalogList heading="Produk unggulan" query={{ featured: '1' }} />;
}
