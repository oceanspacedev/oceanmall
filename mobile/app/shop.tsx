import { CatalogList } from '@/components/catalog-list';
import { useLocalSearchParams } from 'expo-router';

function first(value?: string | string[]): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

export default function ShopScreen() {
  const params = useLocalSearchParams<{ brand?: string; title?: string }>();
  const brand = first(params.brand);
  const title = first(params.title);

  return (
    <CatalogList
      key={brand ?? 'all'}
      heading={title && title.trim() !== '' ? title : 'Semua produk'}
      query={brand ? { brand } : {}}
    />
  );
}
