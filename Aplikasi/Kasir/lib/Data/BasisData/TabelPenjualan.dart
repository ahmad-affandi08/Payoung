import 'package:drift/drift.dart';

/// Tabel penjualan lokal (skema 2, Rincian F-07c). Penjualan + detail + pembayaran + outbox `Penjualan.Buat` ditulis
/// dalam satu transaksi SQLite (PRD §18.3 no. 3). Uang & jumlah TEXT desimal. Status sinkron tidak disimpan di sini:
/// diturunkan dari outbox (masih ada = belum terkirim/perlu tindakan, tidak ada = terkirim).

@DataClassName('BarisPenjualan')
@TableIndex(name: 'IndeksPenjualanTanggalBisnis', columns: {#TanggalBisnis})
class Penjualan extends Table {
  TextColumn get Uuid => text()();
  TextColumn get Nomor => text().unique()();
  TextColumn get UuidShift => text()();
  TextColumn get UuidPengguna => text()();
  TextColumn get NamaKasir => text()();
  TextColumn get Kanal => text()();
  DateTimeColumn get DibuatPada => dateTime()();

  /// `YYYY-MM-DD` tanggal bisnis outlet.
  TextColumn get TanggalBisnis => text()();
  TextColumn get Status => text()();
  TextColumn get Subtotal => text()();
  TextColumn get TotalDiskon => text()();
  TextColumn get BiayaLayanan => text()();

  /// Skema 20 (F-17 bagian 3): ongkir kotor & potongannya (gratis ongkir) supaya struk cetak ulang memuatnya.
  TextColumn get BiayaKirim => text().withDefault(const Constant('0.00'))();
  TextColumn get DiskonKirim => text().withDefault(const Constant('0.00'))();
  TextColumn get TotalPajak => text()();
  TextColumn get Pembulatan => text()();
  TextColumn get TotalAkhir => text()();
  TextColumn get TotalDibayar => text()();
  TextColumn get Kembalian => text()();
  TextColumn get UuidPenyetujuDiskon => text().nullable()();
  TextColumn get Catatan => text().nullable()();

  /// Laundry (§9.9, skema 15): blok tiket laundry (JSON `LaundryKeranjang`) untuk nota & cetak ulang offline.
  TextColumn get Laundry => text().nullable()();

  /// Skema 22 (v3.52, §9.2): nomor panggil & nama pemesan penjualan bayar-dulu, untuk struk & tiket cetak ulang.
  TextColumn get NomorAntrian => text().nullable()();
  TextColumn get NamaPemesan => text().nullable()();

  /// Skema 27 (Bengkel §9.10): perintah kerja yang ditagih (JSON `{Uuid, Nomor, NomorPolisi}`) untuk struk & cetak ulang.
  TextColumn get PerintahKerja => text().nullable()();

  /// Skema 27 (Apotek §9.5): ringkasan resep untuk struk (JSON `{NomorResep, TanggalResep, NamaDokter}`). Data pasien
  /// sengaja tidak disimpan di sini; hanya di muatan outbox `Penjualan.Buat` (basis data lokal terenkripsi, K-7).
  TextColumn get Resep => text().nullable()();

  /// Skema 29 (K-14, v5.68): bagian bayar tiap tamu bila tagihan dibagi (JSON `BagianTagihan`), untuk struk per tamu
  /// dan cetak ulangnya. Hanya lokal; tidak dikirim ke server.
  TextColumn get BagianTagihan => text().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

@DataClassName('BarisPenjualanDetail')
class PenjualanDetail extends Table {
  TextColumn get Uuid => text()();
  TextColumn get UuidPenjualan => text().references(Penjualan, #Uuid)();
  IntColumn get Urutan => integer()();
  TextColumn get UuidProduk => text()();
  TextColumn get UuidProdukSatuan => text().nullable()();
  TextColumn get NamaProduk => text()();
  TextColumn get NamaSatuan => text().nullable()();
  TextColumn get Jumlah => text()();
  TextColumn get HargaSatuan => text()();
  TextColumn get HargaPilihan => text()();

  /// JSON `[{UuidPilihan, Nama, Harga}]`.
  TextColumn get Pilihan => text()();
  TextColumn get Bruto => text()();
  TextColumn get Diskon => text()();
  TextColumn get DiskonPesanan => text()();
  TextColumn get BiayaLayanan => text()();
  TextColumn get JumlahPajak => text()();
  TextColumn get PajakEksklusif => text()();
  TextColumn get TotalBaris => text()();
  TextColumn get Catatan => text().nullable()();

  /// Skema 20 (F-05h): JSON daftar nomor seri/IMEI yang dijual; null = produk tanpa nomor seri.
  TextColumn get NomorSeri => text().nullable()();

  /// Skema 21 (F-05h): snapshot masa garansi (bulan) saat dijual, untuk "Garansi sampai" di struk cetak ulang.
  IntColumn get MasaGaransiBulan => integer().nullable()();

  /// Skema 28 (Apotek bagian 4): JSON racikan baris jasa racik (`Nama`, `JumlahKemasan`, `AturanPakai`, `Komponen`);
  /// null = baris biasa. Untuk struk cetak ulang & riwayat.
  TextColumn get Racikan => text().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

@DataClassName('BarisPenjualanPembayaran')
class PenjualanPembayaran extends Table {
  TextColumn get Uuid => text()();
  TextColumn get UuidPenjualan => text().references(Penjualan, #Uuid)();
  TextColumn get UuidMetodePembayaran => text()();
  TextColumn get Jenis => text()();
  TextColumn get NamaMetode => text()();
  TextColumn get Jumlah => text()();
  TextColumn get Referensi => text().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

/// Pesanan yang ditahan (parkir) di perangkat ini; tidak dikirim ke server (Rincian F-07b). `Data` = JSON keranjang.
@DataClassName('BarisPesananTertahan')
class PesananTertahan extends Table {
  TextColumn get Uuid => text()();
  TextColumn get Label => text()();
  TextColumn get Data => text()();
  TextColumn get Total => text()();
  IntColumn get JumlahItem => integer()();
  TextColumn get UuidPengguna => text()();
  DateTimeColumn get DibuatPada => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

/// Sekuens nomor penjualan per perangkat per hari (BR-07.1): `INV/{KodeOutlet}/{YYMMDD}/{KodePerangkat}-{SEQ4}`.
@DataClassName('BarisNomorUrutPenjualan')
class NomorUrutPenjualan extends Table {
  TextColumn get KodePerangkat => text()();

  /// `YYMMDD`.
  TextColumn get Tanggal => text()();
  IntColumn get Terakhir => integer()();

  @override
  Set<Column<Object>> get primaryKey => {KodePerangkat, Tanggal};
}
