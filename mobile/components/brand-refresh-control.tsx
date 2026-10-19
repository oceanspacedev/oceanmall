import { COLORS } from '@/lib/theme';
import { RefreshControl, type RefreshControlProps } from 'react-native';

/**
 * Pull-to-refresh with the brand colours: a navy spinner on iOS, and a navy arc on a white disc
 * on Android (the default there is the AppCompat teal). Pair it with useRefresh so the spinner
 * stays until the request finishes:
 *
 *   const { refreshing, onRefresh } = useRefresh(load);
 *   <ScrollView refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />} />
 *
 * On Android and web, ScrollView clones this element with its own style and the scroll view as
 * children, so every prop it receives must reach RefreshControl or the screen renders empty.
 */
export function BrandRefreshControl({
  onRefresh,
  ...props
}: Omit<RefreshControlProps, 'onRefresh'> & {
  onRefresh: () => void | Promise<unknown>;
}) {
  return (
    <RefreshControl
      {...props}
      onRefresh={() => void onRefresh()}
      tintColor={COLORS.navy}
      colors={[COLORS.navy]}
      progressBackgroundColor={COLORS.white}
    />
  );
}
