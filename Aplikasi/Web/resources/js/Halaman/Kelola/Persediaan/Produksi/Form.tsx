import { Link, router, usePage } from '@inertiajs/react';
import { Trash2Icon } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import TawaranDraf from '@/Komponen/Formulir/TawaranDraf';
import { useDrafLokal } from '@/Komponen/Formulir/useDrafLokal';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { useEntriCepat } from '@/Komponen/Persediaan/EntriCepat';
import PemilihProdukStok, { type ProdukStokTerpilih } from '@/Komponen/Persediaan/PemilihProdukStok';
import { BuatUlid } from '@/Komponen/Persediaan/UlidKlien';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatJumlahStok, FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import { AmbilTandaDesimal, CekDesimalValid } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BahanOrderProduksi, IsianOrderProduksi, PropsFormOrderProduksi } from '@/Tipe/Produksi';

import { AlamatProduksi } from './Daftar';

type BahanForm = BahanOrderProduksi & { Kunci: string };

let nomorKunci = 0;

function BuatKunci(): string {
    nomorKunci += 1;

    return `pr-${String(nomorKunci)}`;
}

/** Jumlah positif sesuai aturan desimal produk (server memeriksa ulang). */
export function CekJumlahProduksi(jumlah: string, bolehDesimal: boolean): boolean {
    return CekDesimalValid(jumlah) && AmbilTandaDesimal(jumlah) > 0 && (bolehDesimal || !jumlah.includes('.'));
}

async function AmbilBahanResep(
    uuidProduk: string,
    jumlah: string,
): Promise<{ VersiResep: number | null; Bahan: BahanOrderProduksi[] } | { Galat: string }> {
    const respons = await fetch(
        `${AlamatProduksi}/resep?produk=${encodeURIComponent(uuidProduk)}&jumlah=${encodeURIComponent(jumlah)}`,
        {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        },
    );
    const isi = (await respons.json()) as
        { VersiResep: number | null; Bahan: BahanOrderProduksi[] } | { Galat?: { Pesan?: string } };

    if (!respons.ok || !('Bahan' in isi)) {
        return { Galat: ('Galat' in isi ? isi.Galat?.Pesan : undefined) ?? 'Resep tidak bisa dimuat. Coba lagi.' };
    }

    return isi;
}

