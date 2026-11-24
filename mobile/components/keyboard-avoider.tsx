import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { Dimensions, Keyboard, Platform, View, type StyleProp, type ViewStyle } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

const KeyboardOpenContext = createContext(false);

/**
 * True inside a KeyboardAvoider while the keyboard covers its bottom edge. A StickyBar then sits
 * directly on the keyboard and drops its navigation-bar padding (the keyboard already covers it).
 */
export function useKeyboardCoversBottom(): boolean {
  return useContext(KeyboardOpenContext);
}

/**
 * Android replacement for `<KeyboardAvoidingView behavior="height">` around a screen body.
 *
 * Why not KeyboardAvoidingView: the app is edge-to-edge (RN 0.85) and KAV works from the event's
 * `screenY`, which is wrong there: it sits a navigation-bar height below the keyboard's real top
 * when the keyboard opens (and differs again inside a Modal window), and on close it reports the
 * nav bar's top instead of the screen bottom. KAV also measures itself relative to its parent, not
 * the screen. Result: under a header the keyboard covers the bottom button ("Simpan kata sandi"),
 * and after it closes a band stays above the nav bar.
 *
 * The keyboard event's `height` is reliable (0 when closed) and excludes the navigation bar, which
 * the keyboard also covers. So the keyboard covers `height + insets.bottom` from the screen
 * bottom. This measures where its box really is on screen when the keyboard opens (and again
 * whenever its layout changes while it is open: the tab bar hiding, the offline strip), pads its
 * bottom by the part the keyboard covers, and drops the padding as soon as the keyboard closes. On iOS it is a
 * plain flex View: screens there inset their scroll content (automaticallyAdjustKeyboardInsets).
 */
export function KeyboardAvoider({
  children,
  className,
  style,
}: {
  children: ReactNode;
  className?: string;
  style?: StyleProp<ViewStyle>;
}) {
  const ref = useRef<View>(null);
  const insets = useSafeAreaInsets();
  // Height the keyboard covers from the screen bottom (keyboard + nav bar) while open, else null.
  const keyboardCover = useRef<number | null>(null);
  const navInset = useRef(insets.bottom);
  navInset.current = insets.bottom;
  const [covered, setCovered] = useState(0);

  const measure = useCallback(() => {
    if (keyboardCover.current === null) {
      setCovered(0);
      return;
    }
    // The outer box is unchanged by our own padding, so this is stable across re-measures.
    // `measure` (page coordinates: the edge-to-edge root starts at the screen top), not
    // `measureInWindow`, which on Android is offset by the status bar height.
    ref.current?.measure((_x, _y, _width, height, _pageX, pageY) => {
      const cover = keyboardCover.current;
      if (cover !== null) {
        const belowBox = Dimensions.get('screen').height - (pageY + height);
        setCovered(Math.max(0, Math.round(cover - belowBox)));
      }
    });
  }, []);

  useEffect(() => {
    if (Platform.OS !== 'android') {
      return;
    }
    const show = Keyboard.addListener('keyboardDidShow', (event) => {
      keyboardCover.current = event.endCoordinates.height + navInset.current;
      measure();
    });
    const hide = Keyboard.addListener('keyboardDidHide', () => {
      keyboardCover.current = null;
      setCovered(0);
    });
    return () => {
      show.remove();
      hide.remove();
    };
  }, [measure]);

  return (
    <KeyboardOpenContext.Provider value={covered > 0}>
      <View
        ref={ref}
        className={className}
        onLayout={Platform.OS === 'android' ? measure : undefined}
        style={[{ flex: 1 }, style, { paddingBottom: covered }]}>
        {children}
      </View>
    </KeyboardOpenContext.Provider>
  );
}
