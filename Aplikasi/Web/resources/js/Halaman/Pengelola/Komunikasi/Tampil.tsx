import { router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { JenisStatusSiaran, type BarisSiaran } from './Daftar';

type PenerimaSiaran = {
    Id: number;
    Nama: string;
    Tujuan: string;
    Status: 'Menunggu' | 'Diproses' | 'Terkirim' | 'Gagal' | 'Dilewati';
    Alasan: string | null;
    DikirimPada: string | null;
};

type PropsTampil = {
    Siaran: BarisSiaran & {
        Isi: string;
        Segmen: { Status?: string[]; Paket?: string[]; Kesehatan?: string[]; Tenant?: string[] };
    };
    Penerima: PenerimaSiaran[];
};

const JENIS_PENERIMA: Record<PenerimaSiaran['Status'], 'sukses' | 'bahaya' | 'peringatan' | 'netral'> = {
    Menunggu: 'netral',
    Diproses: 'netral',
    Terkirim: 'sukses',
    Gagal: 'bahaya',
    Dilewati: 'peringatan',
};

const kolom: KolomTabel<PenerimaSiaran>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Penerima',
        meta: { label: 'Penerima', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <>
                <span className="font-semibold">{p.Nama}</span>
                <span className="block font-mono text-keterangan font-normal text-teks-sekunder">{p.Tujuan}</span>
            </>
        ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Hasil',
        meta: { label: 'Hasil', prioritas: 'utama' },
        cell: ({ row: { original: p } }) => <LabelStatus jenis={JENIS_PENERIMA[p.Status]} teks={p.Status} />,
    },
    {
        id: 'Alasan',
        accessorKey: 'Alasan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: p } }) => p.Alasan ?? (p.DikirimPada ? FormatTanggalWaktu(p.DikirimPada) : '—'),
    },
];

function RingkasSegmen(segmen: PropsTampil['Siaran']['Segmen']): string {
    const bagian = [
        segmen.Tenant && segmen.Tenant.length > 0 ? `${String(segmen.Tenant.length)} tenant terpilih` : null,
        segmen.Status && segmen.Status.length > 0 ? `Status ${segmen.Status.join(', ')}` : null,
        segmen.Paket && segmen.Paket.length > 0 ? `Paket ${segmen.Paket.join(', ')}` : null,
        segmen.Kesehatan && segmen.Kesehatan.length > 0 ? `Kesehatan ${segmen.Kesehatan.join(', ')}` : null,
    ].filter((b): b is string => b !== null);

    return bagian.length === 0 ? 'Semua tenant aktif' : bagian.join(' | ');
}

/** Rincian siaran (D-210): isi, segmen, hasil per penerima (gagal dan dilewati di atas), dan batalkan selama terjadwal. */
export default function Tampil({ Siaran, Penerima }: PropsTampil) {
    const [konfirmasi, AturKonfirmasi] = useState(false);
    const [memproses, AturMemproses] = useState(false);

    const Batalkan = () =>
        router.put(
            `/komunikasi/${Siaran.Uuid}/batal`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => {
                    AturMemproses(false);
                    AturKonfirmasi(false);
                },
            },
        );

    return (
        <TataLetakPengelola judul={Siaran.Judul} jejak={[{ label: 'Komunikasi', href: '/komunikasi' }]}>
            <Panel tingkat="h3" judul="Ringkasan">
                <div className="flex flex-wrap items-center gap-3">
                    <LabelStatus jenis={JenisStatusSiaran(Siaran.Status)} teks={Siaran.LabelStatus} />
                    <span className="text-isi text-teks-sekunder">
                        {Siaran.Kanal} | Jadwal {FormatTanggalWaktu(Siaran.JadwalKirimPada)} |{' '}
                        {RingkasSegmen(Siaran.Segmen)}
                    </span>
                    {Siaran.Status === 'Terjadwal' ? (
                        <div className="ml-auto">
                            <Tombol varian="bahaya" onClick={() => AturKonfirmasi(true)}>
                                Batalkan siaran
                            </Tombol>
                        </div>
                    ) : null}
                </div>
                <p className="text-isi tabular-nums text-teks-utama">
                    {Siaran.JumlahTerkirim} terkirim | {Siaran.JumlahGagal} gagal | {Siaran.JumlahDilewati} dilewati
                    dari {Siaran.JumlahPenerima} penerima
                </p>
                <p className="max-w-3xl whitespace-pre-line text-isi text-teks-sekunder">{Siaran.Isi}</p>
            </Panel>
            <TabelData
                id="pengelola-siaran-penerima"
                label="Penerima siaran"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Penerima }}
                ambilIdBaris={(p) => String(p.Id)}
                cari="Cari nama atau tujuan"
                saring={[
                    {
                        id: 'Status',
                        label: 'Hasil',
                        jenis: 'pilihanBanyak',
                        opsi: ['Terkirim', 'Gagal', 'Dilewati', 'Menunggu', 'Diproses'].map((nilai) => ({
                            nilai,
                            label: nilai,
                        })),
                    },
                ]}
                kosong={{ judul: 'Penerima ditetapkan saat pengiriman dimulai.' }}
            />
            {konfirmasi ? (
                <DialogKonfirmasi
                    judul="Batalkan siaran?"
                    labelAksi="Batalkan siaran"
                    memproses={memproses}
                    saatKonfirmasi={Batalkan}
                    saatBatal={() => AturKonfirmasi(false)}
                >
                    Siaran yang dibatalkan tidak dikirim ke siapa pun dan tidak bisa dijadwalkan ulang.
                </DialogKonfirmasi>
            ) : null}
        </TataLetakPengelola>
    );
}
