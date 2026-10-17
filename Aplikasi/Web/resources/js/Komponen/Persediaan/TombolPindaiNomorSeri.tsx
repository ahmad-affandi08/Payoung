import { useState } from 'react';

import TombolPindaiKamera from '@/Komponen/Katalog/TombolPindaiKamera';

/** Panjang maksimal satu nomor seri (sama dengan `PanjangMaksimalNomorSeri` di BidangNomorSeri). */
const PANJANG_MAKSIMAL = 100;

type PropsTombolPindaiNomorSeri = {
    /** Nama aksesibel tombol, misal "Pindai nomor seri Ponsel X". */
    label: string;
    /** Nomor yang sudah ada di isian (tambah) atau yang sudah dicentang (centang). */
    daftar: readonly string[];
    saatBerubah: (daftar: string[]) => void;
    /** Batas jumlah nomor pada mode tambah. */
    maksimal?: number;
    /**
     * Mode centang: hanya nomor di daftar ini yang bisa dimasukkan (nomor yang masih bisa diretur/dikirim). Nomor di
     * luar daftar ditolak dengan pesan, bukan ditambahkan. Tanpa ini = mode tambah (nomor bebas).
     */
    tersedia?: readonly string[];
    disabled?: boolean;
    /** Tombol ikon saja (bawaan: tombol bertulis, karena letaknya di bawah isian). */
    ikonSaja?: boolean;
};

/**
 * Pindai nomor seri/IMEI dengan kamera, **berkelanjutan**: kamera tetap terbuka sampai Selesai, tiap nomor yang terbaca
 * masuk ke daftar. Nomor yang sudah ada diabaikan dengan pesan (tidak pernah ganda), nomor di luar daftar tersedia
 * ditolak pada mode centang, dan hasil tiap pindaian dijelaskan di dalam dialog.
 */
export default function TombolPindaiNomorSeri({
    label,
    daftar,
    saatBerubah,
    maksimal,
    tersedia,
    disabled = false,
    ikonSaja = false,
}: PropsTombolPindaiNomorSeri) {
    const [umpanBalik, AturUmpanBalik] = useState<string | null>(null);

    const Proses = (terbaca: string) => {
        const kode = terbaca.trim();
        // Isian bebas bisa memuat baris kosong di ujung (pengguna baru menekan Enter): jangan ikut dibawa.
        const bersih = daftar.map((nomor) => nomor.trim()).filter((nomor) => nomor !== '');
        const Sama = (nomor: string) => nomor.toLowerCase() === kode.toLowerCase();

        if (kode === '') {
            return;
        }

        if (kode.length > PANJANG_MAKSIMAL) {
            AturUmpanBalik(`Kode terlalu panjang (maksimal ${String(PANJANG_MAKSIMAL)} karakter), tidak dimasukkan.`);

            return;
        }

        if (bersih.some(Sama)) {
            AturUmpanBalik(`${kode} sudah ada, tidak ditambahkan lagi.`);

            return;
        }

        if (tersedia !== undefined) {
            const cocok = tersedia.find(Sama);

            if (cocok === undefined) {
                AturUmpanBalik(`${kode} tidak termasuk nomor seri yang bisa dipilih di sini.`);

                return;
            }

            saatBerubah([...bersih, cocok]);
            AturUmpanBalik(`${cocok} dicentang (${String(bersih.length + 1)} nomor).`);

            return;
        }

        if (maksimal !== undefined && bersih.length >= maksimal) {
            AturUmpanBalik(`Sudah mencapai batas ${maksimal.toLocaleString('id-ID')} nomor seri.`);

            return;
        }

        saatBerubah([...bersih, kode]);
        AturUmpanBalik(`${kode} ditambahkan (${String(bersih.length + 1)} nomor).`);
    };

    return (
        <TombolPindaiKamera
            berkelanjutan
            jenisKode="Seri"
            label={label}
            ikonSaja={ikonSaja}
            disabled={disabled}
            saatTerbaca={Proses}
            umpanBalik={umpanBalik}
        />
    );
}
