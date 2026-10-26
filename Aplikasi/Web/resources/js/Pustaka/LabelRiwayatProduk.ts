/**
 * D-218: nama tabel riwayat produk menjadi kalimat yang dipahami pemilik toko di dialog "Hapus permanen". Tabel yang
 * tidak dikenal tampil dengan nama aslinya (nama tabel sudah Bahasa Indonesia, §13.7).
 */
const label: Record<string, string> = {
    PenjualanDetail: 'baris penjualan',
    ReturPenjualanDetail: 'baris retur penjualan',
    MutasiStok: 'catatan keluar masuk stok',
    SaldoStok: 'saldo stok',
    LapisanFifo: 'riwayat harga beli',
    BatchStok: 'tanggal kedaluwarsa & nomor batch',
    NomorSeri: 'nomor seri',
    StokAwalDetail: 'baris stok awal',
    PenyesuaianStokDetail: 'baris penyesuaian stok',
    StokOpnameDetail: 'baris stok opname',
    TransferStokDetail: 'baris transfer stok',
    PesananPembelianDetail: 'baris pesanan pembelian',
    PenerimaanBarangDetail: 'baris penerimaan barang',
    FakturPembelianDetail: 'baris faktur pembelian',
    ReturPembelianDetail: 'baris retur pembelian',
    PesananGrosirDetail: 'baris pesanan grosir',
    SuratJalanDetail: 'baris surat jalan',
    ReturGrosirDetail: 'baris retur grosir',
    InvoiceDetail: 'baris invoice',
    PenawaranDetail: 'baris penawaran',
    PesananOnlineDetail: 'baris pesanan online',
    PesananTerbukaDetail: 'baris pesanan meja',
    TiketDapurDetail: 'baris tiket dapur',
    Resep: 'resep',
    ResepDetail: 'bahan resep',
    OrderProduksi: 'order produksi',
    OrderProduksiBahan: 'bahan order produksi',
    BahanTerbuang: 'catatan bahan terbuang',
};

export function LabelTabelRiwayat(tabel: string): string {
    return label[tabel] ?? tabel;
}
