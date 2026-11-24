import { useContext } from 'react';
import { View, type ViewProps } from 'react-native';
import {
  initialWindowMetrics,
  SafeAreaInsetsContext,
  type Edge,
  type EdgeInsets,
} from 'react-native-safe-area-context';

const ALL_EDGES: readonly Edge[] = ['top', 'right', 'bottom', 'left'];
const NO_INSETS: EdgeInsets = { top: 0, right: 0, bottom: 0, left: 0 };

/**
 * Bottom padding for the end of a scroll container on a pushed screen: the system navigation
 * bar inset (the app draws edge-to-edge, and 3-button navigation is about 48dp) plus `extra`.
 * Screen pads only the top, so lists use this in `contentContainerStyle={{ paddingBottom }}`.
 * Skip it where a StickyBar (it pads itself) or the tab bar sits below the list.
 */
export function useBottomPad(extra = 16): number {
  const insets = useContext(SafeAreaInsetsContext) ?? initialWindowMetrics?.insets ?? NO_INSETS;
  return insets.bottom + extra;
}

/**
 * Drop-in replacement for react-native-safe-area-context's SafeAreaView that pads from the
 * insets in React context instead of measuring its own native frame.
 *
 * Why: the offline strip (lib/network.tsx) floats over the top of the app and hands every screen
 * its own height as the top inset through SafeAreaInsetsContext. The native SafeAreaView ignores
 * that context and pads only for the status bar, so the strip would cover each header. Reading the
 * context moves every header below the strip while it shows. Use this everywhere; never import
 * SafeAreaView from react-native-safe-area-context directly.
 *
 * The inset padding is set on this view, so put any extra padding on the children (the same rule
 * as the native component).
 */
export function SafeAreaView({
  edges = ALL_EDGES,
  style,
  children,
  ...rest
}: ViewProps & { edges?: readonly Edge[] }) {
  // Outside a provider (the root error boundary, before expo-router mounts its own) fall back to
  // the metrics the native side reported at launch, then to no insets at all.
  const insets = useContext(SafeAreaInsetsContext) ?? initialWindowMetrics?.insets ?? NO_INSETS;

  const padding = {
    paddingTop: edges.includes('top') ? insets.top : 0,
    paddingRight: edges.includes('right') ? insets.right : 0,
    paddingBottom: edges.includes('bottom') ? insets.bottom : 0,
    paddingLeft: edges.includes('left') ? insets.left : 0,
  };

  return (
    <View {...rest} style={[padding, style]}>
      {children}
    </View>
  );
}
