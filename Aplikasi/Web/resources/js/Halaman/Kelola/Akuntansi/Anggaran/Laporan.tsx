import { Link, router } from '@inertiajs/react';

import RingkasanAngka from '@/Komponen/Akuntansi/RingkasanAngka';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TombolEkspor from '@/Komponen/Laporan/TombolEkspor';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { Button } from '@/Komponen/Ui/button';
import { cn } from '@/Komponen/Ui/utils';
import { NamaBulan } from '@/Pustaka/Anggaran';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisAnggaranLaporan, PropsLaporanAnggaran } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/anggaran';

const kelasBaris: Record<BarisAnggaranLaporan['Jenis'], string> = {
    Kepala: 'font-semibold text-teks-utama',
    Akun: 'pl-4 text-teks-utama',
    Subtotal: 'font-semibold text-teks-utama',
    Laba: 'font-semibold text-brand',
};

/** Label status mengikuti arah kelompok: pendapatan & laba dinilai terhadap target, HPP & beban terhadap batas. */
function LabelAnggaran(baris: BarisAnggaranLaporan): { jenis: 'sukses' | 'bahaya' | 'netral'; teks: string } | null {
    if (baris.Status === null) {
        return null;
    }

    const biaya = baris.Kelompok === 'Hpp' || baris.Kelompok === 'Beban';

    if (baris.Status === 'Sesuai') {
        return { jenis: 'netral', teks: 'Sesuai' };
    }

    if (baris.Status === 'Baik') {
        return { jenis: 'sukses', teks: biaya ? 'Hemat' : 'Di atas target' };
    }

    return { jenis: 'bahaya', teks: biaya ? 'Melebihi anggaran' : 'Di bawah target' };
}

function BuatQuery(saring: PropsLaporanAnggaran['Saring']): string {
    const parameter = new URLSearchParams({
        tahun: String(saring.Tahun),
        bulanDari: String(saring.BulanDari),
        bulanSampai: String(saring.BulanSampai),
    });

    if (saring.Outlet !== '') {
        parameter.set('outlet', saring.Outlet);
    }

    return parameter.toString();
}

/**
 * F8 (FIN-11) anggaran vs realisasi: anggaran bulan terpilih dibanding realisasi dari jurnal, per akun pendapatan, HPP,
 * dan beban, plus laba. Anggaran yang dibaca mengikuti outlet saringan (satu outlet, atau seluruh usaha bila tanpa outlet).
 */
