import RingkasanAngka from '@/Komponen/Akuntansi/RingkasanAngka';
import SaringLaporan, { BuatQueryLaporan } from '@/Komponen/Akuntansi/SaringLaporan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { cn } from '@/Komponen/Ui/utils';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisArusKas, PropsArusKas } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/laporan/arus-kas';

const kelasBaris: Record<BarisArusKas['Jenis'], string> = {
    Kepala: 'font-semibold text-teks-utama',
    Rincian: 'pl-4 text-teks-utama',
    Subtotal: 'font-semibold text-teks-utama',
    Saldo: 'text-teks-sekunder',
    Total: 'font-semibold text-brand',
};

/**
 * F-13 arus kas metode langsung (FIN-07 P1): kas masuk & keluar per aktivitas operasi, investasi, dan pendanaan,
 * dengan kas awal + kenaikan bersih = kas akhir. Urutan baris tetap (laporan bertingkat), jadi tabel tidak bisa
 * diurutkan.
 */
export default function HalamanArusKas({ Saring, Laporan, OpsiOutlet }: PropsArusKas) {
    const { Periode, Ringkasan } = Laporan;
    const labelPeriode =
        Periode.Dari === Periode.Sampai
            ? FormatTanggal(Periode.Dari)
            : `${FormatTanggal(Periode.Dari)} – ${FormatTanggal(Periode.Sampai)}`;
    const kolom: KolomTabel<BarisArusKas>[] = [
        {
            id: 'Label',
            header: 'Keterangan',
            enableSorting: false,
            meta: { label: 'Keterangan', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: b } }) => (
                <span className={cn('flex flex-wrap gap-2 break-words', kelasBaris[b.Jenis])}>
                    {b.Kode ? <span className="font-mono text-teks-sekunder">{b.Kode}</span> : null}
                    {b.Label}
                </span>
            ),
        },
        {
            id: 'Nilai',
            accessorKey: 'Nilai',
            header: labelPeriode,
            enableSorting: false,
            meta: {
                sembunyiBilaKosong: true,
                label: `Periode (${labelPeriode})`,
                angka: true,
                prioritas: 'penting',
            },
            cell: ({ row: { original: b } }) =>
                b.Nilai === null ? null : (
                    <span className={cn(b.Jenis === 'Subtotal' || b.Jenis === 'Total' ? 'font-semibold' : undefined)}>
                        {FormatRupiah(b.Nilai)}
                    </span>
                ),
        },
    ];

    return (
        <TataLetakAplikasi judul="Arus kas">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Uang masuk dan keluar dari kas dan bank selama periode, per aktivitas operasi, investasi, dan pendanaan.
            </p>
            <SaringLaporan
                alamat={alamat}
                saring={Saring}
                opsiOutlet={OpsiOutlet}
                ekspor={`${alamat}/ekspor?${BuatQueryLaporan(Saring)}`}
            />
            {Laporan.AdaAkunKas ? null : (
                <Pemberitahuan jenis="peringatan" judul="Belum ada akun kas atau bank">
                    Petakan akun kas outlet, kas brankas, atau bank di halaman pemetaan akun agar arus kas bisa
                    dihitung.
                </Pemberitahuan>
            )}
            <RingkasanAngka
                label="Ringkasan arus kas"
                item={[
                    { label: 'Operasi', nilai: Ringkasan.Operasi },
                    { label: 'Investasi', nilai: Ringkasan.Investasi },
                    { label: 'Pendanaan', nilai: Ringkasan.Pendanaan },
                    {
                        label: 'Kas & bank akhir',
                        nilai: Ringkasan.SaldoAkhir,
                        keterangan: `Awal ${FormatRupiah(Ringkasan.SaldoAwal)}`,
                        tebal: true,
                    },
                ]}
            />
            <TabelData
                id="akuntansi-arus-kas"
                label="Arus kas"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Laporan.Baris }}
                ambilIdBaris={(b) => b.Id}
                cari={false}
                kosong={{ ilustrasi: true, judul: 'Belum ada jurnal kas atau bank.' }}
            />
        </TataLetakAplikasi>
    );
}
