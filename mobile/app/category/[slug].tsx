import { CatalogList } from '@/components/catalog-list';
import { useLocalSearchParams } from 'expo-router';

export default function CategoryScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();

  return <CatalogList heading="Kategori" path={`/catalog/categories/${slug}`} query={{}} />;
}
