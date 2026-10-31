import { Link, router, usePage } from '@inertiajs/react';
import { Trash2Icon } from 'lucide-react';
import { Fragment, useRef, useState, type FormEvent } from 'react';

import TabelForm from '@/Komponen/TabelData/TabelForm';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import {
    CariBarisGanda,
    CekSaldoMinus,
    PanjangMaksimalNomorBatch,
    PeriksaBarisStokAwal,
    PeriksaTanggalStokAwal,
    SusunMasukanBaris,
    type GalatBarisStokAwal,
} from '@/Komponen/Persediaan/AturanFormStokAwal';
import BidangHpp from '@/Komponen/Persediaan/BidangHpp';
import BidangNomorSeri from '@/Komponen/Persediaan/BidangNomorSeri';
import PanelKesiapanAkun from '@/Komponen/Persediaan/PanelKesiapanAkun';
import { useEntriCepat } from '@/Komponen/Persediaan/EntriCepat';
import PemilihProdukStok, { type ProdukStokTerpilih } from '@/Komponen/Persediaan/PemilihProdukStok';
import { BuatUlid } from '@/Komponen/Persediaan/UlidKlien';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import { FormatHppSatuan, FormatJumlahStok, FormatLabelGudang, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { HitungNilaiBaris, HitungTotalNilai } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisFormStokAwal, MasukanStokAwal, PropsFormStokAwal } from '@/Tipe/Persediaan';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';

const alamat = '/kelola/persediaan/stok-awal';

/** Baris form + kunci React lokal dan HPP rata-rata saat ini (petunjuk saja, tidak dikirim). */
type BarisForm = BarisFormStokAwal & { Kunci: string; HppRataRata: string | null };

/** Bidang yang galatnya tampil di bawah isian halaman ini; sisanya tampil di ringkasan galat server. */
const bidangBaris = /^Baris\.\d+\.(Jumlah|HppSatuan|NomorBatch|TanggalKedaluwarsa|NomorSeri)$/;

let nomorKunciBaris = 0;

/** Kunci React unik per baris (bukan data; tidak dikirim ke server). */
function BuatKunciBaris(): string {
    nomorKunciBaris += 1;

    return `baris-${String(nomorKunciBaris)}`;
}

function KelasSel(tambahan = ''): string {
    return `px-2 py-2 align-top whitespace-normal ${tambahan}`.trim();
}

