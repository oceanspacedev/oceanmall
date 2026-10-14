import { COLORS } from '@/lib/theme';
import { RefreshControl } from 'react-native';

/**
 * Pull-to-refresh with the brand colours: a navy spinner on iOS, and a navy arc on a white disc
 * on Android (the default there is the AppCompat teal). Pair it with useRefresh so the spinner
 * stays until the request finishes:
 *
 *   const { refreshing, onRefresh } = useRefresh(load);
 *   <ScrollView refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />} />
 */
export function BrandRefreshControl({
  refreshing,
  onRefresh,
}: {
  refreshing: boolean;
  onRefresh: () => void | Promise<unknown>;
}) {
  return (
    <RefreshControl
      refreshing={refreshing}
      onRefresh={() => void onRefresh()}
      tintColor={COLORS.navy}
      colors={[COLORS.navy]}
      progressBackgroundColor={COLORS.white}
    />
  );
}
