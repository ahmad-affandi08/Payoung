import { Head } from '@inertiajs/react';
import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query';
import { MinusIcon, PlusIcon, ShoppingBagIcon, Trash2Icon, WifiOffIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import GambarProduk from '@/Komponen/Katalog/GambarProduk';
import KeadaanKosong from '@/Komponen/Katalog/KeadaanKosong';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import { Skeleton } from '@/Komponen/Ui/skeleton';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import {
    AlihkanPilihan,
    BacaKeranjang,
    BacaRiwayatPesanan,
    BATAS_JUMLAH,
    CatatRiwayatPesanan,
    HitungJumlahItem,
    PeriksaPilihan,
    SimpanKeranjang,
    TambahKeKeranjang,
    UbahJumlah,
    type BarisKeranjang,
    type KelompokPilihanMenu,
} from '@/Fitur/PesanSendiri/Keranjang';
import { FormatRupiah } from '@/Pustaka/Format';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import { BuatUlid } from '@/Pustaka/Ulid';

/** PRD v2.06: varian produk (anak varian); `Harga` null = belum berharga (tidak bisa dipilih). */
export type VarianMenu = {
    Uuid: string;
    Nama: string;
    Atribut: { Nama: string; Nilai: string }[];
    Harga: string | null;
    Tersedia: boolean;
};

export type ProdukMenu = {
    Uuid: string;
    /** Null untuk induk varian (satuan milik anak varian). */
    UuidProdukSatuan: string | null;
    Nama: string;
    UuidKategori: string | null;
    /** Induk varian: harga varian termurah. */
    Harga: string;
    UrlGambar: string | null;
    KelompokPilihan: KelompokPilihanMenu[];
    NamaAtributVarian?: string | null;
    Varian?: VarianMenu[];
};

export type PropsPesanSendiri = {
    Aktif: boolean;
    Toko: { Nama: string; NamaOutlet: string } | null;
    Meja: { Nama: string } | null;
    Token: string;
    Slug: string;
    Menu: { Kategori: { Uuid: string; Nama: string }[]; Produk: ProdukMenu[] };
    /** SLS-04: `Qris` true bila gerbang & sakelar QRIS toko siap, sehingga tamu bisa bayar dari HP. */
    Pembayaran?: { Qris: boolean };
};

type TagihanQrisMeja = {
    Jumlah: string;
    Status: string;
    SudahDibayar: boolean;
    KedaluwarsaPada: string;
    Qr: string | null;
    UrlBayar: string | null;
};

type PesananQrisMeja = {
    KodeAkses: string;
    Nomor: string;
    Status: string;
    LabelStatus: string;
    PerluBayar: boolean;
    SudahDibayar: boolean;
    Total: string;
};

/** Menu disegarkan tiap 30 detik (BR-17.2: produk yang ditandai habis di POS langsung hilang dari HP tamu). */
const JEDA_MENU_MS = 30000;

/** Perkiraan total dari server (PRD v2.06); pesanan lama tanpa perkiraan = null. */
export type PerkiraanTotal = {
    Diskon: string;
    BiayaLayanan: string;
    Pajak: { Kode: string; Nama: string; Tarif: string; Jumlah: string }[];
    PajakTermasukHarga: string;
    Pembulatan: string;
    Total: string;
};

type HasilHitung = {
    Baris: {
        UuidProduk: string;
        NamaProduk: string;
        Jumlah: string;
        HargaSatuan: string;
        HargaPilihan: string;
        Total: string;
    }[];
    Subtotal: string;
    Catatan: string;
} & Partial<PerkiraanTotal>;

export type StatusPesanan = 'MenungguKonfirmasi' | 'Diterima' | 'Ditolak' | 'Kedaluwarsa';

type PesananTamu = {
    Uuid: string;
    Nomor: string;
    Status: StatusPesanan;
    Baris: { Uuid: string; NamaProduk: string; Jumlah: string; Pilihan: { Nama: string }[]; Catatan: string | null }[];
    Subtotal: string;
    Perkiraan?: PerkiraanTotal | null;
    AlasanTolak: string | null;
};

/** Galat berformat `{"Galat": {"Kode", "Pesan"}}` dari server. */
export class GalatPesanSendiri extends Error {
    constructor(
        public readonly kode: string,
        pesan: string,
        public readonly status: number,
    ) {
        super(pesan);
    }
}

const JEDA_POLLING_MS = 5000;

function CekNol(nilai: string | undefined): boolean {
    return nilai === undefined || /^-?0+(\.0+)?$/.test(nilai);
}

/**
 * Rincian perkiraan total (PRD v2.06): subtotal, diskon, biaya layanan, pajak per jenis, total. Semua angka dari
 * server; halaman tidak menghitung sendiri.
 */
export function RincianPerkiraan({ subtotal, perkiraan }: { subtotal: string; perkiraan: Partial<PerkiraanTotal> }) {
    const baris: { label: string; nilai: string; kurang?: boolean }[] = [
        { label: 'Subtotal', nilai: subtotal },
        ...(CekNol(perkiraan.Diskon) ? [] : [{ label: 'Diskon', nilai: perkiraan.Diskon ?? '0', kurang: true }]),
        ...(CekNol(perkiraan.BiayaLayanan) ? [] : [{ label: 'Biaya layanan', nilai: perkiraan.BiayaLayanan ?? '0' }]),
        ...(perkiraan.Pajak ?? []).map((p) => ({ label: `${p.Nama} ${p.Tarif}%`, nilai: p.Jumlah })),
    ];

    return (
        <dl className="flex flex-col gap-1">
            {baris.map((b) => (
                <div key={b.label} className="flex items-center justify-between gap-3 text-isi text-teks-sekunder">
                    <dt>{b.label}</dt>
                    <dd className="tabular-nums">
                        {b.kurang ? '−' : ''}
                        {FormatRupiah(b.nilai)}
                    </dd>
                </div>
            ))}
            {CekNol(perkiraan.PajakTermasukHarga) ? null : (
                <p className="text-keterangan text-teks-sekunder">
                    Harga sudah termasuk pajak {FormatRupiah(perkiraan.PajakTermasukHarga ?? '0')}.
                </p>
            )}
            <div className="flex items-center justify-between gap-3 text-subjudul font-semibold text-teks-utama">
                <dt>Perkiraan total</dt>
                <dd className="tabular-nums">{FormatRupiah(perkiraan.Total ?? subtotal)}</dd>
            </div>
        </dl>
    );
}

/** Nama varian tampil untuk baris keranjang (null bila bukan varian). */
function AmbilNamaVarian(produk: ProdukMenu | undefined, uuidVarian: string | undefined): string | null {
    return uuidVarian === undefined ? null : (produk?.Varian?.find((v) => v.Uuid === uuidVarian)?.Nama ?? null);
}
const KATEGORI_SEMUA = 'semua';
const KATEGORI_LAIN = 'lainnya';

async function KirimJson<T>(url: string, badan: unknown, sinyal?: AbortSignal): Promise<T> {
    let respons: Response;

    try {
        respons = await fetch(url, {
            method: badan === undefined ? 'GET' : 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            ...(badan === undefined ? {} : { body: JSON.stringify(badan) }),
            ...(sinyal ? { signal: sinyal } : {}),
        });
    } catch {
        throw new GalatPesanSendiri('TanpaKoneksi', 'Tidak ada koneksi internet. Periksa sinyal lalu coba lagi.', 0);
    }

    const isi = (await respons.json().catch(() => null)) as { Galat?: { Kode: string; Pesan: string } } | null;

    if (!respons.ok) {
        throw new GalatPesanSendiri(
            isi?.Galat?.Kode ?? 'GalatServer',
            isi?.Galat?.Pesan ?? 'Terjadi galat. Coba lagi sebentar lagi.',
            respons.status,
        );
    }

    return isi as T;
}

/** Jumlah tanpa nol desimal ("2.0000" → "2"). */
function FormatJumlah(nilai: string): string {
    return nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;
}

function useDaring(): boolean {
    const [daring, AturDaring] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine));

    useEffect(() => {
        const Hidup = () => AturDaring(true);
        const Mati = () => AturDaring(false);
        window.addEventListener('online', Hidup);
        window.addEventListener('offline', Mati);

        return () => {
            window.removeEventListener('online', Hidup);
            window.removeEventListener('offline', Mati);
        };
    }, []);

    return daring;
}

