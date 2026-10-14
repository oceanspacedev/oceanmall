import { CatalogList } from '@/components/catalog-list';
import { useLocalSearchParams } from 'expo-router';

export default function CategoryScreen() {
  const params = useLocalSearchParams<{ slug: string }>();
  const slug = Array.isArray(params.slug) ? params.slug[0] : params.slug;

  // `key` gives each category its own list state (sort, pages, title) when the route is reused.
  return (
    <CatalogList key={slug} heading="Kategori" path={`/catalog/categories/${slug}`} query={{}} />
  );
}
