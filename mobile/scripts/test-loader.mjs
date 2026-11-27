import fs from 'node:fs';
import { createRequire, registerHooks } from 'node:module';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const ts = require('typescript');

const MOBILE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const VIRTUAL_PREFIX = 'virtual:mobile-test-stub:';

if (typeof globalThis.__DEV__ === 'undefined') {
  globalThis.__DEV__ = true;
}

const STUBS = {
  'react-native': `
    export const Platform = { OS: 'ios', select: (obj) => obj.ios ?? obj.default };
    export const AppState = { currentState: 'active', addEventListener: () => ({ remove() {} }) };
    export function Pressable() { return null; }
    export function View() { return null; }
    export function TextInput() { return null; }
    export default { Platform, AppState, Pressable, View, TextInput };
  `,
  'expo-secure-store': `
    const store = new Map();
    export async function getItemAsync(key) { return store.get(key) ?? null; }
    export async function setItemAsync(key, value) { store.set(key, value); }
    export async function deleteItemAsync(key) { store.delete(key); }
  `,
  'expo-router': `
    export const DefaultTheme = { colors: {} };
    export function useFocusEffect() {}
    export function useRouter() { return { push() {}, replace() {}, dismissTo() {}, canDismiss: () => false }; }
  `,
  'react-native-safe-area-context': `
    export const SafeAreaInsetsContext = { Provider: () => null };
    export function useSafeAreaInsets() { return { top: 0, right: 0, bottom: 0, left: 0 }; }
  `,
  'lucide-react-native': `
    export const CircleCheck = () => null;
    export const WifiOff = () => null;
  `,
  '@/components/ui/icon': `export function Icon() { return null; }`,
  '@/components/ui/text': `export function Text() { return null; }`,
  '@/components/ui/toast': `export function ToastHost() { return null; }`,
  '@/lib/a11y': `export function announce() {}`,
};

const EXTENSIONS = ['.ts', '.tsx', '.js', '.mjs'];

function resolveFile(basePath) {
  if (fs.existsSync(basePath) && fs.statSync(basePath).isFile()) {
    return basePath;
  }
  for (const ext of EXTENSIONS) {
    const candidate = `${basePath}${ext}`;
    if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
      return candidate;
    }
  }
  for (const ext of EXTENSIONS) {
    const indexCandidate = path.join(basePath, `index${ext}`);
    if (fs.existsSync(indexCandidate) && fs.statSync(indexCandidate).isFile()) {
      return indexCandidate;
    }
  }
  return null;
}

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (Object.prototype.hasOwnProperty.call(STUBS, specifier)) {
      return { url: `${VIRTUAL_PREFIX}${specifier}`, shortCircuit: true };
    }
    if (specifier.startsWith('@/')) {
      const resolved = resolveFile(path.join(MOBILE_ROOT, specifier.slice(2)));
      if (resolved) {
        return { url: pathToFileURL(resolved).href, shortCircuit: true };
      }
    }
    if (
      (specifier.startsWith('./') || specifier.startsWith('../')) &&
      context.parentURL?.startsWith('file:')
    ) {
      const parentDir = path.dirname(fileURLToPath(context.parentURL));
      const resolved = resolveFile(path.resolve(parentDir, specifier));
      if (resolved) {
        return { url: pathToFileURL(resolved).href, shortCircuit: true };
      }
    }
    if (context.parentURL?.startsWith(VIRTUAL_PREFIX)) {
      return nextResolve(specifier, {
        ...context,
        parentURL: pathToFileURL(path.join(MOBILE_ROOT, 'package.json')).href,
      });
    }
    return nextResolve(specifier, context);
  },

  load(url, context, nextLoad) {
    if (url.startsWith(VIRTUAL_PREFIX)) {
      const key = url.slice(VIRTUAL_PREFIX.length);
      return { format: 'module', source: STUBS[key], shortCircuit: true };
    }
    if (url.startsWith('file:') && (url.endsWith('.ts') || url.endsWith('.tsx'))) {
      const fileName = fileURLToPath(url);
      const source = fs.readFileSync(fileName, 'utf8');
      const { outputText } = ts.transpileModule(source, {
        fileName,
        compilerOptions: {
          module: ts.ModuleKind.ESNext,
          target: ts.ScriptTarget.ES2022,
          jsx: ts.JsxEmit.ReactJSX,
          esModuleInterop: true,
        },
      });
      return { format: 'module', source: outputText, shortCircuit: true };
    }
    return nextLoad(url, context);
  },
});
