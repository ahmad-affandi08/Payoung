import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { PindahkanItem } from '@/Komponen/Katalog/BantuanKatalog';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import KeadaanKosong from '@/Komponen/Katalog/KeadaanKosong';
import KepalaProduk from '@/Komponen/Katalog/KepalaProduk';
import Panel from '@/Komponen/Kelola/Panel';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Button } from '@/Komponen/Ui/button';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsPilihanProduk } from '@/Tipe/Katalog';

type Kelompok = PropsPilihanProduk['Terpasang'][number];

/** F-03 pilihan (modifier) produk: pasang, lepas, dan urutkan kelompok pilihan. Anak varian mewarisi dari induk. */
export default function HalamanPilihanProduk({ Kepala, Terpasang, Tersedia, DariInduk, Izin }: PropsPilihanProduk) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [terpasang, AturTerpasang] = useState<Kelompok[]>(Terpasang);
    const [memproses, AturMemproses] = useState(false);
    const bolehUbah = Izin.Kelola && !DariInduk;
    const semua = [...Terpasang, ...Tersedia];
    const tersedia = semua.filter((item) => !terpasang.some((pasang) => pasang.Uuid === item.Uuid));

    const Simpan = () =>
        router.put(
            `/kelola/produk/${Kepala.Uuid}/pilihan`,
            { KelompokPilihan: terpasang.map((item) => item.Uuid) },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <TataLetakAplikasi judul={`Pilihan ${Kepala.Nama}`}>
            <KepalaProduk kepala={Kepala} tabAktif="Pilihan" />
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="pilihan produk ini" /> : null}
            <DaftarGalatServer galat={props.errors} />
            {DariInduk ? (
                <Pemberitahuan jenis="info">
                    Varian memakai pilihan dari produk induknya.{' '}
                    {Kepala.UuidInduk ? (
                        <Link href={`/kelola/produk/${Kepala.UuidInduk}/pilihan`}>
                            Ubah pilihan di {Kepala.NamaInduk ?? 'produk induk'}
                        </Link>
                    ) : null}
                </Pemberitahuan>
            ) : null}

            {semua.length === 0 ? (
                <KeadaanKosong judul="Belum ada kelompok pilihan, misal Level gula atau Topping.">
                    <Button asChild variant="outline">
                        <Link href="/kelola/kelompok-pilihan">Buat kelompok pilihan</Link>
                    </Button>
                </KeadaanKosong>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel
                        judul={`Terpasang (${String(terpasang.length)})`}
                        idJudul="judul-terpasang"
                        keterangan="Urutan di sini = urutan tampil di kasir."
                    >
                        {terpasang.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">Produk ini dijual tanpa pilihan.</p>
                        ) : (
                            <ol className="flex flex-col divide-y divide-garis">
                                {terpasang.map((item, indeks) => (
                                    <li
                                        key={item.Uuid}
                                        className="flex flex-wrap items-start justify-between gap-2 py-2"
                                    >
                                        <span className="min-w-0">
                                            <span className="block font-semibold break-words text-teks-utama">
                                                {indeks + 1}. {item.Nama}
                                            </span>
                                            <span className="block text-keterangan text-teks-sekunder">
                                                {item.Ringkasan}
                                            </span>
                                        </span>
                                        {bolehUbah ? (
                                            <span className="flex gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={indeks === 0}
                                                    onClick={() =>
                                                        AturTerpasang(PindahkanItem(terpasang, indeks, indeks - 1))
                                                    }
                                                    aria-label={`Naikkan ${item.Nama}`}
                                                >
                                                    Naik
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={indeks === terpasang.length - 1}
                                                    onClick={() =>
                                                        AturTerpasang(PindahkanItem(terpasang, indeks, indeks + 1))
                                                    }
                                                    aria-label={`Turunkan ${item.Nama}`}
                                                >
                                                    Turun
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        AturTerpasang(
                                                            terpasang.filter((lain) => lain.Uuid !== item.Uuid),
                                                        )
                                                    }
                                                    className="text-destructive"
                                                    aria-label={`Lepas ${item.Nama}`}
                                                >
                                                    Lepas
                                                </Button>
                                            </span>
                                        ) : null}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Panel>
                    <Panel judul={`Tersedia (${String(tersedia.length)})`} idJudul="judul-tersedia">
                        {tersedia.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">Semua kelompok pilihan sudah terpasang.</p>
                        ) : (
                            <ul className="flex flex-col divide-y divide-garis">
                                {tersedia.map((item) => (
                                    <li
                                        key={item.Uuid}
                                        className="flex flex-wrap items-start justify-between gap-2 py-2"
                                    >
                                        <span className="min-w-0">
                                            <span className="block font-semibold break-words text-teks-utama">
                                                {item.Nama}
                                            </span>
                                            <span className="block text-keterangan text-teks-sekunder">
                                                {item.Ringkasan}
                                            </span>
                                        </span>
                                        {bolehUbah ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => AturTerpasang([...terpasang, item])}
                                                aria-label={`Pasang ${item.Nama}`}
                                            >
                                                Pasang
                                            </Button>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            )}
            {bolehUbah && semua.length > 0 ? (
                <div>
                    <Tombol onClick={Simpan} memproses={memproses}>
                        Simpan pilihan produk
                    </Tombol>
                </div>
            ) : null}
        </TataLetakAplikasi>
    );
}
