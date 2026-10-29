import { Head } from '@inertiajs/react';
import { SearchIcon, StoreIcon, TruckIcon } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import { useLebarLayar } from '@/Komponen/TabelData/useLebarLayar';
import DialogMasukPembeli, {
    type AlamatPembeli,
    type HasilMasukPembeli,
    type ProfilPembeli,
} from '@/Komponen/TokoOnline/DialogMasukPembeli';
import KartuProduk from '@/Komponen/TokoOnline/KartuProduk';
import KartuSorotanToko from '@/Komponen/TokoOnline/KartuSorotanToko';
import KepalaToko from '@/Komponen/TokoOnline/KepalaToko';
import LembarOpsiProduk from '@/Komponen/TokoOnline/LembarOpsiProduk';
import PanelKeranjang, { type LangkahKeranjang } from '@/Komponen/TokoOnline/PanelKeranjang';
import type { BarisKeranjang, CaraBayar, CaraMenerima, HasilHitung, ProdukToko } from '@/Komponen/TokoOnline/TipeToko';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { BuatUlid } from '@/Pustaka/Ulid';

type Props = {
    Aktif: boolean;
    Slug: string;
    Toko: { Nama: string; NamaOutlet: string; Alamat: string | null; UrlLogo?: string | null } | null;
    Outlet: { Uuid: string; Nama: string }[];
    OutletDipilih: string;
    Pemenuhan: { AmbilSendiri: boolean; Kirim: boolean };
    Pembayaran: { BayarSaatAmbil: boolean; Cod: boolean; QrisOnline: boolean };
    MinimalPesanan: string;
    PesanTutup: string | null;
    Menu: { Kategori: { Uuid: string; Nama: string }[]; Produk: ProdukToko[] };
    /** F-17 bagian 3: akun pembeli opsional (masuk dengan kode WhatsApp). */
    Akun: { Aktif: boolean; Pelanggan: ProfilPembeli | null; AlamatTerakhir: AlamatPembeli | null };
};

async function Kirim<T>(url: string, badan: unknown): Promise<T> {
    const respons = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(badan),
    });
    const json = (await respons.json()) as { Galat?: { Pesan?: string } } & T;
    if (!respons.ok) throw new Error(json.Galat?.Pesan ?? 'Permintaan belum berhasil. Coba lagi.');
    return json;
}

/** Baris tanpa varian dan tanpa pilihan: satu produk polos selalu digabung ke satu baris. */
function CekBarisPolos(b: BarisKeranjang, uuidProduk: string): boolean {
    return b.UuidProduk === uuidProduk && b.UuidVarian === null && b.Pilihan.length === 0;
}

