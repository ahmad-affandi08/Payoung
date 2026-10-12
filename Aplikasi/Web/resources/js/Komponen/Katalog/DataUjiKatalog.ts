import type { HasilTabel } from '@/Komponen/TabelData/Tipe';
/**
 * Props tiruan halaman F-03 untuk test Vitest (DesainF03 bagian E). Termasuk data ekstrem §17.6.6:
 * nama 60 karakter, Rp 1.250.000.000, dan 2.000 baris.
 */
import type {
    AturanJenisProduk,
    BarisProduk,
    IzinKatalog,
    KepalaProduk,
    OpsiKategori,
    OpsiKelompokPajak,
    OpsiSatuan,
} from '@/Tipe/Katalog';

export const NamaPanjang = 'Es Kopi Susu Gula Aren Spesial Ukuran Jumbo Tanpa Es Batu XL';
export const HargaEkstrem = '1250000000.00';

export const IzinPenuh: IzinKatalog = { Kelola: true, UbahHarga: true, KelolaPersediaan: true, KelolaPajak: true };
export const IzinLihat: IzinKatalog = { Kelola: false, UbahHarga: false, KelolaPersediaan: false, KelolaPajak: false };

function BuatAturan(
    Nilai: AturanJenisProduk['Nilai'],
    Label: string,
    [PunyaStok, BisaDijual, BolehPelacakan, BolehResep, BolehKomponen, BolehPilihan, DihitungBatasSku]: boolean[],
): AturanJenisProduk {
    return {
        Nilai,
        Label,
        PunyaStok: PunyaStok ?? false,
        BisaDijual: BisaDijual ?? false,
        BolehPelacakan: BolehPelacakan ?? false,
        BolehResep: BolehResep ?? false,
        BolehKomponen: BolehKomponen ?? false,
        BolehPilihan: BolehPilihan ?? false,
        DihitungBatasSku: DihitungBatasSku ?? false,
    };
}

/** Aturan per jenis sesuai tabel DesainF03 C.1. */
export const AturanJenis: AturanJenisProduk[] = [
    BuatAturan('Stok', 'Barang stok', [true, true, true, false, false, true, true]),
    BuatAturan('IndukVarian', 'Produk bervarian', [false, false, false, false, false, true, false]),
    BuatAturan('Resep', 'Menu resep', [false, true, false, true, false, true, true]),
    BuatAturan('Produksi', 'Barang produksi', [true, true, true, true, false, true, true]),
    BuatAturan('Paket', 'Paket', [false, true, false, false, true, true, true]),
    BuatAturan('Jasa', 'Jasa', [false, true, false, false, false, true, true]),
    BuatAturan('NonStok', 'Non-stok', [false, true, false, false, false, true, true]),
    BuatAturan('BahanBaku', 'Bahan baku', [true, false, true, false, false, false, true]),
    BuatAturan('Konsinyasi', 'Konsinyasi', [true, true, true, false, false, true, true]),
];

export const OpsiKategoriUji: OpsiKategori[] = [
    { Uuid: 'KAT-MINUM', Nama: 'Minuman', Jalur: 'Minuman', Kedalaman: 1, UuidInduk: null },
    { Uuid: 'KAT-KOPI', Nama: 'Kopi', Jalur: 'Minuman › Kopi', Kedalaman: 2, UuidInduk: 'KAT-MINUM' },
    { Uuid: 'KAT-BAHAN', Nama: 'Bahan', Jalur: 'Bahan', Kedalaman: 1, UuidInduk: null },
];

export const OpsiSatuanUji: OpsiSatuan[] = [
    { Uuid: 'SAT-PCS', Nama: 'Pieces', Simbol: 'pcs', BolehDesimal: false },
    { Uuid: 'SAT-PAK', Nama: 'Pak', Simbol: 'pak', BolehDesimal: false },
    { Uuid: 'SAT-KG', Nama: 'Kilogram', Simbol: 'kg', BolehDesimal: true },
    { Uuid: 'SAT-ML', Nama: 'Mililiter', Simbol: 'ml', BolehDesimal: true },
];

export const OpsiKelompokPajakUji: OpsiKelompokPajak[] = [
    { Uuid: 'KP-PBJT', Nama: 'Makan & minum', Kategori: 'KenaPbjt', LabelKategori: 'Kena PBJT' },
    { Uuid: 'KP-BEBAS', Nama: 'Bebas pajak', Kategori: 'NonPajak', LabelKategori: 'Bukan objek pajak' },
];

export function BuatKepala(perubahan: Partial<KepalaProduk> = {}): KepalaProduk {
    return {
        Uuid: '01J9PRODUK00000000000000001',
        Nama: 'Es Kopi Susu',
        Sku: 'PRD-000001',
        Jenis: 'Resep',
        LabelJenis: 'Menu resep',
        Status: 'Aktif',
        UrlGambarKecil: null,
        UuidInduk: null,
        NamaInduk: null,
        Tab: [
            { Kunci: 'Ringkasan', Label: 'Ringkasan', Tautan: '/kelola/produk/01J9PRODUK00000000000000001' },
            { Kunci: 'Harga', Label: 'Harga', Tautan: '/kelola/produk/01J9PRODUK00000000000000001/harga' },
            { Kunci: 'Pilihan', Label: 'Pilihan', Tautan: '/kelola/produk/01J9PRODUK00000000000000001/pilihan' },
            { Kunci: 'Resep', Label: 'Resep', Tautan: '/kelola/produk/01J9PRODUK00000000000000001/resep' },
        ],
        ...perubahan,
    };
}

export function BuatBarisProduk(nomor: number, perubahan: Partial<BarisProduk> = {}): BarisProduk {
    const kode = String(nomor).padStart(6, '0');

    return {
        Uuid: `01J9PRODUK${kode.padStart(16, '0')}`,
        Nama: `Produk ${String(nomor)}`,
        Sku: `PRD-${kode}`,
        Jenis: 'Stok',
        LabelJenis: 'Barang stok',
        NamaKategori: 'Minuman › Kopi',
        Merek: null,
        HargaDasar: '18000.00',
        SimbolSatuan: 'pcs',
        JumlahVarian: 0,
        TampilDiPos: true,
        Favorit: false,
        DiubahPada: '2026-09-20T03:15:00Z',
        Status: 'Aktif',
        UrlGambarKecil: null,
        ...perubahan,
    };
}

/** Hasil `TabelData` satu halaman (D-16). */
export function BuatHasilTabel<T>(Data: T[], total = Data.length): HasilTabel<T> {
    return {
        Data,
        Meta: { Halaman: 1, PerHalaman: 25, Total: total, JumlahHalaman: Math.max(1, Math.ceil(total / 25)) },
    };
}
