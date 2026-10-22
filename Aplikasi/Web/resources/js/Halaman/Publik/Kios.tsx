import { Head } from '@inertiajs/react';
import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, MinusIcon, PlusIcon, ShoppingBagIcon, StoreIcon, Trash2Icon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

import { GalatKios, KirimJsonKios } from '@/Fitur/Kios/KlienKios';
import { DETIK_PERINGATAN, useDiam } from '@/Fitur/Kios/useDiam';
import {
    BATAS_JUMLAH,
    HitungJumlahItem,
    TambahKeKeranjang,
    UbahJumlah,
    type BarisKeranjang,
} from '@/Fitur/PesanSendiri/Keranjang';
import GambarProduk from '@/Komponen/Katalog/GambarProduk';
import KeadaanKosong from '@/Komponen/Katalog/KeadaanKosong';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import { FormatRupiah } from '@/Pustaka/Format';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import { BuatUlid } from '@/Pustaka/Ulid';

import { LembarPilihan, RincianPerkiraan, type PerkiraanTotal, type ProdukMenu } from './PesanSendiri';

type Santap = 'MakanDiTempat' | 'BawaPulang';
type Metode = 'BayarSaatAmbil' | 'QrisOnline';
type DataMenu = { Kategori: { Uuid: string; Nama: string }[]; Produk: ProdukMenu[] };

export type PropsKios = {
    Aktif: boolean;
    Slug: string;
    Token: string;
    Toko: { Nama: string } | null;
    Menu: DataMenu;
    Pembayaran: { BayarDiKasir: boolean; Qris: boolean };
    DetikDiam: number;
};

type HasilHitung = { Subtotal: string; Total: string } & Partial<PerkiraanTotal>;

type RingkasPesanan = {
    KodeAkses: string;
    Nomor: string;
    NomorAntrian: string | null;
    JenisSantap: string | null;
    Status: string;
    LabelStatus: string;
    PerluBayar: boolean;
    SudahDibayar: boolean;
    BayarDiKasir: boolean;
    Total: string;
};

type TagihanKios = {
    Jumlah: string;
    Status: string;
    SudahDibayar: boolean;
    KedaluwarsaPada: string;
    Qr: string | null;
    UrlBayar: string | null;
};

type Layar = 'sambut' | 'santap' | 'menu' | 'bayar' | 'qris' | 'selesai';

const JEDA_HITUNG_MS = 300;
const DETIK_LAYAR_SELESAI = 30;
const KATEGORI_SEMUA = 'semua';
const KATEGORI_LAIN = 'lainnya';

/**
 * F-17 bagian 4: kios pesan sendiri di layar sentuh outlet (tablet/monitor), tanpa login dan tanpa data pribadi.
 * Alur: sambutan → makan di sini/bawa pulang → menu → bayar (di kasir atau QRIS) → nomor antrian. Harga, pajak, promo,
 * dan stok selalu dari server. Tidak disentuh = kembali ke awal dan keranjang dikosongkan (layar dipakai bergantian).
 */
export default function HalamanKios({ Aktif, Slug, Token, Toko, Menu, Pembayaran, DetikDiam }: PropsKios) {
    const alamat = `/${Slug}/kios/${Token}`;

    if (Toko === null) {
        return (
            <KerangkaKios judul="Kios tidak dikenal">
                <PesanTengah judul="Tautan kios tidak berlaku">
                    Tautan ini sudah diganti atau salah. Minta tautan terbaru ke pemilik toko.
                </PesanTengah>
            </KerangkaKios>
        );
    }

    if (!Aktif) {
        return (
            <KerangkaKios judul={Toko.Nama}>
                <PesanTengah judul="Kios sedang tidak menerima pesanan">
                    Silakan pesan langsung di kasir. Terima kasih sudah berkunjung ke {Toko.Nama}.
                </PesanTengah>
            </KerangkaKios>
        );
    }

    return (
        <KerangkaKios judul={Toko.Nama}>
            <AlurKios
                alamat={alamat}
                token={Token}
                namaToko={Toko.Nama}
                menuAwal={Menu}
                pembayaran={Pembayaran}
                detikDiam={DetikDiam}
            />
        </KerangkaKios>
    );
}

