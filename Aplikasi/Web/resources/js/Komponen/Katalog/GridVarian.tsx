import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { AdaStok, type GridVarian as DataGrid, KunciSel, RingkasStok } from '@/Pustaka/GridVarian';
import type { BarisVarian } from '@/Tipe/Katalog';

import BidangJumlah from './BidangJumlah';
import DaftarTab from './DaftarTab';

type Mode = 'Harga' | 'Stok';

type PropsGridVarian = {
    uuidProduk: string;
    grid: DataGrid;
    stok: Record<string, string>;
    /** null = pelaku tidak boleh mencatat stok awal (tab Stok awal menjelaskannya). */
    lokasiStok: { Uuid: string; Nama: string; NamaOutlet: string | null }[] | null;
    bolehUbahHarga: boolean;
    galat: Record<string, string | undefined>;
};

/** Harga server ("31250.00") ke isian uang tanpa nol desimal berlebih. */
function KeIsianHarga(harga: string | null): string {
    if (harga === null) {
        return '';
    }

    const [bulat = '0', pecahan = ''] = harga.split('.');
    const dua = pecahan.slice(0, 2).replace(/0+$/, '');

    return dua === '' ? bulat : `${bulat}.${dua}`;
}

/**
 * Grid ukuran × warna (§9.4, D-143): harga jual dan stok awal semua varian dalam satu matriks. Tab Harga memakai
 * `PUT /kelola/produk/{induk}/varian` (hanya sel yang berubah); tab Stok awal membuat SATU dokumen stok awal yang
 * langsung diposting lewat `POST …/varian/stok-awal` (varian yang sudah punya stok hanya ditampilkan).
 */
export default function GridVarian({ uuidProduk, grid, stok, lokasiStok, bolehUbahHarga, galat }: PropsGridVarian) {
    const semua = Object.values(grid.Sel);
    const [mode, AturMode] = useState<Mode>('Harga');

    return (
        <Panel
            judul={`Grid ${grid.NamaBaris.toLowerCase()} × ${grid.NamaKolom.toLowerCase()}`}
            keterangan="Isi harga atau stok awal semua kombinasi sekaligus. Kombinasi yang belum dibuat ditandai tanda hubung."
        >
            <DaftarTab
                label="Grid varian"
                aktif={mode}
                saatPilih={AturMode}
                tab={[
                    { Kunci: 'Harga', Label: 'Harga jual' },
                    { Kunci: 'Stok', Label: 'Stok awal' },
                ]}
                panel={{
                    Harga: (
                        <GridHarga uuidProduk={uuidProduk} grid={grid} semua={semua} bolehUbahHarga={bolehUbahHarga} />
                    ),
                    Stok: (
                        <GridStok
                            uuidProduk={uuidProduk}
                            grid={grid}
                            stok={stok}
                            lokasiStok={lokasiStok}
                            galat={galat}
                        />
                    ),
                }}
            />
        </Panel>
    );
}

function KepalaGrid({ grid }: { grid: DataGrid }) {
    return (
        <TableHeader>
            <TableRow>
                <TableHead scope="col">
                    {grid.NamaBaris} \ {grid.NamaKolom}
                </TableHead>
                {grid.NilaiKolom.map((kolom) => (
                    <TableHead key={kolom} scope="col" className="w-40">
                        {kolom}
                    </TableHead>
                ))}
            </TableRow>
        </TableHeader>
    );
}

function GridHarga({
    uuidProduk,
    grid,
    semua,
    bolehUbahHarga,
}: {
    uuidProduk: string;
    grid: DataGrid;
    semua: BarisVarian[];
    bolehUbahHarga: boolean;
}) {
    const awal = Object.fromEntries(semua.map((v) => [v.Uuid, KeIsianHarga(v.HargaDasar)]));
    const [isian, AturIsian] = useState<Record<string, string>>(awal);
    const [hargaSemua, AturHargaSemua] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const berubah = semua.filter((v) => (isian[v.Uuid] ?? '') !== (awal[v.Uuid] ?? '') && (isian[v.Uuid] ?? '') !== '');

    const SamakanPerBaris = () =>
        AturIsian((lama) => {
            const baru = { ...lama };

            for (const baris of grid.NilaiBaris) {
                const acuan = grid.NilaiKolom.map((kolom) => grid.Sel[KunciSel(baris, kolom)]).find(
                    (v) => v !== undefined && (baru[v.Uuid] ?? '') !== '',
                );

                if (acuan === undefined) {
                    continue;
                }

                for (const kolom of grid.NilaiKolom) {
                    const sel = grid.Sel[KunciSel(baris, kolom)];

                    if (sel !== undefined) {
                        baru[sel.Uuid] = baru[acuan.Uuid] ?? '';
                    }
                }
            }

            return baru;
        });

    const Simpan = () =>
        router.put(
            `/kelola/produk/${uuidProduk}/varian`,
            { Baris: berubah.map((v) => ({ Uuid: v.Uuid, Harga: isian[v.Uuid] ?? '', Barcode: null })) },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <div className="flex flex-col gap-3">
            {bolehUbahHarga ? (
                <div className="flex flex-wrap items-end gap-2">
                    <div className="w-48">
                        <BidangUang label="Harga untuk semua sel" nilai={hargaSemua} saatBerubah={AturHargaSemua} />
                    </div>
                    <Tombol
                        varian="sekunder"
                        disabled={hargaSemua === ''}
                        onClick={() => AturIsian(Object.fromEntries(semua.map((v) => [v.Uuid, hargaSemua])))}
                    >
                        Isi sama untuk semua
                    </Tombol>
                    <Tombol varian="sekunder" onClick={SamakanPerBaris}>
                        Samakan tiap baris dengan sel terisi pertama
                    </Tombol>
                </div>
            ) : (
                <p className="text-keterangan text-teks-sekunder">
                    Harga diubah oleh pengguna dengan izin produk.harga.ubah.
                </p>
            )}
            <TabelForm label="Grid harga jual varian" lebar="lebar">
                <TableCaption className="sr-only">Harga jual per kombinasi varian</TableCaption>
                <KepalaGrid grid={grid} />
                <TableBody>
                    {grid.NilaiBaris.map((baris) => (
                        <TableRow key={baris}>
                            <TableHead scope="row">{baris}</TableHead>
                            {grid.NilaiKolom.map((kolom) => {
                                const sel = grid.Sel[KunciSel(baris, kolom)];

                                return (
                                    <TableCell key={kolom} className="align-top">
                                        {sel === undefined ? (
                                            <span aria-label="Belum dibuat">-</span>
                                        ) : (
                                            <BidangUang
                                                label={`Harga ${sel.Nama}`}
                                                labelTersembunyi
                                                nilai={isian[sel.Uuid] ?? ''}
                                                saatBerubah={(harga) =>
                                                    AturIsian((lama) => ({ ...lama, [sel.Uuid]: harga }))
                                                }
                                                disabled={!bolehUbahHarga}
                                            />
                                        )}
                                    </TableCell>
                                );
                            })}
                        </TableRow>
                    ))}
                </TableBody>
            </TabelForm>
            <div>
                <Tombol disabled={berubah.length === 0} memproses={memproses} onClick={Simpan}>
                    Simpan harga {berubah.length > 0 ? `${String(berubah.length)} varian` : 'varian'}
                </Tombol>
            </div>
        </div>
    );
}