const infoStatus: Record<
    StatusPesanan,
    { jenis: 'sukses' | 'peringatan' | 'bahaya' | 'netral'; label: string; pesan: string }
> = {
    MenungguKonfirmasi: {
        jenis: 'peringatan',
        label: 'Menunggu konfirmasi',
        pesan: 'Menunggu konfirmasi staf. Halaman ini diperbarui otomatis.',
    },
    Diterima: {
        jenis: 'sukses',
        label: 'Diterima',
        pesan: 'Pesanan diteruskan ke dapur. Bayar di kasir setelah selesai.',
    },
    Ditolak: { jenis: 'bahaya', label: 'Ditolak', pesan: 'Pesanan ditolak staf.' },
    Kedaluwarsa: {
        jenis: 'netral',
        label: 'Kedaluwarsa',
        pesan: 'Pesanan tidak dikonfirmasi dalam 30 menit. Silakan pesan ulang atau panggil staf.',
    },
};

/**
 * F-17 Self-Order QR Meja (X12, SLS-04): halaman publik ringan tanpa login. Menu per kategori → keranjang (disimpan di
 * perangkat tamu) → nama & catatan opsional → "Kirim pesanan" → status dipantau tiap 5 detik sampai staf menerima
 * atau menolak. Harga, subtotal, dan validasi pilihan selalu dari server (web publik tidak menghitung harga).
 */
export default function HalamanPesanSendiri({ Aktif, Toko, Meja, Token, Slug, Menu, Pembayaran }: PropsPesanSendiri) {
    const alamat = `/${Slug}/meja/${Token}`;
    const daring = useDaring();

    if (Meja === null || Toko === null) {
        return (
            <KerangkaPublik judul="QR meja tidak dikenal">
                <section className="flex flex-col gap-2 py-10">
                    <JudulHalaman skala="ringkas">QR meja tidak dikenal</JudulHalaman>
                    <p className="text-isi text-teks-sekunder">
                        QR ini sudah diganti atau tautannya salah. Minta QR terbaru ke staf, atau pesan langsung ke
                        staf.
                    </p>
                </section>
            </KerangkaPublik>
        );
    }

    return (
        <KerangkaPublik judul={`Meja ${Meja.Nama} | ${Toko.Nama}`}>
            <header className="flex flex-col gap-1 border-b border-garis pb-3">
                <p className="text-label text-teks-sekunder">
                    {Toko.Nama} | {Toko.NamaOutlet}
                </p>
                <JudulHalaman>Meja {Meja.Nama}</JudulHalaman>
            </header>
            {daring ? null : (
                <div
                    role="status"
                    className="flex items-center gap-2 rounded-kontrol border border-peringatan bg-peringatan-lembut p-3 text-label text-teks-utama"
                >
                    <WifiOffIcon aria-hidden="true" className="size-4 shrink-0 text-peringatan" />
                    Tidak ada koneksi internet. Keranjang tetap tersimpan; kirim pesanan setelah tersambung lagi.
                </div>
            )}
            {Aktif ? (
                <PemesananAktif
                    alamat={alamat}
                    token={Token}
                    menuAwal={Menu}
                    daring={daring}
                    qris={Pembayaran?.Qris === true}
                />
            ) : (
                <Pemberitahuan jenis="info" judul="Pesan sendiri belum aktif">
                    Outlet ini belum menerima pesanan lewat QR. Silakan pesan langsung ke staf.
                </Pemberitahuan>
            )}
        </KerangkaPublik>
    );
}

