/**
 * "Cara bayar" for virtual accounts and QRIS, shown as an accordion on the payment panel.
 *
 * The menu paths follow the banks' own wording where it is published (BCA's payment guide) and
 * otherwise what the gateway-side guides agree on. Banks rename menus between app versions, so a
 * generic "ATM atau internet banking" entry closes every bank's list, and the steps avoid naming
 * screens we cannot be sure of. Re-check them against the banks' current pages before each release.
 */

export type PaymentGuide = {
  title: string;
  steps: string[];
};

export type BankKey = 'BCA' | 'BRI' | 'MANDIRI' | 'BNI' | 'PERMATA';

/**
 * Maps the gateway's `bank_code` to one of the banks we have steps for. The backend falls back
 * from bank_code to the bank's display name ("BANK CENTRAL ASIA"), so the full names count too.
 */
export function bankKeyFor(bankCode?: string | null): BankKey | null {
  const code = (bankCode ?? '').trim().toUpperCase();
  if (code === '') {
    return null;
  }
  if (/\bBCA\b|CENTRAL ASIA/.test(code)) {
    return 'BCA';
  }
  if (/\bBRI\b|\bBRIVA\b|RAKYAT INDONESIA/.test(code)) {
    return 'BRI';
  }
  if (/MANDIRI/.test(code)) {
    return 'MANDIRI';
  }
  if (/\bBNI\b|NEGARA INDONESIA/.test(code)) {
    return 'BNI';
  }
  if (/PERMATA/.test(code)) {
    return 'PERMATA';
  }

  return null;
}

/** The sentence fragment for the number the customer types, with the number itself when known. */
function nomor(va: string | null | undefined, label = 'nomor virtual account'): string {
  const value = (va ?? '').trim();

  return value !== '' ? value : label;
}

const GENERIC_TITLE = 'ATM atau internet banking';

function genericVa(va: string | null | undefined, bank?: string): PaymentGuide {
  const target = bank ? `Virtual Account ${bank}` : 'Virtual Account';

  return {
    title: GENERIC_TITLE,
    steps: [
      'Masuk ke ATM, aplikasi m-banking, atau internet banking.',
      `Cari menu Transfer atau Pembayaran, lalu pilih ${target}. Nama menu bisa berbeda di tiap bank.`,
      `Masukkan ${nomor(va)}.`,
      'Cek nominal dan nama tujuan, lalu konfirmasi dengan PIN atau token.',
    ],
  };
}

