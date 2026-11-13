import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import { AlamatSaldoSesi, JenisStatusSaldoSesi } from '@/Komponen/Pelanggan/KolomSaldoSesi';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { MutasiSesi, PemakaianSesi, PropsDetailSaldoSesi } from '@/Tipe/Pelanggan';

const kolomMutasi: KolomTabel<MutasiSesi>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'LabelJenis',
        accessorKey: 'LabelJenis',
        header: 'Jenis',
        meta: { label: 'Jenis', prioritas: 'utama' },
        cell: ({ row: { original: m } }) => (
            <span className="flex flex-col">
                <span>{m.LabelJenis}</span>
                {m.Keterangan ? <span className="text-keterangan text-teks-sekunder">{m.Keterangan}</span> : null}
            </span>
        ),
    },
    {
        id: 'JumlahSesi',
        accessorKey: 'JumlahSesi',
        header: 'Sesi',
        meta: { label: 'Sesi', prioritas: 'penting', angka: true },
        cell: ({ row }) =>
            `${row.original.JumlahSesi > 0 ? '+' : ''}${row.original.JumlahSesi.toLocaleString('id-ID')}`,
    },
    {
        id: 'Nilai',
        accessorKey: 'Nilai',
        header: 'Nilai',
        meta: { label: 'Nilai', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.Nilai),
    },
    {
        id: 'SisaSetelah',
        accessorKey: 'SisaSetelah',
        header: 'Sisa',
        meta: { label: 'Sisa', prioritas: 'rendah', angka: true },
        cell: ({ row }) => row.original.SisaSetelah.toLocaleString('id-ID'),
    },
];

