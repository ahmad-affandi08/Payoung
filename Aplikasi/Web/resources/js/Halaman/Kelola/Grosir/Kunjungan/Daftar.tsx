import { Link } from '@inertiajs/react';

import { AlamatGrosir, HalamanGrosir } from '@/Komponen/Grosir/BagianDokumenGrosir';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatDurasi, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { BarisKunjunganSales, HasilKunjungan, Opsi, PropsDaftarKunjunganSales } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/kunjungan`;

/** Jenis label hasil kunjungan: pesanan = sukses, toko tutup = peringatan, lainnya netral. Teks selalu tampil. */
export function AmbilJenisHasilKunjungan(hasil: HasilKunjungan): 'sukses' | 'peringatan' | 'netral' {
    switch (hasil) {
        case 'PesananDibuat':
            return 'sukses';
        case 'TokoTutup':
            return 'peringatan';
        default:
            return 'netral';
    }
}

/** Tautan peta untuk koordinat check-in (string desimal apa adanya, tidak diubah ke number). */
export function BuatTautanPeta(latitude: string | null, longitude: string | null): string | null {
    return latitude === null || longitude === null ? null : `https://www.google.com/maps?q=${latitude},${longitude}`;
}

const kolom: KolomTabel<BarisKunjunganSales>[] = [
    {
        id: 'MasukPada',
        accessorKey: 'MasukPada',
        header: 'Waktu masuk',
        meta: { label: 'Waktu masuk', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.MasukPada),
    },
    {
        id: 'Salesman',
        header: 'Salesman',
        enableSorting: false,
        meta: { label: 'Salesman', prioritas: 'penting' },
        cell: ({ row }) => <span className="break-words">{row.original.NamaSalesman}</span>,
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row }) => <span className="break-words">{row.original.NamaPelanggan}</span>,
    },
    {
        id: 'Hasil',
        header: 'Hasil',
        enableSorting: false,
        meta: { label: 'Hasil kunjungan', prioritas: 'penting' },
        cell: ({ row: { original: k } }) => (
            <span className="flex flex-col gap-1">
                <span>
                    <LabelStatus jenis={AmbilJenisHasilKunjungan(k.Hasil)} teks={k.LabelHasil} />
                </span>
                {k.Catatan !== null ? <span className="break-words text-teks-sekunder">{k.Catatan}</span> : null}
            </span>
        ),
    },
    {
        id: 'Durasi',
        header: 'Durasi',
        enableSorting: false,
        meta: { label: 'Durasi kunjungan', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) =>
            row.original.DurasiMenit === null ? (
                <span className="text-teks-sekunder">Belum check-out</span>
            ) : (
                FormatDurasi(row.original.DurasiMenit * 60)
            ),
    },
    {
        id: 'Pesanan',
        header: 'Pesanan',
        enableSorting: false,
        meta: { label: 'Pesanan grosir', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: k } }) =>
            k.UuidPesananGrosir !== null && k.NomorPesananGrosir !== null ? (
                <Link
                    href={`${AlamatGrosir}/pesanan/${k.UuidPesananGrosir}`}
                    className="font-mono font-semibold break-all text-brand underline"
                >
                    {k.NomorPesananGrosir}
                </Link>
            ) : (
                <span className="text-teks-sekunder">—</span>
            ),
    },
    {
        id: 'Lokasi',
        header: 'Lokasi',
        enableSorting: false,
        meta: { label: 'Lokasi check-in', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: k } }) => {
            const tautan = BuatTautanPeta(k.Latitude, k.Longitude);

            return tautan === null ? (
                <span className="text-teks-sekunder">Tanpa lokasi</span>
            ) : (
                <a href={tautan} target="_blank" rel="noreferrer" className="text-brand underline">
                    Buka peta
                    {k.AkurasiMeter !== null ? (
                        <span className="text-teks-sekunder"> (±{String(k.AkurasiMeter)} m)</span>
                    ) : null}
                </a>
            );
        },
    },
];

function BuatSaringKunjungan(opsiSalesman: Opsi[], opsiHasil: Opsi[]): DefinisiSaring[] {
    return [
        {
            id: 'Salesman',
            label: 'Salesman',
            jenis: 'pilihanBanyak',
            opsi: opsiSalesman.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Hasil',
            label: 'Hasil',
            jenis: 'pilihanBanyak',
            opsi: opsiHasil.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
    ];
}

/**
 * Kunjungan salesman (Modul Salesman bagian 1, §9.7). Dicatat dari aplikasi salesman saat check-out, bisa offline;
 * back-office hanya membaca. Pesanan yang diambil saat kunjungan masuk sebagai draf pesanan grosir.
 */
export default function HalamanDaftarKunjunganSales({
    Kunjungan,
    OpsiSalesman,
    OpsiHasil,
    Izin,
}: PropsDaftarKunjunganSales) {
    return (
        <HalamanGrosir
            judul="Kunjungan salesman"
            keterangan="Kunjungan salesman: waktu, hasil, pesanan, dan lokasi check-in. Pesanan lapangan masuk sebagai draf di pesanan grosir."
            izin={Izin}
            objek="kunjungan salesman"
        >
            <TabelData
                id="grosir-kunjungan"
                label="Daftar kunjungan salesman"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Kunjungan }}
                ambilIdBaris={(k) => k.Uuid}
                urutBawaan="-MasukPada"
                cari="Cari nama pelanggan"
                saring={BuatSaringKunjungan(OpsiSalesman, OpsiHasil)}
                ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true }}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kunjungan. Kunjungan muncul di sini setelah salesman check-out dari aplikasi.',
                }}
            />
        </HalamanGrosir>
    );
}
