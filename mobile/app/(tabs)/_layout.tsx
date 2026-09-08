import { Icon } from '@/components/ui/icon';
import { useCart } from '@/lib/cart';
import { Tabs } from 'expo-router';
import { HomeIcon, SearchIcon, ShoppingBagIcon, UserIcon } from 'lucide-react-native';

export default function TabsLayout() {
  const { itemCount } = useCart();

  return (
    <Tabs
      screenOptions={{
        headerTitleStyle: { fontWeight: '700' },
        headerShadowVisible: false,
        tabBarActiveTintColor: '#0a2a6b',
        tabBarInactiveTintColor: '#8b93a7',
        tabBarLabelStyle: { fontSize: 11, fontWeight: '600' },
        tabBarStyle: { borderTopColor: '#eceef2' },
      }}>
      <Tabs.Screen
        name="index"
        options={{
          title: 'OceanMall',
          headerShown: false,
          tabBarLabel: 'Beranda',
          tabBarIcon: ({ color, size }) => <Icon as={HomeIcon} color={color} size={size} />,
        }}
      />
      <Tabs.Screen
        name="search"
        options={{
          title: 'Cari',
          headerShown: false,
          tabBarIcon: ({ color, size }) => <Icon as={SearchIcon} color={color} size={size} />,
        }}
      />
      <Tabs.Screen
        name="cart"
        options={{
          title: 'Keranjang',
          headerShown: false,
          tabBarIcon: ({ color, size }) => <Icon as={ShoppingBagIcon} color={color} size={size} />,
          tabBarBadge: itemCount > 0 ? (itemCount > 99 ? '99+' : itemCount) : undefined,
          tabBarBadgeStyle: { backgroundColor: '#e11d48', fontSize: 10, fontWeight: '700' },
        }}
      />
      <Tabs.Screen
        name="account"
        options={{
          title: 'Akun',
          headerShown: false,
          tabBarIcon: ({ color, size }) => <Icon as={UserIcon} color={color} size={size} />,
        }}
      />
    </Tabs>
  );
}
