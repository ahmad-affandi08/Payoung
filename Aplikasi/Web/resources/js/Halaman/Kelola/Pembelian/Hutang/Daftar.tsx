import { Link, router } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import AksiMassalHutang from '@/Komponen/Pembelian/AksiMassalHutang';
import { AmbilJenisUmur } from '@/Komponen/Pembelian/AturanPembelian';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import { HalamanDaftarPembelian, KolomNomor, KolomPemasok, KolomUang } from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisDaftarFaktur, PropsDaftarHutang, RingkasanHutang } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/hutang`;
const alamatFaktur = `${AlamatPembelian}/faktur`;

/** Keterangan keterlambatan: belum jatuh tempo / hari ini / lewat N hari. */
export function FormatHariLewat(hari: number | null): string {
    if (hari === null || hari < 0) {
        return hari === null ? '—' : `${Math.abs(hari).toLocaleString('id-ID')} hari lagi`;
    }

    return hari === 0 ? 'Jatuh tempo hari ini' : `Lewat ${hari.toLocaleString('id-ID')} hari`;
}

const kolom: KolomTabel<BarisDaftarFaktur>[] = [
    KolomNomor(alamatFaktur, 'Faktur'),
    KolomPemasok(),
    {
        id: 'JatuhTempo',
        accessorKey: 'JatuhTempo',
        header: 'Jatuh tempo',
        meta: { label: 'Jatuh tempo', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: f } }) => (
            <span className="flex flex-col">
                <span>{FormatTanggal(f.JatuhTempo)}</span>
                <span className="text-keterangan text-teks-sekunder">{FormatHariLewat(f.HariLewat)}</span>
            </span>
        ),
    },
    {
        id: 'Umur',
        header: 'Umur',
        enableSorting: false,
        meta: { label: 'Umur hutang', prioritas: 'penting' },
        cell: ({ row: { original: f } }) =>
            f.LabelUmur ? <LabelStatus jenis={AmbilJenisUmur(f.Umur)} teks={f.LabelUmur} /> : '—',
    },
    KolomUang('Total', 'Total faktur', (f) => f.Total, true),
    KolomUang('Sisa', 'Sisa hutang', (f) => f.Sisa),
];

function Ringkasan({ ringkasan }: { ringkasan: RingkasanHutang | undefined }) {
    if (ringkasan === undefined) {
        return null;
    }

    return (
        <section aria-label="Ringkasan umur hutang" className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <div className="col-span-2 min-w-0 rounded-panel border border-garis bg-permukaan px-3 py-2 sm:col-span-1">
                <p className="text-label text-teks-sekunder">Total hutang</p>
                <p className="text-subjudul font-semibold tabular-nums">{FormatRupiah(ringkasan.Total)}</p>
            </div>
            {ringkasan.Kelompok.map((k) => (
                <div key={k.Kunci} className="min-w-0 rounded-panel border border-garis bg-permukaan px-3 py-2">
                    <p className="text-label text-teks-sekunder">{k.Label}</p>
                    <p className="font-semibold tabular-nums wrap-anywhere">{FormatRupiah(k.Sisa)}</p>
                    <p className="text-keterangan text-teks-sekunder">{k.Jumlah.toLocaleString('id-ID')} faktur</p>
                </div>
            ))}
        </section>
    );
}

/** F-04 fase 1: hutang usaha terbuka per faktur, umur 0–30/31–60/61–90/>90 hari, dan pintasan bayar. */
export default function HalamanDaftarHutang({ Hutang, OpsiUmur, OpsiPemasok, Izin }: PropsDaftarHutang) {
    const saring: DefinisiSaring[] = [
        {
            id: 'Umur',
            label: 'Umur hutang',
            jenis: 'pilihanBanyak',
            opsi: OpsiUmur.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Pemasok',
            label: 'Pemasok',
            jenis: 'pilihan',
            opsi: OpsiPemasok.map((p) => ({ nilai: p.Uuid, label: `${p.Nama} (${p.Kode})` })),
        },
    ];
    const tombol = Izin.Kelola ? (
        <Button asChild className="h-8 pointer-coarse:h-11">
            <Link href={`${AlamatPembelian}/pembayaran/buat`}>Bayar hutang</Link>
        </Button>
    ) : null;

    return (
        <HalamanDaftarPembelian
            judul="Hutang pemasok"
            keterangan="Faktur yang belum lunas, diurutkan dari jatuh tempo terdekat. Bayar satu atau beberapa faktur satu pemasok sekaligus."
            izin={Izin}
            objek="hutang"
        >
            <AksiHalaman>
                {/* F-05i: hutang barang titipan dikelola terpisah (dasarnya penjualan, bukan faktur). */}
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href={`${AlamatPembelian}/pembayaran`}>Riwayat pembayaran</Link>
                </Button>
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href={`${AlamatPembelian}/konsinyasi`}>Konsinyasi (titipan)</Link>
                </Button>
                {tombol}
            </AksiHalaman>
            <TabelData
                id="pembelian-hutang"
                label="Daftar hutang pemasok"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Hutang }}
                ambilIdBaris={(f) => f.Uuid}
                urutBawaan="JatuhTempo"
                ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, eksporBesar: true }}
                cari="Cari nomor atau nomor faktur pemasok"
                saring={saring}
                alamatDetail={(f) => `${alamatFaktur}/${f.Uuid}`}
                ringkasan={(hasil) => (
                    <Ringkasan
                        ringkasan={
                            (hasil as HasilTabel<BarisDaftarFaktur, RingkasanHutang> | undefined)?.Ringkasan ??
                            Hutang.Ringkasan
                        }
                    />
                )}
                labelBaris={(f) => `faktur ${f.Nomor}`}
                {...(Izin.Kelola
                    ? {
                          aksiMassal: (konteks: KonteksAksiMassal<BarisDaftarFaktur>) => (
                              <AksiMassalHutang konteks={konteks} />
                          ),
                          aksiBaris: (f: BarisDaftarFaktur) => (
                              <ItemAksiBaris
                                  aksi={[
                                      {
                                          label: 'Bayar faktur ini',
                                          saatPilih: () =>
                                              router.visit(
                                                  `${AlamatPembelian}/pembayaran/buat?pemasok=${f.UuidPemasok ?? ''}&faktur=${f.Uuid}`,
                                              ),
                                      },
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Tidak ada hutang terbuka. Semua faktur sudah lunas.' }}
            />
        </HalamanDaftarPembelian>
    );
}
