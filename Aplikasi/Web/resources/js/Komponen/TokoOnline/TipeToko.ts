/** Tipe bersama halaman publik toko online (F-17): menu, baris keranjang, dan hasil hitung server. */

export type PilihanProduk = { Uuid: string; Nama: string; Harga: string };

export type KelompokPilihanProduk = {
    Uuid: string;
    Nama: string;
    MinimalPilih: number;
    MaksimalPilih: number;
    Pilihan: PilihanProduk[];
};

export type VarianProduk = { Uuid: string; Nama: string; Harga: string | null; Tersedia: boolean };

export type ProdukToko = {
    Uuid: string;
    Nama: string;
    Harga: string;
    UrlGambar: string | null;
    UuidKategori: string | null;
    /** Produk favorit pilihan pemilik (K-16); satu di antaranya jadi kartu sorotan di kepala toko. */
    Favorit?: boolean;
    KelompokPilihan: KelompokPilihanProduk[];
    Varian?: VarianProduk[];
};

export type BarisKeranjang = {
    Uuid: string;
    UuidProduk: string;
    UuidVarian: string | null;
    Jumlah: number;
    Pilihan: string[];
    Catatan: string;
};

export type HasilHitung = {
    Subtotal: string;
    Diskon: string;
    BiayaLayanan: string;
    Pajak: { Nama: string; Tarif: string; Jumlah: string }[];
    Ongkir: string;
    /** Potongan promo gratis ongkir (F-16c); yang dibayar = Ongkir − DiskonOngkir, sudah termasuk di Total. */
    DiskonOngkir: string;
    Total: string;
    Zona: { Nama: string; EstimasiHariMin: number; EstimasiHariMaks: number } | null;
    /** v3.46: voucher yang berlaku di perhitungan ini (diperiksa server). */
    Voucher?: { Kode: string; NamaPromo: string } | null;
};

export type CaraMenerima = 'AmbilSendiri' | 'Kirim';

export type CaraBayar = 'BayarSaatAmbil' | 'Cod' | 'QrisOnline';

/** Produk berpilihan (varian atau kelompok tambahan) dipilih lewat lembar opsi, bukan satu ketukan. */
export function PerluOpsi(p: ProdukToko): boolean {
    return (p.Varian?.length ?? 0) > 0 || p.KelompokPilihan.length > 0;
}
