import {
    AlamatGrosir,
    BuatSaringGrosir,
    HalamanGrosir,
    KolomNomorGrosir,
    KolomPelangganGrosir,
    KolomStatusGrosir,
    KolomTanggalGrosir,
    KolomUangGrosir,
} from '@/Komponen/Grosir/BagianDokumenGrosir';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { JenisLabelPengiriman } from '@/Komponen/Grosir/PanelPengirimanGrosir';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { Link } from '@inertiajs/react';
import type { BarisDaftarSuratJalan, PropsDaftarSuratJalan } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/surat-jalan`;

const kolom: KolomTabel<BarisDaftarSuratJalan>[] = [
    KolomNomorGrosir(alamat),
    KolomTanggalGrosir('Diserahkan'),
    KolomPelangganGrosir(),
    {
        id: 'Pesanan',
        header: 'Pesanan',
        enableSorting: false,
        meta: { label: 'Pesanan', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => <span className="font-mono">{row.original.NomorPesanan ?? '—'}</span>,
    },
    {
        id: 'Faktur',
        header: 'Faktur',
        enableSorting: false,
        meta: { label: 'Faktur', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: d } }) =>
            d.NomorFaktur === null ? (
                <span className="text-teks-sekunder">Belum difakturkan</span>
            ) : (
                <span className="font-mono">{d.NomorFaktur}</span>
            ),
    },
    KolomStatusGrosir(),
    {
        id: 'Pengiriman',
        header: 'Pengiriman',
        enableSorting: false,
        meta: { label: 'Pengiriman', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: d } }) =>
            d.Status === 'Dibatalkan' ? (
                <span className="text-teks-sekunder">—</span>
            ) : (
                <LabelStatus jenis={JenisLabelPengiriman(d.StatusPengiriman)} teks={d.LabelStatusPengiriman} />
            ),
    },
    KolomUangGrosir('Total', 'Total', (d) => d.Total, true),
];

/**
 * Daftar surat jalan grosir (F-12, §9.7, BR-12.2). Inilah titik pengakuan: setiap baris di sini sudah memotong stok,
 * membukukan HPP & pendapatan, dan menerbitkan PPN keluaran. Saringan "Belum difakturkan" adalah tujuan butir Kotak
 * Tindakan BR-12.4 — nilai yang sudah diserahkan tetapi belum ditagihkan ke pembeli.
 */
export default function HalamanDaftarSuratJalan({
    SuratJalan,
    OpsiStatus,
    OpsiStatusPengiriman,
    Izin,
}: PropsDaftarSuratJalan) {
    return (
        <HalamanGrosir
            judul="Surat jalan grosir"
            keterangan="Barang yang sudah diserahkan ke pembeli. Surat jalan tidak bisa diedit. Koreksinya lewat pembatalan yang membalik stok dan jurnalnya."
            izin={Izin}
            objek="surat jalan"
        >
            <AksiHalaman>
                {Izin.Kelola ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatGrosir}/faktur/buat`}>Buat faktur dari surat jalan</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="grosir-surat-jalan"
                label="Daftar surat jalan grosir"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: SuratJalan }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor surat jalan"
                saring={BuatSaringGrosir(OpsiStatus, [
                    {
                        id: 'Difakturkan',
                        label: 'Faktur',
                        jenis: 'pilihan',
                        opsi: [
                            { nilai: 'Belum', label: 'Belum difakturkan' },
                            { nilai: 'Sudah', label: 'Sudah difakturkan' },
                        ],
                    },
                    {
                        id: 'Pengiriman',
                        label: 'Pengiriman',
                        jenis: 'pilihan',
                        opsi: OpsiStatusPengiriman.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                ])}
                alamatDetail={(d) => `${alamat}/${d.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada surat jalan.' }}
            />
        </HalamanGrosir>
    );
}
