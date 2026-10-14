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
 * Drop-in replacement for react-native-safe-area-context's SafeAreaView that pads from the
 * insets in React context instead of measuring its own native frame.
 *
 * Why: the offline strip (lib/network.tsx) sits in flow under the status bar and hands every
 * screen below it a zero top inset through SafeAreaInsetsContext. The native SafeAreaView ignores
 * that context and pads for the status bar anyway, so each header grew by a second status-bar
 * height whenever the strip appeared (and shrank back when it left). Reading the context keeps
 * every screen's top edge the same with or without the strip. Use this everywhere; never import
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
