import { Link, router, usePage } from '@inertiajs/react';
import { Fragment, useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { cn } from '@/Komponen/Ui/utils';
import { AdaAngkaAnggaran, HitungTotalSetahun, NamaBulan, SamaAnggaran, SingkatBulan } from '@/Pustaka/Anggaran';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { AkunAnggaran, PropsSusunAnggaran, TipeAkun } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamat = '/kelola/akuntansi/anggaran';
const kelompok: { Jenis: TipeAkun; Judul: string }[] = [
    { Jenis: 'Pendapatan', Judul: 'Pendapatan' },
    { Jenis: 'Hpp', Judul: 'Harga pokok penjualan' },
    { Jenis: 'Beban', Judul: 'Beban' },
];
const kelasKepala = 'h-auto px-2 py-2 text-label text-teks-sekunder';

type Isian = Record<string, string[]>;

function Awal(akun: AkunAnggaran[]): Isian {
    return Object.fromEntries(akun.map((a) => [a.Uuid, [...a.PerBulan]]));
}

/** Angka yang sama di semua dua belas bulan ("isi semua bulan"), atau kosong bila bulan-bulannya berbeda. */
function NilaiSeragam(perBulan: readonly string[]): string {
    const pertama = perBulan[0] ?? '';

    return pertama !== '' && perBulan.every((nilai) => nilai === pertama) ? pertama : '';
}

function IsiSusun({ Tahun, Outlet, OpsiOutlet, SeluruhUsahaBoleh, Akun }: PropsSusunAnggaran) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [awal] = useState(() => Awal(Akun));
    const [isian, AturIsian] = useState<Isian>(() => Awal(Akun));
    const [memproses, AturMemproses] = useState(false);
    const berubah = Akun.filter((a) => !SamaAnggaran(isian[a.Uuid] ?? [], awal[a.Uuid] ?? []));
    const kotor = berubah.length > 0;
    const adaAngka = Object.values(awal).some(AdaAngkaAnggaran);
    const namaOutlet = OpsiOutlet.find((o) => o.Uuid === Outlet)?.Nama ?? 'Seluruh usaha';
    const opsiTahun = Array.from(
        new Set([Tahun, new Date().getFullYear() - 1, new Date().getFullYear(), new Date().getFullYear() + 1]),
    )
        .sort((a, b) => b - a)
        .map((tahun) => ({ Nilai: String(tahun), Label: String(tahun) }));

    const Pindah = (ubah: { tahun?: number; outlet?: string }) => {
        const parameter = new URLSearchParams({ tahun: String(ubah.tahun ?? Tahun) });
        const outlet = ubah.outlet ?? Outlet;

        if (outlet !== '') {
            parameter.set('outlet', outlet);
        }

        router.get(`${alamat}/susun?${parameter.toString()}`);
    };

    const UbahBulan = (uuid: string, indeks: number, nilai: string) =>
        AturIsian((lama) => ({ ...lama, [uuid]: (lama[uuid] ?? []).map((n, i) => (i === indeks ? nilai : n)) }));

    const IsiSemua = (uuid: string, nilai: string) =>
        AturIsian((lama) => ({ ...lama, [uuid]: Array<string>(12).fill(nilai) }));

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (memproses || !kotor) {
            return;
        }

        router.put(
            alamat,
            {
                Tahun,
                Outlet: Outlet === '' ? null : Outlet,
                Baris: berubah.map((a) => ({ UuidAkun: a.Uuid, PerBulan: isian[a.Uuid] ?? [] })),
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
            },
        );
    };

    const Salin = () => {
        router.post(
            `${alamat}/salin?tahun=${String(Tahun)}${Outlet === '' ? '' : `&outlet=${Outlet}`}`,
            {},
            { preserveScroll: true },
        );
    };

    const GalatBaris = (uuid: string) => {
        const indeks = berubah.findIndex((a) => a.Uuid === uuid);

        return indeks < 0
            ? undefined
            : Object.entries(galat).find(([kunci]) => kunci.startsWith(`Baris.${String(indeks)}.`))?.[1];
    };

    const kecuali = [...Object.keys(galat).filter((k) => k.startsWith('Baris.'))];

    return (
        <TataLetakAplikasi
            judul={`Susun anggaran ${String(Tahun)}`}
            jejak={[{ label: 'Anggaran vs realisasi', href: alamat }]}
        >
            <DaftarGalatServer galat={galat} kecuali={kecuali} />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Isi angka anggaran per akun dan per bulan. Kolom &quot;Semua bulan&quot; mengisi dua belas bulan
                sekaligus; bulan yang dikosongkan atau diisi 0 berarti tidak dianggarkan. Anggaran seluruh usaha dan
                anggaran tiap outlet disimpan terpisah.
            </p>
            <section
                aria-label="Cakupan anggaran"
                className="grid gap-3 rounded-panel border border-garis bg-permukaan p-3 sm:grid-cols-2 lg:grid-cols-3 lg:items-end"
            >
                <BidangPilihan
                    label="Tahun"
                    nilai={String(Tahun)}
                    opsi={opsiTahun}
                    disabled={kotor}
                    saatBerubah={(nilai) => Pindah({ tahun: Number(nilai) })}
                />
                <BidangPilihan
                    label="Cakupan"
                    nilai={Outlet}
                    opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                    {...(SeluruhUsahaBoleh ? { kosong: 'Seluruh usaha' } : {})}
                    disabled={kotor}
                    saatBerubah={(nilai) => Pindah({ outlet: nilai })}
                />
                <p
                    role="status"
                    className={cn('text-label', kotor ? 'font-semibold text-peringatan' : 'text-teks-sekunder')}
                >
                    {kotor
                        ? `${String(berubah.length)} akun diubah dan belum disimpan. Simpan dulu sebelum mengganti tahun atau cakupan.`
                        : `Menyusun anggaran ${namaOutlet}.`}
                </p>
            </section>
            <form onSubmit={Simpan} className="flex flex-col gap-4" aria-label="Formulir anggaran" noValidate>
                <div className="rounded-panel border border-garis bg-card">
                    <TabelForm label="Isian anggaran per bulan" lebar="dokumen">
                        <TableCaption className="sr-only">
                            Satu baris per akun dengan angka anggaran Januari sampai Desember.
                        </TableCaption>
                        <TableHeader>
                            <TableRow className="border-garis hover:bg-transparent">
                                <TableHead scope="col" className={cn(kelasHeadSticky, kelasKepala)}>
                                    Akun
                                </TableHead>
                                <TableHead scope="col" className={cn(kelasKepala, 'text-right')}>
                                    Semua bulan
                                </TableHead>
                                {NamaBulan.map((nama, indeks) => (
                                    <TableHead key={nama} scope="col" className={cn(kelasKepala, 'text-right')}>
                                        <span aria-hidden="true">{SingkatBulan(indeks)}</span>
                                        <span className="sr-only">{nama}</span>
                                    </TableHead>
                                ))}
                                <TableHead scope="col" className={cn(kelasKepala, 'text-right')}>
                                    Setahun
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {kelompok.map((k) => {
                                const akunKelompok = Akun.filter((a) => a.Jenis === k.Jenis);

                                if (akunKelompok.length === 0) {
                                    return null;
                                }

                                return (
                                    <Fragment key={k.Jenis}>
                                        <TableRow className="border-garis bg-permukaan-redup hover:bg-permukaan-redup">
                                            <TableCell colSpan={15} className="px-3 py-2 font-semibold text-teks-utama">
                                                {k.Judul}
                                            </TableCell>
                                        </TableRow>
                                        {akunKelompok.map((a) => {
                                            const nilai = isian[a.Uuid] ?? [];
                                            const galatBaris = GalatBaris(a.Uuid);

                                            return (
                                                <TableRow key={a.Uuid} className="border-garis align-top">
                                                    <TableCell className={cn(kelasHeadSticky, 'px-3 py-2')}>
                                                        <span className="flex flex-wrap gap-2 break-words text-teks-utama">
                                                            <span className="font-mono text-teks-sekunder">
                                                                {a.Kode}
                                                            </span>
                                                            {a.Nama}
                                                        </span>
                                                        {!a.Aktif ? (
                                                            <span className="text-label text-teks-sekunder">
                                                                Akun nonaktif
                                                            </span>
                                                        ) : null}
                                                        {galatBaris ? (
                                                            <span role="alert" className="block text-label text-bahaya">
                                                                {galatBaris}
                                                            </span>
                                                        ) : null}
                                                    </TableCell>
                                                    <TableCell className="px-2 py-2">
                                                        <BidangUang
                                                            label={`Semua bulan ${a.Nama}`}
                                                            labelTersembunyi
                                                            nilai={NilaiSeragam(nilai)}
                                                            saatBerubah={(n) => IsiSemua(a.Uuid, n)}
                                                            disabled={!a.Aktif}
                                                        />
                                                    </TableCell>
                                                    {NamaBulan.map((bulan, indeks) => (
                                                        <TableCell key={bulan} className="px-2 py-2">
                                                            <BidangUang
                                                                label={`${a.Nama} ${bulan}`}
                                                                labelTersembunyi
                                                                nilai={nilai[indeks] ?? ''}
                                                                saatBerubah={(n) => UbahBulan(a.Uuid, indeks, n)}
                                                            />
                                                        </TableCell>
                                                    ))}
                                                    <TableCell className="px-3 py-2 text-right font-semibold whitespace-nowrap tabular-nums">
                                                        {FormatRupiah(HitungTotalSetahun(nilai))}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                    </TabelForm>
                </div>
                {Akun.length === 0 ? (
                    <p role="status" className="text-isi text-teks-sekunder">
                        Belum ada akun pendapatan, HPP, atau beban. Tambahkan akunnya di Bagan akun dulu.
                    </p>
                ) : null}
                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses} disabled={!kotor}>
                        Simpan anggaran
                    </Tombol>
                    {!adaAngka && !kotor ? (
                        <Tombol type="button" varian="sekunder" onClick={Salin}>
                            {`Salin dari ${String(Tahun - 1)}`}
                        </Tombol>
                    ) : null}
                    <Button asChild variant="outline">
                        <Link href={`${alamat}?tahun=${String(Tahun)}${Outlet === '' ? '' : `&outlet=${Outlet}`}`}>
                            Lihat laporan
                        </Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}

const kelasHeadSticky = 'sticky left-0 z-10 min-w-48 bg-card';

/**
 * F8 (FIN-11) halaman "Susun anggaran": tabel isian akun × dua belas bulan untuk satu tahun dan satu cakupan (seluruh usaha
 * atau satu outlet). Hanya akun yang diubah yang dikirim; server memeriksa ulang akun, angka, dan akses outlet. Isian
 * dibangun ulang tiap data dari server berganti (setelah simpan atau salin).
 */
export default function HalamanSusunAnggaran(props: PropsSusunAnggaran) {
    const kunci = `${String(props.Tahun)}|${props.Outlet}|${props.Akun.map((a) => a.PerBulan.join(',')).join('|')}`;

    return <IsiSusun key={kunci} {...props} />;
}