function KerangkaKios({ judul, children }: { judul: string; children: ReactNode }) {
    return (
        <>
            <Head title={judul} />
            <main className="flex min-h-screen w-full flex-col bg-latar text-isi text-teks-utama select-none">
                {children}
            </main>
        </>
    );
}

function PesanTengah({ judul, children }: { judul: string; children: ReactNode }) {
    return (
        <section className="m-auto flex max-w-xl flex-col items-center gap-3 p-8 text-center">
            <StoreIcon aria-hidden="true" className="size-14 text-teks-sekunder" />
            <JudulHalaman>{judul}</JudulHalaman>
            <p className="text-subjudul text-teks-sekunder">{children}</p>
        </section>
    );
}

type PropsAlur = {
    alamat: string;
    token: string;
    namaToko: string;
    menuAwal: DataMenu;
    pembayaran: PropsKios['Pembayaran'];
    detikDiam: number;
};

function AlurKios({ alamat, token, namaToko, menuAwal, pembayaran, detikDiam }: PropsAlur) {
    const [layar, AturLayar] = useState<Layar>('sambut');
    const [santap, AturSantap] = useState<Santap>('MakanDiTempat');
    const [keranjang, AturKeranjang] = useState<BarisKeranjang[]>([]);
    const [pesanan, AturPesanan] = useState<RingkasPesanan | null>(null);
    const [tagihan, AturTagihan] = useState<TagihanKios | null>(null);

    const Mulai = () => {
        AturKeranjang([]);
        AturPesanan(null);
        AturTagihan(null);
        AturSantap('MakanDiTempat');
        AturLayar('sambut');
    };

    // Layar sambutan dan "pesanan diterima" punya waktunya sendiri; QRIS menunggu pembayaran (QR berlaku 15 menit).
    const { sisa, Lanjutkan } = useDiam(layar === 'santap' || layar === 'menu' || layar === 'bayar', detikDiam, Mulai);

    return (
        <>
            {layar === 'sambut' ? <Sambut namaToko={namaToko} saatMulai={() => AturLayar('santap')} /> : null}
            {layar === 'santap' ? (
                <PilihSantap
                    saatPilih={(s) => {
                        AturSantap(s);
                        AturLayar('menu');
                    }}
                    saatBatal={Mulai}
                />
            ) : null}
            {layar === 'menu' || layar === 'bayar' ? (
                <Memesan
                    alamat={alamat}
                    token={token}
                    namaToko={namaToko}
                    menuAwal={menuAwal}
                    pembayaran={pembayaran}
                    santap={santap}
                    keranjang={keranjang}
                    aturKeranjang={AturKeranjang}
                    bayar={layar === 'bayar'}
                    saatBayar={() => AturLayar('bayar')}
                    saatKembali={() => AturLayar('menu')}
                    saatGantiSantap={() => AturLayar('santap')}
                    saatBatal={Mulai}
                    saatPesan={(hasil, tagihanBaru) => {
                        AturPesanan(hasil);
                        AturTagihan(tagihanBaru);
                        AturLayar(tagihanBaru === null ? 'selesai' : 'qris');
                    }}
                />
            ) : null}
            {layar === 'qris' && pesanan && tagihan ? (
                <LayarQris
                    alamat={alamat}
                    token={token}
                    pesanan={pesanan}
                    tagihan={tagihan}
                    saatLunas={(baru) => {
                        AturPesanan(baru);
                        AturLayar('selesai');
                    }}
                    saatBatal={Mulai}
                />
            ) : null}
            {layar === 'selesai' && pesanan ? (
                <LayarSelesai alamat={alamat} token={token} pesanan={pesanan} saatSelesai={Mulai} />
            ) : null}
            {sisa === null ? null : (
                <div
                    role="alertdialog"
                    aria-label="Masih memesan?"
                    className="fixed inset-0 z-50 flex items-center justify-center bg-teks-utama/60 p-6"
                >
                    <div className="flex w-full max-w-md flex-col gap-4 rounded-panel bg-permukaan p-8 text-center">
                        <h2 className="text-judul font-semibold">Masih memesan?</h2>
                        <p className="text-subjudul text-teks-sekunder">
                            Layar akan kembali ke awal dalam {sisa} detik, dan pesanan yang belum dibayar dibatalkan.
                        </p>
                        <Tombol ukuran="besar" onClick={Lanjutkan}>
                            Lanjutkan memesan
                        </Tombol>
                        <p className="text-keterangan text-teks-sekunder">
                            Layar menyala otomatis {DETIK_PERINGATAN} detik terakhir.
                        </p>
                    </div>
                </div>
            )}
        </>
    );
}