/** F-05a: form draf stok awal (buat/ubah). Posting dilakukan dari halaman detail setelah draf tersimpan. */
export default function HalamanFormStokAwal({
    Mode,
    StokAwal,
    OpsiGudang,
    HariIni,
    BatasBaris,
    MaksimalNomorSeriPerBaris,
    WajibKedaluwarsaBatch,
    KesiapanAkun,
}: PropsFormStokAwal) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galatServer = props.errors;
    const entri = useEntriCepat('stok-awal');
    // Kunci idempotensi dokumen baru: dibuat sekali per form, dipakai ulang saat kirim ulang.
    const [uuidBaru] = useState(() => (StokAwal === null ? BuatUlid() : null));
    const [uuidGudang, AturUuidGudang] = useState(StokAwal?.UuidGudang ?? '');
    const [tanggal, AturTanggal] = useState(StokAwal?.Tanggal ?? HariIni);
    const [catatan, AturCatatan] = useState(StokAwal?.Catatan ?? '');
    const [daftarBaris, AturDaftarBaris] = useState<BarisForm[]>(() =>
        (StokAwal?.Baris ?? []).map((baris) => ({ ...baris, Kunci: BuatKunciBaris(), HppRataRata: null })),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    // Audit kemudahan pakai #13: tombol utama menyimpan lalu langsung memproses dokumen; draf jadi pilihan kedua.
    const lanjutkan = useRef(false);
    const aturan = { WajibKedaluwarsaBatch, MaksimalNomorSeriPerBaris };
    const ganda = CariBarisGanda(daftarBaris);
    const totalPerkiraan = HitungTotalNilai(daftarBaris);
    const penuh = daftarBaris.length >= BatasBaris;
    const gudangTerpilih = OpsiGudang.find((gudang) => gudang.Uuid === uuidGudang) ?? null;
    const opsiGudang = OpsiGudang.filter((gudang) => gudang.Aktif || gudang.Uuid === uuidGudang);
    const galatGudang = galatServer.UuidGudang ?? (periksa && uuidGudang === '' ? 'Pilih lokasi stok.' : undefined);
    const galatTanggal =
        galatServer.Tanggal ?? (periksa ? (PeriksaTanggalStokAwal(tanggal, HariIni) ?? undefined) : undefined);
    const galatDaftar =
        galatServer.Baris ??
        (periksa && daftarBaris.length === 0 ? 'Tambah minimal satu produk ke stok awal.' : undefined);

    const UbahBaris = (kunci: string, perubahan: Partial<BarisForm>) =>
        AturDaftarBaris((lama) => lama.map((baris) => (baris.Kunci === kunci ? { ...baris, ...perubahan } : baris)));

    const HapusBaris = (kunci: string) => AturDaftarBaris((lama) => lama.filter((baris) => baris.Kunci !== kunci));

    const TambahProduk = (produk: ProdukStokTerpilih) => {
        const kunci = BuatKunciBaris();

        // Entri cepat: barang baru di paling atas (dekat kotak cari) dan jumlahnya langsung difokuskan.
        if (produk.Pelacakan !== 'Seri') {
            entri.CatatBarisBaru(kunci);
        }

        AturDaftarBaris((lama) => [
            {
                Kunci: kunci,
                UuidProduk: produk.Uuid,
                NamaProduk: produk.Nama,
                Sku: produk.Sku,
                SimbolSatuan: produk.SimbolSatuan,
                BolehDesimal: produk.BolehDesimal,
                Pelacakan: produk.Pelacakan,
                SaldoDiGudang: produk.SaldoDiGudang,
                HppRataRata: produk.HppRataRata,
                Jumlah: '',
                // Modal per satuan terisi dari HPP rata-rata produk bila ada (bisa diubah).
                HppSatuan: produk.HppRataRata ?? '',
                NomorBatch: null,
                TanggalKedaluwarsa: null,
                NomorSeri: [],
            },
            ...lama,
        ]);
    };

    /** Ganti lokasi: saldo per baris milik lokasi lama, jadi disembunyikan sampai produk dipilih ulang. */
    const GantiGudang = (nilai: string) => {
        AturUuidGudang(nilai);
        AturDaftarBaris((lama) => lama.map((baris) => ({ ...baris, SaldoDiGudang: null, HppRataRata: null })));
    };

    const AmbilGalat = (indeks: number, baris: BarisForm): GalatBarisStokAwal => {
        const lokal = periksa ? PeriksaBarisStokAwal(baris, aturan) : {};
        const kunci = `Baris.${String(indeks)}`;

        return {
            Jumlah: galatServer[`${kunci}.Jumlah`] ?? lokal.Jumlah,
            HppSatuan: galatServer[`${kunci}.HppSatuan`] ?? lokal.HppSatuan,
            NomorBatch:
                galatServer[`${kunci}.NomorBatch`] ??
                lokal.NomorBatch ??
                (periksa && ganda.has(indeks) ? 'Nomor batch ini sudah ada di baris lain.' : undefined),
            TanggalKedaluwarsa: galatServer[`${kunci}.TanggalKedaluwarsa`] ?? lokal.TanggalKedaluwarsa,
            NomorSeri: galatServer[`${kunci}.NomorSeri`] ?? lokal.NomorSeri,
        };
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        const adaGalat =
            uuidGudang === '' ||
            PeriksaTanggalStokAwal(tanggal, HariIni) !== null ||
            daftarBaris.length === 0 ||
            daftarBaris.length > BatasBaris ||
            ganda.size > 0 ||
            daftarBaris.some((baris) => Object.keys(PeriksaBarisStokAwal(baris, aturan)).length > 0);

        if (adaGalat) {
            return;
        }

        const masukan: MasukanStokAwal = {
            UuidGudang: uuidGudang,
            Tanggal: tanggal,
            Catatan: catatan.trim() === '' ? null : catatan.trim(),
            Baris: daftarBaris.map(SusunMasukanBaris),
        };
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
        };

        if (StokAwal === null) {
            router.post(alamat, { ...masukan, Uuid: uuidBaru ?? BuatUlid(), Lanjutkan: lanjutkan.current }, opsi);
        } else {
            router.put(
                `${alamat}/${StokAwal.Uuid}`,
                { ...masukan, VersiDiubahPada: StokAwal.VersiDiubahPada, Lanjutkan: lanjutkan.current },
                opsi,
            );
        }
    };

    const judul = Mode === 'Buat' ? 'Buat stok awal' : 'Ubah draf stok awal';
    const kembali = StokAwal === null ? alamat : `${alamat}/${StokAwal.Uuid}`;

    return (
        <TataLetakAplikasi judul={judul}>
            <PanelKesiapanAkun kesiapan={KesiapanAkun} />
            <DaftarGalatServer
                galat={galatServer}
                kecuali={[
                    'UuidGudang',
                    'Tanggal',
                    'Catatan',
                    'Baris',
                    ...Object.keys(galatServer).filter((kunci) => bidangBaris.test(kunci)),
                ]}
            />

            <form onSubmit={Simpan} noValidate aria-label={judul} className="flex flex-col gap-4">
                <Panel
                    judul="Dokumen"
                    idJudul="judul-dokumen-stok-awal"
                    keterangan="Satu dokumen untuk satu lokasi stok. Jumlah dan harga modal memakai satuan dasar produk."
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <BidangOutlet
                            label="Lokasi stok"
                            nilai={uuidGudang}
                            kosong="Pilih lokasi stok"
                            opsi={opsiGudang.map((gudang) => ({
                                Nilai: gudang.Uuid,
                                Label: FormatLabelGudang(gudang),
                            }))}
                            saatBerubah={GantiGudang}
                            galat={galatGudang}
                        />
                        <PemilihTanggal
                            id="tanggal-stok-awal"
                            label="Tanggal stok awal"
                            nilai={tanggal}
                            max={HariIni}
                            required
                            saatBerubah={AturTanggal}
                            galat={galatTanggal}
                        />
                    </div>
                    {OpsiGudang.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">
                            Belum ada lokasi stok aktif yang bisa Anda akses. Tambahkan lokasi stok di{' '}
                            <Link href="/kelola/outlet" className="font-semibold text-brand underline">
                                menu Outlet
                            </Link>
                            .
                        </p>
                    ) : null}
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        galat={galatServer.Catatan}
                        baris={2}
                        maksimal={500}
                    />
                </Panel>

                <Panel
                    judul="Barang"
                    idJudul="judul-barang-stok-awal"
                    keterangan={`${daftarBaris.length.toLocaleString('id-ID')} dari ${BatasBaris.toLocaleString('id-ID')} baris`}
                >
                    <PemilihProdukStok
                        label="Tambah produk"
                        id={entri.idCari}
                        alihkanFokusSetelahPilih={entri.AlihkanFokusSetelahPilih}
                        uuidGudang={gudangTerpilih?.Uuid ?? null}
                        saatPilih={TambahProduk}
                        kecuali={daftarBaris
                            .filter((baris) => baris.Pelacakan !== 'Batch')
                            .map((baris) => baris.UuidProduk)}
                        tolakStokAwalAda
                        disabled={uuidGudang === '' || penuh}
                        keterangan={
                            uuidGudang === ''
                                ? 'Pilih lokasi stok dulu agar stok saat ini ikut tampil.'
                                : penuh
                                  ? `Batas ${BatasBaris.toLocaleString('id-ID')} baris per dokumen tercapai. Simpan draf ini lalu buat dokumen baru, atau pakai impor Excel.`
                                  : 'Hanya produk yang punya stok. Barang yang dipilih muncul paling atas, ketik jumlahnya lalu tekan Enter untuk lanjut ke barang berikutnya. Produk batch boleh ditambah lebih dari sekali untuk batch berbeda.'
                        }
                    />
                    {galatDaftar ? <GalatBidang>{galatDaftar}</GalatBidang> : null}

                    {daftarBaris.length === 0 ? (
                        <p className="rounded-kontrol border border-dashed border-garis-input px-3 py-2 text-isi text-teks-sekunder">
                            Belum ada barang. Cari produk di atas untuk menambah baris.
                        </p>
                    ) : (
                        <TabelForm label="Baris stok awal" lebar="dokumen">
                            <TableCaption className="sr-only">Barang stok awal</TableCaption>
                            <TableHeader>
                                <TableRow className="border-garis hover:bg-transparent">
                                    <TableHead scope="col" className="px-2 text-label text-teks-sekunder">
                                        Produk
                                    </TableHead>
                                    <TableHead
                                        scope="col"
                                        className="w-44 px-2 text-right text-label text-teks-sekunder"
                                    >
                                        Jumlah
                                    </TableHead>
                                    <TableHead
                                        scope="col"
                                        className="w-56 px-2 text-right text-label text-teks-sekunder"
                                    >
                                        Harga modal per satuan
                                    </TableHead>
                                    <TableHead scope="col" className="px-2 text-right text-label text-teks-sekunder">
                                        Nilai (perkiraan)
                                    </TableHead>
                                    <TableHead scope="col" className="px-2">
                                        <span className="sr-only">Aksi</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {daftarBaris.map((baris, indeks) => {
                                    const galat = AmbilGalat(indeks, baris);
                                    const nilai = HitungNilaiBaris(baris.Jumlah, baris.HppSatuan);
                                    const nama = baris.NamaProduk;

                                    return (
                                        <Fragment key={baris.Kunci}>
                                            <TableRow
                                                className={baris.Pelacakan === 'Tidak' ? 'border-garis' : 'border-0'}
                                            >
                                                <th scope="row" className={KelasSel('text-left font-normal')}>
                                                    <span className="block font-semibold break-words text-teks-utama">
                                                        {nama}
                                                    </span>
                                                    <span className="block text-keterangan text-teks-sekunder">
                                                        <span className="font-mono">{baris.Sku ?? 'Tanpa SKU'}</span>
                                                        {baris.SaldoDiGudang !== null
                                                            ? ` | stok saat ini ${FormatJumlahStok(baris.SaldoDiGudang, baris.SimbolSatuan)}`
                                                            : null}
                                                    </span>
                                                    {CekSaldoMinus(baris.SaldoDiGudang) ? (
                                                        <span className="block text-keterangan font-semibold text-peringatan">
                                                            Stok minus. Selisih HPP dicatat saat stok awal dicatat.
                                                        </span>
                                                    ) : null}
                                                </th>
                                                <TableCell className={KelasSel()}>
                                                    {baris.Pelacakan === 'Seri' ? (
                                                        <p className="h-8 pointer-coarse:h-11 py-2 text-right text-teks-utama tabular-nums">
                                                            {FormatJumlahStok(
                                                                String(baris.NomorSeri.length),
                                                                baris.SimbolSatuan,
                                                            )}
                                                            <span className="block text-keterangan text-teks-sekunder">
                                                                dari nomor seri
                                                            </span>
                                                        </p>
                                                    ) : (
                                                        <BidangJumlah
                                                            label={`Jumlah ${nama}`}
                                                            labelTersembunyi
                                                            id={entri.IdJumlah(baris.Kunci)}
                                                            saatEnter={entri.KembaliKeCari}
                                                            nilai={baris.Jumlah}
                                                            saatBerubah={(jumlah) =>
                                                                UbahBaris(baris.Kunci, { Jumlah: jumlah })
                                                            }
                                                            desimal={baris.BolehDesimal ? 4 : 0}
                                                            akhiran={baris.SimbolSatuan}
                                                            galat={galat.Jumlah}
                                                            required
                                                        />
                                                    )}
                                                </TableCell>
                                                <TableCell className={KelasSel()}>
                                                    <BidangHpp
                                                        label={`Harga modal per satuan ${nama}`}
                                                        labelTersembunyi
                                                        nilai={baris.HppSatuan}
                                                        saatBerubah={(hpp) =>
                                                            UbahBaris(baris.Kunci, { HppSatuan: hpp })
                                                        }
                                                        simbolSatuan={baris.SimbolSatuan}
                                                        galat={galat.HppSatuan}
                                                        keterangan={
                                                            baris.HppRataRata !== null
                                                                ? `HPP rata-rata saat ini ${FormatHppSatuan(baris.HppRataRata)}`
                                                                : undefined
                                                        }
                                                        required
                                                    />
                                                </TableCell>
                                                <TableCell
                                                    className={KelasSel('text-right whitespace-nowrap tabular-nums')}
                                                >
                                                    <span className="inline-block py-2">
                                                        {nilai === null ? '—' : FormatNilai(nilai)}
                                                    </span>
                                                </TableCell>
                                                <TableCell className={KelasSel('text-right')}>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Hapus baris ${nama}`}
                                                        onClick={() => HapusBaris(baris.Kunci)}
                                                    >
                                                        <Trash2Icon aria-hidden="true" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                            {baris.Pelacakan === 'Batch' ? (
                                                <TableRow className="border-garis hover:bg-transparent">
                                                    <TableCell colSpan={5} className="px-2 pt-0 pb-3 whitespace-normal">
                                                        <div className="grid gap-3 rounded-kontrol bg-permukaan-redup p-3 sm:grid-cols-2">
                                                            <BidangTeks
                                                                label={`Nomor batch ${nama}`}
                                                                nilai={baris.NomorBatch ?? ''}
                                                                saatBerubah={(nomor) =>
                                                                    UbahBaris(baris.Kunci, { NomorBatch: nomor })
                                                                }
                                                                galat={galat.NomorBatch}
                                                                maxLength={PanjangMaksimalNomorBatch}
                                                                kode
                                                                required
                                                            />
                                                            <PemilihTanggal
                                                                id={`${baris.Kunci}-kedaluwarsa`}
                                                                label={`Kedaluwarsa ${nama}${WajibKedaluwarsaBatch ? '' : ' (opsional)'}`}
                                                                nilai={baris.TanggalKedaluwarsa ?? ''}
                                                                required={WajibKedaluwarsaBatch}
                                                                saatBerubah={(nilai) =>
                                                                    UbahBaris(baris.Kunci, {
                                                                        TanggalKedaluwarsa: nilai || null,
                                                                    })
                                                                }
                                                                galat={galat.TanggalKedaluwarsa}
                                                            />
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ) : null}
                                            {baris.Pelacakan === 'Seri' ? (
                                                <TableRow className="border-garis hover:bg-transparent">
                                                    <TableCell colSpan={5} className="px-2 pt-0 pb-3 whitespace-normal">
                                                        <div className="rounded-kontrol bg-permukaan-redup p-3">
                                                            <BidangNomorSeri
                                                                label={`Nomor seri ${nama}`}
                                                                nilai={baris.NomorSeri}
                                                                saatBerubah={(nomor) =>
                                                                    UbahBaris(baris.Kunci, {
                                                                        NomorSeri: nomor,
                                                                        Jumlah: String(nomor.length),
                                                                    })
                                                                }
                                                                maksimal={MaksimalNomorSeriPerBaris}
                                                                galat={galat.NomorSeri}
                                                            />
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ) : null}
                                        </Fragment>
                                    );
                                })}
                            </TableBody>
                        </TabelForm>
                    )}

                    <div className="flex flex-wrap items-baseline justify-end gap-x-3 gap-y-1 border-t border-garis pt-3">
                        <span className="text-label font-semibold text-teks-sekunder">Total nilai (perkiraan)</span>
                        <span className="text-subjudul font-semibold text-teks-utama tabular-nums" aria-live="polite">
                            {FormatNilai(totalPerkiraan)}
                        </span>
                        <span className="w-full text-right text-keterangan text-teks-sekunder">
                            Nilai akhir dihitung otomatis saat draf disimpan.
                        </span>
                    </div>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses} onClick={() => (lanjutkan.current = true)}>
                        Simpan & catat
                    </Tombol>
                    <Tombol
                        type="submit"
                        varian="sekunder"
                        disabled={memproses}
                        onClick={() => (lanjutkan.current = false)}
                    >
                        Simpan draf
                    </Tombol>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={kembali}>Batal</Link>
                    </Button>
                </BilahAksiForm>
                <p className="text-keterangan text-teks-sekunder">
                    Draf belum mengubah stok. Stok dan jurnal tercatat setelah draf dicatat dari halaman detail.
                </p>
            </form>
        </TataLetakAplikasi>
    );
}
