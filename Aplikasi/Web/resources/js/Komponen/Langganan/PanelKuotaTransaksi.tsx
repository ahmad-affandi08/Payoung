import Panel from '@/Komponen/Kelola/Panel';
import { Progress } from '@/Komponen/Ui/progress';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

export type StatusKuotaTransaksi = 'TanpaBatas' | 'Normal' | 'HampirHabis' | 'Habis';

export type DataKuotaTransaksi = {
    /** Bulan kalender (WIB) dalam bentuk `TTTT-BB`. */
    Periode: string;
    Terpakai: number;
    /** Null = tanpa batas. */
    Batas: number | null;
    Sisa: number | null;
    Persen: number | null;
    Status: StatusKuotaTransaksi;
    LabelStatus: string;
};

const jenisLabel = {
    TanpaBatas: 'netral',
    Normal: 'sukses',
    HampirHabis: 'peringatan',
    Habis: 'bahaya',
} as const;

const namaBulan = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

export function FormatPeriodeKuota(periode: string): string {
    const [tahun, bulan] = periode.split('-');
    const nama = namaBulan[Number(bulan) - 1];

    return nama === undefined ? periode : `${nama} ${tahun}`;
}

/**
 * P-04: kuota transaksi penjualan per bulan di halaman Langganan. Hanya tampil bila paket punya batas. Menjelaskan apa
 * yang terjadi saat habis: layar jual kasir terkunci dan pesanan online baru ditolak, sedangkan transaksi yang sudah
 * terjadi di perangkat tetap diterima.
 */
export default function PanelKuotaTransaksi({ kuota }: { kuota: DataKuotaTransaksi | null | undefined }) {
    if (kuota === null || kuota === undefined || kuota.Batas === null) {
        return null;
    }

    return (
        <Panel
            judul="Kuota transaksi bulan ini"
            keterangan={FormatPeriodeKuota(kuota.Periode)}
            aksi={<LabelStatus jenis={jenisLabel[kuota.Status]} teks={kuota.LabelStatus} />}
        >
            <div className="grid gap-3">
                <p className="text-isi text-teks-utama">
                    <span className="font-semibold tabular-nums">{kuota.Terpakai.toLocaleString('id-ID')}</span> dari{' '}
                    <span className="tabular-nums">{kuota.Batas.toLocaleString('id-ID')}</span> transaksi terpakai
                    {kuota.Sisa !== null ? (
                        <>
                            , sisa <span className="tabular-nums">{kuota.Sisa.toLocaleString('id-ID')}</span>
                        </>
                    ) : null}
                    .
                </p>
                <Progress
                    value={kuota.Persen ?? 0}
                    aria-label={`Kuota transaksi terpakai ${kuota.Persen ?? 0} persen`}
                />
                {kuota.Status === 'Habis' ? (
                    <Pemberitahuan jenis="bahaya" judul="Kuota transaksi bulan ini habis">
                        Layar jual di aplikasi kasir terkunci dan pesanan online baru ditolak sampai kuota bertambah
                        atau bulan berganti. Transaksi yang sudah terjadi di perangkat tetap tersimpan. Naik paket atau
                        tambah add-on di bawah untuk menambah kuota.
                    </Pemberitahuan>
                ) : kuota.Status === 'HampirHabis' ? (
                    <Pemberitahuan jenis="peringatan" judul="Kuota transaksi hampir habis">
                        Naik paket sebelum kuota habis supaya penjualan tidak terhenti.
                    </Pemberitahuan>
                ) : null}
                <p className="text-keterangan text-teks-sekunder">
                    Kuota dihitung per bulan kalender dan kembali penuh tiap tanggal 1. Transaksi yang dibatalkan (void)
                    tetap dihitung; retur tidak.
                </p>
            </div>
        </Panel>
    );
}
