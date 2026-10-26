import { Link, router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilGalatBerawalan } from '@/Komponen/Katalog/BantuanKatalog';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormDaftarHarga from '@/Komponen/Katalog/FormDaftarHarga';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelHargaBertingkat, { PeriksaBarisHarga } from '@/Komponen/Katalog/TabelHargaBertingkat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Komponen/Ui/alert-dialog';
import { Button } from '@/Komponen/Ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisHarga, PropsDetailDaftarHarga } from '@/Tipe/Katalog';

type BarisProdukDaftar = PropsDetailDaftarHarga['Baris']['Data'][number];

/** Hanya baris yang berubah yang dikirim; server mengganti set harga per satuan produk (DesainF03 C.3). */
export function AmbilBarisBerubah(
    awal: BarisProdukDaftar[],
    harga: Record<string, BarisHarga[]>,
): { UuidProdukSatuan: string; Harga: BarisHarga[] }[] {
    return awal
        .filter((baris) => JSON.stringify(harga[baris.UuidProdukSatuan] ?? []) !== JSON.stringify(baris.Harga))
        .map((baris) => ({ UuidProdukSatuan: baris.UuidProdukSatuan, Harga: harga[baris.UuidProdukSatuan] ?? [] }));
}

/** F-03 isi satu daftar harga: pengaturan, status, dan harga per produk-satuan (boleh bertingkat, misal 12+). */
export default function HalamanDetailDaftarHarga({
    DaftarHarga,
    Baris,
    Outlet,
    Kanal,
    OpsiTier,
    ZonaWaktu,
    Izin,
}: PropsDetailDaftarHarga) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [ubahPengaturan, AturUbahPengaturan] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const [periksa, AturPeriksa] = useState(false);
    // Isian yang diubah (per produk-satuan) dan baris yang sudah pernah tampil, agar perubahan di beberapa
    // halaman tabel tersimpan sekaligus.
    const [ubahan, AturUbahan] = useState<Record<string, BarisHarga[]>>({});
    const [dilihat, AturDilihat] = useState<Record<string, BarisProdukDaftar>>({});
    const [urutanKiriman, AturUrutanKiriman] = useState<string[]>([]);
    const barisDilihat = Object.values(dilihat);
    const harga: Record<string, BarisHarga[]> = {
        ...Object.fromEntries(barisDilihat.map((baris) => [baris.UuidProdukSatuan, baris.Harga])),
        ...ubahan,
    };
    const berubah = AmbilBarisBerubah(barisDilihat, harga);
    const alamat = `/kelola/daftar-harga/${DaftarHarga.Uuid}`;
    const formAwal = {
        Nama: DaftarHarga.Nama,
        UuidOutlet: DaftarHarga.UuidOutlet,
        Kanal: DaftarHarga.Kanal,
        TierPelanggan: DaftarHarga.TierPelanggan,
        MulaiPada: DaftarHarga.MulaiPada,
        SelesaiPada: DaftarHarga.SelesaiPada,
        Prioritas: DaftarHarga.Prioritas,
    };

    const CatatDilihat = useCallback((baris: BarisProdukDaftar[]) => {
        AturDilihat((lama) => ({
            ...lama,
            ...Object.fromEntries(
                baris.filter((b) => !(b.UuidProdukSatuan in lama)).map((b) => [b.UuidProdukSatuan, b]),
            ),
        }));
    }, []);

    const UbahStatus = () =>
        router.post(`${alamat}/${DaftarHarga.Aktif ? 'nonaktifkan' : 'aktifkan'}`, {}, { preserveScroll: true });

    const Simpan = () => {
        AturPeriksa(true);
        const salah = berubah.some((baris) => {
            const hasil = PeriksaBarisHarga(baris.Harga, { wajibDasar: false, bolehDesimal: true });

            return Object.keys(hasil.perBaris).length > 0;
        });

        if (salah || berubah.length === 0) {
            return;
        }

        AturUrutanKiriman(berubah.map((baris) => baris.UuidProdukSatuan));
        router.put(
            `${alamat}/harga`,
            { Baris: berubah },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => {
                    // Harga tersimpan menjadi harga awal baru: baris dimuat ulang dari server.
                    AturUbahan({});
                    AturDilihat({});
                    AturPeriksa(false);
                },
            },
        );
    };

    const kolom: KolomTabel<BarisProdukDaftar>[] = [
        {
            id: 'Produk',
            header: 'Produk',
            enableSorting: false,
            meta: { label: 'Produk', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: baris } }) => (
                <>
                    <span className="block font-semibold break-words text-teks-utama">{baris.NamaProduk}</span>
                    <span className="text-keterangan font-normal text-teks-sekunder">
                        <span className="font-mono">{baris.Sku ?? 'Tanpa SKU'}</span> | per {baris.NamaSatuan}
                    </span>
                </>
            ),
        },
        {
            id: 'HargaDasar',
            header: 'Harga biasa',
            enableSorting: false,
            meta: { label: 'Harga biasa', angka: true, prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
            cell: ({ row }) => (row.original.HargaDasar === null ? '—' : FormatRupiah(row.original.HargaDasar)),
        },
        {
            id: 'HargaDaftar',
            header: 'Harga di daftar ini',
            enableSorting: false,
            meta: { label: 'Harga di daftar ini', prioritas: 'penting', wajib: true },
            cell: ({ row: { original: baris } }) => {
                const indeksKiriman = urutanKiriman.indexOf(baris.UuidProdukSatuan);

                return (
                    <TabelHargaBertingkat
                        judul={`Harga ${baris.NamaProduk} per ${baris.NamaSatuan}`}
                        baris={ubahan[baris.UuidProdukSatuan] ?? baris.Harga}
                        saatBerubah={(nilai) => AturUbahan((lama) => ({ ...lama, [baris.UuidProdukSatuan]: nilai }))}
                        simbolSatuan={baris.NamaSatuan}
                        bolehDesimal
                        wajibDasar={false}
                        galatServer={
                            indeksKiriman < 0
                                ? {}
                                : AmbilGalatBerawalan(props.errors, `Baris.${String(indeksKiriman)}.Harga`)
                        }
                        tampilkanGalat={periksa}
                        disabled={!Izin.UbahHarga}
                    />
                );
            },
        },
    ];

    return (
        <TataLetakAplikasi judul={DaftarHarga.Nama}>
            <p className="text-label">
                <Link href="/kelola/daftar-harga" className="font-semibold text-brand underline">
                    Kembali ke daftar harga
                </Link>
            </p>
            {!Izin.UbahHarga ? <PesanHanyaLihat izin="produk.harga.ubah" objek="daftar harga ini" /> : null}
            <DaftarGalatServer
                galat={props.errors}
                kecuali={Object.keys(props.errors).filter((kunci) => ubahPengaturan || /^Baris\./.test(kunci))}
            />

            <div className="flex flex-wrap items-center gap-2">
                <LabelStatus
                    jenis={DaftarHarga.Aktif ? 'sukses' : 'netral'}
                    teks={DaftarHarga.Aktif ? 'Aktif' : 'Nonaktif'}
                />
                <span className="text-isi text-teks-sekunder">
                    {[
                        DaftarHarga.UuidOutlet.length === 0
                            ? 'Semua outlet'
                            : Outlet.filter((item) => DaftarHarga.UuidOutlet.includes(item.Nilai))
                                  .map((item) => item.Label)
                                  .join(', '),
                        Kanal.find((item) => item.Nilai === DaftarHarga.Kanal)?.Label ?? 'Semua kanal',
                        DaftarHarga.TierPelanggan ? `Pelanggan ${DaftarHarga.TierPelanggan}` : null,
                        `Prioritas ${DaftarHarga.Prioritas}`,
                    ]
                        .filter(Boolean)
                        .join(' | ')}
                </span>
                {Izin.UbahHarga ? (
                    <span className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" onClick={() => AturUbahPengaturan(true)}>
                            Ubah pengaturan
                        </Button>
                        {DaftarHarga.Aktif ? (
                            <AlertDialog>
                                <AlertDialogTrigger asChild>
                                    <Button type="button" variant="outline">
                                        Nonaktifkan daftar
                                    </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>Nonaktifkan {DaftarHarga.Nama}?</AlertDialogTitle>
                                        <AlertDialogDescription>
                                            Kasir berhenti memakai harga di daftar ini dan kembali ke harga jual biasa
                                            atau daftar lain yang cocok. Daftar bisa diaktifkan lagi kapan saja.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>Batal</AlertDialogCancel>
                                        <AlertDialogAction variant="destructive" onClick={UbahStatus}>
                                            Nonaktifkan daftar
                                        </AlertDialogAction>
                                    </AlertDialogFooter>
                                </AlertDialogContent>
                            </AlertDialog>
                        ) : (
                            <Button type="button" variant="outline" onClick={UbahStatus}>
                                Aktifkan daftar
                            </Button>
                        )}
                    </span>
                ) : null}
            </div>

            <Sheet open={ubahPengaturan} onOpenChange={AturUbahPengaturan}>
                {ubahPengaturan ? (
                    <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                        <SheetHeader>
                            <SheetTitle>Ubah pengaturan {DaftarHarga.Nama}</SheetTitle>
                            <SheetDescription>
                                Atur untuk outlet, kanal, tingkat pelanggan, dan periode mana daftar ini berlaku.
                            </SheetDescription>
                        </SheetHeader>
                        <div className="px-4 pb-4">
                            <FormDaftarHarga
                                uuid={DaftarHarga.Uuid}
                                awal={formAwal}
                                outlet={Outlet}
                                kanal={Kanal}
                                tier={OpsiTier ?? []}
                                zonaWaktu={ZonaWaktu}
                                saatSelesai={() => AturUbahPengaturan(false)}
                                saatBatal={() => AturUbahPengaturan(false)}
                            />
                        </div>
                    </SheetContent>
                ) : null}
            </Sheet>

            <TabelData
                id={`katalog-daftar-harga-${DaftarHarga.Uuid}`}
                label={`Harga produk di ${DaftarHarga.Nama}`}
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Baris }}
                ambilIdBaris={(baris) => baris.UuidProdukSatuan}
                cari="Cari nama atau SKU produk"
                saatData={CatatDilihat}
                kosong={{ judul: 'Belum ada produk yang bisa dijual.' }}
            />
            {Izin.UbahHarga && barisDilihat.length > 0 ? (
                <div className="sticky bottom-0 flex flex-wrap items-center gap-3 border-t border-garis bg-latar py-3 tepi-bawah-aman">
                    <Tombol onClick={Simpan} memproses={memproses} disabled={berubah.length === 0}>
                        Simpan harga
                    </Tombol>
                    <span aria-live="polite" className="text-label text-teks-sekunder tabular-nums">
                        {berubah.length === 0 ? 'Belum ada perubahan.' : `${String(berubah.length)} produk berubah.`}
                    </span>
                </div>
            ) : null}
        </TataLetakAplikasi>
    );
}
