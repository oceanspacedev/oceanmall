import { CatalogList } from '@/components/catalog-list';

export default function FeaturedScreen() {
  // Same name as the Beranda section that links here.
  return <CatalogList heading="Rekomendasi untukmu" query={{ featured: '1' }} />;
}