function KerangkaPublik({ judul, children }: { judul: string; children: ReactNode }) {
    return (
        <>
            <Head title={judul} />
            <main className="mx-auto flex min-h-screen w-full max-w-2xl flex-col gap-4 bg-latar px-4 pt-4 pb-28 text-isi text-teks-utama">
                {children}
            </main>
        </>
    );
}

type PropsPemesanan = {
    alamat: string;
    token: string;
    menuAwal: PropsPesanSendiri['Menu'];
    daring: boolean;
    qris: boolean;
};

function useMenuTersegarkan(alamat: string, token: string, menuAwal: PropsPesanSendiri['Menu'], daring: boolean) {
    const kueri = useQuery({
        queryKey: KunciKueri.PesanSendiri.Menu(token),
        queryFn: ({ signal }) => KirimJson<PropsPesanSendiri['Menu']>(`${alamat}/menu`, undefined, signal),
        initialData: menuAwal,
        staleTime: JEDA_MENU_MS,
        refetchInterval: daring ? JEDA_MENU_MS : false,
        refetchOnWindowFocus: true,
        retry: false,
    });

    return kueri.data;
}

function PemesananAktif({ alamat, token, menuAwal, daring, qris }: PropsPemesanan) {
    const menu = useMenuTersegarkan(alamat, token, menuAwal, daring);
    const [bayarQris, AturBayarQris] = useState<string | null>(null);
    const [keranjang, AturKeranjang] = useState<BarisKeranjang[]>(() => BacaKeranjang(token));
    const [riwayat, AturRiwayat] = useState<string[]>(() => BacaRiwayatPesanan(token));
    const [kategori, AturKategori] = useState(KATEGORI_SEMUA);
    const [produkDipilih, AturProdukDipilih] = useState<ProdukMenu | null>(null);
    const [keranjangTerbuka, AturKeranjangTerbuka] = useState(false);
    const [lihatStatus, AturLihatStatus] = useState<string | null>(null);
    const produkPerUuid = useMemo(() => new Map(menu.Produk.map((p) => [p.Uuid, p])), [menu.Produk]);
    const adaTanpaKategori = menu.Produk.some((p) => p.UuidKategori === null);

    useEffect(() => SimpanKeranjang(token, keranjang), [token, keranjang]);

    const hitung = useHitungKeranjang(alamat, token, keranjang);
    const jumlahItem = HitungJumlahItem(keranjang);
    const produkTampil = menu.Produk.filter((p) =>
        kategori === KATEGORI_SEMUA
            ? true
            : kategori === KATEGORI_LAIN
              ? p.UuidKategori === null
              : p.UuidKategori === kategori,
    );

    const TambahProduk = (produk: ProdukMenu) => {
        if (produk.KelompokPilihan.length > 0 || (produk.Varian?.length ?? 0) > 0) {
            AturProdukDipilih(produk);
            return;
        }

        AturKeranjang((lama) =>
            TambahKeKeranjang(lama, { Uuid: BuatUlid(), UuidProduk: produk.Uuid, Jumlah: 1, Pilihan: [], Catatan: '' }),
        );
    };

    if (bayarQris !== null) {
        return (
            <PanelBayarQris
                alamat={alamat}
                token={token}
                kodeAkses={bayarQris}
                saatKembali={() => AturBayarQris(null)}
            />
        );
    }

    if (lihatStatus !== null) {
        return (
            <PanelStatusPesanan
                alamat={alamat}
                token={token}
                uuid={lihatStatus}
                saatKembali={() => AturLihatStatus(null)}
            />
        );
    }

    return (
        <>
            {riwayat[0] ? (
                <RingkasanPesananTerakhir
                    alamat={alamat}
                    token={token}
                    uuid={riwayat[0]}
                    saatLihat={() => AturLihatStatus(riwayat[0] ?? null)}
                />
            ) : null}

            {menu.Produk.length === 0 ? (
                <KeadaanKosong judul="Menu belum tersedia" ilustrasi>
                    Belum ada menu yang bisa dipesan lewat QR. Silakan pesan langsung ke staf.
                </KeadaanKosong>
            ) : (
                <>
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
                                    className={`min-h-11 rounded-kontrol border px-4 text-label font-semibold focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none ${
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
                    <ul
                        aria-label="Daftar menu"
                        className="flex flex-col divide-y divide-garis rounded-panel border border-garis bg-permukaan"
                    >
                        {produkTampil.map((produk) => (
                            <li key={produk.Uuid} className="flex items-center gap-3 p-3">
                                <GambarProduk
                                    nama={produk.Nama}
                                    url={produk.UrlGambar}
                                    className="flex size-16 shrink-0 rounded-kontrol border border-garis bg-permukaan-redup"
                                />
                                <div className="flex min-w-0 flex-1 flex-col gap-1">
                                    <p className="font-semibold break-words">{produk.Nama}</p>
                                    <p className="text-teks-sekunder tabular-nums">
                                        {(produk.Varian?.length ?? 0) > 0 ? 'Mulai ' : ''}
                                        {FormatRupiah(produk.Harga)}
                                    </p>
                                    {(produk.Varian?.length ?? 0) > 0 ? (
                                        <p className="text-keterangan text-teks-sekunder">
                                            Pilih {(produk.NamaAtributVarian ?? 'varian').toLowerCase()}
                                        </p>
                                    ) : produk.KelompokPilihan.length > 0 ? (
                                        <p className="text-keterangan text-teks-sekunder">Ada pilihan</p>
                                    ) : null}
                                </div>
                                <Tombol
                                    varian="sekunder"
                                    onClick={() => TambahProduk(produk)}
                                    aria-label={`Tambah ${produk.Nama}`}
                                >
                                    Tambah
                                </Tombol>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {produkDipilih ? (
                <LembarPilihan
                    produk={produkDipilih}
                    saatTutup={() => AturProdukDipilih(null)}
                    saatTambah={(baris) => {
                        AturKeranjang((lama) => TambahKeKeranjang(lama, baris));
                        AturProdukDipilih(null);
                    }}
                />
            ) : null}

            {jumlahItem > 0 ? (
                <div className="fixed inset-x-0 bottom-0 z-40 border-t border-garis bg-permukaan p-3 tepi-bawah-aman">
                    <button
                        type="button"
                        onClick={() => AturKeranjangTerbuka(true)}
                        className="mx-auto flex min-h-12 w-full max-w-2xl items-center justify-between gap-3 rounded-kontrol bg-brand px-4 text-brand-teks focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <span className="flex items-center gap-2 font-semibold">
                            <ShoppingBagIcon aria-hidden="true" className="size-5" />
                            Lihat keranjang | {jumlahItem} item
                        </span>
                        <span className="font-semibold tabular-nums">
                            {hitung.data ? FormatRupiah(hitung.data.Total ?? hitung.data.Subtotal) : 'Menghitung…'}
                        </span>
                    </button>
                </div>
            ) : null}

            {keranjangTerbuka ? (
                <LembarKeranjang
                    alamat={alamat}
                    keranjang={keranjang}
                    produkPerUuid={produkPerUuid}
                    hitung={hitung}
                    daring={daring}
                    qris={qris}
                    aturKeranjang={AturKeranjang}
                    saatTutup={() => AturKeranjangTerbuka(false)}
                    saatBayarQris={(kodeAkses) => {
                        AturKeranjang([]);
                        AturKeranjangTerbuka(false);
                        AturBayarQris(kodeAkses);
                    }}
                    saatTerkirim={(uuid) => {
                        AturRiwayat(CatatRiwayatPesanan(token, uuid));
                        AturKeranjang([]);
                        AturKeranjangTerbuka(false);
                        AturLihatStatus(uuid);
                    }}
                />
            ) : null}
        </>
    );
}

const JEDA_HITUNG_MS = 300;

/** Nilai yang baru diteruskan setelah tidak berubah selama `jeda` ms (ketukan +/− beruntun = satu permintaan). */
function useNilaiTertunda<T>(nilai: T, jeda: number): T {
    const [tertunda, AturTertunda] = useState(nilai);

    useEffect(() => {
        const pewaktu = window.setTimeout(() => AturTertunda(nilai), jeda);

        return () => window.clearTimeout(pewaktu);
    }, [nilai, jeda]);

    return tertunda;
}

function useHitungKeranjang(alamat: string, token: string, keranjang: BarisKeranjang[]) {
    const tanda = useNilaiTertunda(
        JSON.stringify(
            keranjang.map((b) => ({
                UuidProduk: b.UuidProduk,
                ...(b.UuidVarian ? { UuidVarian: b.UuidVarian } : {}),
                Jumlah: b.Jumlah,
                Pilihan: b.Pilihan,
            })),
        ),
        JEDA_HITUNG_MS,
    );
    const kosong = tanda === '[]';

    return useQuery({
        queryKey: KunciKueri.PesanSendiri.Hitung(token, tanda),
        queryFn: ({ signal }) =>
            KirimJson<HasilHitung>(`${alamat}/hitung`, { Baris: JSON.parse(tanda) as unknown }, signal),
        enabled: !kosong,
        placeholderData: keepPreviousData,
        staleTime: 30_000,
        retry: false,
    });
}

export type PropsLembarPilihan = {
    produk: ProdukMenu;
    saatTutup: () => void;
    saatTambah: (baris: BarisKeranjang) => void;
};

export function LembarPilihan({ produk, saatTutup, saatTambah }: PropsLembarPilihan) {
    const [dipilih, AturDipilih] = useState<string[]>([]);
    const [jumlah, AturJumlah] = useState(1);
    const [catatan, AturCatatan] = useState('');
    const [coba, AturCoba] = useState(false);
    const daftarVarian = produk.Varian ?? [];
    const [varian, AturVarian] = useState<string | null>(() =>
        daftarVarian.filter((v) => v.Tersedia).length === 1
            ? (daftarVarian.find((v) => v.Tersedia)?.Uuid ?? null)
            : null,
    );
    const varianDipilih = daftarVarian.find((v) => v.Uuid === varian);
    const labelVarian = produk.NamaAtributVarian ?? 'Varian';
    const masalah =
        daftarVarian.length > 0 && !varianDipilih
            ? `Pilih ${labelVarian.toLowerCase()} dulu.`
            : PeriksaPilihan(produk.KelompokPilihan, dipilih);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturCoba(true);

        if (masalah === null) {
            saatTambah({
                Uuid: BuatUlid(),
                UuidProduk: produk.Uuid,
                ...(varianDipilih ? { UuidVarian: varianDipilih.Uuid } : {}),
                Jumlah: jumlah,
                Pilihan: dipilih,
                Catatan: catatan.trim(),
            });
        }
    };

    return (
        <Sheet open onOpenChange={(terbuka) => (terbuka ? undefined : saatTutup())}>
            <SheetContent
                side="bottom"
                className="mx-auto max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-t-panel"
            >
                <SheetHeader>
                    <SheetTitle className="text-subjudul font-semibold text-teks-utama">{produk.Nama}</SheetTitle>
                    <SheetDescription className="text-isi text-teks-sekunder tabular-nums">
                        {varianDipilih?.Harga
                            ? FormatRupiah(varianDipilih.Harga)
                            : `${daftarVarian.length > 0 ? 'Mulai ' : ''}${FormatRupiah(produk.Harga)}`}
                    </SheetDescription>
                </SheetHeader>
                <form onSubmit={Kirim} noValidate className="flex flex-col gap-4 px-4 pb-4">
                    {daftarVarian.length > 0 ? (
                        <fieldset className="flex flex-col gap-2">
                            <legend className="text-label font-semibold">
                                {labelVarian} <span className="font-normal text-teks-sekunder">(wajib)</span>
                            </legend>
                            {daftarVarian.map((v) => (
                                <label
                                    key={v.Uuid}
                                    className={`flex min-h-11 items-center justify-between gap-3 rounded-kontrol border border-garis px-3 ${
                                        v.Tersedia ? '' : 'text-teks-sekunder'
                                    }`}
                                >
                                    <span className="flex items-center gap-3">
                                        <input
                                            type="radio"
                                            name={`varian-${produk.Uuid}`}
                                            checked={varian === v.Uuid}
                                            disabled={!v.Tersedia}
                                            onChange={() => AturVarian(v.Uuid)}
                                            className="size-5 accent-brand disabled:cursor-not-allowed"
                                        />
                                        {v.Nama}
                                    </span>
                                    <span className="tabular-nums">
                                        {v.Tersedia && v.Harga ? FormatRupiah(v.Harga) : 'Habis'}
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    ) : null}
                    {produk.KelompokPilihan.map((k) => (
                        <fieldset key={k.Uuid} className="flex flex-col gap-2">
                            <legend className="text-label font-semibold">
                                {k.Nama}{' '}
                                <span className="font-normal text-teks-sekunder">
                                    {k.MinimalPilih > 0 ? '(wajib' : '(opsional'}
                                    {k.MaksimalPilih > 1 ? `, maks. ${String(k.MaksimalPilih)})` : ')'}
                                </span>
                            </legend>
                            {k.Pilihan.map((p) => (
                                <label
                                    key={p.Uuid}
                                    className="flex min-h-11 items-center justify-between gap-3 rounded-kontrol border border-garis px-3"
                                >
                                    <span className="flex items-center gap-3">
                                        <input
                                            type={k.MaksimalPilih === 1 ? 'radio' : 'checkbox'}
                                            name={k.Uuid}
                                            checked={dipilih.includes(p.Uuid)}
                                            onChange={() => AturDipilih((lama) => AlihkanPilihan(k, lama, p.Uuid))}
                                            onClick={() => {
                                                // Radio yang sudah terpilih bisa dibatalkan di kelompok opsional.
                                                if (
                                                    k.MaksimalPilih === 1 &&
                                                    k.MinimalPilih === 0 &&
                                                    dipilih.includes(p.Uuid)
                                                ) {
                                                    AturDipilih((lama) => lama.filter((u) => u !== p.Uuid));
                                                }
                                            }}
                                            className="size-5 accent-brand"
                                        />
                                        {p.Nama}
                                    </span>
                                    {/^0+(\.0+)?$/.test(p.Harga) ? null : (
                                        <span className="text-teks-sekunder tabular-nums">
                                            +{FormatRupiah(p.Harga)}
                                        </span>
                                    )}
                                </label>
                            ))}
                        </fieldset>
                    ))}
                    <PengaturJumlah label={produk.Nama} jumlah={jumlah} saatUbah={(n) => AturJumlah(Math.max(1, n))} />
                    <BidangTeks
                        label="Catatan untuk dapur (opsional)"
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        keterangan="Misal tidak pedas atau es dipisah"
                        maxLength={100}
                    />
                    {coba && masalah ? <Pemberitahuan jenis="peringatan">{masalah}</Pemberitahuan> : null}
                    <Tombol type="submit">Tambah ke keranjang</Tombol>
                </form>
            </SheetContent>
        </Sheet>
    );
}

export function PengaturJumlah({
    label,
    jumlah,
    saatUbah,
}: {
    label: string;
    jumlah: number;
    saatUbah: (jumlah: number) => void;
}) {
    return (
        <div className="flex items-center gap-2" role="group" aria-label={`Jumlah ${label}`}>
            <button
                type="button"
                onClick={() => saatUbah(jumlah - 1)}
                aria-label={`Kurangi ${label}`}
                className="flex size-11 items-center justify-center rounded-kontrol border border-garis-input bg-permukaan focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
            >
                <MinusIcon aria-hidden="true" className="size-4" />
            </button>
            <span className="min-w-8 text-center font-semibold tabular-nums" aria-live="polite">
                {jumlah}
            </span>
            <button
                type="button"
                onClick={() => saatUbah(jumlah + 1)}
                disabled={jumlah >= BATAS_JUMLAH}
                aria-label={`Tambah ${label}`}
                className="flex size-11 items-center justify-center rounded-kontrol border border-garis-input bg-permukaan focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
            >
                <PlusIcon aria-hidden="true" className="size-4" />
            </button>
        </div>
    );
}

type PropsLembarKeranjang = {
    alamat: string;
    keranjang: BarisKeranjang[];
    produkPerUuid: Map<string, ProdukMenu>;
    hitung: ReturnType<typeof useHitungKeranjang>;
    daring: boolean;
    qris: boolean;
    aturKeranjang: (ubah: (lama: BarisKeranjang[]) => BarisKeranjang[]) => void;
    saatTutup: () => void;
    saatTerkirim: (uuid: string) => void;
    saatBayarQris: (kodeAkses: string) => void;
};

function LembarKeranjang({
    alamat,
    keranjang,
    produkPerUuid,
    hitung,
    daring,
    qris,
    aturKeranjang,
    saatTutup,
    saatTerkirim,
    saatBayarQris,
}: PropsLembarKeranjang) {
    const [nama, AturNama] = useState('');
    const [catatan, AturCatatan] = useState('');
    // Uuid kiriman tetap sama saat dicoba ulang (idempoten), dan diganti bila isi keranjang berubah.
    const kiriman = useRef<{ tanda: string; uuid: string } | null>(null);

    const BuatBadan = () => {
        const tanda = JSON.stringify(keranjang);

        if (kiriman.current?.tanda !== tanda) {
            kiriman.current = { tanda, uuid: BuatUlid() };
        }

        return {
            Uuid: kiriman.current.uuid,
            NamaPemesan: nama.trim() === '' ? null : nama.trim(),
            Catatan: catatan.trim() === '' ? null : catatan.trim(),
            Baris: keranjang.map((b) => ({
                Uuid: b.Uuid,
                UuidProduk: b.UuidProduk,
                ...(b.UuidVarian ? { UuidVarian: b.UuidVarian } : {}),
                Jumlah: b.Jumlah,
                Pilihan: b.Pilihan,
                Catatan: b.Catatan === '' ? null : b.Catatan,
            })),
        };
    };

    const kirim = useMutation({
        mutationFn: () =>
            KirimJson<{ Uuid: string; Nomor: string; Status: StatusPesanan }>(`${alamat}/pesan`, BuatBadan()),
        onSuccess: (hasil) => saatTerkirim(hasil.Uuid),
    });
    // SLS-04: bayar sekarang dengan QRIS dari HP; pesanan dibuat menunggu pembayaran lalu layar QR dibuka.
    const kirimQris = useMutation({
        mutationFn: () => KirimJson<PesananQrisMeja>(`${alamat}/pesan-qris`, BuatBadan()),
        onSuccess: (hasil) => saatBayarQris(hasil.KodeAkses),
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        kirim.mutate();
    };

    const galatHitung = hitung.error instanceof GalatPesanSendiri ? hitung.error.message : null;

    return (
        <Sheet open onOpenChange={(terbuka) => (terbuka || kirim.isPending ? undefined : saatTutup())}>
            <SheetContent
                side="bottom"
                className="mx-auto max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-t-panel"
            >
                <SheetHeader>
                    <SheetTitle className="text-subjudul font-semibold text-teks-utama">Keranjang</SheetTitle>
                    <SheetDescription className="text-isi text-teks-sekunder">
                        Periksa pesanan sebelum dikirim ke staf.
                    </SheetDescription>
                </SheetHeader>
                <form onSubmit={Kirim} noValidate className="flex flex-col gap-4 px-4 pb-4">
                    {keranjang.length === 0 ? (
                        <p className="text-teks-sekunder">Keranjang masih kosong. Tambah menu dulu.</p>
                    ) : (
                        <ul aria-label="Isi keranjang" className="flex flex-col divide-y divide-garis">
                            {keranjang.map((b, i) => {
                                const produk = produkPerUuid.get(b.UuidProduk);
                                const namaPilihan = (produk?.KelompokPilihan ?? [])
                                    .flatMap((k) => k.Pilihan)
                                    .filter((p) => b.Pilihan.includes(p.Uuid))
                                    .map((p) => p.Nama);
                                const total = hitung.data?.Baris[i]?.Total;
                                const namaVarian = AmbilNamaVarian(produk, b.UuidVarian);
                                const namaTampil = produk
                                    ? `${produk.Nama}${namaVarian ? ` — ${namaVarian}` : ''}`
                                    : 'Menu tidak tersedia';

                                return (
                                    <li key={b.Uuid} className="flex flex-col gap-2 py-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="font-semibold break-words">{namaTampil}</p>
                                                {namaPilihan.length > 0 ? (
                                                    <p className="text-keterangan text-teks-sekunder">
                                                        {namaPilihan.join(', ')}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <span className="shrink-0 text-right font-semibold tabular-nums">
                                                {total ? FormatRupiah(total) : '…'}
                                            </span>
                                        </div>
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <PengaturJumlah
                                                label={namaTampil}
                                                jumlah={b.Jumlah}
                                                saatUbah={(n) => aturKeranjang((lama) => UbahJumlah(lama, b.Uuid, n))}
                                            />
                                            <button
                                                type="button"
                                                onClick={() => aturKeranjang((lama) => UbahJumlah(lama, b.Uuid, 0))}
                                                className="flex min-h-11 items-center gap-1 px-2 text-label font-semibold text-bahaya focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                                            >
                                                <Trash2Icon aria-hidden="true" className="size-4" />
                                                Hapus
                                            </button>
                                        </div>
                                        <BidangTeks
                                            label={`Catatan ${namaTampil} (opsional)`}
                                            nilai={b.Catatan}
                                            saatBerubah={(nilai) =>
                                                aturKeranjang((lama) =>
                                                    lama.map((x) => (x.Uuid === b.Uuid ? { ...x, Catatan: nilai } : x)),
                                                )
                                            }
                                            maxLength={100}
                                        />
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    <div className="flex flex-col gap-1 border-t border-garis pt-3">
                        {hitung.isPending && keranjang.length > 0 ? (
                            <Skeleton className="h-5 w-full" aria-label="Menghitung total" />
                        ) : hitung.data ? (
                            <RincianPerkiraan subtotal={hitung.data.Subtotal} perkiraan={hitung.data} />
                        ) : (
                            <div className="flex items-center justify-between gap-3 text-subjudul font-semibold">
                                <span>Perkiraan total</span>
                                <span className="tabular-nums">–</span>
                            </div>
                        )}
                        <p className="text-keterangan text-teks-sekunder">
                            {hitung.data?.Catatan ?? 'Perkiraan. Total akhir mengikuti tagihan di kasir.'}{' '}
                            {qris ? 'Bayar di kasir atau sekarang dengan QRIS.' : 'Bayar di kasir setelah selesai.'}
                        </p>
                    </div>
                    {galatHitung ? <Pemberitahuan jenis="bahaya">{galatHitung}</Pemberitahuan> : null}
                    <BidangTeks
                        label="Nama pemesan (opsional)"
                        nilai={nama}
                        saatBerubah={AturNama}
                        maxLength={60}
                        autoComplete="name"
                    />
                    <BidangTeksPanjang
                        label="Catatan pesanan (opsional)"
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        maksimal={200}
                        baris={2}
                    />
                    {kirim.error || kirimQris.error ? (
                        <Pemberitahuan jenis="bahaya">
                            {(kirim.error ?? kirimQris.error) instanceof GalatPesanSendiri
                                ? (kirim.error ?? kirimQris.error)?.message
                                : 'Pesanan gagal dikirim. Coba lagi.'}
                        </Pemberitahuan>
                    ) : null}
                    <Tombol
                        type="submit"
                        memproses={kirim.isPending}
                        disabled={keranjang.length === 0 || !daring || galatHitung !== null || kirimQris.isPending}
                    >
                        {qris ? 'Kirim pesanan, bayar di kasir' : 'Kirim pesanan'}
                    </Tombol>
                    {qris ? (
                        <Tombol
                            varian="sekunder"
                            memproses={kirimQris.isPending}
                            disabled={keranjang.length === 0 || !daring || galatHitung !== null || kirim.isPending}
                            onClick={() => kirimQris.mutate()}
                        >
                            Bayar sekarang dengan QRIS
                        </Tombol>
                    ) : null}
                    {daring ? null : (
                        <p className="text-keterangan text-teks-sekunder">Menunggu koneksi internet untuk mengirim.</p>
                    )}
                </form>
            </SheetContent>
        </Sheet>
    );
}

function useStatusPesanan(alamat: string, token: string, uuid: string) {
    return useQuery({
        queryKey: KunciKueri.PesanSendiri.Status(token, uuid),
        queryFn: ({ signal }) => KirimJson<PesananTamu>(`${alamat}/pesanan/${uuid}`, undefined, signal),
        refetchInterval: (kueri) =>
            kueri.state.data && kueri.state.data.Status !== 'MenungguKonfirmasi' ? false : JEDA_POLLING_MS,
        retry: false,
    });
}

function RingkasanPesananTerakhir({
    alamat,
    token,
    uuid,
    saatLihat,
}: {
    alamat: string;
    token: string;
    uuid: string;
    saatLihat: () => void;
}) {
    const status = useStatusPesanan(alamat, token, uuid);

    if (!status.data) {
        return null;
    }

    const info = infoStatus[status.data.Status];

    return (
        <section
            aria-label="Pesanan terakhir"
            className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis bg-permukaan p-3"
        >
            <div className="flex flex-col gap-1">
                <p className="font-mono text-label">{status.data.Nomor}</p>
                <LabelStatus jenis={info.jenis} teks={info.label} />
            </div>
            <Tombol varian="sekunder" onClick={saatLihat}>
                Lihat status
            </Tombol>
        </section>
    );
}

type PropsPanelStatus = { alamat: string; token: string; uuid: string; saatKembali: () => void };

function PanelStatusPesanan({ alamat, token, uuid, saatKembali }: PropsPanelStatus) {
    const status = useStatusPesanan(alamat, token, uuid);

    if (status.isPending) {
        return (
            <section aria-label="Memuat status pesanan" className="flex flex-col gap-3">
                <Skeleton className="h-6 w-40" />
                <Skeleton className="h-20 w-full" />
            </section>
        );
    }

    if (status.isError || !status.data) {
        return (
            <section className="flex flex-col gap-3">
                <Pemberitahuan jenis="bahaya">
                    {status.error instanceof GalatPesanSendiri ? status.error.message : 'Status pesanan gagal dimuat.'}
                </Pemberitahuan>
                <div className="flex flex-wrap gap-2">
                    <Tombol onClick={() => void status.refetch()}>Coba lagi</Tombol>
                    <Tombol varian="sekunder" onClick={saatKembali}>
                        Kembali ke menu
                    </Tombol>
                </div>
            </section>
        );
    }

    const pesanan = status.data;
    const info = infoStatus[pesanan.Status];

    return (
        <section aria-label={`Status pesanan ${pesanan.Nomor}`} className="flex flex-col gap-4">
            <div className="flex flex-col gap-2 rounded-panel border border-garis bg-permukaan p-4">
                <p className="font-mono text-label text-teks-sekunder">{pesanan.Nomor}</p>
                <LabelStatus jenis={info.jenis} teks={info.label} />
                <p className="text-subjudul font-semibold" role="status" aria-live="polite">
                    {info.pesan}
                </p>
                {pesanan.Status === 'Ditolak' && pesanan.AlasanTolak ? (
                    <p className="text-teks-sekunder">Alasan: {pesanan.AlasanTolak}</p>
                ) : null}
            </div>
            <ul
                aria-label="Isi pesanan"
                className="flex flex-col divide-y divide-garis rounded-panel border border-garis bg-permukaan"
            >
                {pesanan.Baris.map((b) => (
                    <li key={b.Uuid} className="flex justify-between gap-3 p-3">
                        <div className="min-w-0">
                            <p className="break-words">{b.NamaProduk}</p>
                            {b.Pilihan.length > 0 ? (
                                <p className="text-keterangan text-teks-sekunder">
                                    {b.Pilihan.map((p) => p.Nama).join(', ')}
                                </p>
                            ) : null}
                            {b.Catatan ? (
                                <p className="text-keterangan text-teks-sekunder">Catatan: {b.Catatan}</p>
                            ) : null}
                        </div>
                        <span className="shrink-0 tabular-nums">× {FormatJumlah(b.Jumlah)}</span>
                    </li>
                ))}
                <li className="p-3">
                    {pesanan.Perkiraan ? (
                        <RincianPerkiraan subtotal={pesanan.Subtotal} perkiraan={pesanan.Perkiraan} />
                    ) : (
                        <div className="flex justify-between gap-3 font-semibold">
                            <span>Subtotal</span>
                            <span className="tabular-nums">{FormatRupiah(pesanan.Subtotal)}</span>
                        </div>
                    )}
                </li>
            </ul>
            <Tombol varian={pesanan.Status === 'MenungguKonfirmasi' ? 'sekunder' : 'utama'} onClick={saatKembali}>
                {pesanan.Status === 'MenungguKonfirmasi' ? 'Kembali ke menu' : 'Pesan lagi'}
            </Tombol>
        </section>
    );
}

type PropsPanelBayarQris = { alamat: string; token: string; kodeAkses: string; saatKembali: () => void };

/** SLS-04: layar bayar QRIS dari HP tamu. QR dibuat sekali (idempoten di server), status dipantau tiap 3 detik. */
function PanelBayarQris({ alamat, token, kodeAkses, saatKembali }: PropsPanelBayarQris) {
    const tagihan = useQuery({
        queryKey: KunciKueri.PesanSendiri.StatusQris(token, `${kodeAkses}:tagihan`),
        queryFn: () => KirimJson<TagihanQrisMeja>(`${alamat}/pesan-qris/${kodeAkses}/bayar`, {}),
        staleTime: Infinity,
        retry: false,
    });
    const status = useQuery({
        queryKey: KunciKueri.PesanSendiri.StatusQris(token, kodeAkses),
        queryFn: ({ signal }) =>
            KirimJson<PesananQrisMeja>(`${alamat}/pesan-qris/${kodeAkses}/status`, undefined, signal),
        refetchInterval: (kueri) => (kueri.state.data && !kueri.state.data.PerluBayar ? false : 3000),
        retry: true,
    });
    const data = status.data;
    const habis = data !== undefined && ['Kedaluwarsa', 'Dibatalkan', 'Ditolak'].includes(data.Status);
    const lunas = data !== undefined && !data.PerluBayar && !habis;

    return (
        <section aria-label="Bayar dengan QRIS" className="flex flex-col gap-4">
            <JudulHalaman skala="ringkas">Bayar dengan QRIS</JudulHalaman>
            {tagihan.isPending ? (
                <Skeleton className="h-64 w-full" aria-label="Menyiapkan QRIS" />
            ) : tagihan.isError || !tagihan.data ? (
                <Pemberitahuan jenis="bahaya">
                    {tagihan.error instanceof GalatPesanSendiri
                        ? tagihan.error.message
                        : 'QRIS belum bisa ditampilkan. Kembali ke menu dan pilih bayar di kasir.'}
                </Pemberitahuan>
            ) : lunas ? (
                <Pemberitahuan jenis="sukses" judul="Pembayaran diterima">
                    Pesanan {data?.Nomor} sudah dibayar. {data?.LabelStatus}.
                </Pemberitahuan>
            ) : habis ? (
                <Pemberitahuan jenis="bahaya">QR sudah kedaluwarsa. Kembali ke menu untuk memesan lagi.</Pemberitahuan>
            ) : (
                <div className="flex flex-col items-center gap-3 rounded-panel border border-garis bg-permukaan p-4 text-center">
                    <p className="text-subjudul font-semibold tabular-nums">{FormatRupiah(tagihan.data.Jumlah)}</p>
                    {tagihan.data.Qr ? (
                        <img
                            alt="Kode QRIS untuk pembayaran"
                            className="size-64 rounded-kontrol border border-garis bg-permukaan p-2"
                            src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(tagihan.data.Qr)}`}
                        />
                    ) : tagihan.data.UrlBayar ? (
                        <a
                            href={tagihan.data.UrlBayar}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex min-h-11 items-center rounded-kontrol bg-brand px-4 text-label font-semibold text-brand-teks"
                        >
                            Buka halaman pembayaran
                        </a>
                    ) : null}
                    <p role="status" aria-live="polite" className="text-keterangan text-teks-sekunder">
                        Pindai dengan aplikasi bank atau dompet digital. Halaman ini berganti sendiri setelah pembayaran
                        diterima.
                    </p>
                </div>
            )}
            <Tombol varian={lunas ? 'utama' : 'sekunder'} onClick={saatKembali}>
                {lunas ? 'Pesan lagi' : 'Kembali ke menu'}
            </Tombol>
        </section>
    );
}
