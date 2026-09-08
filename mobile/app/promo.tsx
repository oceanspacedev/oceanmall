import { CatalogList } from '@/components/catalog-list';

export default function PromoScreen() {
  return <CatalogList heading="Promo hari ini" query={{ promo: '1' }} />;
}
