import { Icon } from '@/components/ui/icon';
import { useCart } from '@/lib/cart';
import { useUnreadCount } from '@/lib/notifications';
import { COLORS } from '@/lib/theme';
import { Tabs } from 'expo-router/js-tabs';
import { House, Search, ShoppingCart, UserRound, type LucideIcon } from 'lucide-react-native';
import { StyleSheet, type ColorValue } from 'react-native';

/** Tab glyph: outlined when idle, filled (and a touch bolder) when its tab is the active one. */
function TabGlyph({
  as,
  color,
  focused,
  outlineOnly = false,
}: {
  as: LucideIcon;
  color: ColorValue;
  focused: boolean;
  /** The magnifier has no inside to fill; it just gets a heavier stroke. */
  outlineOnly?: boolean;
}) {
  return (
    <Icon
      as={as}
      color={color}
      size={24}
      fill={focused && !outlineOnly ? color : 'transparent'}
      strokeWidth={focused ? (outlineOnly ? 2.5 : 2.25) : 2}
    />
  );
}

function badgeCount(count: number): string | number | undefined {
  if (count <= 0) {
    return undefined;
  }

  return count > 99 ? '99+' : count;
}

export default function TabsLayout() {
  const { itemCount } = useCart();
  // Unread notifications live behind the account tab, so the badge is on Akun.
  const { unread } = useUnreadCount();

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarStyle: {
          backgroundColor: COLORS.white,
          borderTopWidth: StyleSheet.hairlineWidth,
          borderTopColor: COLORS.border,
        },
        // slate-500 on white is 4.77:1 (the old inactive grey was 3.08:1).
        tabBarActiveTintColor: COLORS.navy,
        tabBarInactiveTintColor: COLORS.slate,
        tabBarHideOnKeyboard: true,
        // The bar is chrome: its labels stay one size whatever the system font scale is.
        tabBarAllowFontScaling: false,
        tabBarLabelStyle: { fontSize: 11, fontWeight: '600' },
        tabBarItemStyle: { paddingTop: 4 },
        tabBarBadgeStyle: {
          backgroundColor: COLORS.sale,
          color: COLORS.white,
          fontSize: 10,
          fontWeight: '700',
        },
      }}>
      <Tabs.Screen
        name="index"
        options={{
          title: 'OceanMall',
          tabBarLabel: 'Beranda',
          tabBarAccessibilityLabel: 'Beranda',
          tabBarIcon: ({ color, focused }) => (
            <TabGlyph as={House} color={color} focused={focused} />
          ),
        }}
      />
      <Tabs.Screen
        name="search"
        options={{
          title: 'Cari',
          tabBarAccessibilityLabel: 'Cari',
          tabBarIcon: ({ color, focused }) => (
            <TabGlyph as={Search} color={color} focused={focused} outlineOnly />
          ),
        }}
      />
      <Tabs.Screen
        name="cart"
        options={{
          title: 'Keranjang',
          tabBarAccessibilityLabel: itemCount > 0 ? `Keranjang, ${itemCount} barang` : 'Keranjang',
          tabBarIcon: ({ color, focused }) => (
            <TabGlyph as={ShoppingCart} color={color} focused={focused} />
          ),
          tabBarBadge: badgeCount(itemCount),
        }}
      />
      <Tabs.Screen
        name="account"
        options={{
          title: 'Akun',
          tabBarAccessibilityLabel: unread > 0 ? `Akun, ${unread} notifikasi belum dibaca` : 'Akun',
          tabBarIcon: ({ color, focused }) => (
            <TabGlyph as={UserRound} color={color} focused={focused} />
          ),
          tabBarBadge: badgeCount(unread),
        }}
      />
    </Tabs>
  );
}
