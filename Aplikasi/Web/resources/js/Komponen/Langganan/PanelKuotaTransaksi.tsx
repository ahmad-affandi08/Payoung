import Panel from '@/Komponen/Kelola/Panel';
import { Progress } from '@/Komponen/Ui/progress';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

export type StatusKuotaTransaksi = 'TanpaBatas' | 'Normal' | 'HampirHabis' | 'Habis';

export type JangkaKuotaTransaksi = 'Bulan' | 'Hari';

export type DataKuotaTransaksi = {
    /** `Bulan` (batas per bulan kalender) atau `Hari` (batas per hari bisnis outlet). */
    Jangka: JangkaKuotaTransaksi;
    /** Jangka Bulan: `TTTT-BB` (WIB). Jangka Hari: tanggal bisnis `TTTT-BB-HH`. */
    Periode: string;
    Terpakai: number;
    /** Null = tanpa batas. */
    Batas: number | null;
    Sisa: number | null;
    Persen: number | null;
    Status: StatusKuotaTransaksi;
    LabelStatus: string;
    BerakhirPada?: string | null;
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
    const [tahun, bulan, hari] = periode.split('-');
    const nama = namaBulan[Number(bulan) - 1];

    if (nama === undefined) {
        return periode;
    }

    return hari === undefined ? `${nama} ${tahun}` : `${Number(hari)} ${nama} ${tahun}`;
}

/**
 * P-04: kuota transaksi penjualan (per bulan atau per hari bisnis) di halaman Langganan, satu panel per jangka. Hanya tampil bila paket punya batas. Menjelaskan apa
 * yang terjadi saat habis: layar jual kasir terkunci dan pesanan online baru ditolak, sedangkan transaksi yang sudah
 * terjadi di perangkat tetap diterima.
 */
export default function PanelKuotaTransaksi({ kuota }: { kuota: DataKuotaTransaksi | null | undefined }) {
    if (kuota === null || kuota === undefined || kuota.Batas === null) {
        return null;
    }

    const sebutan = kuota.Jangka === 'Hari' ? 'hari ini' : 'bulan ini';

    return (
        <Panel
            judul={`Kuota transaksi ${sebutan}`}
            keterangan={
                kuota.Jangka === 'Hari'
                    ? `Hari bisnis ${FormatPeriodeKuota(kuota.Periode)}`
                    : FormatPeriodeKuota(kuota.Periode)
            }
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
                    <Pemberitahuan jenis="bahaya" judul={`Kuota transaksi ${sebutan} habis`}>
                        Layar jual di aplikasi kasir terkunci dan pesanan online baru ditolak sampai kuota bertambah
                        atau {kuota.Jangka === 'Hari' ? 'hari bisnis berganti' : 'bulan berganti'}. Transaksi yang sudah
                        terjadi di perangkat tetap tersimpan. Naik paket atau tambah add-on di bawah untuk menambah
                        kuota.
                    </Pemberitahuan>
                ) : kuota.Status === 'HampirHabis' ? (
                    <Pemberitahuan jenis="peringatan" judul="Kuota transaksi hampir habis">
                        Naik paket sebelum kuota habis supaya penjualan tidak terhenti.
                    </Pemberitahuan>
                ) : null}
                <p className="text-keterangan text-teks-sekunder">
                    {kuota.Jangka === 'Hari'
                        ? 'Kuota dihitung per hari bisnis outlet (mengikuti zona waktu dan jam tutup buku) dan kembali penuh saat hari berganti. '
                        : 'Kuota dihitung per bulan kalender dan kembali penuh tiap tanggal 1. '}
                    Transaksi yang dibatalkan (void) tetap dihitung; retur tidak.
                </p>
            </div>
        </Panel>
    );
}
