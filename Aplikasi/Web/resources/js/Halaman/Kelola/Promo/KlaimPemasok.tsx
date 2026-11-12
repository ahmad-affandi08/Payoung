import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import GrupRadio from '@/Komponen/Katalog/GrupRadio';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisKlaimTerbuka, BarisPenerimaanKlaim, PropsKlaimPemasok } from '@/Tipe/Promo';

type CaraPenerimaanKlaim = 'KasBank' | 'PotongHutang';

const alamat = '/kelola/promo/klaim-pemasok';

const kolomTerbuka: KolomTabel<BarisKlaimTerbuka>[] = [
    {
        id: 'NamaPemasok',
        accessorKey: 'NamaPemasok',
        header: 'Pemasok',
        meta: { label: 'Pemasok', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama' },
    },
    {
        id: 'Promo',
        accessorKey: 'Promo',
        header: 'Promo',
        enableSorting: false,
        meta: { label: 'Promo', prioritas: 'rendah', kelasSel: 'font-mono' },
    },
    {
        id: 'JumlahTransaksi',
        accessorKey: 'JumlahTransaksi',
        header: 'Transaksi',
        meta: { label: 'Transaksi', prioritas: 'rendah', angka: true },
    },
    {
        id: 'TanggalTertua',
        accessorKey: 'TanggalTertua',
        header: 'Sejak',
        meta: { label: 'Sejak', prioritas: 'rendah' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalTertua),
    },
    {
        id: 'Total',
        accessorKey: 'Total',
        header: 'Klaim belum diterima',
        meta: { label: 'Klaim belum diterima', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.Total),
    },
    {
        id: 'SisaHutang',
        accessorKey: 'SisaHutang',
        header: 'Hutang ke pemasok',
        meta: { label: 'Hutang ke pemasok', prioritas: 'rendah', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.SisaHutang),
    },
];

const kolomPenerimaan: KolomTabel<BarisPenerimaanKlaim>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'NamaPemasok',
        accessorKey: 'NamaPemasok',
        header: 'Pemasok',
        meta: { label: 'Pemasok', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama' },
        cell: ({ row: { original: p } }) => (
            <>
                <span className="block">{p.NamaPemasok}</span>
                <span className="block text-label text-teks-sekunder">
                    {p.JumlahKlaim} transaksi | {p.Cara}
                    {p.AkunKasBank ? ` ke ${p.AkunKasBank}` : ''}
                    {p.Keterangan ? ` | ${p.Keterangan}` : ''}
                </span>
            </>
        ),
    },
    {
        id: 'NomorJurnal',
        header: 'Jurnal',
        enableSorting: false,
        meta: { label: 'Jurnal', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: p } }) =>
            p.UuidJurnal && p.NomorJurnal ? (
                <Link href={`/kelola/akuntansi/jurnal/${p.UuidJurnal}`} className="font-mono text-brand underline">
                    {p.NomorJurnal}
                </Link>
            ) : (
                <span className="text-teks-sekunder">—</span>
            ),
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Diterima',
        meta: { label: 'Diterima', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
];

/**
 * Klaim promo ke pemasok (F-16c bagian 4b, 4d, 4e): bagian potongan promo yang ditanggung pemasok, per pemasok, dan
 * riwayat penyelesaiannya. Potongan ke pelanggan tetap Diskon Penjualan; klaim diakui saat penjualan sebagai Piutang
 * Klaim Promosi Pemasok (Cr HPP, PSAK 72), lalu diselesaikan lewat kas/bank atau dipotong dari hutang ke pemasok.
 */
