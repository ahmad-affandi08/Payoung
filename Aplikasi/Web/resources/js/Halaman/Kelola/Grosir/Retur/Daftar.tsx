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
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import type { BarisDaftarRetur, PropsDaftarRetur } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/retur`;

const kolom: KolomTabel<BarisDaftarRetur>[] = [
    KolomNomorGrosir(alamat),
    KolomTanggalGrosir('Tanggal retur'),
    KolomPelangganGrosir(),
    {
        id: 'SuratJalan',
        header: 'Surat jalan',
        enableSorting: false,
        meta: { label: 'Surat jalan', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => <span className="font-mono">{row.original.NomorSuratJalan ?? '—'}</span>,
    },
    {
        id: 'Piutang',
        header: 'Nota kredit',
        enableSorting: false,
        meta: { label: 'Nota kredit', prioritas: 'penting' },
        cell: ({ row: { original: d } }) =>
            d.MengurangiPiutang ? (
                <span className="flex flex-col">
                    <span>Mengurangi piutang</span>
                    <span className="font-mono text-keterangan text-teks-sekunder">{d.NomorFaktur ?? '—'}</span>
                </span>
            ) : (
                <span className="text-teks-sekunder">Belum difakturkan</span>
            ),
    },
    KolomStatusGrosir(),
    {
        id: 'Tinjauan',
        header: 'Tinjauan',
        enableSorting: false,
        meta: { label: 'Perlu ditinjau', prioritas: 'rendah' },
        cell: ({ row }) =>
            row.original.PerluTinjauan ? <LabelStatus jenis="peringatan" teks="Perlu ditinjau" /> : <span>—</span>,
    },
    {
        id: 'Alasan',
        header: 'Alasan',
        enableSorting: false,
        meta: { label: 'Alasan', prioritas: 'rendah' },
        cell: ({ row }) => <span className="break-words">{row.original.Alasan}</span>,
    },
    KolomUangGrosir('Total', 'Total', (d) => d.Total, true),
];

/**
 * Daftar retur grosir (F-12, §9.7, BR-12.7, J-12.4). Retur dibuat dari surat jalannya, karena barang yang kembali
 * selalu milik satu penyerahan tertentu: dokumen itulah yang punya harga, HPP, dan tarif pajak untuk dibalik.
 */
export default function HalamanDaftarReturGrosir({ Retur, OpsiStatus, Izin }: PropsDaftarRetur) {
    return (
        <HalamanGrosir
            judul="Retur grosir"
            keterangan="Barang yang dikembalikan pembeli. Retur membalik pengakuan penjualan surat jalannya. Bila penyerahan itu sudah difakturkan, returnya sekaligus menjadi nota kredit yang mengurangi piutang."
            izin={Izin}
            objek="retur grosir"
        >
            <TabelData
                id="grosir-retur"
                label="Daftar retur grosir"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Retur }}
                ambilIdBaris={(d) => d.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor retur"
                saring={BuatSaringGrosir(OpsiStatus)}
                alamatDetail={(d) => `${alamat}/${d.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada retur grosir. Retur dibuat dari halaman surat jalan yang barangnya dikembalikan.',
                }}
            />
        </HalamanGrosir>
    );
}
