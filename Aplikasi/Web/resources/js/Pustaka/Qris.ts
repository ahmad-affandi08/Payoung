/**
 * F-08 (D-189): pemeriksaan teks QRIS **statis** di peramban, sebelum dikirim ke server. Aturan dan kode galat sama dengan
 * `PemeriksaQris` (PHP) dan `Qris` (Dart, `Paket/Inti`), dijaga vektor bersama `Spesifikasi/VektorUjiQris/QrisDinamis.json`.
 * Server tetap memeriksa ulang; ini hanya memberi tahu pemilik toko lebih cepat dan mencegah teks cacat terkirim.
 */

export type KodeGalatQris =
    'Kosong' | 'BukanQris' | 'CrcTidakValid' | 'BukanStatis' | 'BukanRupiahIndonesia' | 'TanpaMerchant';

export type HasilPeriksaQris =
    { Sah: true; NamaMerchant: string; Kota: string } | { Sah: false; Galat: KodeGalatQris; Pesan: string };

const PESAN: Record<KodeGalatQris, string> = {
    Kosong: 'Kode QRIS kosong.',
    BukanQris: 'Ini bukan kode QRIS. Pastikan gambar berisi QRIS dari bank atau penyedia Anda.',
    CrcTidakValid: 'Kode QRIS rusak atau sudah diubah (checksum tidak cocok). Unggah ulang gambar QRIS asli.',
    BukanStatis: 'QRIS ini sudah berisi nominal (dinamis). Unggah QRIS statis toko Anda.',
    BukanRupiahIndonesia: 'QRIS ini bukan QRIS rupiah Indonesia.',
    TanpaMerchant: 'QRIS ini tidak memuat data merchant. Unggah QRIS dari bank atau penyedia Anda.',
};

/** CRC16/CCITT-FALSE (polinomial 0x1021, awal 0xFFFF), empat digit heksa huruf besar. */
export function HitungCrc16(teks: string): string {
    let crc = 0xffff;

    for (const byte of new TextEncoder().encode(teks)) {
        crc ^= byte << 8;

        for (let i = 0; i < 8; i++) {
            crc = (crc & 0x8000) !== 0 ? ((crc << 1) ^ 0x1021) & 0xffff : (crc << 1) & 0xffff;
        }
    }

    return crc.toString(16).toUpperCase().padStart(4, '0');
}

/** TLV tingkat atas; null bila bukan QRIS atau strukturnya terpotong. */
export function UraiQris(teks: string): [string, string][] | null {
    if (teks.length < 12 || !teks.startsWith('000201')) {
        return null;
    }

    const hasil: [string, string][] = [];
    let i = 0;

    while (i < teks.length) {
        const tag = teks.slice(i, i + 2);
        const panjang = teks.slice(i + 2, i + 4);

        if (
            i + 4 > teks.length ||
            !/^\d{2}$/.test(tag) ||
            !/^\d{2}$/.test(panjang) ||
            i + 4 + Number(panjang) > teks.length
        ) {
            return null;
        }

        hasil.push([tag, teks.slice(i + 4, i + 4 + Number(panjang))]);
        i += 4 + Number(panjang);
    }

    return hasil;
}

function Gagal(galat: KodeGalatQris): HasilPeriksaQris {
    return { Sah: false, Galat: galat, Pesan: PESAN[galat] };
}

/** Memastikan [teks] QRIS statis yang utuh (CRC sah, rupiah Indonesia, ada blok merchant). */
export function PeriksaQris(teks: string): HasilPeriksaQris {
    const isi = teks.trim();

    if (isi === '') {
        return Gagal('Kosong');
    }

    const bagian = UraiQris(isi);
    const terakhir = bagian?.[bagian.length - 1];

    if (bagian === null || terakhir === undefined || terakhir[0] !== '63' || terakhir[1].length !== 4) {
        return Gagal('BukanQris');
    }

    if (HitungCrc16(isi.slice(0, -4)) !== terakhir[1].toUpperCase()) {
        return Gagal('CrcTidakValid');
    }

    const AmbilNilai = (tag: string): string | undefined => bagian.find(([t]) => t === tag)?.[1];

    if (AmbilNilai('01') === '12') {
        return Gagal('BukanStatis');
    }

    if (AmbilNilai('01') !== '11') {
        return Gagal('BukanQris');
    }

    if (AmbilNilai('53') !== '360' || AmbilNilai('58') !== 'ID') {
        return Gagal('BukanRupiahIndonesia');
    }

    if (!bagian.some(([tag]) => Number(tag) >= 26 && Number(tag) <= 51)) {
        return Gagal('TanpaMerchant');
    }

    return { Sah: true, NamaMerchant: AmbilNilai('59') ?? '', Kota: AmbilNilai('60') ?? '' };
}
