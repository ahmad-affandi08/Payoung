import { Link } from '@inertiajs/react';

import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { ButirPilihDokumen } from '@/Tipe/PilihDokumen';

const BATAS_TAMPIL = 3;

/**
 * Saran lembut di atas form dokumen baru (D-287): bila ada dokumen hulu yang menunggu (permintaan yang sudah disetujui,
 * permintaan stok, penawaran yang diterima), tawarkan memulai dari dokumen itu supaya isinya terbawa. Tidak memblokir:
 * dokumen tanpa hulu tetap sah. Kosong = tidak tampil. Tautan tiap butir sudah dibuat server.
 */
export default function SaranDokumenHulu({
    butir,
    judulSatu,
    judulBanyak,
    keterangan,
    labelAksi,
    tautanSemua,
}: {
    butir: ButirPilihDokumen[];
    /** Judul bila hanya satu dokumen menunggu. */
    judulSatu: string;
    /** Judul bila lebih dari satu, `{n}` diganti jumlahnya. */
    judulBanyak: string;
    keterangan: string;
    /** Awalan tautan, misal "Mulai dari" menjadi "Mulai dari PNW/2611/0001". */
    labelAksi: string;
    /** Alamat daftar lengkap, untuk dokumen yang tidak muat di saran (lebih dari tiga). */
    tautanSemua: string;
}) {
    if (butir.length === 0) {
        return null;
    }

    return (
        <Pemberitahuan
            jenis="info"
            judul={butir.length === 1 ? judulSatu : judulBanyak.replace('{n}', String(butir.length))}
        >
            {keterangan}
            <span className="mt-1 flex flex-col gap-0.5">
                {butir.slice(0, BATAS_TAMPIL).map((b) => (
                    <span key={b.Uuid}>
                        <Link href={b.Tautan}>
                            {labelAksi} {b.Nomor}
                        </Link>{' '}
                        | {b.Pihak} | {FormatTanggal(b.Tanggal)}
                    </span>
                ))}
                {butir.length > BATAS_TAMPIL ? (
                    <span>
                        <Link href={tautanSemua}>Lihat semua ({String(butir.length)})</Link>
                    </span>
                ) : null}
            </span>
        </Pemberitahuan>
    );
}