export default function HalamanLaporanAnggaran({
    Saring,
    Laporan,
    OpsiOutlet,
    SeluruhUsahaBoleh,
    Izin,
}: PropsLaporanAnggaran) {
    const { Ringkasan } = Laporan;
    const tahunIni = new Date().getFullYear();
    const opsiTahun = Array.from(new Set([Saring.Tahun, tahunIni - 2, tahunIni - 1, tahunIni, tahunIni + 1]))
        .sort((a, b) => b - a)
        .map((tahun) => ({ Nilai: String(tahun), Label: String(tahun) }));
    const opsiBulan = NamaBulan.map((nama, indeks) => ({ Nilai: String(indeks + 1), Label: nama }));
    const Terapkan = (ubah: Partial<PropsLaporanAnggaran['Saring']>) => {
        router.get(`${alamat}?${BuatQuery({ ...Saring, ...ubah })}`, {}, { preserveScroll: true, preserveState: true });
    };
    const queryTersaring = BuatQuery(Saring);
    const adaBaris = Laporan.Baris.some((b) => b.Jenis === 'Akun');
    const tautanSusun = `${alamat}/susun?tahun=${String(Saring.Tahun)}${Saring.Outlet === '' ? '' : `&outlet=${Saring.Outlet}`}`;

    const kolom: KolomTabel<BarisAnggaranLaporan>[] = [
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
            id: 'Anggaran',
            header: 'Anggaran',
            enableSorting: false,
            meta: { label: 'Anggaran', angka: true, prioritas: 'utama' },
            cell: ({ row: { original: b } }) => (b.Anggaran === null ? null : FormatRupiah(b.Anggaran)),
        },
        {
            id: 'Realisasi',
            header: 'Realisasi',
            enableSorting: false,
            meta: { label: 'Realisasi', angka: true, prioritas: 'utama' },
            cell: ({ row: { original: b } }) => (b.Realisasi === null ? null : FormatRupiah(b.Realisasi)),
        },
        {
            id: 'Selisih',
            header: 'Selisih',
            enableSorting: false,
            meta: { label: 'Selisih (realisasi − anggaran)', angka: true, prioritas: 'penting' },
            cell: ({ row: { original: b } }) => (b.Selisih === null ? null : FormatRupiah(b.Selisih)),
        },
        {
            id: 'Capaian',
            header: 'Capaian',
            enableSorting: false,
            meta: { label: 'Capaian', angka: true, prioritas: 'penting' },
            cell: ({ row: { original: b } }) =>
                b.Jenis === 'Kepala' ? null : b.Capaian === null ? '–' : `${FormatPersen(b.Capaian)}%`,
        },
        {
            id: 'Status',
            header: 'Status',
            enableSorting: false,
            meta: { label: 'Status', prioritas: 'utama' },
            cell: ({ row: { original: b } }) => {
                const label = LabelAnggaran(b);

                return label === null ? null : <LabelStatus jenis={label.jenis} teks={label.teks} />;
            },
        },
    ];

    return (
        <TataLetakAplikasi judul="Anggaran vs realisasi">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Bandingkan anggaran dengan hasil sebenarnya dari jurnal. Pendapatan di bawah target dan biaya di atas
                anggaran ditandai. Memilih satu outlet membandingkan anggaran outlet itu; tanpa outlet, yang dibaca
                adalah anggaran seluruh usaha.
            </p>
            <AksiHalaman>
                {Izin.Kelola ? (
                    <Button asChild variant="outline">
                        <Link href={tautanSusun}>Susun anggaran</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <section
                aria-label="Saringan laporan"
                className="grid gap-3 rounded-panel border border-garis bg-permukaan p-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end"
            >
                <BidangPilihan
                    label="Tahun"
                    nilai={String(Saring.Tahun)}
                    opsi={opsiTahun}
                    saatBerubah={(nilai) => Terapkan({ Tahun: Number(nilai) })}
                />
                <BidangPilihan
                    label="Dari bulan"
                    nilai={String(Saring.BulanDari)}
                    opsi={opsiBulan}
                    saatBerubah={(nilai) => Terapkan({ BulanDari: Number(nilai) })}
                />
                <BidangPilihan
                    label="Sampai bulan"
                    nilai={String(Saring.BulanSampai)}
                    opsi={opsiBulan}
                    saatBerubah={(nilai) => Terapkan({ BulanSampai: Number(nilai) })}
                />
                <BidangPilihan
                    label="Outlet"
                    nilai={Saring.Outlet}
                    opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                    {...(SeluruhUsahaBoleh ? { kosong: 'Seluruh usaha' } : {})}
                    saatBerubah={(nilai) => Terapkan({ Outlet: nilai })}
                />
                <div className="flex lg:justify-end">
                    <TombolEkspor alamat={`${alamat}/ekspor`} query={queryTersaring} className="w-full sm:w-auto" />
                </div>
            </section>
            {!Laporan.AdaAnggaran ? (
                <p
                    role="status"
                    className="rounded-panel border border-garis bg-permukaan-redup p-3 text-isi text-teks-utama"
                >
                    Belum ada anggaran untuk {String(Saring.Tahun)} pada cakupan ini.
                    {Izin.Kelola
                        ? ' Susun anggarannya dulu supaya perbandingan bisa terbaca.'
                        : ' Minta pengelola keuangan menyusunnya.'}
                </p>
            ) : null}
            <RingkasanAngka
                label="Ringkasan anggaran vs realisasi"
                item={[
                    {
                        label: 'Pendapatan',
                        nilai: Ringkasan.Pendapatan.Realisasi,
                        keterangan: `Anggaran ${FormatRupiah(Ringkasan.Pendapatan.Anggaran)}`,
                    },
                    {
                        label: 'HPP',
                        nilai: Ringkasan.Hpp.Realisasi,
                        keterangan: `Anggaran ${FormatRupiah(Ringkasan.Hpp.Anggaran)}`,
                    },
                    {
                        label: 'Beban',
                        nilai: Ringkasan.Beban.Realisasi,
                        keterangan: `Anggaran ${FormatRupiah(Ringkasan.Beban.Anggaran)}`,
                    },
                    {
                        label: 'Laba bersih',
                        nilai: Ringkasan.LabaBersih.Realisasi,
                        keterangan: `Anggaran ${FormatRupiah(Ringkasan.LabaBersih.Anggaran)}`,
                        tebal: true,
                    },
                ]}
            />
            <TabelData
                id="akuntansi-anggaran-realisasi"
                label="Anggaran vs realisasi"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: adaBaris ? Laporan.Baris : [] }}
                ambilIdBaris={(b) => b.Id}
                cari={false}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada anggaran atau jurnal pendapatan, HPP, dan beban pada periode ini.',
                }}
            />
        </TataLetakAplikasi>
    );
}
