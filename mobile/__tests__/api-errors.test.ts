/// <reference types="node" />
import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';
import {
  ApiError,
  LEGACY_CAPABILITIES,
  api,
  apiFieldErrors,
  errorMessage,
  findPlacedOrder,
} from '@/lib/api';
import { NetworkError, TimeoutError } from '@/lib/network';

const originalFetch = globalThis.fetch;

afterEach(() => {
  globalThis.fetch = originalFetch;
});

describe('api error localization & contracts', () => {
  it('preserves 429 Indonesian WIB quota copy and falls back on framework throttle', () => {
    const quotaMsg =
      'Batas kuota pencarian destinasi harian sudah tercapai. Silakan coba lagi setelah 27 Nov 2026 00:00 WIB.';
    const quotaErr = new ApiError(quotaMsg, 429, {
      message: quotaMsg,
      data: [],
      retry_at_wib: '27 Nov 2026 00:00',
    });
    assert.equal(errorMessage(quotaErr), quotaMsg);

    const frameworkThrottle = new ApiError(
      'Too Many Attempts.',
      429,
      { message: 'Too Many Attempts.' },
      30
    );
    assert.equal(
      errorMessage(frameworkThrottle),
      'Terlalu banyak percobaan. Coba lagi dalam 30 detik.'
    );
  });

  it('preserves 502 destination outage message and falls back for non-JSON 502', () => {
    const outageErr = new ApiError('Pencarian destinasi sedang tidak tersedia.', 502, {
      message: 'Pencarian destinasi sedang tidak tersedia.',
      data: [],
    });
    assert.equal(errorMessage(outageErr), 'Pencarian destinasi sedang tidak tersedia.');

    const html502 = new ApiError('', 502, {});
    assert.equal(
      errorMessage(html502),
      'Lagi ada gangguan di sistem kami. Coba lagi beberapa saat, ya.'
    );
  });

  it('handles TimeoutError, NetworkError, and 422 pin_point/destination field errors', () => {
    assert.equal(errorMessage(new TimeoutError()), 'Koneksi lambat. Coba lagi, ya.');
    assert.equal(errorMessage(new NetworkError()), 'Kamu sedang offline');

    const validationErr = new ApiError('Validation failed', 422, {
      errors: {
        pin_point: ['Format pin point tidak valid.'],
        destination_id: ['The destination field is required.'],
      },
    });
    const fields = apiFieldErrors(validationErr);
    assert.deepEqual(fields.pin_point, ['Format titik lokasi tidak valid.']);
    assert.deepEqual(fields.destination_id, ['The kecamatan field is required.']);
    assert.equal(errorMessage(validationErr), 'Format titik lokasi tidak valid.');
  });

  it('exposes LEGACY_CAPABILITIES defaults', () => {
    assert.deepEqual(LEGACY_CAPABILITIES, {
      destination_search: true,
      requires_destination: true,
      requires_coordinates: false,
    });
  });

  it('api() parses Retry-After on 429 and throws ApiError on 502', async () => {
    globalThis.fetch = (async () =>
      new Response(JSON.stringify({ message: 'Too Many Attempts.' }), {
        status: 429,
        headers: {
          'Content-Type': 'application/json',
          'Retry-After': '45',
        },
      })) as typeof fetch;

    await assert.rejects(
      () => api('/checkout/destinations?q=bandung'),
      (err: unknown) => {
        if (!(err instanceof ApiError)) {
          return false;
        }
        assert.equal(err.status, 429);
        assert.equal(err.retryAfter, 45);
        assert.equal(errorMessage(err), 'Terlalu banyak percobaan. Coba lagi dalam 45 detik.');
        return true;
      }
    );

    globalThis.fetch = (async () =>
      new Response('Bad Gateway', {
        status: 502,
        headers: { 'Content-Type': 'text/plain' },
      })) as typeof fetch;

    await assert.rejects(
      () => api('/checkout/destinations?q=jakarta'),
      (err: unknown) => {
        if (!(err instanceof ApiError)) {
          return false;
        }
        assert.equal(err.status, 502);
        return true;
      }
    );
  });

  it('findPlacedOrder() queries /orders without ?status=unpaid and recovers COD orders', async () => {
    const urls: string[] = [];
    const submittedAt = Date.parse('2026-11-26T08:10:00Z');

    globalThis.fetch = (async (input: RequestInfo | URL) => {
      const url = String(input);
      urls.push(url);
      if (url.endsWith('/cart')) {
        return new Response(
          JSON.stringify({
            data: {
              id: 1,
              currency: 'IDR',
              lines: [],
              totals: { subtotal: 0, discount: 0, tax: 0, total: 0 },
            },
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        );
      }
      if (url.endsWith('/orders')) {
        return new Response(
          JSON.stringify({
            data: [
              {
                id: 77,
                number: 'ORD-COD-77',
                status: 'processing',
                payment_status: 'unpaid',
                amount: 85000,
                created_at: '2026-11-26T08:10:03Z',
                payment_driver: 'manual',
              },
            ],
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        );
      }
      return new Response('{}', { status: 404 });
    }) as typeof fetch;

    const found = await findPlacedOrder(submittedAt);
    assert.deepEqual(found, { state: 'placed', number: 'ORD-COD-77' });
    assert.equal(
      urls.some((u) => u.endsWith('/api/v1/orders')),
      true
    );
    assert.equal(
      urls.some((u) => u.includes('status=unpaid')),
      false
    );
  });
});