export default function HalamanKlaimPemasok({ Terbuka, Penerimaan, OpsiAkunKasBank, Izin }: PropsKlaimPemasok) {
    const [terima, AturTerima] = useState<BarisKlaimTerbuka | null>(null);

    return (
        <TataLetakAplikasi judul="Klaim promo pemasok">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Promo yang ditanggung pemasok jadi piutang ke pemasok. Catat penyelesaiannya di sini, dibayar atau
                dipotong dari hutang.
            </p>
            <h2 className="text-subjudul font-semibold text-teks-utama">Klaim belum diterima</h2>
            <TabelData
                id="promo-klaim-terbuka"
                label="Klaim belum diterima"
                kolom={kolomTerbuka}
                sumber={{ mode: 'lokal', data: Terbuka }}
                ambilIdBaris={(k) => k.UuidPemasok}
                labelBaris={(k) => `klaim ${k.NamaPemasok}`}
                urutBawaan="NamaPemasok"
                cari="Cari pemasok"
                {...(Izin.Terima
                    ? {
                          aksiBaris: (k: BarisKlaimTerbuka) => (
                              <DropdownMenuItem onSelect={() => AturTerima(k)}>
                                  Catat penyelesaian klaim
                              </DropdownMenuItem>
                          ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Tidak ada klaim promo yang belum diterima.' }}
            />
            <h2 className="text-subjudul font-semibold text-teks-utama">Riwayat penerimaan</h2>
            <TabelData
                id="promo-klaim-penerimaan"
                label="Riwayat penerimaan klaim"
                kolom={kolomPenerimaan}
                sumber={{ mode: 'lokal', data: Penerimaan }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari pemasok"
                kosong={{ ilustrasi: true, judul: 'Belum ada pembayaran klaim dari pemasok.' }}
            />
            {terima ? (
                <FormTerima klaim={terima} opsiAkun={OpsiAkunKasBank} saatSelesai={() => AturTerima(null)} />
            ) : null}
        </TataLetakAplikasi>
    );
}

function FormTerima({
    klaim,
    opsiAkun,
    saatSelesai,
}: {
    klaim: BarisKlaimTerbuka;
    opsiAkun: PropsKlaimPemasok['OpsiAkunKasBank'];
    saatSelesai: () => void;
}) {
    const hariIni = TulisTanggal(new Date());
    const bisaPotong = Number(klaim.SisaHutang) >= Number(klaim.Total);
    const formulir = useForm<{
        UuidPemasok: string;
        Tanggal: string;
        Cara: CaraPenerimaanKlaim;
        UuidAkunKasBank: string;
        Keterangan: string;
    }>({
        UuidPemasok: klaim.UuidPemasok,
        Tanggal: hariIni,
        Cara: 'KasBank',
        UuidAkunKasBank: opsiAkun[0]?.Uuid ?? '',
        Keterangan: '',
    });
    const potong = formulir.data.Cara === 'PotongHutang';

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/penerimaan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Penyelesaian klaim ${klaim.NamaPemasok}`}
            keterangan={`Semua klaim terbuka sampai tanggal penyelesaian (${FormatRupiah(klaim.Total)} untuk ${klaim.JumlahTransaksi} transaksi) ditandai diterima.`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <div className="sm:col-span-2">
                    <GrupRadio<CaraPenerimaanKlaim>
                        legenda="Cara penyelesaian"
                        nilai={formulir.data.Cara}
                        opsi={[
                            {
                                Nilai: 'KasBank',
                                Label: 'Dibayar ke kas/bank',
                                Keterangan: 'Pemasok mentransfer atau membayar tunai.',
                            },
                            {
                                Nilai: 'PotongHutang',
                                Label: 'Potong hutang ke pemasok',
                                Keterangan: bisaPotong
                                    ? `Mengurangi faktur terbuka paling lama (sisa hutang ${FormatRupiah(klaim.SisaHutang)}).`
                                    : `Sisa hutang ${FormatRupiah(klaim.SisaHutang)} kurang dari total klaim.`,
                            },
                        ]}
                        saatBerubah={(nilai) => formulir.setData('Cara', nilai)}
                        galat={formulir.errors.Cara}
                    />
                </div>
                <PemilihTanggal
                    label={potong ? 'Tanggal dipotong' : 'Tanggal diterima'}
                    nilai={formulir.data.Tanggal}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    max={hariIni}
                    required
                />
                {potong ? null : (
                    <BidangPilihan
                        label="Diterima di"
                        nilai={formulir.data.UuidAkunKasBank}
                        opsi={opsiAkun.map((a) => ({ Nilai: a.Uuid, Label: a.Nama }))}
                        saatBerubah={(nilai) => formulir.setData('UuidAkunKasBank', nilai)}
                        galat={formulir.errors.UuidAkunKasBank}
                        required
                    />
                )}
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Keterangan (opsional)"
                        nilai={formulir.data.Keterangan}
                        saatBerubah={(nilai) => formulir.setData('Keterangan', nilai)}
                        galat={formulir.errors.Keterangan}
                        maxLength={255}
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing} disabled={potong && !bisaPotong}>
                        {potong ? 'Potong dari hutang' : 'Simpan penerimaan'}
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
