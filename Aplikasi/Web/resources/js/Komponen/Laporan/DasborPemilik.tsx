import { Link, router } from '@inertiajs/react';
import { lazy, Suspense, useState, type ReactNode } from 'react';

import PemilihRentangTanggal from '@/Komponen/Tanggal/PemilihRentangTanggal';
import { Button } from '@/Komponen/Ui/button';
import Panel from '@/Komponen/Kelola/Panel';
import { Card, CardContent, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import { Skeleton } from '@/Komponen/Ui/skeleton';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import { HitungPerubahanPersen, NamaHari } from '@/Pustaka/Laporan';
import { GabungRentang, PecahRentang, TulisTanggal } from '@/Pustaka/Tanggal';
import type { AngkaPenjualan, DasborPemilik as DataDasbor, PembandingDasbor } from '@/Tipe/Laporan';

// T1 (PRD v3.30): pustaka grafik (recharts) dimuat belakangan supaya Beranda tampil tanpa menunggunya.
const GrafikPenjualanHarian = lazy(() => import('./GrafikPenjualanHarian'));

type Ukuran = 'uang' | 'bilangan';

/** Teks perbandingan: "+12,5% dari kemarin (Rp 10.000.000)" atau "Rabu lalu: belum ada penjualan". */
function TeksBanding({
    sekarang,
    pembanding,
    nama,
    tampil,
}: {
    sekarang: string;
    pembanding: string;
    nama: string;
    tampil: string;
}) {
    const persen = HitungPerubahanPersen(sekarang, pembanding);

    return (
        <span className="block">
            {persen === null ? `${nama}: belum ada penjualan` : `${persen} dari ${nama} (${tampil})`}
        </span>
    );
}

/** Nama pembanding yang dibaca pengguna: "kemarin", "Rabu lalu", atau "periode sebelumnya". */
function NamaPembanding(pembanding: PembandingDasbor, namaMingguLalu: string): string {
    if (pembanding.Nama === 'mingguLalu') {
        return `${namaMingguLalu} lalu`;
    }

    return pembanding.Nama === 'kemarin' ? 'kemarin' : 'periode sebelumnya';
}

function KartuAngka({
    judul,
    kunci,
    ukuran,
    data,
    namaMingguLalu,
}: {
    judul: string;
    kunci: keyof AngkaPenjualan;
    ukuran: Ukuran;
    data: DataDasbor;
    namaMingguLalu: string;
}) {
    const Ambil = (a: AngkaPenjualan) => String(a[kunci] ?? '0');
    const Tampil = (nilai: string) => (ukuran === 'uang' ? FormatRupiah(nilai) : nilai);
    const KeAngka = (nilai: string) => (ukuran === 'uang' ? nilai : `${nilai}.00`);

    return (
        <Card className="gap-1 py-4 rounded-panel shadow-none">
            <CardHeader className="px-4">
                <CardTitle>
                    <h3 className="text-label font-semibold text-teks-sekunder">{judul}</h3>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1 px-4">
                <p className="text-judul font-semibold text-teks-utama tabular-nums">{Tampil(Ambil(data.Angka))}</p>
                <p className="text-keterangan text-teks-sekunder tabular-nums">
                    {data.Pembanding.map((p) => (
                        <TeksBanding
                            key={p.Nama}
                            sekarang={KeAngka(Ambil(data.Angka))}
                            pembanding={KeAngka(Ambil(p.Angka))}
                            nama={NamaPembanding(p, namaMingguLalu)}
                            tampil={Tampil(Ambil(p.Angka))}
                        />
                    ))}
                </p>
            </CardContent>
        </Card>
    );
}

function TautanPanel({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Button asChild variant="link" className="h-auto p-0 text-label font-semibold">
            <Link href={href}>{children}</Link>
        </Button>
    );
}

function BarisDaftar({ kiri, kanan, keterangan }: { kiri: ReactNode; kanan: ReactNode; keterangan?: ReactNode }) {
    return (
        <li className="flex items-start justify-between gap-3 border-b border-garis py-2 last:border-b-0">
            <span className="min-w-0">
                <span className="block break-words text-isi text-teks-utama">{kiri}</span>
                {keterangan ? <span className="block text-keterangan text-teks-sekunder">{keterangan}</span> : null}
            </span>
            <span className="shrink-0 text-right text-isi text-teks-utama tabular-nums">{kanan}</span>
        </li>
    );
}

/** Jumlah hari terjauh yang dilayani server (`DasborPemilik::MAKS_HARI`). */
const MAKS_HARI_RENTANG = 92;

/** Judul bagian ringkasan menurut periode: "hari ini | 7 Okt 2026", "8 Okt 2026", atau "1 Okt 2026 – 7 Okt 2026". */
function JudulPeriode(periode: DataDasbor['Periode']): string {
    if (!periode.SatuHari) {
        return `${FormatTanggal(periode.Dari)} – ${FormatTanggal(periode.Sampai)}`;
    }

    return periode.Sampai === periode.HariIni
        ? `hari ini | ${FormatTanggal(periode.Sampai)}`
        : FormatTanggal(periode.Sampai);
}

/**
 * Dasbor pemilik F-14a di beranda: angka periode terpilih (bawaan hari ini) vs pembandingnya, grafik, produk terlaris,
 * penjualan per outlet, dan hal yang perlu perhatian (stok kritis, shift, selisih kas, penjualan ditinjau). Rentang
 * dipilih lewat `PemilihRentangTanggal` dan disimpan di URL (`?dari=&sampai=`) supaya bisa dibagikan & dimuat ulang;
 * hanya prop `Dasbor` yang dimuat ulang. Perlu perhatian & shift selalu keadaan saat ini, tidak mengikuti rentang.
 */
export default function DasborPemilik({ data }: { data: DataDasbor }) {
    const { Periode: periode } = data;
    const indeksHari = (new Date(`${data.Tanggal}T00:00:00`).getDay() + 6) % 7;
    const namaMingguLalu = NamaHari[indeksHari] ?? 'minggu';
    const [memuat, AturMemuat] = useState(false);
    const hariIni = new Date(`${periode.HariIni}T00:00:00`);
    const batasAwal = TulisTanggal(
        new Date(hariIni.getFullYear(), hariIni.getMonth(), hariIni.getDate() - (MAKS_HARI_RENTANG - 1)),
    );
    const nilaiRentang = GabungRentang(periode.Dari, periode.Sampai);
    const hariGrafik = periode.SatuHari ? 14 : periode.Hari;
    const judulGrafik = periode.SatuHari
        ? periode.Sampai === periode.HariIni
            ? 'Penjualan bersih 14 hari terakhir'
            : `Penjualan bersih 14 hari sampai ${FormatTanggal(periode.Sampai)}`
        : `Penjualan bersih ${String(hariGrafik)} hari`;
    const judulTerlaris =
        periode.SatuHari && periode.Sampai === periode.HariIni
            ? 'Produk terlaris 7 hari'
            : periode.SatuHari
              ? `Produk terlaris 7 hari sampai ${FormatTanggal(periode.Sampai)}`
              : `Produk terlaris ${String(periode.JumlahHariTerlaris)} hari`;
    const teksKosongTerlaris = periode.SatuHari
        ? 'Belum ada penjualan dalam 7 hari itu.'
        : 'Belum ada penjualan pada periode ini.';

    const Pilih = (nilai: string) => {
        const [dari, sampai] = PecahRentang(nilai);
        // Kosong = kembali ke bawaan (hari ini); satu ujung saja = satu hari itu.
        const params = nilai === '' ? {} : { dari: dari || sampai, sampai: sampai || dari };

        router.get('/kelola', params, {
            only: ['Dasbor'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => AturMemuat(true),
            onFinish: () => AturMemuat(false),
        });
    };

    return (
        <section
            aria-labelledby="judul-dasbor"
            aria-busy={memuat}
            className={`flex flex-col gap-4 transition-opacity ${memuat ? 'opacity-60' : ''}`}
        >
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h2 id="judul-dasbor" className="text-subjudul font-semibold text-teks-utama">
                        Ringkasan {JudulPeriode(periode)}
                    </h2>
                    <TautanPanel href="/kelola/laporan/penjualan">Buka laporan penjualan</TautanPanel>
                </div>
                <div className="w-full sm:w-72">
                    <PemilihRentangTanggal
                        label="Periode"
                        nilai={nilaiRentang}
                        saatBerubah={Pilih}
                        min={batasAwal}
                        max={periode.HariIni}
                        kosong="Hari ini"
                    />
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <KartuAngka
                    judul="Penjualan bersih"
                    kunci="Bersih"
                    ukuran="uang"
                    data={data}
                    namaMingguLalu={namaMingguLalu}
                />
                <KartuAngka
                    judul="Transaksi"
                    kunci="JumlahTransaksi"
                    ukuran="bilangan"
                    data={data}
                    namaMingguLalu={namaMingguLalu}
                />
                <KartuAngka
                    judul="Rata-rata keranjang"
                    kunci="RataRataKeranjang"
                    ukuran="uang"
                    data={data}
                    namaMingguLalu={namaMingguLalu}
                />
                <KartuAngka
                    judul="Laba kotor"
                    kunci="LabaKotor"
                    ukuran="uang"
                    data={data}
                    namaMingguLalu={namaMingguLalu}
                />
            </div>

            <Panel tingkat="h3" judul={judulGrafik}>
                <Suspense fallback={<Skeleton className="h-64 w-full" aria-label="Memuat grafik" />}>
                    <GrafikPenjualanHarian data={data.Grafik} judul={judulGrafik} />
                </Suspense>
            </Panel>

            <div className="grid gap-3 lg:grid-cols-2">
                <Panel
                    tingkat="h3"
                    judul={judulTerlaris}
                    aksi={<TautanPanel href="/kelola/laporan/penjualan?tab=produk">Semua produk</TautanPanel>}
                >
                    {data.ProdukTerlaris.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">{teksKosongTerlaris}</p>
                    ) : (
                        <ol>
                            {data.ProdukTerlaris.map((p) => (
                                <BarisDaftar
                                    key={p.Kunci}
                                    kiri={p.NamaProduk}
                                    keterangan={`${FormatJumlahStok(p.Qty)} terjual`}
                                    kanan={FormatRupiah(p.Bersih)}
                                />
                            ))}
                        </ol>
                    )}
                </Panel>

                <Panel
                    tingkat="h3"
                    judul={
                        periode.SatuHari
                            ? `Penjualan per outlet ${periode.Sampai === periode.HariIni ? 'hari ini' : FormatTanggal(periode.Sampai)}`
                            : 'Penjualan per outlet pada periode'
                    }
                >
                    {data.PerOutlet.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">Belum ada outlet.</p>
                    ) : (
                        <ul>
                            {data.PerOutlet.map((o) => (
                                <BarisDaftar
                                    key={o.Kunci}
                                    kiri={o.NamaOutlet}
                                    keterangan={`${String(o.JumlahTransaksi)} transaksi`}
                                    kanan={FormatRupiah(o.Bersih)}
                                />
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel
                    tingkat="h3"
                    judul="Perlu perhatian"
                    aksi={
                        data.JumlahPerluTinjauan > 0 ? (
                            <TautanPanel href="/kelola/penjualan?saring[PerluTinjauan]=1">Tinjau penjualan</TautanPanel>
                        ) : null
                    }
                >
                    <ul>
                        <BarisDaftar
                            kiri="Penjualan perlu ditinjau"
                            kanan={
                                <LabelStatus
                                    jenis={data.JumlahPerluTinjauan > 0 ? 'peringatan' : 'netral'}
                                    teks={String(data.JumlahPerluTinjauan)}
                                />
                            }
                        />
                        <BarisDaftar
                            kiri="Shift masih terbuka"
                            keterangan={
                                data.Shift.Terbuka.map((s) => `${s.NamaKasir} | ${s.NamaOutlet}`).join(', ') || null
                            }
                            kanan={String(data.Shift.JumlahTerbuka)}
                        />
                        {data.StokKritis ? (
                            <BarisDaftar
                                kiri={
                                    <Link href="/kelola/laporan/stok?tab=kritis" className="text-brand underline">
                                        Stok kritis
                                    </Link>
                                }
                                keterangan={
                                    data.StokKritis.Baris.map(
                                        (b) => `${b.NamaProduk} (${FormatJumlahStok(b.Saldo, b.SimbolSatuan)})`,
                                    ).join(', ') || null
                                }
                                kanan={
                                    <LabelStatus
                                        jenis={data.StokKritis.Jumlah > 0 ? 'peringatan' : 'netral'}
                                        teks={String(data.StokKritis.Jumlah)}
                                    />
                                }
                            />
                        ) : null}
                    </ul>
                </Panel>

                <Panel
                    tingkat="h3"
                    judul="Selisih kas shift terbaru"
                    aksi={<TautanPanel href="/kelola/kasir/shift">Semua shift</TautanPanel>}
                >
                    {data.Shift.Tertutup.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">Belum ada shift yang ditutup.</p>
                    ) : (
                        <ul>
                            {data.Shift.Tertutup.map((s) => {
                                const tanda = s.Selisih === null ? 0 : AmbilTandaDesimal(s.Selisih);

                                return (
                                    <BarisDaftar
                                        key={s.Uuid}
                                        kiri={
                                            <Link
                                                href={`/kelola/kasir/shift/${s.Uuid}`}
                                                className="text-brand underline"
                                            >
                                                {s.NamaKasir || 'Shift'} | {s.NamaOutlet}
                                            </Link>
                                        }
                                        keterangan={FormatTanggalWaktu(s.DitutupPada)}
                                        kanan={
                                            <span className="flex flex-col items-end gap-1">
                                                <span>{s.Selisih === null ? '—' : FormatRupiah(s.Selisih)}</span>
                                                <LabelStatus
                                                    jenis={tanda === 0 ? 'sukses' : 'peringatan'}
                                                    teks={tanda === 0 ? 'Pas' : tanda > 0 ? 'Lebih' : 'Kurang'}
                                                />
                                            </span>
                                        }
                                    />
                                );
                            })}
                        </ul>
                    )}
                </Panel>
            </div>
        </section>
    );
}