function Sambut({ namaToko, saatMulai }: { namaToko: string; saatMulai: () => void }) {
    return (
        <button
            type="button"
            onClick={saatMulai}
            className="flex min-h-screen w-full flex-col items-center justify-center gap-6 bg-brand-gelap p-8 text-center text-brand-teks focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none"
        >
            <StoreIcon aria-hidden="true" className="size-20" />
            <span className="text-sorotan-besar font-bold">{namaToko}</span>
            <span className="text-sorotan font-semibold">Sentuh untuk memesan</span>
            <span className="text-subjudul">Pesan sendiri tanpa antre, bayar di kasir atau lewat QRIS.</span>
        </button>
    );
}

function PilihSantap({ saatPilih, saatBatal }: { saatPilih: (s: Santap) => void; saatBatal: () => void }) {
    return (
        <section className="flex min-h-screen flex-col gap-8 p-8">
            <JudulHalaman className="text-center" skala="sorotan">
                Makan di sini atau bawa pulang?
            </JudulHalaman>
            <div className="grid flex-1 grid-cols-1 gap-6 md:grid-cols-2">
                {(
                    [
                        ['MakanDiTempat', 'Makan di sini', 'Pesanan diantar atau diambil di meja pengambilan.'],
                        ['BawaPulang', 'Bawa pulang', 'Pesanan dibungkus untuk dibawa.'],
                    ] as const
                ).map(([nilai, judul, keterangan]) => (
                    <button
                        key={nilai}
                        type="button"
                        onClick={() => saatPilih(nilai)}
                        className="flex min-h-48 flex-col items-center justify-center gap-3 rounded-panel border-2 border-garis-input bg-permukaan p-8 text-center focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none active:border-brand active:bg-brand-lembut"
                    >
                        <span className="text-sorotan font-bold">{judul}</span>
                        <span className="text-subjudul text-teks-sekunder">{keterangan}</span>
                    </button>
                ))}
            </div>
            <div className="mx-auto w-full max-w-sm">
                <Tombol varian="sekunder" ukuran="besar" onClick={saatBatal}>
                    Batal
                </Tombol>
            </div>
        </section>
    );
}

function useNilaiTertunda<T>(nilai: T, jeda: number): T {
    const [tertunda, AturTertunda] = useState(nilai);

    useEffect(() => {
        const pewaktu = window.setTimeout(() => AturTertunda(nilai), jeda);

        return () => window.clearTimeout(pewaktu);
    }, [nilai, jeda]);

    return tertunda;
}

type PropsMemesan = {
    alamat: string;
    token: string;
    namaToko: string;
    menuAwal: DataMenu;
    pembayaran: PropsKios['Pembayaran'];
    santap: Santap;
    keranjang: BarisKeranjang[];
    aturKeranjang: (ubah: (lama: BarisKeranjang[]) => BarisKeranjang[]) => void;
    bayar: boolean;
    saatBayar: () => void;
    saatKembali: () => void;
    saatGantiSantap: () => void;
    saatBatal: () => void;
    saatPesan: (pesanan: RingkasPesanan, tagihan: TagihanKios | null) => void;
};

