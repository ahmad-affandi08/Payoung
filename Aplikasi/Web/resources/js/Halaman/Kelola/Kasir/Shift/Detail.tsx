import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import LencanaShift from '@/Komponen/Kasir/LencanaShift';
import { kolomPenjualan } from '@/Komponen/Penjualan/KolomPenjualan';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { AmbilTandaDesimal, KurangiDesimal } from '@/Pustaka/HitungDesimal';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisBukaLaci, BarisBukaUlangShift, LaporanShift, PropsDetailShift, TutupShift } from '@/Tipe/Kasir';

type MutasiKas = PropsDetailShift['MutasiKas'][number];

const kolom: KolomTabel<MutasiKas>[] = [
    {
        id: 'DicatatPada',
        accessorKey: 'DicatatPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DicatatPada),
    },
    {
        id: 'Jenis',
        accessorKey: 'Jenis',
        header: 'Jenis & kategori',
        meta: { label: 'Jenis & kategori', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: m } }) => (
            <>
                <span className="block text-teks-utama">{m.LabelJenis}</span>
                {m.NamaKategori ? <span className="block text-label text-teks-sekunder">{m.NamaKategori}</span> : null}
                {m.Catatan ? (
                    <span className="block text-label break-words text-teks-sekunder">{m.Catatan}</span>
                ) : null}
                {m.AdaBukti ? (
                    <a
                        href={`/kelola/kasir/mutasi-kas/${m.Uuid}/bukti`}
                        target="_blank"
                        rel="noreferrer"
                        className="block text-label font-semibold text-brand underline"
                    >
                        Lihat foto bukti
                    </a>
                ) : null}
                {m.PerluTinjauan ? (
                    <span className="block text-label text-bahaya">
                        Perlu ditinjau{m.AlasanTinjauan ? `: ${m.AlasanTinjauan}` : ''}
                    </span>
                ) : null}
            </>
        ),
    },
    {
        id: 'DicatatOleh',
        accessorKey: 'DicatatOleh',
        header: 'Dicatat oleh',
        meta: { label: 'Dicatat oleh', prioritas: 'rendah' },
        cell: ({ row: { original: m } }) => (
            <>
                <span className="block">{m.DicatatOleh}</span>
                {m.DisetujuiOleh ? (
                    <span className="block text-label text-teks-sekunder">Disetujui {m.DisetujuiOleh}</span>
                ) : null}
            </>
        ),
    },
    {
        id: 'NomorJurnal',
        header: 'Jurnal',
        enableSorting: false,
        meta: { label: 'Jurnal', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: m } }) =>
            m.UuidJurnal && m.NomorJurnal ? (
                <Link href={`/kelola/akuntansi/jurnal/${m.UuidJurnal}`} className="font-mono text-brand underline">
                    {m.NomorJurnal}
                </Link>
            ) : (
                <span className="text-teks-sekunder">—</span>
            ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: m } }) => (
            <span className={m.Jenis === 'Masuk' ? 'text-sukses' : 'text-teks-utama'}>
                {m.Jenis === 'Masuk' ? '+' : '−'}
                {FormatRupiah(m.Jumlah)}
            </span>
        ),
    },
];

const kolomBukaLaci: KolomTabel<BarisBukaLaci>[] = [
    {
        id: 'DibukaPada',
        accessorKey: 'DibukaPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DibukaPada),
    },
    {
        id: 'Alasan',
        accessorKey: 'Alasan',
        header: 'Alasan',
        meta: { label: 'Alasan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block break-words text-teks-utama">{b.Alasan}</span>
                {b.PerluTinjauan ? (
                    <span className="block text-label text-bahaya">
                        Perlu ditinjau{b.AlasanTinjauan ? `: ${b.AlasanTinjauan}` : ''}
                    </span>
                ) : null}
            </>
        ),
    },
    {
        id: 'DibukaOleh',
        accessorKey: 'DibukaOleh',
        header: 'Dibuka oleh',
        meta: { label: 'Dibuka oleh', prioritas: 'penting' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block">{b.DibukaOleh}</span>
                {b.DisetujuiOleh ? (
                    <span className="block text-label text-teks-sekunder">Disetujui {b.DisetujuiOleh}</span>
                ) : null}
            </>
        ),
    },
];

