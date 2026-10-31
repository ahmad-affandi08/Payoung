import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { BacaDraf, BuatKunciDraf, HapusDraf, TulisDraf, type IsiDraf } from '@/Pustaka/DrafLokal';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const JedaSimpanMs = 500;

type OpsiDrafLokal<T> = {
    /** Nama draf, unik per form (misal `transfer-stok`). Dipasangkan dengan tenant dan pengguna. */
    nama: string;
    /** Isian form saat ini, hanya yang diketik pengguna (tanpa tanggal bawaan, Uuid klien, atau data turunan). */
    nilai: T;
    /** True bila form masih kosong (belum ada yang layak disimpan). */
    kosong: (nilai: T) => boolean;
    /** False untuk mode ubah draf yang sudah ada di server (datanya sudah aman di sana). */
    aktif?: boolean;
};

/**
 * Draf otomatis form dokumen panjang. Menyimpan isian ke peramban setelah jeda singkat, menawarkan "Lanjutkan" saat form
 * dibuka lagi dalam keadaan kosong, dan terhapus saat dokumen berhasil disimpan (`Hapus` di `onSuccess`). Selama tawaran
 * draf lama masih tampil, isian baru belum disimpan supaya draf lama tidak tertimpa sebelum pengguna memilih.
 */
export function useDrafLokal<T>({ nama, nilai, kosong, aktif = true }: OpsiDrafLokal<T>) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const kunci = BuatKunciDraf(props.TenantAktif?.Slug ?? '-', props.Pengguna?.Uuid ?? '-', nama);
    const [tawaran, AturTawaran] = useState<IsiDraf<T> | null>(() => {
        if (!aktif) {
            return null;
        }

        const draf = BacaDraf<T>(kunci);

        return draf !== null && !kosong(draf.Data) ? draf : null;
    });
    const terakhir = useRef(nilai);
    const dihapus = useRef(false);
    const tawaranAda = tawaran !== null;

    const Simpan = useCallback(() => {
        if (!aktif || dihapus.current || tawaranAda || kosong(terakhir.current)) {
            return;
        }

        TulisDraf(kunci, terakhir.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [aktif, kunci, tawaranAda]);

    // Selalu simpan isian terbaru di ref (dibaca penyimpan terjadwal dan saat form ditinggalkan). Dideklarasikan lebih
    // dulu dari efek penyimpan supaya nilainya sudah mutakhir saat efek itu berjalan.
    useEffect(() => {
        terakhir.current = nilai;
    });

    useEffect(() => {
        const penunda = window.setTimeout(Simpan, JedaSimpanMs);

        return () => window.clearTimeout(penunda);
    }, [nilai, Simpan]);

    // Simpan sisa ketikan terakhir saat tab ditutup atau form ditinggalkan.
    useEffect(() => {
        window.addEventListener('pagehide', Simpan);

        return () => {
            window.removeEventListener('pagehide', Simpan);
            Simpan();
        };
    }, [Simpan]);

    /** Dipanggil saat dokumen berhasil disimpan: draf dihapus dan tidak ditulis lagi. */
    const Hapus = useCallback(() => {
        dihapus.current = true;
        HapusDraf(kunci);
        AturTawaran(null);
    }, [kunci]);

    /** Ambil isi draf lama (untuk dipasang ke form), lalu penyimpanan otomatis berjalan lagi. */
    const Pulihkan = useCallback((): T | null => {
        const isi = tawaran?.Data ?? null;

        AturTawaran(null);

        return isi;
    }, [tawaran]);

    const Buang = useCallback(() => {
        HapusDraf(kunci);
        AturTawaran(null);
    }, [kunci]);

    return { tawaran, Pulihkan, Buang, Hapus };
}
