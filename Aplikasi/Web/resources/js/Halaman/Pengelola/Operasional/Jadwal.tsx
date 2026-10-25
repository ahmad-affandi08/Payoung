import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { FormatDurasi, FormatUkuranBerkas } from '@/Pustaka/FormatUkuran';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

type Jalan = {
    MulaiPada: string;
    SelesaiPada: string | null;
    Berhasil: boolean | null;
    DurasiMs: number | null;
    Keterangan: string | null;
    Sumber: 'Jadwal' | 'Manual';
};

type StatusJadwal = 'Berhasil' | 'Gagal' | 'Berjalan' | 'TidakSelesai' | 'BelumPernah';

export type BarisJadwal = {
    Perintah: string;
    Ekspresi: string;
    Zona: string;
    Berikutnya: string;
    Status: StatusJadwal;
    Terakhir: Jalan | null;
    Riwayat: Jalan[];
};

export type PropsJadwal = {
    Jadwal: {
        Ringkasan: { Total: number; Gagal: number; Berjalan: number; BelumPernah: number };
        Data: BarisJadwal[];
    };
    Penyimpanan: {
        TotalByte: number;
        JumlahTenant: number;
        Terbesar: { Uuid: string | null; Nama: string; Byte: number; BatasMb: number | null; Persen: number | null }[];
    };
    BolehKelola: boolean;
};

const TEKS_STATUS: Record<StatusJadwal, { teks: string; jenis: 'sukses' | 'bahaya' | 'peringatan' | 'netral' }> = {
    Berhasil: { teks: 'Berhasil', jenis: 'sukses' },
    Gagal: { teks: 'Gagal', jenis: 'bahaya' },
    Berjalan: { teks: 'Berjalan', jenis: 'netral' },
    TidakSelesai: { teks: 'Tidak selesai', jenis: 'peringatan' },
    BelumPernah: { teks: 'Belum pernah jalan', jenis: 'netral' },
};

function BuatKolom(bolehKelola: boolean, sedangMemproses: string | null, Jalankan: (perintah: string) => void) {
    const kolom: KolomTabel<BarisJadwal>[] = [
        {
            id: 'Perintah',
            accessorKey: 'Perintah',
            header: 'Perintah',
            meta: { label: 'Perintah', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: jadwal } }) => (
                <>
                    <span className="font-mono text-label font-semibold text-teks-utama">{jadwal.Perintah}</span>
                    <span className="block font-mono text-keterangan font-normal text-teks-sekunder">
                        {jadwal.Ekspresi} | {jadwal.Zona}
                    </span>
                </>
            ),
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'utama' },
            cell: ({ row: { original: jadwal } }) => (
                <LabelStatus jenis={TEKS_STATUS[jadwal.Status].jenis} teks={TEKS_STATUS[jadwal.Status].teks} />
            ),
        },
        {
            id: 'Terakhir',
            header: 'Jalan terakhir',
            enableSorting: false,
            meta: { label: 'Jalan terakhir', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
            cell: ({ row: { original: jadwal } }) =>
                jadwal.Terakhir === null ? (
                    '—'
                ) : (
                    <>
                        <span className="block">{FormatTanggalWaktu(jadwal.Terakhir.MulaiPada)}</span>
                        <span className="block text-keterangan">
                            {jadwal.Terakhir.DurasiMs === null
                                ? 'Belum selesai'
                                : `Durasi ${FormatDurasi(Math.max(1, Math.round(jadwal.Terakhir.DurasiMs / 1000)))}`}
                            {jadwal.Terakhir.Sumber === 'Manual' ? ' | Manual' : ''}
                        </span>
                        {jadwal.Terakhir.Keterangan ? (
                            <span className="block max-w-xs break-words text-keterangan">
                                {jadwal.Terakhir.Keterangan}
                            </span>
                        ) : null}
                    </>
                ),
        },
        {
            id: 'Berikutnya',
            accessorKey: 'Berikutnya',
            header: 'Jadwal berikutnya',
            meta: { label: 'Jadwal berikutnya', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
            cell: ({ row }) => FormatTanggalWaktu(row.original.Berikutnya),
        },
    ];

    if (bolehKelola) {
        kolom.push({
            id: 'Aksi',
            header: 'Aksi',
            enableSorting: false,
            meta: { label: 'Aksi', prioritas: 'utama' },
            cell: ({ row: { original: jadwal } }) => (
                <Tombol
                    varian="sekunder"
                    memproses={sedangMemproses === jadwal.Perintah}
                    disabled={sedangMemproses !== null || jadwal.Status === 'Berjalan'}
                    onClick={() => Jalankan(jadwal.Perintah)}
                >
                    Jalankan sekarang
                </Tombol>
            ),
        });
    }

    return kolom;
}