const kolomBukaUlang: KolomTabel<BarisBukaUlangShift>[] = [
    {
        id: 'DibukaUlangPada',
        accessorKey: 'DibukaUlangPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DibukaUlangPada),
    },
    {
        id: 'Alasan',
        accessorKey: 'Alasan',
        header: 'Alasan',
        meta: { label: 'Alasan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block break-words text-teks-utama">{b.Alasan}</span>
                <span className="block text-label text-teks-sekunder">
                    Diminta {b.DimintaOleh} | disetujui {b.DisetujuiOleh}
                </span>
            </>
        ),
    },
    {
        id: 'TutupSebelumnya',
        header: 'Tutup yang dibatalkan',
        enableSorting: false,
        meta: { label: 'Tutup yang dibatalkan', prioritas: 'penting' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block tabular-nums">
                    Kas aktual {b.KasAktualSebelumnya === null ? '—' : FormatRupiah(b.KasAktualSebelumnya)}
                </span>
                <span className="block text-label text-teks-sekunder tabular-nums">
                    Selisih {b.SelisihSebelumnya === null ? '—' : FormatRupiah(b.SelisihSebelumnya)}
                    {b.DitutupOlehSebelumnya ? ` | ditutup ${b.DitutupOlehSebelumnya}` : ''}
                    {b.DitutupPadaSebelumnya ? ` ${FormatTanggalWaktu(b.DitutupPadaSebelumnya)}` : ''}
                </span>
            </>
        ),
    },
];

function Nilai({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-label text-teks-sekunder">{label}</dt>
            <dd className="text-isi text-teks-utama">{children}</dd>
        </div>
    );
}

function Uang({ nilai, tebal = false }: { nilai: string; tebal?: boolean }) {
    return <span className={`tabular-nums ${tebal ? 'font-semibold' : ''}`}>{FormatRupiah(nilai)}</span>;
}

/** Selisih bertanda: "+Rp 28.000" / "−Rp 7.000" / "Rp 0", warna bahaya bila tidak nol (teks tetap terbaca). */
function Selisih({ nilai }: { nilai: string }) {
    const tanda = AmbilTandaDesimal(nilai);

    return (
        <span className={`font-semibold tabular-nums ${tanda === 0 ? 'text-teks-utama' : 'text-bahaya'}`}>
            {tanda > 0 ? '+' : ''}
            {FormatRupiah(nilai)}
            {tanda < 0 ? ' (kurang)' : tanda > 0 ? ' (lebih)' : ''}
        </span>
    );
}