function GridStok({
    uuidProduk,
    grid,
    stok,
    lokasiStok,
    galat,
}: {
    uuidProduk: string;
    grid: DataGrid;
    stok: Record<string, string>;
    lokasiStok: PropsGridVarian['lokasiStok'];
    galat: Record<string, string | undefined>;
}) {
    const [lokasi, AturLokasi] = useState(lokasiStok?.length === 1 ? (lokasiStok[0]?.Uuid ?? '') : '');
    const [jumlah, AturJumlah] = useState<Record<string, string>>({});
    const [hargaBeli, AturHargaBeli] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const terisi = Object.entries(jumlah).filter(([, nilai]) => nilai !== '');

    if (lokasiStok === null) {
        return (
            <p className="text-isi text-teks-sekunder">
                Anda belum punya izin mencatat stok awal. Minta Pemilik, atau catat lewat halaman Stok awal.
            </p>
        );
    }

    const Simpan = () =>
        router.post(
            `/kelola/produk/${uuidProduk}/varian/stok-awal`,
            {
                UuidGudang: lokasi,
                Baris: terisi.map(([uuid, nilai]) => ({ Uuid: uuid, Jumlah: nilai, HargaBeli: hargaBeli })),
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturJumlah({}),
            },
        );

    const galatBaris = Object.entries(galat).find(([kunci]) => kunci.startsWith('Baris'))?.[1];

    return (
        <div className="flex flex-col gap-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <BidangPilihan
                    label="Lokasi stok"
                    nilai={lokasi}
                    opsi={lokasiStok.map((l) => ({
                        Nilai: l.Uuid,
                        Label: l.NamaOutlet === null ? l.Nama : `${l.NamaOutlet} | ${l.Nama}`,
                    }))}
                    saatBerubah={AturLokasi}
                    galat={galat.UuidGudang}
                    required
                />
                <BidangUang
                    label="Harga beli per unit (sama untuk semua sel)"
                    nilai={hargaBeli}
                    saatBerubah={AturHargaBeli}
                    keterangan="Kosong = Rp 0. Dipakai untuk nilai persediaan awal."
                />
            </div>
            <TabelForm label="Grid stok awal varian" lebar="lebar">
                <TableCaption className="sr-only">Stok awal per kombinasi varian</TableCaption>
                <KepalaGrid grid={grid} />
                <TableBody>
                    {grid.NilaiBaris.map((baris) => (
                        <TableRow key={baris}>
                            <TableHead scope="row">{baris}</TableHead>
                            {grid.NilaiKolom.map((kolom) => {
                                const sel = grid.Sel[KunciSel(baris, kolom)];

                                if (sel === undefined) {
                                    return (
                                        <TableCell key={kolom} className="align-top">
                                            <span aria-label="Belum dibuat">-</span>
                                        </TableCell>
                                    );
                                }

                                const sudah = AdaStok(stok[sel.Uuid]);

                                return (
                                    <TableCell key={kolom} className="align-top">
                                        {sudah ? (
                                            <span className="text-isi text-teks-sekunder tabular-nums">
                                                Stok {RingkasStok(stok[sel.Uuid])}
                                            </span>
                                        ) : (
                                            <BidangJumlah
                                                label={`Stok awal ${sel.Nama}`}
                                                labelTersembunyi
                                                nilai={jumlah[sel.Uuid] ?? ''}
                                                saatBerubah={(nilai) =>
                                                    AturJumlah((lama) => ({ ...lama, [sel.Uuid]: nilai }))
                                                }
                                                desimal={0}
                                                digitBulat={9}
                                            />
                                        )}
                                    </TableCell>
                                );
                            })}
                        </TableRow>
                    ))}
                </TableBody>
            </TabelForm>
            {galatBaris ? <p className="text-keterangan font-semibold text-bahaya">{galatBaris}</p> : null}
            <div>
                <Tombol disabled={terisi.length === 0 || lokasi === ''} memproses={memproses} onClick={Simpan}>
                    Catat stok awal {terisi.length > 0 ? `${String(terisi.length)} varian` : 'varian'}
                </Tombol>
            </div>
        </div>
    );
}
