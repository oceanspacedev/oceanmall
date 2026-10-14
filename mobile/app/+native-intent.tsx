/**
 * Maps every incoming system link (custom scheme, and later https universal links) onto a route that
 * really exists in the app, so a malformed or web-only link never lands on +not-found.
 *
 * expo-router passes the FULL url string, not just a path:
 *   oceanmall://shop/iphone-15      (custom scheme: host + path is the path)
 *   oceanmall:///                   (the root url of every normal launch, with initial: true)
 *   https://host/shop/iphone-15?x=1 (universal link: only path + query count)
 * We normalise that to "/path?query", apply the table below, and fall back to "/" (home).
 */

// Web storefront and app spellings of a detail page: first segment -> app route (one slug follows).
const DETAIL_ROUTES = new Map([
  ['shop', '/product'],
  ['product', '/product'],
  ['categories', '/category'],
  ['category', '/category'],
  ['collections', '/collection'],
  ['collection', '/collection'],
  ['order', '/order'],
]);

// Pages without a slug that can open cold. Their query string is kept (?q=, ?brand=, ?status=).
const PAGE_ROUTES = new Map([
  ['search', '/search'],
  ['cart', '/cart'],
  ['shop', '/shop'],
  ['promo', '/promo'],
  ['featured', '/featured'],
  ['account', '/account'],
  ['orders', '/orders'],
  ['notifications', '/notifications'],
]);

// Web account paths that have an app equivalent.
const ALIASES = new Map([
  ['account/orders', '/orders'],
  ['account/notifications', '/notifications'],
]);

// Everything else (/categories index, /login, /register, /checkout/*, web-only pages, unknown) -> '/'.
const HOME = '/';
const SCHEME = /^([a-z][a-z0-9+.-]*):\/\//i;
const SLUG = /^[\w.~%-]+$/;

function splitUrl(input: string): { pathname: string; search: string } {
  let rest = input.trim();
  const scheme = SCHEME.exec(rest);

  if (scheme) {
    rest = rest.slice(scheme[0].length);

    if (/^https?$/i.test(scheme[1])) {
      // Drop user:pass@host:port, keep /path?query.
      const start = rest.search(/[/?#]/);
      rest = start === -1 ? '' : rest.slice(start);
    } else {
      // Custom scheme: host + path IS the path. Expo Go dev links wrap it as host/--/path.
      const expoGo = rest.indexOf('/--/');
      if (expoGo !== -1) {
        rest = rest.slice(expoGo + 3);
      }
    }
  }

  rest = rest.split('#')[0] ?? '';
  const queryStart = rest.indexOf('?');

  return {
    pathname: queryStart === -1 ? rest : rest.slice(0, queryStart),
    search: queryStart === -1 ? '' : rest.slice(queryStart),
  };
}

export function mapSystemPath(input: string): string {
  const { pathname, search } = splitUrl(input);
  const segments = pathname.split('/').filter(Boolean);
  const key = segments.map((segment) => segment.toLowerCase());

  if (segments.length === 0) {
    return HOME;
  }

  if (segments.length === 1) {
    const page = PAGE_ROUTES.get(key[0]);
    return page ? `${page}${search}` : HOME;
  }

  if (segments.length === 2) {
    const alias = ALIASES.get(key.join('/'));
    if (alias) {
      return `${alias}${search}`;
    }

    const detail = DETAIL_ROUTES.get(key[0]);
    return detail && SLUG.test(segments[1]) ? `${detail}/${segments[1]}` : HOME;
  }

  return HOME;
}

export function redirectSystemPath({ path, initial }: { path: string; initial: boolean }): string {
  try {
    const mapped = mapSystemPath(path);

    if (__DEV__) {
      console.log(`[native-intent] ${initial ? 'initial' : 'link'} ${path} -> ${mapped}`);
    }

    return mapped;
  } catch {
    return HOME;
  }
}
