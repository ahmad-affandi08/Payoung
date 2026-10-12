import {
    ArrowLeftIcon,
    MinusIcon,
    PlusIcon,
    QrCodeIcon,
    ShoppingBagIcon,
    StoreIcon,
    TicketPercentIcon,
    Trash2Icon,
    TruckIcon,
} from 'lucide-react';
import type { FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import type { ProfilPembeli } from '@/Komponen/TokoOnline/DialogMasukPembeli';
import type { BarisKeranjang, CaraBayar, CaraMenerima, HasilHitung, ProdukToko } from '@/Komponen/TokoOnline/TipeToko';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';

export type DataPenerima = {
    NamaPelanggan: string;
    NoHp: string;
    Email: string;
    Alamat: string;
    Kelurahan: string;
    Kecamatan: string;
    Kota: string;
    Provinsi: string;
    Catatan: string;
};

export type LangkahKeranjang = 'keranjang' | 'pesan';

type PropsPanelKeranjang = {
    langkah: LangkahKeranjang;
    saatGantiLangkah: (langkah: LangkahKeranjang) => void;
    baris: BarisKeranjang[];
    produk: Map<string, ProdukToko>;
    saatUbahJumlah: (uuid: string, selisih: number) => void;
    saatHapus: (uuid: string) => void;
    opsiPemenuhan: { nilai: CaraMenerima; label: string; ikon: typeof StoreIcon }[];
    jenis: CaraMenerima;
    saatGantiJenis: (jenis: CaraMenerima) => void;
    kodePos: string;
    saatGantiKodePos: (kodePos: string) => void;
    isianVoucher: string;
    saatGantiIsianVoucher: (nilai: string) => void;
    galatVoucher: string | null;
    saatPakaiVoucher: () => void;
    saatLepasVoucher: () => void;
    hasil: HasilHitung | null;
    minimalPesanan: string;
    galat: string | null;
    pembeli: ProfilPembeli | null;
    akunAktif: boolean;
    saatBukaMasuk: () => void;
    penerima: DataPenerima;
    saatGantiPenerima: (ubah: Partial<DataPenerima>) => void;
    opsiBayar: { Nilai: string; Label: string }[];
    bayar: CaraBayar;
    saatGantiBayar: (bayar: CaraBayar) => void;
    setuju: boolean;
    saatGantiSetuju: (setuju: boolean) => void;
    memproses: boolean;
    saatPesan: (e: FormEvent) => void;
};

/** Rincian harga dari server; dipakai di langkah keranjang dan ringkasan langkah pesan. */
function RincianHarga({ hasil }: { hasil: HasilHitung }) {
    return (
        <dl className="flex flex-col gap-1.5 rounded-panel bg-permukaan-redup p-3 text-isi">
            <div className="flex justify-between gap-3">
                <dt className="text-teks-sekunder">Subtotal</dt>
                <dd className="tabular-nums">{FormatRupiah(hasil.Subtotal)}</dd>
            </div>
            {Number(hasil.Diskon) > 0 ? (
                <div className="flex justify-between gap-3 text-sukses">
                    <dt>Diskon promo</dt>
                    <dd className="tabular-nums">−{FormatRupiah(hasil.Diskon)}</dd>
                </div>
            ) : null}
            {Number(hasil.Ongkir) > 0 ? (
                <div className="flex justify-between gap-3">
                    <dt className="text-teks-sekunder">Ongkir</dt>
                    <dd className="tabular-nums">{FormatRupiah(hasil.Ongkir)}</dd>
                </div>
            ) : null}
            {Number(hasil.DiskonOngkir) > 0 ? (
                <div className="flex justify-between gap-3 text-sukses">
                    <dt>{Number(hasil.DiskonOngkir) === Number(hasil.Ongkir) ? 'Gratis ongkir' : 'Diskon ongkir'}</dt>
                    <dd className="tabular-nums">−{FormatRupiah(hasil.DiskonOngkir)}</dd>
                </div>
            ) : null}
            <div className="flex justify-between gap-3 border-t border-garis pt-2 text-subjudul font-semibold">
                <dt>Total</dt>
                <dd className="tabular-nums">{FormatRupiah(hasil.Total)}</dd>
            </div>
            {hasil.Zona ? (
                <p className="text-keterangan text-teks-sekunder">
                    {hasil.Zona.Nama} | perkiraan {hasil.Zona.EstimasiHariMin}–{hasil.Zona.EstimasiHariMaks} hari
                </p>
            ) : null}
        </dl>
    );
}

/**
 * Keranjang dua langkah: (1) isi keranjang, cara menerima, voucher, dan total; (2) data penerima, pembayaran, lalu
 * kirim pesanan. Dipakai di kolom lengket (desktop) maupun lembar bawah (HP), jadi pembeli tidak perlu menggulir ke
 * dasar halaman. Total selalu hasil hitung server.
 */
export default function PanelKeranjang(p: PropsPanelKeranjang) {
    const jumlahItem = p.baris.reduce((n, b) => n + b.Jumlah, 0);
    const langkah = p.baris.length === 0 ? 'keranjang' : p.langkah;

    if (p.baris.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 py-8 text-center">
                <span className="grid size-14 place-items-center rounded-full bg-brand-lembut text-brand">
                    <ShoppingBagIcon aria-hidden="true" className="size-7" />
                </span>
                <p className="text-isi font-semibold text-teks-utama">Keranjang masih kosong</p>
                <p className="text-keterangan text-teks-sekunder">Pilih menu, lalu tekan Tambah.</p>
            </div>
        );
    }

    if (langkah === 'keranjang') {
        return (
            <div className="flex flex-col gap-5">
                <ul className="divide-y divide-garis rounded-panel border border-garis" aria-label="Isi keranjang">
                    {p.baris.map((b) => {
                        const barisProduk = p.produk.get(b.UuidProduk);
                        const rincian = [
                            barisProduk?.Varian?.find((v) => v.Uuid === b.UuidVarian)?.Nama,
                            ...(barisProduk?.KelompokPilihan.flatMap((k) =>
                                k.Pilihan.filter((x) => b.Pilihan.includes(x.Uuid)).map((x) => x.Nama),
                            ) ?? []),
                        ].filter((x): x is string => Boolean(x));

                        return (
                            <li key={b.Uuid} className="flex items-center gap-3 p-3">
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate text-isi font-semibold text-teks-utama">
                                        {barisProduk?.Nama}
                                    </span>
                                    {rincian.length > 0 ? (
                                        <span className="truncate text-keterangan text-teks-sekunder">
                                            {rincian.join(', ')}
                                        </span>
                                    ) : null}
                                </span>
                                <div className="flex shrink-0 items-center gap-1">
                                    <div className="flex items-center rounded-full border border-garis">
                                        <button
                                            type="button"
                                            aria-label="Kurangi jumlah"
                                            className="grid size-10 place-items-center rounded-full"
                                            onClick={() => p.saatUbahJumlah(b.Uuid, -1)}
                                        >
                                            <MinusIcon aria-hidden="true" className="size-4" />
                                        </button>
                                        <span className="w-6 text-center text-isi font-semibold tabular-nums">
                                            {b.Jumlah}
                                        </span>
                                        <button
                                            type="button"
                                            aria-label="Tambah jumlah"
                                            className="grid size-10 place-items-center rounded-full"
                                            onClick={() => p.saatUbahJumlah(b.Uuid, 1)}
                                        >
                                            <PlusIcon aria-hidden="true" className="size-4" />
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        aria-label="Hapus"
                                        className="grid size-10 place-items-center rounded-full text-bahaya"
                                        onClick={() => p.saatHapus(b.Uuid)}
                                    >
                                        <Trash2Icon aria-hidden="true" className="size-4" />
                                    </button>
                                </div>
                            </li>
                        );
                    })}
                </ul>

                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-1 text-label font-semibold text-teks-utama">Cara menerima</legend>
                    <div className="grid grid-cols-2 gap-2">
                        {p.opsiPemenuhan.map((o) => (
                            <label
                                key={o.nilai}
                                className={`flex min-h-14 cursor-pointer items-center gap-2 rounded-panel border p-3 text-isi font-semibold has-focus-visible:ring-2 has-focus-visible:ring-brand ${
                                    p.jenis === o.nilai
                                        ? 'border-brand bg-brand-lembut text-teks-utama'
                                        : 'border-garis text-teks-utama'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="cara-menerima"
                                    className="sr-only"
                                    checked={p.jenis === o.nilai}
                                    onChange={() => p.saatGantiJenis(o.nilai)}
                                />
                                <o.ikon
                                    aria-hidden="true"
                                    className={`size-5 shrink-0 ${p.jenis === o.nilai ? 'text-brand' : 'text-teks-sekunder'}`}
                                />
                                {o.label}
                            </label>
                        ))}
                    </div>
                    {p.jenis === 'Kirim' ? (
                        <BidangTeks
                            label="Kode pos tujuan"
                            keterangan="Dipakai untuk menghitung ongkir."
                            nilai={p.kodePos}
                            saatBerubah={p.saatGantiKodePos}
                            inputMode="numeric"
                            maxLength={5}
                            required
                        />
                    ) : null}
                </fieldset>

                <div className="flex flex-col gap-1">
                    <div className="flex items-end gap-2">
                        <div className="min-w-0 flex-1">
                            <BidangTeks
                                label="Kode voucher (opsional)"
                                nilai={p.isianVoucher}
                                saatBerubah={p.saatGantiIsianVoucher}
                                galat={p.galatVoucher ?? undefined}
                                kode
                            />
                        </div>
                        {p.hasil?.Voucher ? (
                            <Tombol varian="sekunder" onClick={p.saatLepasVoucher}>
                                Lepas
                            </Tombol>
                        ) : (
                            <Tombol
                                varian="sekunder"
                                disabled={p.isianVoucher.trim() === '' || p.baris.length === 0}
                                onClick={p.saatPakaiVoucher}
                            >
                                Pakai
                            </Tombol>
                        )}
                    </div>
                    {p.hasil?.Voucher ? (
                        <p className="inline-flex items-center gap-1.5 text-keterangan text-sukses">
                            <TicketPercentIcon aria-hidden="true" className="size-4" />
                            Voucher {p.hasil.Voucher.Kode} dipakai: {p.hasil.Voucher.NamaPromo}
                        </p>
                    ) : null}
                </div>

                {p.galat ? <Pemberitahuan jenis="bahaya">{p.galat}</Pemberitahuan> : null}

                {p.hasil ? (
                    <RincianHarga hasil={p.hasil} />
                ) : (
                    <p className="text-keterangan text-teks-sekunder">
                        {p.jenis === 'Kirim' ? 'Isi kode pos tujuan untuk melihat total. ' : ''}
                        Minimal belanja {FormatRupiah(p.minimalPesanan)}. Total dikunci oleh server sebelum pesanan
                        dikirim.
                    </p>
                )}
                <Tombol ukuran="besar" disabled={!p.hasil} onClick={() => p.saatGantiLangkah('pesan')}>
                    Lanjut ke data pesanan
                </Tombol>
            </div>
        );
    }

    return (
        <form onSubmit={p.saatPesan} className="flex flex-col gap-5" aria-label="Data pesanan">
            <button
                type="button"
                className="inline-flex min-h-10 w-fit items-center gap-1.5 text-label font-semibold text-brand"
                onClick={() => p.saatGantiLangkah('keranjang')}
            >
                <ArrowLeftIcon aria-hidden="true" className="size-4" />
                Kembali ke keranjang
            </button>

            <div className="flex items-center justify-between gap-3 rounded-panel bg-brand-lembut p-3">
                <span className="inline-flex items-center gap-2 text-isi text-teks-utama">
                    {p.jenis === 'Kirim' ? (
                        <TruckIcon aria-hidden="true" className="size-4 text-brand" />
                    ) : (
                        <StoreIcon aria-hidden="true" className="size-4 text-brand" />
                    )}
                    {jumlahItem} item | {p.jenis === 'Kirim' ? `dikirim ke ${p.kodePos}` : 'ambil sendiri'}
                </span>
                <span className="text-subjudul font-semibold text-teks-utama tabular-nums">
                    {p.hasil ? FormatRupiah(p.hasil.Total) : ''}
                </span>
            </div>

            {p.jenis === 'Kirim' ? (
                <div className="flex flex-col gap-3">
                    <h3 className="text-label font-semibold text-teks-utama">Alamat pengiriman</h3>
                    <BidangTeksPanjang
                        label="Alamat lengkap"
                        nilai={p.penerima.Alamat}
                        saatBerubah={(v) => p.saatGantiPenerima({ Alamat: v })}
                        required
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <BidangTeks
                            label="Kelurahan"
                            nilai={p.penerima.Kelurahan}
                            saatBerubah={(v) => p.saatGantiPenerima({ Kelurahan: v })}
                            required
                        />
                        <BidangTeks
                            label="Kecamatan"
                            nilai={p.penerima.Kecamatan}
                            saatBerubah={(v) => p.saatGantiPenerima({ Kecamatan: v })}
                            required
                        />
                        <BidangTeks
                            label="Kota/kabupaten"
                            nilai={p.penerima.Kota}
                            saatBerubah={(v) => p.saatGantiPenerima({ Kota: v })}
                            required
                        />
                        <BidangTeks
                            label="Provinsi"
                            nilai={p.penerima.Provinsi}
                            saatBerubah={(v) => p.saatGantiPenerima({ Provinsi: v })}
                            required
                        />
                    </div>
                </div>
            ) : null}

            <div className="flex flex-col gap-3">
                <h3 className="text-label font-semibold text-teks-utama">Data penerima</h3>
                {p.pembeli ? (
                    <Pemberitahuan jenis="info">
                        Pesanan tercatat di akun {p.pembeli.Nama} ({p.pembeli.NoHp})
                        {p.pembeli.Tier ? ` | harga member ${p.pembeli.Tier} sudah dihitung` : ''}.
                    </Pemberitahuan>
                ) : p.akunAktif ? (
                    <p className="rounded-panel bg-brand-lembut p-3 text-keterangan text-teks-sekunder">
                        Sudah pernah belanja di sini?{' '}
                        <button type="button" className="font-semibold text-brand underline" onClick={p.saatBukaMasuk}>
                            Masuk dengan WhatsApp
                        </button>{' '}
                        untuk mengisi data otomatis dan mengumpulkan poin. Tanpa masuk juga bisa.
                    </p>
                ) : null}
                <BidangTeks
                    label={p.pembeli ? 'Nama penerima' : 'Nama'}
                    nilai={p.penerima.NamaPelanggan}
                    saatBerubah={(v) => p.saatGantiPenerima({ NamaPelanggan: v })}
                    required
                />
                {p.pembeli ? null : (
                    <BidangTeks
                        label="Nomor WhatsApp"
                        nilai={p.penerima.NoHp}
                        saatBerubah={(v) => p.saatGantiPenerima({ NoHp: v })}
                        inputMode="tel"
                        required
                    />
                )}
                <BidangTeks
                    label="Email (opsional)"
                    nilai={p.penerima.Email}
                    saatBerubah={(v) => p.saatGantiPenerima({ Email: v })}
                    jenis="email"
                />
                <BidangTeksPanjang
                    label="Catatan (opsional)"
                    nilai={p.penerima.Catatan}
                    saatBerubah={(v) => p.saatGantiPenerima({ Catatan: v })}
                    baris={3}
                    maksimal={500}
                />
            </div>

            <div className="flex flex-col gap-3">
                <h3 className="text-label font-semibold text-teks-utama">Pembayaran</h3>
                {p.opsiBayar.length > 1 ? (
                    <BidangPilihan
                        label="Cara bayar"
                        nilai={p.bayar}
                        opsi={p.opsiBayar}
                        saatBerubah={(v) => p.saatGantiBayar(v as CaraBayar)}
                    />
                ) : null}
                <p className="flex items-start gap-2 rounded-panel bg-permukaan-redup p-3 text-keterangan text-teks-sekunder">
                    <QrCodeIcon aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-brand" />
                    {p.bayar === 'QrisOnline'
                        ? 'Setelah pesanan dikirim, Anda akan mendapat QRIS untuk dibayar sekarang. Pesanan diproses toko setelah pembayaran masuk.'
                        : p.bayar === 'Cod'
                          ? 'Bayar tunai ke kurir saat barang tiba.'
                          : 'Bayar di kasir saat mengambil pesanan.'}
                </p>
            </div>

            <KotakCentang
                label="Saya setuju data ini dipakai untuk memproses pesanan dan pengiriman."
                nilai={p.setuju}
                saatBerubah={p.saatGantiSetuju}
            />
            {p.galat ? <Pemberitahuan jenis="bahaya">{p.galat}</Pemberitahuan> : null}
            <Tombol type="submit" ukuran="besar" memproses={p.memproses} disabled={!p.hasil || !p.setuju}>
                Kirim pesanan
            </Tombol>
        </form>
    );
}
