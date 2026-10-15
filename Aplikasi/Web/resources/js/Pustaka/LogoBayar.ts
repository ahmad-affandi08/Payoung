import type { MetodeBayarSnap } from '@/Tipe/TagihanLangganan';

/** Kata kunci nama bank pada label atau kode channel (`VIRTUAL_ACCOUNT_BNI`) -> kode berkas logo di `Aset/Bayar`. */
const KATA_BANK: [string, RegExp][] = [
    ['bni', /\bbni\b/],
    ['bri', /\bbri\b/],
    ['mandiri', /mandiri/],
    ['bsi', /\bbsi\b|syariah indonesia/],
    ['permata', /permata/],
    ['cimb', /cimb/],
    ['bca', /\bbca\b/],
    ['danamon', /danamon/],
    ['maybank', /maybank/],
    ['btn', /\bbtn\b|tabungan negara/],
    ['bjb', /\bbjb\b/],
    ['sinarmas', /sinarmas/],
];

/**
 * Kode logo cara bayar: `qris`, `dana`, `shopeepay`, atau kode bank (`bni`, `bri`, ...). Bank tidak dikenal = `bank`,
 * yang tampil sebagai lencana teks. Nama bank datang dari isian operator di konsol, jadi dicocokkan lewat kata kunci,
 * bukan daftar kode yang dianggap pasti.
 */
export function AmbilKodeLogo(metode: MetodeBayarSnap, teks: string): string {
    if (metode === 'Qris') {
        return 'qris';
    }

    if (metode === 'Dana') {
        return 'dana';
    }

    if (metode === 'ShopeePay') {
        return 'shopeepay';
    }

    const bersih = teks.toLowerCase().replaceAll('_', ' ');

    return KATA_BANK.find(([, pola]) => pola.test(bersih))?.[0] ?? 'bank';
}

/** Lencana teks cadangan bila berkas logo belum ada: huruf awal nama (maks. 4). */
export function AmbilSingkatan(teks: string): string {
    const nama = teks.replace(/^virtual account\s+/i, '').trim();

    return nama.slice(0, 4).toUpperCase();
}