/** F-11: laporan shift X (shift berjalan) / Z (shift tertutup) dari data server. */
function BagianLaporan({ laporan, tertutup }: { laporan: LaporanShift; tertutup: boolean }) {
    const p = laporan.Penjualan;
    const k = laporan.Kas;

    return (
        <Card className="gap-4 rounded-panel p-4 shadow-none" aria-labelledby="judul-laporan-shift">
            <div>
                <h2 id="judul-laporan-shift" className="text-subjudul font-semibold text-teks-utama">
                    {tertutup ? 'Laporan Z (shift ditutup)' : 'Laporan X (shift berjalan)'}
                </h2>
                <p className="text-keterangan text-teks-sekunder">
                    Dihitung dari data yang sudah diterima server. Penjualan yang di-void tidak termasuk penjualan.
                </p>
            </div>
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Nilai label="Jumlah transaksi">
                    <span className="tabular-nums">{p.JumlahTransaksi}</span>
                </Nilai>
                <Nilai label="Penjualan kotor">
                    <Uang nilai={p.PenjualanKotor} />
                </Nilai>
                <Nilai label="Diskon">
                    <Uang nilai={p.TotalDiskon} />
                </Nilai>
                <Nilai label="Penjualan bersih">
                    <Uang nilai={p.PenjualanBersih} tebal />
                </Nilai>
                <Nilai label="Pajak">
                    <Uang nilai={p.TotalPajak} />
                </Nilai>
                <Nilai label="Biaya layanan">
                    <Uang nilai={p.BiayaLayanan} />
                </Nilai>
                <Nilai label="Pembulatan">
                    <Uang nilai={p.Pembulatan} />
                </Nilai>
                <Nilai label="Total dibayar pelanggan">
                    <Uang nilai={p.TotalAkhir} tebal />
                </Nilai>
                <Nilai label="Void">
                    <span className="tabular-nums">
                        {p.JumlahVoid} | {FormatRupiah(p.NominalVoid)}
                    </span>
                </Nilai>
                <Nilai label="Retur">
                    <span className="tabular-nums">
                        {p.JumlahRetur} | {FormatRupiah(p.NominalRetur)}
                    </span>
                </Nilai>
            </dl>
            <div>
                <h3 className="text-label font-semibold text-teks-sekunder">Per metode bayar</h3>
                {p.PerMetode.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada pembayaran.</p>
                ) : (
                    <ul className="mt-1 flex flex-col divide-y divide-garis">
                        {p.PerMetode.map((m) => (
                            <li key={m.UuidMetodePembayaran} className="flex items-baseline justify-between gap-4 py-1">
                                <span className="min-w-0 break-words">{m.Nama}</span>
                                <Uang nilai={m.Jumlah} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <div>
                <h3 className="text-label font-semibold text-teks-sekunder">Kas laci</h3>
                <ul className="mt-1 flex flex-col divide-y divide-garis">
                    {[
                        ['Kas awal', k.KasAwal, ''],
                        ['Penjualan tunai bersih', k.TunaiMasukBersih, '+'],
                        ['Kas masuk', k.TotalMasuk, '+'],
                        ['Kas keluar', k.TotalKeluar, '−'],
                        ['Setoran', k.TotalSetoran, '−'],
                        ['Refund tunai (void & retur)', k.RefundTunai, '−'],
                    ].map(([label, nilai, tanda]) => (
                        <li key={label} className="flex items-baseline justify-between gap-4 py-1">
                            <span>{label}</span>
                            <span className="tabular-nums">
                                {tanda}
                                {FormatRupiah(nilai ?? '0')}
                            </span>
                        </li>
                    ))}
                    <li className="flex items-baseline justify-between gap-4 py-1 font-semibold">
                        <span>Kas seharusnya</span>
                        <Uang nilai={k.KasSeharusnya} tebal />
                    </li>
                </ul>
            </div>
        </Card>
    );
}

/** F-11: hasil tutup shift yang dikirim perangkat (angka kas seharusnya dihitung server saat diterima). */
function BagianTutup({ tutup }: { tutup: TutupShift }) {
    return (
        <Card className="gap-4 rounded-panel p-4 shadow-none" aria-labelledby="judul-tutup-shift">
            <h2 id="judul-tutup-shift" className="text-subjudul font-semibold text-teks-utama">
                Tutup shift
            </h2>
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Nilai label="Ditutup">
                    {tutup.DitutupOleh} | {FormatTanggalWaktu(tutup.DitutupPada)}
                </Nilai>
                <Nilai label="Kas seharusnya">
                    <Uang nilai={tutup.KasSeharusnya} />
                </Nilai>
                <Nilai label="Kas aktual (dihitung kasir)">
                    <Uang nilai={tutup.KasAktual} />
                </Nilai>
                <Nilai label="Selisih">
                    <Selisih nilai={tutup.Selisih} />
                </Nilai>
                {tutup.AlasanSelisih ? (
                    <Nilai label="Alasan selisih">
                        <span className="break-words">{tutup.AlasanSelisih}</span>
                    </Nilai>
                ) : null}
                {tutup.Penyetuju ? <Nilai label="Disetujui">{tutup.Penyetuju}</Nilai> : null}
                {tutup.UuidJurnal && tutup.NomorJurnal ? (
                    <Nilai label="Jurnal selisih">
                        <Link
                            href={`/kelola/akuntansi/jurnal/${tutup.UuidJurnal}`}
                            className="font-mono text-brand underline"
                        >
                            {tutup.NomorJurnal}
                        </Link>
                    </Nilai>
                ) : null}
            </dl>
            {tutup.PecahanKasAkhir.length > 0 ? (
                <div>
                    <h3 className="text-label font-semibold text-teks-sekunder">Hitungan pecahan kas akhir</h3>
                    <ul className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-isi tabular-nums">
                        {tutup.PecahanKasAkhir.map((p) => (
                            <li key={p.Nominal}>
                                {FormatRupiah(p.Nominal)} × {p.Jumlah}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
            {tutup.NonTunai.length > 0 ? (
                <div>
                    <h3 className="text-label font-semibold text-teks-sekunder">
                        Non-tunai: sistem dan hitungan kasir
                    </h3>
                    <ul className="mt-1 flex flex-col divide-y divide-garis">
                        {tutup.NonTunai.map((m) => {
                            const beda =
                                m.JumlahDilaporkan === null ? null : KurangiDesimal(m.JumlahDilaporkan, m.JumlahSistem);

                            return (
                                <li
                                    key={m.UuidMetodePembayaran}
                                    className="flex flex-col gap-0.5 py-1 sm:flex-row sm:justify-between sm:gap-4"
                                >
                                    <span className="min-w-0 break-words">{m.Nama}</span>
                                    <span className="tabular-nums">
                                        Sistem {FormatRupiah(m.JumlahSistem)} | Kasir{' '}
                                        {m.JumlahDilaporkan === null ? 'tidak diisi' : FormatRupiah(m.JumlahDilaporkan)}
                                        {beda !== null && AmbilTandaDesimal(beda) !== 0 ? (
                                            <span className="font-semibold text-bahaya">
                                                {' '}
                                                | beda {FormatRupiah(beda)}
                                            </span>
                                        ) : null}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ) : null}
        </Card>
    );
}

function FormTutupPaksa({ uuidShift, saatTutup }: { uuidShift: string; saatTutup: () => void }) {
    const formulir = useForm({ Alasan: '', KasAktual: '' });
    const Kirim = (p: FormEvent) => {
        p.preventDefault();
        formulir.post(`/kelola/kasir/shift/${uuidShift}/tutup-paksa`, { preserveScroll: true, onSuccess: saatTutup });
    };

    return (
        <DialogFormulir
            judul="Tutup paksa shift"
            keterangan="Shift ditutup sekarang atas nama Anda dan ditandai perlu ditinjau. Penjualan dan kas yang sudah tercatat tidak berubah."
            saatTutup={saatTutup}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Alasan tutup paksa"
                    keterangan="Misal: kasir lupa menutup shift kemarin, perangkat rusak."
                    nilai={formulir.data.Alasan}
                    saatBerubah={(v) => formulir.setData('Alasan', v)}
                    galat={formulir.errors.Alasan}
                    maxLength={255}
                    required
                    autoFocus
                />
                <BidangUang
                    label="Uang di laci menurut hitungan Anda (opsional)"
                    keterangan="Kosongkan bila tidak dihitung: kas dianggap sama dengan seharusnya (tanpa selisih). Bila diisi, selisihnya dibukukan sebagai selisih kas."
                    nilai={formulir.data.KasAktual}
                    saatBerubah={(v) => formulir.setData('KasAktual', v)}
                    galat={formulir.errors.KasAktual}
                />
                <div className="flex justify-end gap-2">
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={formulir.processing}>
                        Tutup shift sekarang
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

/**
 * F-06: detail shift (baca saja): pembukaan, pecahan kas awal, ringkasan kas non-penjualan, dan mutasi kas.
 * F-07b: penjualan yang dibuat di shift ini. F-11: laporan shift X/Z dan hasil tutup shift. Cetak struk bagian 4:
 * log buka laci manual tanpa transaksi. K-18: riwayat buka ulang shift dan tautan foto bukti kas.
 */
export default function HalamanDetailShift({
    Shift,
    MutasiKas,
    BukaLaci,
    BukaUlang,
    Penjualan,
    Laporan,
    Tutup,
    Izin,
}: PropsDetailShift) {
    const [tutupPaksa, AturTutupPaksa] = useState(false);
    const aktif = Shift.Status === 'Terbuka' || Shift.Status === 'DibukaUlang';

    return (
        <TataLetakAplikasi judul={`Shift ${Shift.NamaKasir} | ${FormatTanggalWaktu(Shift.DibukaPada)}`}>
            <Button asChild variant="link" className="h-auto self-start px-0">
                <Link href="/kelola/kasir/shift">Kembali ke daftar shift</Link>
            </Button>

            {aktif && Izin.TutupPaksa ? (
                <Pemberitahuan jenis="peringatan" judul="Shift ini masih terbuka">
                    <div className="flex flex-col items-start gap-2">
                        <p>
                            Kalau kasirnya sudah pulang atau perangkatnya tidak bisa menutup shift, Anda bisa menutupnya
                            dari sini supaya kasir bisa membuka shift baru.
                        </p>
                        <Tombol varian="sekunder" onClick={() => AturTutupPaksa(true)}>
                            Tutup paksa shift
                        </Tombol>
                    </div>
                </Pemberitahuan>
            ) : null}
            {tutupPaksa ? <FormTutupPaksa uuidShift={Shift.Uuid} saatTutup={() => AturTutupPaksa(false)} /> : null}

            {Shift.PerluTinjauan ? (
                <Pemberitahuan jenis="peringatan" judul="Shift ini perlu ditinjau">
                    {Shift.AlasanTinjauan ?? 'Shift ini diterima meski melanggar aturan satu shift terbuka.'}
                </Pemberitahuan>
            ) : null}

            <Card className="gap-4 rounded-panel p-4 shadow-none">
                <LencanaShift
                    status={Shift.Status}
                    label={Shift.LabelStatus}
                    perluTinjauan={Shift.PerluTinjauan}
                    bersama={Shift.Bersama}
                />
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Nilai label="Outlet">{Shift.NamaOutlet}</Nilai>
                    <Nilai label="Perangkat">
                        <span className="font-mono">{Shift.Perangkat}</span>
                    </Nilai>
                    <Nilai label="Hari bisnis">{FormatTanggal(Shift.TanggalBisnis)}</Nilai>
                    <Nilai label="Masuk dari kasir">{FormatTanggalWaktu(Shift.DiterimaPada)}</Nilai>
                    <Nilai label="Kas awal">
                        <span className="tabular-nums">{FormatRupiah(Shift.KasAwal)}</span>
                    </Nilai>
                    <Nilai label="Kas masuk">
                        <span className="tabular-nums">{FormatRupiah(Shift.TotalMasuk)}</span>
                    </Nilai>
                    <Nilai label="Kas keluar + setoran">
                        <span className="tabular-nums">
                            {FormatRupiah(Shift.TotalKeluar)} + {FormatRupiah(Shift.TotalSetoran)}
                        </span>
                    </Nilai>
                    <Nilai label="Kas di laci (tanpa penjualan)">
                        <span className="font-semibold tabular-nums">{FormatRupiah(Shift.KasNonPenjualan)}</span>
                    </Nilai>
                </dl>
                {Shift.PecahanKasAwal.length > 0 ? (
                    <div>
                        <h2 className="text-label font-semibold text-teks-sekunder">Hitungan pecahan kas awal</h2>
                        <ul className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-isi tabular-nums">
                            {Shift.PecahanKasAwal.map((p) => (
                                <li key={p.Nominal}>
                                    {FormatRupiah(p.Nominal)} × {p.Jumlah}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </Card>

            {Tutup ? <BagianTutup tutup={Tutup} /> : null}
            {BukaUlang.length > 0 ? (
                <>
                    <h2 className="text-subjudul font-semibold text-teks-utama">Buka ulang shift</h2>
                    <TabelData
                        id="kasir-shift-buka-ulang"
                        label="Buka ulang shift"
                        kolom={kolomBukaUlang}
                        sumber={{ mode: 'lokal', data: BukaUlang }}
                        ambilIdBaris={(b) => b.Uuid}
                        urutBawaan="DibukaUlangPada"
                        kosong={{ judul: 'Shift ini tidak pernah dibuka ulang.' }}
                    />
                </>
            ) : null}
            <BagianLaporan laporan={Laporan} tertutup={Tutup !== null} />

            <h2 className="text-subjudul font-semibold text-teks-utama">Kas masuk, keluar & setoran</h2>
            <TabelData
                id="kasir-shift-mutasi"
                label="Mutasi kas shift"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: MutasiKas }}
                ambilIdBaris={(m) => m.Uuid}
                urutBawaan="DicatatPada"
                saring={[
                    {
                        id: 'Jenis',
                        label: 'Jenis',
                        jenis: 'pilihanBanyak',
                        opsi: [...new Map(MutasiKas.map((m) => [m.Jenis, m.LabelJenis])).entries()].map(
                            ([nilai, label]) => ({ nilai, label }),
                        ),
                    },
                ]}
                kosong={{ judul: 'Belum ada kas masuk, kas keluar, atau setoran di shift ini.' }}
            />

            <h2 className="text-subjudul font-semibold text-teks-utama">Buka laci tanpa transaksi</h2>
            <TabelData
                id="kasir-shift-buka-laci"
                label="Buka laci tanpa transaksi"
                kolom={kolomBukaLaci}
                sumber={{ mode: 'lokal', data: BukaLaci }}
                ambilIdBaris={(b) => b.Uuid}
                urutBawaan="DibukaPada"
                cari="Cari alasan"
                kosong={{ judul: 'Laci tidak pernah dibuka tanpa transaksi di shift ini.' }}
            />

            <h2 className="text-subjudul font-semibold text-teks-utama">Penjualan</h2>
            <p className="text-isi text-teks-sekunder">
                {Penjualan.JumlahTransaksi} transaksi, total{' '}
                <span className="font-semibold tabular-nums">{FormatRupiah(Penjualan.TotalPenjualan)}</span>.
                {Penjualan.DaftarTerpotong ? (
                    <>
                        {' '}
                        Tabel menampilkan {Penjualan.Daftar.length} penjualan terakhir. Semua penjualan ada di{' '}
                        <Link href="/kelola/penjualan" className="font-semibold text-brand underline">
                            daftar penjualan
                        </Link>
                        .
                    </>
                ) : null}
            </p>
            <TabelData
                id="kasir-shift-penjualan"
                label="Penjualan shift"
                kolom={kolomPenjualan}
                sumber={{ mode: 'lokal', data: Penjualan.Daftar }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="DibuatOfflinePada"
                cari="Cari nomor"
                alamatDetail={(p) => `/kelola/penjualan/${p.Uuid}`}
                kosong={{ judul: 'Belum ada penjualan di shift ini.' }}
            />
        </TataLetakAplikasi>
    );
}