/**
 * Jadwal otomatis & penyimpanan (D-209, P-11): kapan tiap perintah terjadwal terakhir berjalan dan hasilnya, tombol
 * "Jalankan sekarang" (diantrekan, hanya perintah yang ada di jadwal), serta tenant dengan penyimpanan terbesar.
 */
export default function Jadwal({ Jadwal: jadwal, Penyimpanan, BolehKelola }: PropsJadwal) {
    const [sedangMemproses, AturSedangMemproses] = useState<string | null>(null);
    const { Ringkasan } = jadwal;

    const Jalankan = (perintah: string) =>
        router.post(
            '/operasional/jadwal/jalankan',
            { Perintah: perintah },
            {
                preserveScroll: true,
                onStart: () => AturSedangMemproses(perintah),
                onFinish: () => AturSedangMemproses(null),
            },
        );

    return (
        <TataLetakPengelola judul="Jadwal & penyimpanan" jejak={[{ label: 'Operasional', href: '/operasional' }]}>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Angka label="Perintah terjadwal" nilai={String(Ringkasan.Total)} />
                <Angka label="Gagal atau tidak selesai" nilai={String(Ringkasan.Gagal)} bahaya={Ringkasan.Gagal > 0} />
                <Angka label="Sedang berjalan" nilai={String(Ringkasan.Berjalan)} />
                <Angka label="Penyimpanan berkas tenant" nilai={FormatUkuranBerkas(Penyimpanan.TotalByte)} />
            </div>
            <p className="max-w-3xl text-keterangan text-teks-sekunder">
                Riwayat disimpan 30 jalan terakhir per perintah. Perintah yang berjalan tiap menit (antrean) tidak
                dicatat.
            </p>
            <TabelData
                id="pengelola-jadwal-otomatis"
                label="Jadwal otomatis"
                kolom={BuatKolom(BolehKelola, sedangMemproses, Jalankan)}
                sumber={{ mode: 'lokal', data: jadwal.Data }}
                ambilIdBaris={(baris) => baris.Perintah}
                cari="Cari nama perintah"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: Object.entries(TEKS_STATUS).map(([nilai, { teks }]) => ({ nilai, label: teks })),
                    },
                ]}
                kosong={{ ilustrasi: true, judul: 'Belum ada perintah terjadwal.' }}
            />
            <Panel tingkat="h3" judul="Tenant dengan penyimpanan terbesar">
                {Penyimpanan.Terbesar.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada tenant yang menyimpan berkas.</p>
                ) : (
                    <ul className="flex flex-col divide-y divide-garis">
                        {Penyimpanan.Terbesar.map((tenant) => (
                            <li
                                key={tenant.Uuid ?? tenant.Nama}
                                className="flex flex-wrap items-center justify-between gap-2 py-2 text-isi"
                            >
                                {tenant.Uuid ? (
                                    <Link
                                        href={`/tenant/${tenant.Uuid}`}
                                        className="font-semibold text-brand underline"
                                    >
                                        {tenant.Nama}
                                    </Link>
                                ) : (
                                    <span className="font-semibold">{tenant.Nama}</span>
                                )}
                                <span className="tabular-nums text-teks-sekunder">
                                    {FormatUkuranBerkas(tenant.Byte)}
                                    {tenant.BatasMb === null ? ' | tanpa batas' : ` dari ${tenant.BatasMb} MB`}
                                    {tenant.Persen === null ? '' : ` (${tenant.Persen}%)`}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-keterangan text-teks-sekunder">
                    {Penyimpanan.JumlahTenant} tenant menyimpan berkas. Pemakaian diukur ulang tiap hari.
                </p>
            </Panel>
        </TataLetakPengelola>
    );
}

function Angka({ label, nilai, bahaya = false }: { label: string; nilai: string; bahaya?: boolean }) {
    return (
        <Panel>
            <p className="text-keterangan text-teks-sekunder">{label}</p>
            <p className={`text-judul font-semibold tabular-nums ${bahaya ? 'text-bahaya' : 'text-teks-utama'}`}>
                {nilai}
            </p>
        </Panel>
    );
}
