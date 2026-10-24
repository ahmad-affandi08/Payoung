/**
 * Logika murni wizard "Buat kampanye" (CRM-07): daftar langkah, pemetaan kunci galat server ke langkah, dan validasi
 * klien per langkah. Wizard hanya menyimpan DRAF; menjadwalkan dan mengirim tetap di halaman rincian kampanye.
 */
import type { LangkahWizard } from '@/Komponen/Formulir/Wizard/TipeWizard';

export type KunciLangkahKampanye = 'Kampanye' | 'Penerima' | 'Pesan' | 'Tinjau';
export type LangkahKampanye = LangkahWizard<KunciLangkahKampanye>;

export const urutanLangkahKampanye: KunciLangkahKampanye[] = ['Kampanye', 'Penerima', 'Pesan', 'Tinjau'];

/** Sapaan awal isi pesan; pesan yang hanya berisi sapaan ini belum layak dikirim ke pelanggan. */
export const SapaanAwalKampanye = 'Halo {nama}, ';

export const langkahKampanye: LangkahKampanye[] = [
    {
        Kunci: 'Kampanye',
        Label: 'Nama & kanal',
        Keterangan: 'Beri nama kampanye supaya mudah dicari nanti, lalu pilih kanal pengirimannya.',
    },
    {
        Kunci: 'Penerima',
        Label: 'Penerima',
        Keterangan:
            'Pilih siapa yang menerima. Hanya pelanggan aktif yang menyetujui kabar promosi. Filter yang dikosongkan berarti semua.',
    },
    {
        Kunci: 'Pesan',
        Label: 'Isi pesan',
        Keterangan: 'Tulis pesan yang akan dikirim. Pelanggan membaca ini di WhatsApp mereka.',
    },
    {
        Kunci: 'Tinjau',
        Label: 'Tinjau & simpan',
        Keterangan:
            'Periksa ringkasan di bawah. Pesan baru disimpan sebagai draf; mengirim atau menjadwalkan dilakukan setelahnya.',
    },
];

export function PetakanGalatKampanyeKeLangkah(kunci: string): KunciLangkahKampanye | null {
    if (kunci === 'Nama' || kunci === 'Kanal') {
        return 'Kampanye';
    }

    if (kunci === 'Segmen' || kunci.startsWith('Segmen.')) {
        return 'Penerima';
    }

    return kunci === 'Isi' || kunci === 'Judul' ? 'Pesan' : null;
}

export function HitungLangkahKampanyeBergalat(galat: Record<string, string | undefined>): KunciLangkahKampanye[] {
    const ada = new Set<KunciLangkahKampanye>();

    for (const [kunci, pesan] of Object.entries(galat)) {
        const langkah = pesan ? PetakanGalatKampanyeKeLangkah(kunci) : null;

        if (langkah !== null) {
            ada.add(langkah);
        }
    }

    return urutanLangkahKampanye.filter((kunci) => ada.has(kunci));
}

export function AmbilGalatLangkahKampanye(
    galat: Record<string, string | undefined>,
    kunci: KunciLangkahKampanye,
): Record<string, string | undefined> {
    return Object.fromEntries(
        Object.entries(galat).filter(([isi, pesan]) => Boolean(pesan) && PetakanGalatKampanyeKeLangkah(isi) === kunci),
    );
}

export type IsianKampanye = { Nama: string; Isi: string };

/** Galat klien satu langkah (aturan `SimpanKampanyePesanPermintaan`: nama maks 120, isi 10 sampai `maksIsi` karakter). */
export function PeriksaLangkahKampanye(
    kunci: KunciLangkahKampanye,
    isian: IsianKampanye,
    maksIsi: number,
): Record<string, string> {
    const galat: Record<string, string> = {};

    if (kunci === 'Kampanye') {
        if (isian.Nama.trim() === '') {
            galat.Nama = 'Isi nama kampanye dulu.';
        } else if (isian.Nama.trim().length > 120) {
            galat.Nama = 'Nama kampanye maksimal 120 karakter.';
        }
    }

    if (kunci === 'Pesan') {
        const isi = isian.Isi.trim();

        if (isi.length < 10) {
            galat.Isi = 'Isi pesan minimal 10 karakter.';
        } else if (isi === SapaanAwalKampanye.trim()) {
            galat.Isi = 'Lengkapi pesannya. Baru ada sapaan, belum ada isi untuk pelanggan.';
        } else if (isi.length > maksIsi) {
            galat.Isi = `Isi pesan maksimal ${String(maksIsi)} karakter.`;
        }
    }

    return galat;
}

/** Contoh bacaan pesan untuk satu pelanggan; {toko} diganti server saat pengiriman. */
export function ContohIsiKampanye(isi: string, namaContoh = 'Budi'): string {
    return isi.replaceAll('{nama}', namaContoh);
}