function Memesan(p: PropsMemesan) {
    const [kategori, AturKategori] = useState(KATEGORI_SEMUA);
    const [produkDipilih, AturProdukDipilih] = useState<ProdukMenu | null>(null);
    const [keranjangTerbuka, AturKeranjangTerbuka] = useState(false);

    // Harga menu bisa beda antara makan di sini dan bawa pulang, jadi menu dimuat ulang per pilihan; juga tiap 30 detik
    // supaya produk yang ditandai habis ("86") ikut hilang.
    const menuKueri = useQuery({
        queryKey: KunciKueri.Kios.Menu(p.token, p.santap),
        queryFn: ({ signal }) => KirimJsonKios<DataMenu>(`${p.alamat}/menu?santap=${p.santap}`, undefined, signal),
        ...(p.santap === 'MakanDiTempat' ? { initialData: p.menuAwal } : {}),
        refetchInterval: 30_000,
        placeholderData: keepPreviousData,
        retry: false,
    });
    const menu = menuKueri.data ?? p.menuAwal;
    const produkPerUuid = useMemo(() => new Map(menu.Produk.map((produk) => [produk.Uuid, produk])), [menu.Produk]);
    const tanda = useNilaiTertunda(
        JSON.stringify(
            p.keranjang.map((b) => ({
                UuidProduk: b.UuidProduk,
                ...(b.UuidVarian ? { UuidVarian: b.UuidVarian } : {}),
                Jumlah: b.Jumlah,
                Pilihan: b.Pilihan,
            })),
        ),
        JEDA_HITUNG_MS,
    );
    const hitung = useQuery({
        queryKey: KunciKueri.Kios.Hitung(p.token, p.santap, tanda),
        queryFn: ({ signal }) =>
            KirimJsonKios<HasilHitung>(
                `${p.alamat}/hitung`,
                { JenisSantap: p.santap, Baris: JSON.parse(tanda) as unknown },
                signal,
            ),
        enabled: tanda !== '[]',
        placeholderData: keepPreviousData,
        staleTime: 30_000,
        retry: false,
    });
    const jumlahItem = HitungJumlahItem(p.keranjang);
    const adaTanpaKategori = menu.Produk.some((produk) => produk.UuidKategori === null);
    const produkTampil = menu.Produk.filter((produk) =>
        kategori === KATEGORI_SEMUA
            ? true
            : kategori === KATEGORI_LAIN
              ? produk.UuidKategori === null
              : produk.UuidKategori === kategori,
    );

    const Tambah = (produk: ProdukMenu) => {
        if (produk.KelompokPilihan.length > 0 || (produk.Varian?.length ?? 0) > 0) {
            AturProdukDipilih(produk);

            return;
        }

        p.aturKeranjang((lama) =>
            TambahKeKeranjang(lama, { Uuid: BuatUlid(), UuidProduk: produk.Uuid, Jumlah: 1, Pilihan: [], Catatan: '' }),
        );
    };

    const panel = (
        <PanelKeranjang
            keranjang={p.keranjang}
            produkPerUuid={produkPerUuid}
            hitung={hitung}
            bayar={p.bayar}
            alamat={p.alamat}
            santap={p.santap}
            pembayaran={p.pembayaran}
            aturKeranjang={p.aturKeranjang}
            saatBayar={() => {
                AturKeranjangTerbuka(false);
                p.saatBayar();
            }}
            saatKembali={p.saatKembali}
            saatPesan={p.saatPesan}
            saatKosongkan={p.saatBatal}
        />
    );

    return (
        <div className="grid min-h-screen grid-cols-1 lg:grid-cols-[1fr_26rem]">
            <section className="flex min-w-0 flex-col gap-4 p-4 pb-28 lg:pb-4">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col">
                        <p className="text-label text-teks-sekunder">{p.namaToko}</p>
                        <JudulHalaman>{p.santap === 'MakanDiTempat' ? 'Makan di sini' : 'Bawa pulang'}</JudulHalaman>
                    </div>
                    <Tombol varian="sekunder" onClick={p.saatGantiSantap}>
                        <ArrowLeftIcon aria-hidden="true" className="size-4" />
                        Ganti pilihan
                    </Tombol>
                </header>
                <nav aria-label="Kategori menu" className="-mx-4 overflow-x-auto px-4">
                    <div className="flex w-max gap-2">
                        {[
                            { Uuid: KATEGORI_SEMUA, Nama: 'Semua' },
                            ...menu.Kategori,
                            ...(adaTanpaKategori ? [{ Uuid: KATEGORI_LAIN, Nama: 'Lainnya' }] : []),
                        ].map((k) => (
                            <button
                                key={k.Uuid}
                                type="button"
                                aria-pressed={kategori === k.Uuid}
                                onClick={() => AturKategori(k.Uuid)}
                                className={`min-h-14 rounded-kontrol border-2 px-6 text-subjudul font-semibold focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none ${
                                    kategori === k.Uuid
                                        ? 'border-brand bg-brand text-brand-teks'
                                        : 'border-garis-input bg-permukaan text-teks-utama'
                                }`}
                            >
                                {k.Nama}
                            </button>
                        ))}
                    </div>
                </nav>
                {menu.Produk.length === 0 ? (
                    <KeadaanKosong judul="Menu belum tersedia" ilustrasi>
                        Belum ada menu yang bisa dipesan di kios. Silakan pesan langsung di kasir.
                    </KeadaanKosong>
                ) : (
                    <ul aria-label="Daftar menu" className="grid grid-cols-2 gap-3 xl:grid-cols-3">
                        {produkTampil.map((produk) => (
                            <li key={produk.Uuid}>
                                <button
                                    type="button"
                                    onClick={() => Tambah(produk)}
                                    aria-label={`Tambah ${produk.Nama}`}
                                    className="flex h-full w-full flex-col overflow-hidden rounded-panel border-2 border-garis bg-permukaan text-left focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none active:border-brand"
                                >
                                    <GambarProduk
                                        nama={produk.Nama}
                                        url={produk.UrlGambar}
                                        ukuran="besar"
                                        className="flex aspect-[4/3] w-full bg-permukaan-sorot"
                                    />
                                    <span className="flex flex-1 flex-col gap-1 p-3">
                                        <span className="text-subjudul font-semibold break-words">{produk.Nama}</span>
                                        <span className="text-subjudul text-teks-sekunder tabular-nums">
                                            {(produk.Varian?.length ?? 0) > 0 ? 'Mulai ' : ''}
                                            {FormatRupiah(produk.Harga)}
                                        </span>
                                        {(produk.Varian?.length ?? 0) > 0 || produk.KelompokPilihan.length > 0 ? (
                                            <span className="text-keterangan text-teks-sekunder">Ada pilihan</span>
                                        ) : null}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <aside aria-label="Pesanan Anda" className="hidden border-l border-garis bg-permukaan lg:block">
                <div className="sticky top-0 flex max-h-screen flex-col overflow-y-auto p-4">{panel}</div>
            </aside>

            <div className="tepi-bawah-aman fixed inset-x-0 bottom-0 z-30 border-t border-garis bg-permukaan p-3 lg:hidden">
                <Tombol ukuran="besar" disabled={jumlahItem === 0} onClick={() => AturKeranjangTerbuka(true)}>
                    <ShoppingBagIcon aria-hidden="true" className="size-5" />
                    Lihat pesanan ({jumlahItem})
                </Tombol>
            </div>
            <Sheet open={keranjangTerbuka} onOpenChange={AturKeranjangTerbuka}>
                <SheetContent side="bottom" className="max-h-[90vh] overflow-y-auto rounded-t-panel p-4 lg:hidden">
                    <SheetHeader>
                        <SheetTitle className="text-judul font-semibold">Pesanan Anda</SheetTitle>
                        <SheetDescription className="sr-only">Daftar pesanan dan total.</SheetDescription>
                    </SheetHeader>
                    {panel}
                </SheetContent>
            </Sheet>

            {produkDipilih ? (
                <LembarPilihan
                    produk={produkDipilih}
                    saatTutup={() => AturProdukDipilih(null)}
                    saatTambah={(baris) => {
                        p.aturKeranjang((lama) => TambahKeKeranjang(lama, baris));
                        AturProdukDipilih(null);
                    }}
                />
            ) : null}
        </div>
    );
}

type PropsPanel = {
    keranjang: BarisKeranjang[];
    produkPerUuid: Map<string, ProdukMenu>;
    hitung: { data: HasilHitung | undefined; isError: boolean; error: unknown };
    bayar: boolean;
    alamat: string;
    santap: Santap;
    pembayaran: PropsKios['Pembayaran'];
    aturKeranjang: (ubah: (lama: BarisKeranjang[]) => BarisKeranjang[]) => void;
    saatBayar: () => void;
    saatKembali: () => void;
    saatPesan: (pesanan: RingkasPesanan, tagihan: TagihanKios | null) => void;
    saatKosongkan: () => void;
};

function PanelKeranjang(p: PropsPanel) {
    // Uuid kiriman tetap saat dicoba ulang (idempoten per pesanan) dan diganti bila isi keranjang berubah.
    const kiriman = useRef<{ tanda: string; uuid: string } | null>(null);
    const tanda = JSON.stringify(
        p.keranjang.map((b) => [b.UuidProduk, b.UuidVarian, b.Jumlah, b.Pilihan, b.Catatan, p.santap]),
    );
    const [galat, AturGalat] = useState<string | null>(null);
    const adaMasalahHitung = p.hitung.isError;
    const kosong = p.keranjang.length === 0;

    const pesan = useMutation({
        mutationFn: async (metode: Metode) => {
            if (kiriman.current?.tanda !== tanda) {
                kiriman.current = { tanda, uuid: BuatUlid() };
            }

            const hasil = await KirimJsonKios<RingkasPesanan>(`${p.alamat}/pesan`, {
                Uuid: kiriman.current.uuid,
                JenisSantap: p.santap,
                MetodePembayaran: metode,
                Baris: p.keranjang.map((b) => ({
                    UuidProduk: b.UuidProduk,
                    ...(b.UuidVarian ? { UuidVarian: b.UuidVarian } : {}),
                    Jumlah: b.Jumlah,
                    Pilihan: b.Pilihan,
                    ...(b.Catatan === '' ? {} : { Catatan: b.Catatan }),
                })),
            });

            if (metode !== 'QrisOnline') {
                return { hasil, tagihan: null };
            }

            return {
                hasil,
                tagihan: await KirimJsonKios<TagihanKios>(`${p.alamat}/pesanan/${hasil.KodeAkses}/bayar`, {}),
            };
        },
        onSuccess: ({ hasil, tagihan }) => p.saatPesan(hasil, tagihan),
        onError: (e) => AturGalat(e instanceof GalatKios ? e.message : 'Pesanan gagal dikirim. Coba lagi.'),
    });

    return (
        <div className="flex flex-col gap-4">
            <h2 className="text-judul font-semibold">{p.bayar ? 'Periksa pesanan Anda' : 'Pesanan Anda'}</h2>
            {kosong ? (
                <p className="text-subjudul text-teks-sekunder">Belum ada pesanan. Sentuh menu untuk menambahkan.</p>
            ) : (
                <ul className="flex flex-col divide-y divide-garis">
                    {p.keranjang.map((b) => {
                        const produk = p.produkPerUuid.get(b.UuidProduk);
                        const varian = produk?.Varian?.find((v) => v.Uuid === b.UuidVarian);

                        return (
                            <li key={b.Uuid} className="flex flex-col gap-2 py-3">
                                <div className="flex items-start justify-between gap-2">
                                    <span className="text-subjudul font-semibold break-words">
                                        {produk?.Nama ?? 'Menu'}
                                        {varian ? ` | ${varian.Nama}` : ''}
                                    </span>
                                    {p.bayar ? null : (
                                        <button
                                            type="button"
                                            aria-label={`Hapus ${produk?.Nama ?? 'menu'}`}
                                            onClick={() => p.aturKeranjang((lama) => UbahJumlah(lama, b.Uuid, 0))}
                                            className="flex size-12 shrink-0 items-center justify-center rounded-kontrol border border-garis-input focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none"
                                        >
                                            <Trash2Icon aria-hidden="true" className="size-5" />
                                        </button>
                                    )}
                                </div>
                                {b.Pilihan.length > 0 ? (
                                    <p className="text-keterangan text-teks-sekunder">
                                        {produk?.KelompokPilihan.flatMap((k) => k.Pilihan)
                                            .filter((o) => b.Pilihan.includes(o.Uuid))
                                            .map((o) => o.Nama)
                                            .join(', ')}
                                    </p>
                                ) : null}
                                {p.bayar ? (
                                    <p className="text-subjudul tabular-nums">{b.Jumlah} x</p>
                                ) : (
                                    <div
                                        className="flex items-center gap-3"
                                        role="group"
                                        aria-label={`Jumlah ${produk?.Nama ?? 'menu'}`}
                                    >
                                        <button
                                            type="button"
                                            aria-label="Kurangi"
                                            onClick={() =>
                                                p.aturKeranjang((lama) => UbahJumlah(lama, b.Uuid, b.Jumlah - 1))
                                            }
                                            className="flex size-14 items-center justify-center rounded-kontrol border-2 border-garis-input bg-permukaan focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none"
                                        >
                                            <MinusIcon aria-hidden="true" className="size-6" />
                                        </button>
                                        <span
                                            className="min-w-10 text-center text-judul font-semibold tabular-nums"
                                            aria-live="polite"
                                        >
                                            {b.Jumlah}
                                        </span>
                                        <button
                                            type="button"
                                            aria-label="Tambah"
                                            disabled={b.Jumlah >= BATAS_JUMLAH}
                                            onClick={() =>
                                                p.aturKeranjang((lama) => UbahJumlah(lama, b.Uuid, b.Jumlah + 1))
                                            }
                                            className="flex size-14 items-center justify-center rounded-kontrol border-2 border-garis-input bg-permukaan focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <PlusIcon aria-hidden="true" className="size-6" />
                                        </button>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
            {adaMasalahHitung ? (
                <Pemberitahuan jenis="bahaya">
                    {p.hitung.error instanceof GalatKios ? p.hitung.error.message : 'Total gagal dihitung. Coba lagi.'}
                </Pemberitahuan>
            ) : p.hitung.data && !kosong ? (
                <RincianPerkiraan subtotal={p.hitung.data.Subtotal} perkiraan={p.hitung.data} />
            ) : null}
            {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}
            {p.bayar ? (
                <div className="flex flex-col gap-3">
                    <h3 className="text-subjudul font-semibold">Bayar dengan</h3>
                    <Tombol
                        ukuran="besar"
                        memproses={pesan.isPending && pesan.variables === 'BayarSaatAmbil'}
                        disabled={pesan.isPending || adaMasalahHitung}
                        onClick={() => {
                            AturGalat(null);
                            pesan.mutate('BayarSaatAmbil');
                        }}
                    >
                        Bayar di kasir
                    </Tombol>
                    {p.pembayaran.Qris ? (
                        <Tombol
                            ukuran="besar"
                            varian="sekunder"
                            memproses={pesan.isPending && pesan.variables === 'QrisOnline'}
                            disabled={pesan.isPending || adaMasalahHitung}
                            onClick={() => {
                                AturGalat(null);
                                pesan.mutate('QrisOnline');
                            }}
                        >
                            Bayar sekarang dengan QRIS
                        </Tombol>
                    ) : null}
                    <Tombol varian="sekunder" ukuran="besar" disabled={pesan.isPending} onClick={p.saatKembali}>
                        Kembali ke menu
                    </Tombol>
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    <Tombol
                        ukuran="besar"
                        disabled={kosong || adaMasalahHitung || p.hitung.data === undefined}
                        onClick={p.saatBayar}
                    >
                        Lanjut ke pembayaran
                    </Tombol>
                    <Tombol varian="bahaya" ukuran="besar" disabled={kosong} onClick={p.saatKosongkan}>
                        Batalkan pesanan
                    </Tombol>
                </div>
            )}
        </div>
    );
}

type PropsQris = {
    alamat: string;
    token: string;
    pesanan: RingkasPesanan;
    tagihan: TagihanKios;
    saatLunas: (pesanan: RingkasPesanan) => void;
    saatBatal: () => void;
};

function LayarQris({ alamat, token, pesanan, tagihan, saatLunas, saatBatal }: PropsQris) {
    const status = useQuery({
        queryKey: KunciKueri.Kios.Status(token, `${pesanan.KodeAkses}:bayar`),
        queryFn: ({ signal }) =>
            KirimJsonKios<RingkasPesanan>(`${alamat}/pesanan/${pesanan.KodeAkses}/status-bayar`, undefined, signal),
        refetchInterval: 3000,
        retry: true,
    });
    const data = status.data;
    const habis = data !== undefined && ['Kedaluwarsa', 'Dibatalkan', 'Ditolak'].includes(data.Status);

    useEffect(() => {
        if (data && !data.PerluBayar && !habis) {
            saatLunas(data);
        }
    }, [data, habis, saatLunas]);

    return (
        <section className="m-auto flex w-full max-w-xl flex-col items-center gap-5 p-8 text-center">
            <JudulHalaman skala="sorotan">Bayar dengan QRIS</JudulHalaman>
            <p className="text-judul font-semibold tabular-nums">{FormatRupiah(tagihan.Jumlah)}</p>
            {habis ? (
                <Pemberitahuan jenis="bahaya">QR sudah kedaluwarsa. Mulai lagi atau bayar di kasir.</Pemberitahuan>
            ) : tagihan.Qr ? (
                <img
                    alt="Kode QRIS untuk pembayaran"
                    className="size-80 rounded-panel border border-garis bg-permukaan p-3"
                    src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(tagihan.Qr)}`}
                />
            ) : (
                <Pemberitahuan jenis="peringatan">
                    QRIS belum bisa ditampilkan di layar ini. Silakan mulai lagi dan pilih bayar di kasir.
                </Pemberitahuan>
            )}
            <p className="text-subjudul text-teks-sekunder">
                Pindai dengan aplikasi bank atau dompet digital. Layar ini berganti sendiri setelah pembayaran diterima.
            </p>
            <Tombol varian="sekunder" ukuran="besar" onClick={saatBatal}>
                {habis ? 'Mulai lagi' : 'Batalkan pembayaran'}
            </Tombol>
        </section>
    );
}

type PropsSelesai = { alamat: string; token: string; pesanan: RingkasPesanan; saatSelesai: () => void };

function LayarSelesai({ alamat, token, pesanan, saatSelesai }: PropsSelesai) {
    const [sisa, AturSisa] = useState(DETIK_LAYAR_SELESAI);
    const status = useQuery({
        queryKey: KunciKueri.Kios.Status(token, pesanan.KodeAkses),
        queryFn: ({ signal }) =>
            KirimJsonKios<RingkasPesanan>(`${alamat}/pesanan/${pesanan.KodeAkses}`, undefined, signal),
        refetchInterval: 5000,
        initialData: pesanan,
        retry: true,
    });
    const terkini = status.data;

    useEffect(() => {
        const pewaktu = window.setInterval(() => AturSisa((lama) => lama - 1), 1000);

        return () => window.clearInterval(pewaktu);
    }, []);

    useEffect(() => {
        if (sisa <= 0) {
            saatSelesai();
        }
    }, [sisa, saatSelesai]);

    return (
        <section className="m-auto flex w-full max-w-2xl flex-col items-center gap-5 p-8 text-center">
            <JudulHalaman>Pesanan diterima. Nomor antrian Anda</JudulHalaman>
            <p
                aria-label={`Nomor antrian ${terkini.NomorAntrian ?? ''}`}
                className="text-sorotan-besar font-bold tabular-nums text-brand"
            >
                {terkini.NomorAntrian ?? '-'}
            </p>
            <p className="text-judul font-semibold">{terkini.LabelStatus}</p>
            {terkini.BayarDiKasir && !terkini.SudahDibayar ? (
                <p className="text-subjudul">
                    Tunjukkan nomor ini di kasir dan bayar {FormatRupiah(terkini.Total)}. Pesanan mulai disiapkan
                    setelah dibayar.
                </p>
            ) : (
                <p className="text-subjudul">Pembayaran diterima. Tunggu nomor Anda dipanggil.</p>
            )}
            <p className="text-subjudul text-teks-sekunder">Layar kembali ke awal dalam {Math.max(sisa, 0)} detik.</p>
            <Tombol ukuran="besar" onClick={saatSelesai}>
                Pesan lagi
            </Tombol>
        </section>
    );
}