export default function TokoOnline({
    Aktif,
    Slug,
    Toko,
    Outlet,
    OutletDipilih,
    Pemenuhan,
    Pembayaran,
    MinimalPesanan,
    PesanTutup,
    Menu,
    Akun,
}: Props) {
    const desktop = useLebarLayar() === 'desktop';
    const [pembeli, AturPembeli] = useState<ProfilPembeli | null>(Akun.Pelanggan);
    const [dialogMasuk, AturDialogMasuk] = useState(false);
    const [baris, AturBaris] = useState<BarisKeranjang[]>([]);
    const [produkOpsi, AturProdukOpsi] = useState<ProdukToko | null>(null);
    const [laciBuka, AturLaciBuka] = useState(false);
    const [langkah, AturLangkah] = useState<LangkahKeranjang>('keranjang');
    const [jenis, AturJenis] = useState<CaraMenerima>(Pemenuhan.AmbilSendiri ? 'AmbilSendiri' : 'Kirim');
    const [kodePos, AturKodePos] = useState(Akun.AlamatTerakhir?.KodePos ?? '');
    const [bayar, AturBayar] = useState<CaraBayar>(
        Pembayaran.QrisOnline ? 'QrisOnline' : Pemenuhan.AmbilSendiri ? 'BayarSaatAmbil' : 'Cod',
    );
    const [hasil, AturHasil] = useState<HasilHitung | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);
    // v3.46: kode yang diketik, kode yang sedang dipakai di perhitungan, dan galat khusus voucher.
    const [isianVoucher, AturIsianVoucher] = useState('');
    const [voucher, AturVoucher] = useState('');
    const [galatVoucher, AturGalatVoucher] = useState<string | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const [setuju, AturSetuju] = useState(false);
    const [penerima, AturPenerima] = useState(() => ({
        NamaPelanggan: Akun.Pelanggan?.Nama ?? '',
        NoHp: Akun.Pelanggan?.NoHp ?? '',
        Email: Akun.Pelanggan?.Email ?? '',
        ...IsiAlamat(Akun.AlamatTerakhir),
        Catatan: '',
    }));
    const produk = useMemo(() => new Map(Menu.Produk.map((p) => [p.Uuid, p])), [Menu.Produk]);
    const hasilBerlaku = baris.length > 0 && (jenis !== 'Kirim' || kodePos.length === 5) ? hasil : null;
    // Bayar saat ambil hanya untuk ambil sendiri, COD hanya untuk kirim; QRIS berlaku untuk keduanya.
    const opsiBayar = useMemo(() => {
        const opsi: { Nilai: string; Label: string }[] = [];
        if (Pembayaran.QrisOnline) opsi.push({ Nilai: 'QrisOnline', Label: 'Bayar sekarang (QRIS)' });
        if (jenis === 'AmbilSendiri' && Pembayaran.BayarSaatAmbil)
            opsi.push({ Nilai: 'BayarSaatAmbil', Label: 'Bayar saat ambil' });
        if (jenis === 'Kirim' && Pembayaran.Cod) opsi.push({ Nilai: 'Cod', Label: 'Bayar di tempat (COD)' });
        return opsi;
    }, [Pembayaran, jenis]);

    // Pilihan yang tidak berlaku lagi (mis. ganti Ambil sendiri → Kirim) diturunkan ke opsi pertama, bukan
    // disinkronkan lewat efek: state tetap apa yang pelanggan pilih, tampilan & kiriman memakai yang berlaku.
    const bayarBerlaku = opsiBayar.some((o) => o.Nilai === bayar)
        ? bayar
        : ((opsiBayar[0]?.Nilai ?? bayar) as CaraBayar);

    useEffect(() => {
        if (baris.length === 0 || (jenis === 'Kirim' && kodePos.length !== 5)) {
            return;
        }
        const tunda = window.setTimeout(() => {
            void Kirim<HasilHitung>(`/${Slug}/keranjang/hitung`, {
                Outlet: OutletDipilih,
                JenisPemenuhan: jenis,
                KodePos: kodePos || null,
                KodeVoucher: voucher || null,
                Baris: baris,
            })
                .then((h) => {
                    AturHasil(h);
                    AturGalat(null);
                })
                .catch((e: unknown) => {
                    const pesan = e instanceof Error ? e.message : 'Gagal menghitung keranjang.';
                    // Voucher yang ditolak dilepas lalu keranjang dihitung ulang tanpa voucher.
                    if (voucher !== '') {
                        AturGalatVoucher(pesan);
                        AturVoucher('');
                        return;
                    }
                    AturHasil(null);
                    AturGalat(pesan);
                });
        }, 250);
        return () => window.clearTimeout(tunda);
        // Masuk/keluar mengubah harga (harga tier & promo pelanggan dihitung server), jadi keranjang dihitung ulang.
    }, [Slug, OutletDipilih, baris, jenis, kodePos, pembeli?.Uuid, voucher]);

    function PakaiVoucher() {
        const kode = isianVoucher.trim().toUpperCase();
        AturGalatVoucher(null);
        AturVoucher(kode);
    }

    /** Menambah satu ke baris yang sama persis (produk, varian, pilihan), atau membuat baris baru. */
    function TambahBaris(uuidProduk: string, varian: string | null, pilihan: string[]) {
        const kunci = [...pilihan].sort().join('|');
        AturBaris((lama) => {
            const ada = lama.find(
                (b) =>
                    b.UuidProduk === uuidProduk && b.UuidVarian === varian && [...b.Pilihan].sort().join('|') === kunci,
            );

            if (ada) {
                return lama.map((b) => (b === ada ? { ...b, Jumlah: b.Jumlah + 1 } : b));
            }

            return [
                ...lama,
                {
                    Uuid: BuatUlid(),
                    UuidProduk: uuidProduk,
                    UuidVarian: varian,
                    Jumlah: 1,
                    Pilihan: pilihan,
                    Catatan: '',
                },
            ];
        });
        AturGalat(null);
    }

    function KurangiPolos(uuidProduk: string) {
        AturBaris((lama) =>
            lama.flatMap((b) => {
                if (!CekBarisPolos(b, uuidProduk)) {
                    return [b];
                }

                return b.Jumlah > 1 ? [{ ...b, Jumlah: b.Jumlah - 1 }] : [];
            }),
        );
    }

    function SaatMasuk(hasilMasuk: HasilMasukPembeli) {
        AturPembeli(hasilMasuk.Pelanggan);
        AturDialogMasuk(false);
        // Data akun mengisi yang masih kosong; ketikan pembeli tidak ditimpa.
        AturPenerima((x) => {
            const alamat = IsiAlamat(hasilMasuk.AlamatTerakhir);
            return {
                ...x,
                NamaPelanggan: x.NamaPelanggan || hasilMasuk.Pelanggan.Nama,
                NoHp: hasilMasuk.Pelanggan.NoHp,
                Email: x.Email || (hasilMasuk.Pelanggan.Email ?? ''),
                ...(x.Alamat === '' ? alamat : {}),
            };
        });
        if (kodePos === '' && hasilMasuk.AlamatTerakhir?.KodePos) AturKodePos(hasilMasuk.AlamatTerakhir.KodePos);
    }

    function UbahJumlah(uuid: string, selisih: number) {
        AturBaris((lama) => lama.map((b) => (b.Uuid === uuid ? { ...b, Jumlah: Math.max(1, b.Jumlah + selisih) } : b)));
    }

    async function Pesan(e: FormEvent) {
        e.preventDefault();
        AturMemproses(true);
        AturGalat(null);
        try {
            const respons = await Kirim<{ UrlStatus: string }>(`/${Slug}/pesan`, {
                Uuid: BuatUlid(),
                ...penerima,
                Outlet: OutletDipilih,
                JenisPemenuhan: jenis,
                MetodePembayaran: bayarBerlaku,
                KodePos: kodePos || null,
                KodeVoucher: hasilBerlaku?.Voucher?.Kode ?? null,
                SetujuDataPribadi: setuju,
                Baris: baris,
            });
            window.location.assign(respons.UrlStatus);
        } catch (e2: unknown) {
            AturGalat(e2 instanceof Error ? e2.message : 'Pesanan belum berhasil dikirim.');
            AturMemproses(false);
        }
    }

    /** Desktop: kolom keranjang sudah terlihat (hanya digulir ke sana); HP/tablet: membuka laci keranjang. */
    function BukaKeranjang() {
        if (desktop) {
            document.getElementById('keranjang')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        AturLaciBuka(true);
    }

    const [kategoriAktif, AturKategoriAktif] = useState('');
    const [cari, AturCari] = useState('');
    const jumlahItem = baris.reduce((n, b) => n + b.Jumlah, 0);
    const jumlahPerProduk = useMemo(() => {
        const peta = new Map<string, number>();
        for (const b of baris) {
            peta.set(b.UuidProduk, (peta.get(b.UuidProduk) ?? 0) + b.Jumlah);
        }
        return peta;
    }, [baris]);
    const jumlahPolosPerProduk = useMemo(() => {
        const peta = new Map<string, number>();
        for (const b of baris) {
            if (b.UuidVarian === null && b.Pilihan.length === 0) {
                peta.set(b.UuidProduk, (peta.get(b.UuidProduk) ?? 0) + b.Jumlah);
            }
        }
        return peta;
    }, [baris]);
    const produkTampil = useMemo(() => {
        const kata = cari.trim().toLowerCase();

        return Menu.Produk.filter(
            (p) =>
                (kategoriAktif === '' || p.UuidKategori === kategoriAktif) &&
                (kata === '' || p.Nama.toLowerCase().includes(kata)),
        );
    }, [Menu.Produk, kategoriAktif, cari]);
    // Kartu sorotan di kepala toko = produk favorit pilihan pemilik yang tampil di menu (tanpa favorit, tidak ada kartu).
    const produkSorotan = Aktif ? Menu.Produk.find((p) => p.Favorit === true) : undefined;
    const namaKategori = useMemo(() => new Map(Menu.Kategori.map((k) => [k.Uuid, k.Nama])), [Menu.Kategori]);
    const opsiPemenuhan = [
        { nilai: 'AmbilSendiri' as const, label: 'Ambil sendiri', ikon: StoreIcon, ada: Pemenuhan.AmbilSendiri },
        { nilai: 'Kirim' as const, label: 'Dikirim', ikon: TruckIcon, ada: Pemenuhan.Kirim },
    ].filter((o) => o.ada);

    const panel = (
        <PanelKeranjang
            langkah={langkah}
            saatGantiLangkah={AturLangkah}
            baris={baris}
            produk={produk}
            saatUbahJumlah={UbahJumlah}
            saatHapus={(uuid) => AturBaris((x) => x.filter((z) => z.Uuid !== uuid))}
            opsiPemenuhan={opsiPemenuhan}
            jenis={jenis}
            saatGantiJenis={AturJenis}
            kodePos={kodePos}
            saatGantiKodePos={AturKodePos}
            isianVoucher={isianVoucher}
            saatGantiIsianVoucher={AturIsianVoucher}
            galatVoucher={galatVoucher}
            saatPakaiVoucher={PakaiVoucher}
            saatLepasVoucher={() => {
                AturVoucher('');
                AturIsianVoucher('');
            }}
            hasil={hasilBerlaku}
            minimalPesanan={MinimalPesanan}
            galat={galat}
            pembeli={pembeli}
            akunAktif={Akun.Aktif}
            saatBukaMasuk={() => AturDialogMasuk(true)}
            penerima={penerima}
            saatGantiPenerima={(ubah) => AturPenerima((x) => ({ ...x, ...ubah }))}
            opsiBayar={opsiBayar}
            bayar={bayarBerlaku}
            saatGantiBayar={AturBayar}
            setuju={setuju}
            saatGantiSetuju={AturSetuju}
            memproses={memproses}
            saatPesan={(e) => void Pesan(e)}
        />
    );

    return (
        <div className="min-h-dvh bg-latar text-teks-utama">
            <Head title={Toko?.Nama ?? 'Toko online'}>
                {/* D-110: pratinjau tautan di WhatsApp, Instagram, dan mesin pencari. */}
                <meta
                    name="description"
                    content={`Pesan online dari ${Toko?.Nama ?? 'toko kami'}: pilih menu, ambil sendiri atau diantar, bayar mudah.`}
                />
                <meta property="og:type" content="website" />
                <meta property="og:title" content={Toko?.Nama ?? 'Toko online'} />
                <meta
                    property="og:description"
                    content={`Pesan online dari ${Toko?.Nama ?? 'toko kami'}${Toko?.Alamat ? ` | ${Toko.Alamat}` : ''}.`}
                />
            </Head>

            <KepalaToko
                toko={Toko}
                aktif={Aktif}
                pemenuhan={Pemenuhan}
                qrisOnline={Pembayaran.QrisOnline}
                minimalPesanan={MinimalPesanan}
                pembeli={pembeli}
                slug={Slug}
                akunAktif={Akun.Aktif}
                jumlahItem={jumlahItem}
                saatBukaKeranjang={BukaKeranjang}
                saatBukaMasuk={() => AturDialogMasuk(true)}
                adaSorotan={produkSorotan !== undefined}
            >
                {produkSorotan ? (
                    <KartuSorotanToko
                        produk={produkSorotan}
                        jumlahDiKeranjang={jumlahPerProduk.get(produkSorotan.Uuid) ?? 0}
                        saatTambah={() => TambahBaris(produkSorotan.Uuid, null, [])}
                        saatBukaOpsi={() => AturProdukOpsi(produkSorotan)}
                    />
                ) : null}
                {Outlet.length > 1 ? (
                    <div className="max-w-xs rounded-3xl bg-permukaan p-2 text-teks-utama">
                        <BidangPilihan
                            label="Belanja dari outlet"
                            nilai={OutletDipilih}
                            opsi={Outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                            saatBerubah={(nilai) =>
                                window.location.assign(`/${Slug}?outlet=${encodeURIComponent(nilai)}`)
                            }
                        />
                    </div>
                ) : null}
            </KepalaToko>

            <div className={produkSorotan ? 'pt-14 lg:pt-0' : ''}>
                {Aktif && Menu.Produk.length > 0 ? (
                    <nav aria-label="Kategori menu" className="sticky top-0 z-10 bg-latar">
                        <div className="mx-auto flex max-w-6xl flex-col gap-3 px-4 pt-4 pb-3 lg:flex-row lg:items-center lg:gap-4">
                            <label className="relative block">
                                <span className="sr-only">Cari menu</span>
                                <SearchIcon
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-teks-sekunder"
                                />
                                <input
                                    type="search"
                                    value={cari}
                                    onChange={(e) => AturCari(e.target.value)}
                                    placeholder="Cari menu"
                                    className="h-12 w-full rounded-full border-2 border-garis bg-permukaan pr-5 pl-12 text-subjudul text-teks-utama placeholder:text-teks-sekunder focus-visible:border-brand focus-visible:outline-none"
                                />
                            </label>
                            {Menu.Kategori.length > 0 ? (
                                <div className="-mx-4 flex min-w-0 gap-2 overflow-x-auto px-4 pb-1 lg:mx-0 lg:px-0">
                                    {[{ Uuid: '', Nama: 'Semua' }, ...Menu.Kategori].map((k) => (
                                        <button
                                            key={k.Uuid || 'semua'}
                                            type="button"
                                            aria-pressed={kategoriAktif === k.Uuid}
                                            onClick={() => AturKategoriAktif(k.Uuid)}
                                            className={`min-h-11 shrink-0 rounded-full border-2 px-5 text-isi font-bold whitespace-nowrap ${
                                                kategoriAktif === k.Uuid
                                                    ? 'border-brand-gelap bg-brand-gelap text-permukaan'
                                                    : 'border-garis bg-permukaan text-teks-utama'
                                            }`}
                                        >
                                            {k.Nama}
                                        </button>
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    </nav>
                ) : null}

                <div className="mx-auto grid max-w-6xl items-start gap-6 px-4 pt-2 pb-28 lg:grid-cols-[1fr_380px] lg:pb-10">
                    <section className="flex min-w-0 flex-col gap-4" aria-label="Daftar menu">
                        {!Aktif ? (
                            <Pemberitahuan jenis="peringatan">
                                {PesanTutup ?? 'Toko online sedang tutup.'}
                            </Pemberitahuan>
                        ) : Menu.Produk.length === 0 ? (
                            <Pemberitahuan jenis="info">Belum ada produk yang ditampilkan online.</Pemberitahuan>
                        ) : produkTampil.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 rounded-panel border border-garis bg-permukaan p-8 text-center">
                                <SearchIcon aria-hidden="true" className="size-8 text-teks-sekunder" />
                                <p className="text-isi text-teks-sekunder">
                                    Tidak ada menu yang cocok. Coba kata lain atau pilih kategori Semua.
                                </p>
                            </div>
                        ) : (
                            <>
                                <h2 className="flex items-baseline gap-3 text-judul-bagian-hp font-extrabold text-teks-utama">
                                    Menu
                                    <span className="text-label font-semibold text-teks-sekunder">
                                        {produkTampil.length} pilihan
                                    </span>
                                </h2>
                                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    {produkTampil.map((p) => (
                                        <KartuProduk
                                            key={p.Uuid}
                                            produk={p}
                                            namaKategori={
                                                p.UuidKategori ? (namaKategori.get(p.UuidKategori) ?? null) : null
                                            }
                                            jumlahDiKeranjang={jumlahPerProduk.get(p.Uuid) ?? 0}
                                            jumlahPolos={jumlahPolosPerProduk.get(p.Uuid) ?? 0}
                                            saatTambah={() => TambahBaris(p.Uuid, null, [])}
                                            saatKurang={() => KurangiPolos(p.Uuid)}
                                            saatBukaOpsi={() => AturProdukOpsi(p)}
                                        />
                                    ))}
                                </div>
                            </>
                        )}
                    </section>

                    {desktop ? (
                        <aside
                            id="keranjang"
                            aria-label="Keranjang"
                            className="flex max-h-[calc(100dvh-8rem)] flex-col rounded-3xl bg-permukaan lg:sticky lg:top-32"
                        >
                            <div className="flex items-center justify-between gap-2 p-5 pb-3">
                                <h2 className="text-judul-bagian-hp font-extrabold text-teks-utama">Keranjang</h2>
                                {jumlahItem > 0 ? (
                                    <span className="rounded-full bg-aksen px-3 py-1 text-label font-bold text-teks-utama tabular-nums">
                                        {jumlahItem} item
                                    </span>
                                ) : null}
                            </div>
                            <div className="overflow-y-auto p-5 pt-2">{panel}</div>
                        </aside>
                    ) : null}
                </div>
            </div>

            {Aktif && jumlahItem > 0 && !desktop ? (
                <button
                    type="button"
                    onClick={() => AturLaciBuka(true)}
                    className="tepi-bawah-aman fixed inset-x-0 bottom-0 z-20 px-3 pt-2 pb-3 text-left"
                >
                    <span className="mx-auto flex max-w-xl items-center gap-3 rounded-full bg-brand-gelap p-2 pr-5 text-permukaan">
                        <span className="grid size-12 shrink-0 place-items-center rounded-full bg-aksen text-subjudul font-extrabold text-teks-utama tabular-nums">
                            {jumlahItem}
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="text-subjudul font-bold">Lihat keranjang | {jumlahItem} item</span>
                            <span className="truncate text-keterangan text-brand-gelap-teks">
                                {jenis === 'Kirim' ? 'Dikirim' : 'Ambil sendiri'}
                            </span>
                        </span>
                        <span className="text-subjudul font-extrabold tabular-nums">
                            {hasilBerlaku ? FormatRupiah(hasilBerlaku.Total) : ''}
                        </span>
                    </span>
                </button>
            ) : null}

            {!desktop ? (
                <Sheet open={laciBuka} onOpenChange={AturLaciBuka}>
                    <SheetContent
                        side="bottom"
                        aria-describedby={undefined}
                        className="mx-auto max-h-[92dvh] w-full gap-0 overflow-hidden rounded-t-3xl p-0 sm:max-w-xl"
                    >
                        <SheetHeader className="p-5 pr-14 pb-3">
                            <SheetTitle className="text-judul-bagian-hp font-extrabold">Keranjang</SheetTitle>
                            <SheetDescription className="text-keterangan">
                                {jumlahItem > 0
                                    ? `${String(jumlahItem)} item | periksa pesanan lalu lanjut ke data pesanan.`
                                    : 'Belum ada menu yang dipilih.'}
                            </SheetDescription>
                        </SheetHeader>
                        <div className="tepi-bawah-aman overflow-y-auto p-4">{panel}</div>
                    </SheetContent>
                </Sheet>
            ) : null}

            {produkOpsi ? (
                <LembarOpsiProduk
                    produk={produkOpsi}
                    saatTambah={(varian, pilihan) => {
                        TambahBaris(produkOpsi.Uuid, varian, pilihan);
                        AturProdukOpsi(null);
                    }}
                    saatTutup={() => AturProdukOpsi(null)}
                />
            ) : null}

            {dialogMasuk && Toko ? (
                <DialogMasukPembeli
                    slug={Slug}
                    namaToko={Toko.Nama}
                    saatMasuk={SaatMasuk}
                    saatTutup={() => AturDialogMasuk(false)}
                />
            ) : null}
        </div>
    );
}

function IsiAlamat(a: AlamatPembeli | null) {
    return {
        Alamat: a?.Alamat ?? '',
        Kelurahan: a?.Kelurahan ?? '',
        Kecamatan: a?.Kecamatan ?? '',
        Kota: a?.Kota ?? '',
        Provinsi: a?.Provinsi ?? '',
    };
}