/** Steps for paying a virtual account from the customer's own bank. */
export function guideFor(
  bankCode: string | null | undefined,
  vaNumber: string | null | undefined
): PaymentGuide[] {
  const va = nomor(vaNumber);

  switch (bankKeyFor(bankCode)) {
    case 'BCA':
      return [
        {
          title: 'm-BCA (BCA mobile)',
          steps: [
            'Buka aplikasi BCA mobile, pilih m-BCA, lalu masuk dengan kode akses.',
            'Pilih m-Transfer, lalu BCA Virtual Account.',
            `Masukkan ${va}, lalu tekan Send.`,
            'Cek detail dan nominal, lalu masukkan PIN m-BCA.',
          ],
        },
        {
          title: 'myBCA',
          steps: [
            'Masuk ke myBCA, lalu pilih Transfer.',
            'Pilih Virtual Account.',
            `Masukkan ${va}, lalu pilih Lanjut.`,
            'Pilih sumber dana dan cek nominalnya, lalu masukkan PIN myBCA.',
          ],
        },
        {
          title: 'KlikBCA',
          steps: [
            'Masuk ke KlikBCA, lalu pilih Transfer Dana.',
            'Pilih Transfer ke BCA Virtual Account.',
            `Masukkan ${va}, lalu klik Lanjutkan.`,
            'Cek detail, masukkan respon KeyBCA Appli 1, lalu klik Kirim.',
          ],
        },
        {
          title: 'ATM BCA',
          steps: [
            'Masukkan kartu ATM dan PIN BCA.',
            'Pilih Transaksi Lainnya, lalu Transfer, lalu Ke Rek BCA Virtual Account.',
            `Masukkan ${va}, lalu tekan Benar.`,
            'Cek detail transaksi, lalu pilih Ya.',
          ],
        },
      ];

    case 'BRI':
      return [
        {
          title: 'BRImo',
          steps: [
            'Masuk ke aplikasi BRImo.',
            'Pilih menu BRIVA. Kalau belum terlihat di beranda, cari lewat menu Tagihan.',
            `Masukkan ${va}.`,
            'Cek nominal dan nama tujuan, lalu masukkan PIN untuk membayar.',
          ],
        },
        {
          title: 'ATM BRI',
          steps: [
            'Masukkan kartu ATM dan PIN BRI.',
            'Pilih Transaksi Lain, lalu Pembayaran, lalu Lainnya, lalu BRIVA.',
            `Masukkan ${va}, lalu tekan Benar.`,
            'Cek detail transaksi, lalu pilih Ya.',
          ],
        },
        {
          title: 'Internet banking BRI',
          steps: [
            'Masuk ke internet banking BRI.',
            'Pilih Pembayaran, lalu BRIVA.',
            `Masukkan ${va}, lalu klik Kirim.`,
            'Cek nominal, lalu selesaikan dengan password dan mToken.',
          ],
        },
      ];

    case 'MANDIRI':
      return [
        {
          title: "Livin' by Mandiri",
          steps: [
            "Buka Livin' by Mandiri dan masuk.",
            'Pilih Bayar, lalu cari penyedia jasa atau pilih Multipayment.',
            `Masukkan ${va}.`,
            "Cek nominal dan nama tujuan, lalu konfirmasi dengan PIN Livin'.",
          ],
        },
        genericVa(vaNumber, 'Mandiri'),
      ];

    case 'BNI':
      return [
        {
          title: 'BNI Mobile Banking',
          steps: [
            'Buka BNI Mobile Banking dan masuk.',
            'Pilih Transfer, lalu Virtual Account Billing, lalu pilih rekening debit.',
            `Masukkan ${va} sebagai input baru.`,
            'Cek tagihan di layar konfirmasi, lalu masukkan password transaksi.',
          ],
        },
        {
          title: 'ATM BNI',
          steps: [
            'Masukkan kartu ATM dan PIN BNI.',
            'Pilih Menu Lainnya, lalu Transfer, lalu pilih jenis rekening.',
            `Pilih Virtual Account Billing, lalu masukkan ${va}.`,
            'Cek tagihan yang tampil, lalu konfirmasi.',
          ],
        },
        genericVa(vaNumber, 'BNI'),
      ];

    case 'PERMATA':
      return [
        {
          title: 'PermataMobile X',
          steps: [
            'Buka PermataMobile X dan masuk.',
            'Pilih Pembayaran Tagihan, lalu Virtual Account.',
            `Masukkan ${va}.`,
            'Cek nominal, lalu selesaikan dengan PIN atau token.',
          ],
        },
        {
          title: 'ATM Permata',
          steps: [
            'Masukkan kartu ATM dan PIN Permata.',
            'Pilih Transaksi Lainnya, lalu Pembayaran, lalu Pembayaran Lainnya, lalu Virtual Account.',
            `Masukkan ${va}, lalu tekan Benar.`,
            'Cek detail transaksi, pilih rekening sumber, lalu konfirmasi.',
          ],
        },
        genericVa(vaNumber, 'Permata'),
      ];

    default:
      return [
        {
          title: 'Aplikasi m-banking',
          steps: [
            'Buka aplikasi m-banking dan masuk.',
            'Pilih menu Transfer atau Bayar, lalu pilih Virtual Account.',
            `Masukkan ${va}.`,
            'Cek nominal dan nama tujuan, lalu konfirmasi dengan PIN atau token.',
          ],
        },
        genericVa(vaNumber),
      ];
  }
}

/** Steps for paying a QRIS code. */
export function qrisGuide(): PaymentGuide {
  return {
    title: 'Bayar dengan QRIS',
    steps: [
      'Buka aplikasi m-banking atau e-wallet, pilih Scan atau Bayar, lalu arahkan ke kode QR.',
      'Cek nominal dan nama tujuan di layar.',
      'Konfirmasi pembayaran. Status pesanan diperbarui otomatis.',
    ],
  };
}
