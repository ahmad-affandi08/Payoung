import 'package:drift/drift.dart';

/// Skema 6 (F-07 mode meja fase 1): area & meja outlet (salinan `GET /api/pos/v1/meja`), pesanan terbuka yang dilihat
/// perangkat ini (snapshot server + perubahan lokal yang belum terkirim), dan sekuens nomor pesanan per perangkat.

@DataClassName('BarisAreaMeja')
class AreaMeja extends Table {
  TextColumn get Uuid => text()();
  TextColumn get Nama => text()();
  IntColumn get Urutan => integer().withDefault(const Constant(0))();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

@DataClassName('BarisMeja')
class Meja extends Table {
  TextColumn get Uuid => text()();
  TextColumn get Nama => text()();
  TextColumn get UuidArea => text().nullable()();
  IntColumn get Kapasitas => integer().withDefault(const Constant(4))();
  TextColumn get Bentuk => text().withDefault(const Constant('Persegi'))();
  IntColumn get Urutan => integer().withDefault(const Constant(0))();

  /// Skema 31 (POS-03): titik tengah meja pada kanvas denah relatif 0–1000; null = belum ditata di denah.
  IntColumn get PosisiX => integer().nullable()();
  IntColumn get PosisiY => integer().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

/// Pesanan terbuka (open bill). `Baris` = JSON daftar baris (lihat `BarisPesananMeja`); status Terbuka/Dibayar/
/// Dibatalkan. `DiubahPada` = waktu perubahan lokal terakhir (last-writer-wins header).
@DataClassName('BarisPesananTerbuka')
class PesananTerbuka extends Table {
  TextColumn get Uuid => text()();
  TextColumn get Nomor => text()();
  TextColumn get UuidMeja => text().nullable()();
  TextColumn get NamaMeja => text().nullable()();
  TextColumn get Label => text().nullable()();
  IntColumn get JumlahTamu => integer().withDefault(const Constant(1))();
  TextColumn get DibukaOleh => text().nullable()();
  DateTimeColumn get DibukaPada => dateTime()();
  TextColumn get Status => text()();
  BoolColumn get DikunciBayar => boolean().withDefault(const Constant(false))();
  TextColumn get Baris => text()();
  DateTimeColumn get DiubahPada => dateTime()();

  /// Skema 24 (K-12): tamu minta tagihan sejak waktu ini; null = belum.
  DateTimeColumn get MintaBillPada => dateTime().nullable()();

  @override
  Set<Column<Object>> get primaryKey => {Uuid};
}

/// Sekuens nomor pesanan per perangkat per hari: `OB/{KodeOutlet}/{YYMMDD}/{KodePerangkat}-{SEQ4}`.
@DataClassName('BarisNomorUrutPesananTerbuka')
class NomorUrutPesananTerbuka extends Table {
  TextColumn get KodePerangkat => text()();

  /// `YYMMDD`.
  TextColumn get Tanggal => text()();
  IntColumn get Terakhir => integer()();

  @override
  Set<Column<Object>> get primaryKey => {KodePerangkat, Tanggal};
}

/// Skema 24 (K-12, §9.1): meja yang perlu dibersihkan setelah tagihannya dibayar. Tabel terpisah dari [Meja] karena
/// daftar meja diganti utuh tiap tarik `GET /api/pos/v1/meja`. Isinya dari snapshot pesanan terbuka, ditambah tanda
/// lokal saat pesanan meja dibayar di perangkat ini (bertahan sampai penjualannya terkirim).
@DataClassName('BarisMejaPerluDibersihkan')
class MejaPerluDibersihkan extends Table {
  TextColumn get UuidMeja => text()();
  DateTimeColumn get Sejak => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {UuidMeja};
}