const kolomPemakaian: KolomTabel<PemakaianSesi>[] = [
    {
        id: 'TanggalBisnis',
        accessorKey: 'TanggalBisnis',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalBisnis),
    },
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Layanan',
        meta: { label: 'Layanan', prioritas: 'utama' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col items-start gap-1">
                <span className="break-words">{p.NamaProduk ?? 'Layanan tidak dikenal'}</span>
                {p.Dibatalkan ? <LabelStatus jenis="netral" teks="Dibatalkan" /> : null}
                {p.PerluTinjauan ? <LabelStatus jenis="peringatan" teks="Perlu ditinjau" /> : null}
                {p.AlasanTinjauan ? (
                    <span className="text-keterangan break-words text-teks-sekunder">{p.AlasanTinjauan}</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Sesi',
        meta: { label: 'Sesi', prioritas: 'penting', angka: true },
        cell: ({ row }) => row.original.Jumlah.toLocaleString('id-ID'),
    },
    {
        id: 'NilaiDiakui',
        accessorKey: 'NilaiDiakui',
        header: 'Diakui',
        meta: { label: 'Diakui', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.NilaiDiakui),
    },
];

function Nilai({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="text-label text-teks-sekunder">{label}</dt>
            <dd className="text-isi text-teks-utama">{children}</dd>
        </div>
    );
}

type Tutup = { Jenis: 'Refund' | 'Hangus'; UuidAkun: string; Alasan: string };

/** F-16d bagian 2: detail paket sesi pelanggan: riwayat buku sesi, pemakaian, kembalikan/hanguskan sisa. */
export default function HalamanDetailSaldoSesi({ Saldo: s, AkunKasBank, Izin }: PropsDetailSaldoSesi) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [tutup, AturTutup] = useState<Tutup | null>(null);
    const [batal, AturBatal] = useState<PemakaianSesi | null>(null);
    const [alasanBatal, AturAlasanBatal] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const OpsiKirim = (selesai: () => void) => ({
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
        onSuccess: selesai,
    });

    const KirimTutup = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (tutup === null) {
            return;
        }

        router.post(
            `${AlamatSaldoSesi}/${s.Uuid}/tutup`,
            { Jenis: tutup.Jenis, UuidAkun: tutup.Jenis === 'Refund' ? tutup.UuidAkun : null, Alasan: tutup.Alasan },
            OpsiKirim(() => AturTutup(null)),
        );
    };

    const KirimBatal = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (batal === null) {
            return;
        }

        router.post(
            `/kelola/pelanggan/pemakaian-sesi/${batal.Uuid}/batal`,
            { Alasan: alasanBatal },
            OpsiKirim(() => {
                AturBatal(null);
                AturAlasanBatal('');
            }),
        );
    };

    const bolehTutup = Izin.KelolaSesi && s.Status === 'Aktif';

    return (
        <TataLetakAplikasi judul={`Paket ${s.NamaPaket}`}>
            <DaftarGalatServer galat={galat} kecuali={tutup !== null || batal !== null ? ['Alasan', 'UuidAkun'] : []} />
            <AksiHalaman
                keterangan={<LabelStatus jenis={JenisStatusSaldoSesi[s.Status]} teks={s.LabelStatus} />}
                langkah={
                    bolehTutup
                        ? [
                              {
                                  label: 'Kembalikan sisa ke pelanggan',
                                  saatKlik: () =>
                                      AturTutup({ Jenis: 'Refund', UuidAkun: AkunKasBank[0]?.Uuid ?? '', Alasan: '' }),
                              },
                          ]
                        : []
                }
                lainnya={
                    bolehTutup
                        ? [
                              {
                                  label: 'Hanguskan sisa sesi',
                                  saatKlik: () => AturTutup({ Jenis: 'Hangus', UuidAkun: '', Alasan: '' }),
                                  bahaya: true,
                              },
                          ]
                        : []
                }
            />
            <Card className="gap-4 rounded-panel p-4 shadow-none">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Nilai label="Pelanggan">
                        {s.Pelanggan ? (
                            <Link href={`/kelola/pelanggan/${s.Pelanggan.Uuid}`} className="text-brand underline">
                                {s.Pelanggan.Nama}
                            </Link>
                        ) : (
                            'Belum dikenal (perlu ditinjau)'
                        )}
                    </Nilai>
                    <Nilai label="Penjualan">
                        <span className="font-mono break-all">{s.NomorPenjualan}</span>
                    </Nilai>
                    <Nilai label="Sisa sesi">
                        <span className="tabular-nums">
                            {s.SisaSesi.toLocaleString('id-ID')} dari {s.JumlahSesi.toLocaleString('id-ID')}
                        </span>
                    </Nilai>
                    <Nilai label="Nilai tersisa">
                        <span className="tabular-nums">
                            {FormatRupiah(s.NilaiTersisa)} dari {FormatRupiah(s.NilaiAwal)}
                        </span>
                    </Nilai>
                    <Nilai label="Tanggal beli">{FormatTanggal(s.TanggalBeli)}</Nilai>
                    <Nilai label="Berlaku sampai">
                        {s.BerlakuSampai ? FormatTanggal(s.BerlakuSampai) : 'Tanpa batas'}
                    </Nilai>
                </dl>
            </Card>

            <h2 className="text-subjudul font-semibold text-teks-utama">Pemakaian sesi</h2>
            <TabelData
                id="saldo-sesi-pemakaian"
                label={`Pemakaian paket ${s.NamaPaket}`}
                kolom={kolomPemakaian}
                sumber={{ mode: 'lokal', data: s.Pemakaian }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-TanggalBisnis"
                {...(Izin.KelolaSesi
                    ? {
                          aksiBaris: (p: PemakaianSesi) =>
                              p.Dibatalkan ? null : (
                                  <ItemAksiBaris
                                      aksi={[
                                          { label: 'Batalkan pemakaian', saatPilih: () => AturBatal(p), bahaya: true },
                                      ]}
                                  />
                              ),
                      }
                    : {})}
                kosong={{ judul: 'Belum ada sesi yang dipakai.' }}
            />

            <h2 className="text-subjudul font-semibold text-teks-utama">Riwayat sesi</h2>
            <TabelData
                id="saldo-sesi-mutasi"
                label={`Riwayat sesi paket ${s.NamaPaket}`}
                kolom={kolomMutasi}
                sumber={{ mode: 'lokal', data: s.Mutasi }}
                ambilIdBaris={(m) => m.Uuid}
                urutBawaan="Tanggal"
                kosong={{ judul: 'Belum ada riwayat.' }}
            />

            {tutup ? (
                <DialogFormulir
                    judul={tutup.Jenis === 'Refund' ? 'Kembalikan sisa paket' : 'Hanguskan sisa paket'}
                    jenis="konfirmasi"
                    galatUmum={galat.Umum}
                    saatTutup={() => AturTutup(null)}
                >
                    <form
                        onSubmit={KirimTutup}
                        className="flex flex-col gap-4"
                        aria-label="Formulir tutup sisa sesi"
                        noValidate
                    >
                        <p className="text-isi text-teks-utama">
                            {tutup.Jenis === 'Refund'
                                ? `Sisa ${s.SisaSesi.toLocaleString('id-ID')} sesi senilai ${FormatRupiah(s.NilaiTersisa)} dikembalikan ke pelanggan dari akun kas/bank yang dipilih. Paket tidak bisa dipakai lagi.`
                                : `Sisa ${s.SisaSesi.toLocaleString('id-ID')} sesi senilai ${FormatRupiah(s.NilaiTersisa)} dihanguskan dan dicatat sebagai pendapatan lain. Paket tidak bisa dipakai lagi.`}
                        </p>
                        {tutup.Jenis === 'Refund' ? (
                            <BidangPilihan
                                label="Akun kas/bank"
                                nilai={tutup.UuidAkun}
                                opsi={AkunKasBank.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                                saatBerubah={(nilai) => AturTutup({ ...tutup, UuidAkun: nilai })}
                                galat={galat.UuidAkun}
                                required
                            />
                        ) : null}
                        <BidangTeks
                            label="Alasan"
                            nilai={tutup.Alasan}
                            saatBerubah={(nilai) => AturTutup({ ...tutup, Alasan: nilai })}
                            galat={galat.Alasan}
                            maxLength={255}
                            required
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturTutup(null)}>
                                Batal
                            </Tombol>
                            <Tombol
                                type="submit"
                                varian="bahaya"
                                memproses={memproses}
                                disabled={tutup.Alasan.trim().length < 5}
                            >
                                {tutup.Jenis === 'Refund' ? 'Kembalikan sisa' : 'Hanguskan sisa'}
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}

            {batal ? (
                <DialogFormulir
                    judul="Batalkan pemakaian sesi?"
                    jenis="konfirmasi"
                    galatUmum={galat.Umum}
                    saatTutup={() => AturBatal(null)}
                >
                    <form
                        onSubmit={KirimBatal}
                        className="flex flex-col gap-4"
                        aria-label="Formulir batal pemakaian sesi"
                        noValidate
                    >
                        <p className="text-isi text-teks-utama">
                            {batal.Jumlah.toLocaleString('id-ID')} sesi {batal.NamaProduk ?? ''} dikembalikan ke paket
                            dan pengakuan pendapatannya dibalik.
                        </p>
                        <BidangTeks
                            label="Alasan"
                            nilai={alasanBatal}
                            saatBerubah={AturAlasanBatal}
                            galat={galat.Alasan}
                            maxLength={255}
                            required
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol type="button" varian="sekunder" onClick={() => AturBatal(null)}>
                                Batal
                            </Tombol>
                            <Tombol
                                type="submit"
                                varian="bahaya"
                                memproses={memproses}
                                disabled={alasanBatal.trim().length < 5}
                            >
                                Batalkan pemakaian
                            </Tombol>
                        </div>
                    </form>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