/** F-05e: form draf order produksi (buat/ubah). Bahan diisi dari resep lalu bisa disesuaikan dengan pemakaian nyata. */
export default function HalamanFormOrderProduksi({
    Mode,
    Order,
    Awal,
    OpsiGudang,
    HariIni,
    MaksBahan,
    WajibKedaluwarsaBatch,
}: PropsFormOrderProduksi) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galatServer = props.errors;
    const [uuidBaru] = useState(() => (Order === null ? BuatUlid() : null));
    // Formulir baru yang dibuka dari Rencana produksi sudah berisi produk, lokasi, jumlah, dan tanggal.
    const awal = Order === null ? Awal : null;
    const [gudang, AturGudang] = useState(Order?.UuidGudang ?? awal?.UuidGudang ?? '');
    const [tanggal, AturTanggal] = useState(Order?.Tanggal ?? awal?.Tanggal ?? HariIni);
    const [produk, AturProduk] = useState<IsianOrderProduksi['Produk']>(Order?.Produk ?? awal?.Produk ?? null);
    const [jumlahHasil, AturJumlahHasil] = useState(Order?.JumlahHasil ?? awal?.JumlahHasil ?? '');
    const [overhead, AturOverhead] = useState(
        Order?.BiayaOverhead && AmbilTandaDesimal(Order.BiayaOverhead) > 0 ? Order.BiayaOverhead : '',
    );
    const [nomorBatch, AturNomorBatch] = useState(Order?.NomorBatch ?? '');
    const [kedaluwarsa, AturKedaluwarsa] = useState(Order?.TanggalKedaluwarsa ?? '');
    const [keterangan, AturKeterangan] = useState(Order?.Keterangan ?? '');
    const entri = useEntriCepat('produksi');
    const [bahan, AturBahan] = useState<BahanForm[]>(() =>
        (Order?.Bahan ?? []).map((b) => ({ ...b, Kunci: BuatKunci() })),
    );
    const [galatProduk, AturGalatProduk] = useState<string | null>(null);
    const [galatResep, AturGalatResep] = useState<string | null>(null);
    const [memuatResep, AturMemuatResep] = useState(false);
    // Bahan mengikuti resep otomatis (saat produk dipilih dan jumlah hasil berubah) sampai pengguna mengubah bahan
    // sendiri; "Muat ulang dari resep" menyalakannya lagi. Order lama yang dibuka untuk diubah tidak ditimpa.
    const [otomatis, AturOtomatis] = useState(Order === null);
    const terakhirMuat = useRef<string | null>(null);
    const nomorMuat = useRef(0);
    const [periksa, AturPeriksa] = useState(false);
    const draf = useDrafLokal({
        nama: 'order-produksi',
        aktif: Order === null && awal === null,
        nilai: { gudang, produk, jumlahHasil, overhead, nomorBatch, kedaluwarsa, keterangan, bahan },
        kosong: (n) => n.produk === null && n.bahan.length === 0 && n.keterangan.trim() === '',
    });
    const PulihkanDraf = () => {
        const isi = draf.Pulihkan();

        if (isi === null) {
            return;
        }

        // Bahan dari draf dipertahankan apa adanya, jadi isi otomatis dari resep tidak menimpanya.
        AturOtomatis(false);
        AturGudang(isi.gudang);
        AturProduk(isi.produk);
        AturJumlahHasil(isi.jumlahHasil);
        AturOverhead(isi.overhead);
        AturNomorBatch(isi.nomorBatch);
        AturKedaluwarsa(isi.kedaluwarsa);
        AturKeterangan(isi.keterangan);
        AturBahan(isi.bahan.map((b) => ({ ...b, Kunci: BuatKunci() })));
    };
    const [memproses, AturMemproses] = useState(false);
    const judul = Mode === 'Buat' ? 'Buat order produksi' : 'Ubah draf order produksi';
    const jumlahValid = produk !== null && CekJumlahProduksi(jumlahHasil, produk.BolehDesimal);
    const batch = produk?.Pelacakan === 'Batch';

    const IsiDariResep = async (dariAwal?: { Uuid: string; Jumlah: string }) => {
        const uuid = dariAwal?.Uuid ?? produk?.Uuid;
        const banyak = dariAwal?.Jumlah ?? jumlahHasil;

        // Dari Rencana produksi produk & jumlah sudah dipilih server; selain itu jumlah harus sah dulu.
        if (uuid === undefined || (dariAwal === undefined && !jumlahValid)) {
            AturPeriksa(true);

            return;
        }

        const urutan = ++nomorMuat.current;
        terakhirMuat.current = `${uuid}|${banyak}`;
        AturMemuatResep(true);
        AturGalatResep(null);

        try {
            const hasil = await AmbilBahanResep(uuid, banyak);

            // Jawaban yang sudah tertinggal (produk atau jumlah berganti saat menunggu) dibuang.
            if (urutan !== nomorMuat.current) {
                return;
            }

            if ('Galat' in hasil) {
                AturGalatResep(hasil.Galat);
                terakhirMuat.current = null;
            } else {
                AturBahan(hasil.Bahan.map((b) => ({ ...b, Kunci: BuatKunci() })));
            }
        } catch {
            if (urutan === nomorMuat.current) {
                AturGalatResep('Resep tidak bisa dimuat. Periksa koneksi lalu coba lagi.');
                terakhirMuat.current = null;
            }
        } finally {
            if (urutan === nomorMuat.current) {
                AturMemuatResep(false);
            }
        }
    };

    // Bahan langsung diisi dari resep sekali saja saat formulir dibuka dari Rencana produksi.
    const sudahMuatAwal = useRef(false);

    useEffect(() => {
        if (awal !== null && !sudahMuatAwal.current) {
            sudahMuatAwal.current = true;
            void IsiDariResep({ Uuid: awal.Produk.Uuid, Jumlah: awal.JumlahHasil });
        }
        // Hanya sekali saat dipasang: `IsiDariResep` memakai state yang berubah dan tidak boleh memicu muat ulang.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Jumlah hasil berubah: takaran bahan dihitung ulang dari resep (selama pengguna belum mengubah bahan sendiri).
    useEffect(() => {
        if (!otomatis || produk === null || !jumlahValid || terakhirMuat.current === `${produk.Uuid}|${jumlahHasil}`) {
            return;
        }

        const tunda = setTimeout(() => void IsiDariResep({ Uuid: produk.Uuid, Jumlah: jumlahHasil }), 400);

        return () => clearTimeout(tunda);
        // `IsiDariResep` dibuat ulang tiap render; yang menentukan kapan menghitung ulang hanya tiga nilai ini.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [otomatis, produk, jumlahHasil]);

    const PilihHasil = (p: ProdukStokTerpilih) => {
        if (p.Jenis !== 'Produksi') {
            AturGalatProduk(
                `${p.Nama} bukan produk jenis Produksi. Ubah jenisnya di data produk agar bisa diproduksi.`,
            );

            return;
        }

        if (p.Pelacakan === 'Seri') {
            AturGalatProduk('Produk bernomor seri tidak bisa dibuat lewat order produksi.');

            return;
        }

        AturGalatProduk(null);
        AturOtomatis(true);
        AturProduk({
            Uuid: p.Uuid,
            Nama: p.Nama,
            Sku: p.Sku,
            SimbolSatuan: p.SimbolSatuan,
            BolehDesimal: p.BolehDesimal,
            Pelacakan: p.Pelacakan,
        });
        AturBahan([]);
        // Bahan langsung muncul dari resep; jumlah hasil belum diisi = takaran untuk satu satuan hasil.
        void IsiDariResep({
            Uuid: p.Uuid,
            Jumlah: CekJumlahProduksi(jumlahHasil, p.BolehDesimal) ? jumlahHasil : '1',
        });
    };

    const TambahBahan = (p: ProdukStokTerpilih) => {
        const kunci = BuatKunci();

        AturOtomatis(false);
        // Entri cepat: bahan baru di paling atas dan jumlahnya langsung aktif.
        entri.CatatBarisBaru(kunci);
        AturBahan((lama) => [
            {
                Kunci: kunci,
                UuidProduk: p.Uuid,
                NamaProduk: p.Nama,
                Sku: p.Sku,
                SimbolSatuan: p.SimbolSatuan,
                BolehDesimal: p.BolehDesimal,
                JumlahStandar: '0',
                Jumlah: '',
            },
            ...lama,
        ]);
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (
            gudang === '' ||
            produk === null ||
            !jumlahValid ||
            bahan.length === 0 ||
            bahan.some((b) => !CekJumlahProduksi(b.Jumlah, b.BolehDesimal)) ||
            (overhead !== '' && !CekDesimalValid(overhead)) ||
            (batch && (nomorBatch.trim() === '' || (WajibKedaluwarsaBatch && kedaluwarsa === '')))
        ) {
            return;
        }

        const masukan = {
            UuidGudang: gudang,
            Tanggal: tanggal,
            UuidProduk: produk.Uuid,
            JumlahHasil: jumlahHasil,
            BiayaOverhead: overhead === '' ? '0' : overhead,
            NomorBatch: batch ? nomorBatch.trim() : null,
            TanggalKedaluwarsa: batch && kedaluwarsa !== '' ? kedaluwarsa : null,
            Keterangan: keterangan.trim() === '' ? null : keterangan.trim(),
            Bahan: bahan.map((b) => ({ UuidProduk: b.UuidProduk, Jumlah: b.Jumlah })),
        };
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => draf.Hapus(),
        };

        if (Order === null) {
            router.post(AlamatProduksi, { ...masukan, Uuid: uuidBaru ?? BuatUlid() }, opsi);
        } else {
            router.put(`${AlamatProduksi}/${Order.Uuid}`, { ...masukan, VersiDiubahPada: Order.VersiDiubahPada }, opsi);
        }
    };

    return (
        <TataLetakAplikasi judul={judul}>
            <DaftarGalatServer
                galat={galatServer}
                kecuali={[
                    'UuidGudang',
                    'Tanggal',
                    'UuidProduk',
                    'JumlahHasil',
                    'BiayaOverhead',
                    'NomorBatch',
                    'TanggalKedaluwarsa',
                    'Bahan',
                ]}
            />
            {draf.tawaran ? (
                <TawaranDraf disimpan={draf.tawaran.Disimpan} saatLanjutkan={PulihkanDraf} saatBuang={draf.Buang} />
            ) : null}
            <form onSubmit={Simpan} noValidate aria-label={judul} className="flex flex-col gap-4">
                <Panel
                    judul="Hasil produksi"
                    idJudul="judul-hasil-produksi"
                    keterangan="Stok berubah setelah order dicatat dari halaman detail, bukan saat draf disimpan."
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangOutlet
                            label="Lokasi produksi"
                            nilai={gudang}
                            kosong="Pilih lokasi stok"
                            opsi={OpsiGudang.filter((g) => g.Aktif || g.Uuid === gudang).map((g) => ({
                                Nilai: g.Uuid,
                                Label: FormatLabelGudang(g),
                            }))}
                            saatBerubah={AturGudang}
                            galat={
                                galatServer.UuidGudang ??
                                (periksa && gudang === '' ? 'Pilih lokasi produksi.' : undefined)
                            }
                        />
                        <PemilihTanggal
                            id="tanggal-produksi"
                            label="Tanggal"
                            nilai={tanggal}
                            max={HariIni}
                            required
                            saatBerubah={AturTanggal}
                            galat={galatServer.Tanggal}
                        />
                    </div>
                    {produk === null ? (
                        <PemilihProdukStok
                            label="Produk yang diproduksi"
                            uuidGudang={gudang === '' ? null : gudang}
                            saatPilih={PilihHasil}
                            keterangan="Hanya produk berjenis Produksi (misal roti, kue, barang rakitan)."
                            galat={
                                galatProduk ??
                                galatServer.UuidProduk ??
                                (periksa ? 'Pilih produk yang diproduksi.' : undefined)
                            }
                        />
                    ) : (
                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-kontrol border border-garis p-3">
                            <div className="min-w-0">
                                <span className="block font-semibold break-words text-teks-utama">{produk.Nama}</span>
                                <span className="block font-mono text-keterangan text-teks-sekunder">
                                    {produk.Sku ?? 'Tanpa SKU'}
                                </span>
                            </div>
                            <Tombol varian="sekunder" onClick={() => AturProduk(null)}>
                                Ganti produk
                            </Tombol>
                        </div>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangJumlah
                            label="Jumlah hasil"
                            nilai={jumlahHasil}
                            saatBerubah={AturJumlahHasil}
                            desimal={produk?.BolehDesimal === false ? 0 : 4}
                            {...(produk ? { akhiran: produk.SimbolSatuan } : {})}
                            galat={
                                galatServer.JumlahHasil ??
                                (periksa && !jumlahValid ? 'Isi jumlah hasil lebih dari 0.' : undefined)
                            }
                            required
                        />
                        <BidangUang
                            label="Biaya overhead (opsional)"
                            keterangan="Listrik, gas, tenaga kerja langsung yang dibebankan ke produksi ini."
                            nilai={overhead}
                            saatBerubah={AturOverhead}
                            galat={galatServer.BiayaOverhead}
                        />
                        {batch ? (
                            <>
                                <BidangTeks
                                    label="Nomor batch hasil"
                                    kode
                                    nilai={nomorBatch}
                                    saatBerubah={AturNomorBatch}
                                    maxLength={60}
                                    required
                                    galat={
                                        galatServer.NomorBatch ??
                                        (periksa && nomorBatch.trim() === '' ? 'Isi nomor batch.' : undefined)
                                    }
                                />
                                <PemilihTanggal
                                    id="kedaluwarsa-produksi"
                                    label="Kedaluwarsa"
                                    nilai={kedaluwarsa}
                                    min={tanggal}
                                    required={WajibKedaluwarsaBatch}
                                    saatBerubah={AturKedaluwarsa}
                                    galat={
                                        galatServer.TanggalKedaluwarsa ??
                                        (periksa && WajibKedaluwarsaBatch && kedaluwarsa === ''
                                            ? 'Isi tanggal kedaluwarsa.'
                                            : undefined)
                                    }
                                />
                            </>
                        ) : null}
                    </div>
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={keterangan}
                        saatBerubah={AturKeterangan}
                        baris={2}
                        maksimal={500}
                    />
                </Panel>

                <Panel
                    judul="Bahan"
                    idJudul="judul-bahan-produksi"
                    keterangan={`${bahan.length.toLocaleString('id-ID')} dari ${MaksBahan.toLocaleString('id-ID')} baris. Bahan terisi otomatis dari resep produk yang dipilih dan ikut menyesuaikan jumlah hasil. Ubah jumlahnya bila pemakaian nyata berbeda. Setelah itu penyesuaian otomatis berhenti.`}
                >
                    {produk !== null && (!otomatis || bahan.length === 0) ? (
                        <div className="flex flex-wrap gap-2">
                            <Tombol
                                varian="sekunder"
                                onClick={() => {
                                    AturOtomatis(true);
                                    void IsiDariResep();
                                }}
                                memproses={memuatResep}
                            >
                                {bahan.length === 0 ? 'Isi bahan dari resep' : 'Kembalikan ke resep'}
                            </Tombol>
                        </div>
                    ) : null}
                    {galatResep ? <GalatBidang>{galatResep}</GalatBidang> : null}
                    <PemilihProdukStok
                        label="Tambah bahan"
                        id={entri.idCari}
                        alihkanFokusSetelahPilih={entri.AlihkanFokusSetelahPilih}
                        uuidGudang={gudang === '' ? null : gudang}
                        saatPilih={TambahBahan}
                        kecuali={[...bahan.map((b) => b.UuidProduk), ...(produk ? [produk.Uuid] : [])]}
                        disabled={gudang === '' || bahan.length >= MaksBahan}
                        keterangan={
                            gudang === ''
                                ? 'Pilih lokasi produksi dulu.'
                                : 'Bahan dikurangi dari lokasi produksi dengan HPP berjalan.'
                        }
                    />
                    {galatServer.Bahan ? (
                        <GalatBidang>{galatServer.Bahan}</GalatBidang>
                    ) : periksa && bahan.length === 0 ? (
                        <GalatBidang>Tambahkan minimal satu bahan.</GalatBidang>
                    ) : null}
                    <ul className="flex flex-col divide-y divide-garis" aria-label="Bahan produksi">
                        {bahan.map((b, indeks) => (
                            <li key={b.Kunci} className="flex flex-col gap-2 py-3">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <span className="block font-semibold break-words text-teks-utama">
                                            {b.NamaProduk}
                                        </span>
                                        <span className="block text-keterangan text-teks-sekunder">
                                            <span className="font-mono">{b.Sku ?? 'Tanpa SKU'}</span>
                                            {AmbilTandaDesimal(b.JumlahStandar) > 0
                                                ? ` | standar resep ${FormatJumlahStok(b.JumlahStandar, b.SimbolSatuan)}`
                                                : ' | di luar resep'}
                                        </span>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={`Hapus bahan ${b.NamaProduk}`}
                                        onClick={() => {
                                            AturOtomatis(false);
                                            AturBahan((lama) => lama.filter((x) => x.Kunci !== b.Kunci));
                                        }}
                                    >
                                        <Trash2Icon aria-hidden="true" />
                                    </Button>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <BidangJumlah
                                        label={`Dipakai ${b.NamaProduk}`}
                                        id={entri.IdJumlah(b.Kunci)}
                                        saatEnter={entri.KembaliKeCari}
                                        nilai={b.Jumlah}
                                        saatBerubah={(jumlah) => {
                                            AturOtomatis(false);
                                            AturBahan((lama) =>
                                                lama.map((x) => (x.Kunci === b.Kunci ? { ...x, Jumlah: jumlah } : x)),
                                            );
                                        }}
                                        desimal={b.BolehDesimal ? 4 : 0}
                                        akhiran={b.SimbolSatuan}
                                        galat={
                                            galatServer[`Bahan.${String(indeks)}.Jumlah`] ??
                                            galatServer[`Bahan.${String(indeks)}.UuidProduk`] ??
                                            (periksa && !CekJumlahProduksi(b.Jumlah, b.BolehDesimal)
                                                ? 'Isi jumlah lebih dari 0.'
                                                : undefined)
                                        }
                                        required
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses}>
                        Simpan draf
                    </Tombol>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={Order === null ? AlamatProduksi : `${AlamatProduksi}/${Order.Uuid}`}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
