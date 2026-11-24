import { useEffect, useState } from 'react';
import { Keyboard, Platform } from 'react-native';

/**
 * Height of the open software keyboard in dp (0 when closed), Android only (always 0 on iOS).
 *
 * For bottom sheets in a full-screen Modal (`statusBarTranslucent` + `navigationBarTranslucent`):
 * pad the sheet by `insets.bottom + useKeyboardHeight()` instead of wrapping it in a
 * KeyboardAvoidingView. On Android edge-to-edge the "keyboard hidden" event reports `screenY` at
 * the top of the navigation bar, so KeyboardAvoidingView (which measures from `screenY`) keeps a
 * phantom keyboard after it closes and the sheet floats with a gap above the nav bar. `height`
 * is reliable (0 when hidden), and the keyboard sits on top of the nav bar inset.
 */
export function useKeyboardHeight(): number {
  const [height, setHeight] = useState(0);

  useEffect(() => {
    if (Platform.OS !== 'android') {
      return;
    }
    const show = Keyboard.addListener('keyboardDidShow', (event) =>
      setHeight(Math.max(0, event.endCoordinates.height))
    );
    const hide = Keyboard.addListener('keyboardDidHide', () => setHeight(0));
    return () => {
      show.remove();
      hide.remove();
    };
  }, []);

  return height;
}
