import 'dart:io';

import 'package:drift/drift.dart' show OrderingTerm, Value, driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Data/BasisData/BasisDataKasir.dart';

/// Skema lokal versi 1 (F-06) persis seperti yang terpasang di perangkat sebelum F-07c.
const List<String> skemaVersi1 = [
  'CREATE TABLE "Pengaturan" ("Kunci" TEXT NOT NULL, "Nilai" TEXT NOT NULL, PRIMARY KEY ("Kunci"))',
  'CREATE TABLE "Staf" ("Uuid" TEXT NOT NULL, "Nama" TEXT NOT NULL, "Pemilik" INTEGER NOT NULL CHECK ("Pemilik" IN '
      '(0, 1)), "Izin" TEXT NOT NULL, "PinDiatur" INTEGER NOT NULL CHECK ("PinDiatur" IN (0, 1)), "PinGaram" TEXT NULL, '
      '"PinNonce" TEXT NULL, "PinSandi" TEXT NULL, PRIMARY KEY ("Uuid"))',
  'CREATE TABLE "KategoriKas" ("Uuid" TEXT NOT NULL, "Nama" TEXT NOT NULL, "Jenis" TEXT NOT NULL, PRIMARY KEY '
      '("Uuid"))',
  'CREATE TABLE "Shift" ("Uuid" TEXT NOT NULL, "DibukaOleh" TEXT NOT NULL, "NamaKasir" TEXT NOT NULL, "DibukaPada" '
      'TEXT NOT NULL, "KasAwal" TEXT NOT NULL, "PecahanKasAwal" TEXT NULL, "Bersama" INTEGER NOT NULL CHECK ("Bersama" '
      'IN (0, 1)), "Status" TEXT NOT NULL, PRIMARY KEY ("Uuid"))',
  'CREATE TABLE "MutasiKas" ("Uuid" TEXT NOT NULL, "UuidShift" TEXT NOT NULL REFERENCES Shift (Uuid), "Jenis" TEXT '
      'NOT NULL, "UuidKategori" TEXT NULL, "NamaKategori" TEXT NULL, "Jumlah" TEXT NOT NULL, "Catatan" TEXT NULL, '
      '"DicatatOleh" TEXT NOT NULL, "DicatatPada" TEXT NOT NULL, "DisetujuiOleh" TEXT NULL, PRIMARY KEY ("Uuid"))',
  'CREATE TABLE "Outbox" ("Id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, "Uuid" TEXT NOT NULL UNIQUE, "Jenis" TEXT '
      'NOT NULL, "Data" TEXT NOT NULL, "Status" TEXT NOT NULL, "Percobaan" INTEGER NOT NULL DEFAULT 0, "KodeGalat" TEXT '
      'NULL, "PesanGalat" TEXT NULL, "DibuatPada" TEXT NOT NULL, "BerikutnyaPada" TEXT NOT NULL)',
  'CREATE TABLE "PercobaanPin" ("UuidPengguna" TEXT NOT NULL, "JumlahGagal" INTEGER NOT NULL, "TerkunciSampai" TEXT '
      'NULL, PRIMARY KEY ("UuidPengguna"))',
];

/// Kolom `Shift` yang ditambahkan skema 3 (F-11).
const List<String> kolomTutupShift = [
  'DitutupOleh',
  'NamaPenutup',
  'DitutupPada',
  'KasSeharusnya',
  'KasAktual',
  'Selisih',
  'PecahanKasAkhir',
  'NonTunaiDilaporkan',
  'AlasanSelisih',
  'UuidPenyetujuSelisih',
];

/// Tabel skema 5 (F-09 fase 1: void & retur penjualan).
const List<String> tabelVoidRetur = [
  'VoidPenjualan',
  'ReturPenjualan',
  'ReturPenjualanDetail',
  'ReturPenjualanPembayaran',
  'NomorUrutReturPenjualan',
];

/// Tabel skema 6 (F-07 mode meja fase 1: meja & pesanan terbuka).
const List<String> tabelMeja = ['AreaMeja', 'Meja', 'PesananTerbuka', 'NomorUrutPesananTerbuka'];

/// Hapus tabel skema 13 (F-16d bagian 1 isi deposit lokal), agar sama dengan perangkat skema ≤ 12.
Future<void> HapusTabelDeposit(BasisDataKasir db) async {
  await db.customStatement('DROP TABLE "NomorUrutIsiDeposit"');
  await db.customStatement('DROP TABLE "IsiDepositLokal"');
}

/// Hapus tabel skema 11 (F-12 bagian 2 pre-order lokal), agar sama dengan perangkat skema ≤ 10.
Future<void> HapusTabelPreOrder(BasisDataKasir db) async {
  await db.customStatement('DROP TABLE "NomorUrutPesananPenjualan"');
  await db.customStatement('DROP TABLE "PesananPenjualanLokal"');
}

/// Hapus tabel skema 10 & 11 (F-18 absensi lokal, F-12 bagian 2 pre-order lokal), agar sama dengan perangkat skema ≤ 9.
Future<void> HapusTabelAbsensi(BasisDataKasir db) async {
  await HapusTabelPreOrder(db);
  await db.customStatement('DROP TABLE "AbsensiLokal"');
}

/// Hapus tabel skema 7 (F-16a pelanggan lokal), agar sama dengan perangkat skema ≤ 6.
Future<void> HapusTabelPelanggan(BasisDataKasir db) => db.customStatement('DROP TABLE "PelangganLokal"');

/// Kolom `PelangganLokal` yang ditambahkan skema 12 (F-16c bagian 3).
const kolomPromoPelanggan = ['HariLahir', 'JumlahTransaksi', 'PemakaianPromo', 'PemakaianPada'];

Future<void> HapusKolomPromoPelanggan(BasisDataKasir db) async {
  for (final kolom in kolomPromoPelanggan) {
    await db.customStatement('ALTER TABLE PelangganLokal DROP COLUMN $kolom');
  }
}

/// Hapus tabel skema 6 & 7 dari basis data yang dibangun dengan skema terbaru, agar sama dengan perangkat skema ≤ 5.
Future<void> HapusTabelMeja(BasisDataKasir db) async {
  await HapusTabelPelanggan(db);
  for (final tabel in tabelMeja.reversed) {
    await db.customStatement('DROP TABLE "$tabel"');
  }
}

/// Hapus tabel skema 5 & 6 dari basis data yang dibangun dengan skema terbaru, agar sama dengan perangkat skema ≤ 4.
Future<void> HapusTabelVoidRetur(BasisDataKasir db) async {
  await HapusTabelMeja(db);
  for (final tabel in tabelVoidRetur.reversed) {
    await db.customStatement('DROP TABLE "$tabel"');
  }
}

/// PRD §18.3 no. 9 & Rincian F-07c: migrasi skema lokal 1 → 2 hanya menambah tabel; outbox yang belum terkirim, shift,
/// dan mutasi kas tetap utuh.
void main() {
  setUpAll(() => driftRuntimeOptions.dontWarnAboutMultipleDatabases = true);

  test('migrasi 1 → 2 mempertahankan outbox tertunda & dokumen lama, lalu tabel F-07c siap dipakai', () async {
    final db = BasisDataKasir(
      NativeDatabase.memory(
        setup: (mentah) {
          for (final sql in skemaVersi1) {
            mentah.execute(sql);
          }
          mentah.execute(
            "INSERT INTO Shift VALUES ('SHIFT1', 'STAF1', 'Rina Wulandari', '2026-09-24T01:00:00.000Z', '500000.00', "
            "NULL, 0, 'Terbuka')",
          );
          mentah.execute(
            'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
            "('SHIFT1', 'Shift.Buka', '{\"KasAwal\":\"500000.00\"}', 'Tertunda', 3, '2026-09-24T01:00:00.000Z', "
            "'2026-09-24T01:05:00.000Z'),"
            "('MUTASI1', 'MutasiKas.Catat', '{}', 'PerluTindakan', 0, '2026-09-24T01:10:00.000Z', "
            "'2026-09-24T01:10:00.000Z')",
          );
          mentah.userVersion = 1;
        },
      ),
    );
    addTearDown(db.close);

    final outbox = await (db.select(db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    expect(outbox.map((o) => o.Uuid), ['SHIFT1', 'MUTASI1'], reason: 'Outbox belum terkirim tidak boleh hilang.');
    expect(outbox.first.Percobaan, 3);
    expect(outbox.first.Data, '{"KasAwal":"500000.00"}');
    expect(outbox.last.Status, 'PerluTindakan');
    expect((await db.select(db.shift).get()).single.KasAwal, '500000.00');

    // Migrasi berantai sampai skema terbaru (5, void & retur F-09).
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    final tabel = await db
        .customSelect("SELECT name FROM sqlite_master WHERE type = 'table'")
        .map((r) => r.read<String>('name'))
        .get();
    expect(
      tabel,
      containsAll([
        'Produk',
        'ProdukSatuan',
        'ProdukBarcode',
        'Kategori',
        'Satuan',
        'KelompokPajak',
        'KelompokPajakDetail',
        'DaftarHarga',
        'ProdukHarga',
        'KelompokPilihan',
        'Pilihan',
        'ProdukKelompokPilihan',
        'TarifPajak',
        'MetodePembayaran',
        'Penjualan',
        'PenjualanDetail',
        'PenjualanPembayaran',
        'PesananTertahan',
        'NomorUrutPenjualan',
        ...tabelVoidRetur,
      ]),
    );
    final indeks = await db
        .customSelect("SELECT name FROM sqlite_master WHERE type = 'index'")
        .map((r) => r.read<String>('name'))
        .get();
    expect(indeks, containsAll(['IndeksProdukBarcodeBarcode', 'IndeksPenjualanTanggalBisnis']));

    // Tabel baru bisa langsung ditulis.
    await db
        .into(db.nomorUrutPenjualan)
        .insert(NomorUrutPenjualanCompanion.insert(KodePerangkat: 'POS-001', Tanggal: '260924', Terakhir: 1));
    expect((await db.select(db.nomorUrutPenjualan).get()).single.Terakhir, 1);
  });

  test('basis data baru langsung skema terbaru (18)', () async {
    final db = BasisDataKasir(NativeDatabase.memory());
    addTearDown(db.close);
    expect(await db.select(db.penjualan).get(), isEmpty);
    expect(await db.select(db.returPenjualan).get(), isEmpty);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
  });

  test('F-11 migrasi 2 → 3 hanya menambah kolom tutup shift; outbox tertunda, shift, & penjualan tetap utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    // Bangun skema 2: skema terbaru dikurangi kolom F-11, lalu isi data seperti perangkat lama.
    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    // Skema ≤ 9 belum punya tabel absensi lokal (F-18).
    await HapusTabelAbsensi(lama);
    for (final kolom in kolomTutupShift) {
      await lama.customStatement('ALTER TABLE "Shift" DROP COLUMN "$kolom"');
    }
    await lama.customStatement('ALTER TABLE "KelompokPajakDetail" DROP COLUMN "Kategori"');
    await HapusTabelVoidRetur(lama);
    await lama.customStatement(
      "INSERT INTO Shift (Uuid, DibukaOleh, NamaKasir, DibukaPada, KasAwal, PecahanKasAwal, Bersama, Status) VALUES "
      "('SHIFT1', 'STAF1', 'Rina Wulandari', '2026-09-24T01:00:00.000Z', '500000.00', NULL, 0, 'Terbuka')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('SHIFT1', 'Shift.Buka', '{\"KasAwal\":\"500000.00\"}', 'Tertunda', 2, '2026-09-24T01:00:00.000Z', '2026-09-24T01:00:00.000Z'),"
      "('JUAL1', 'Penjualan.Buat', '{}', 'PerluTindakan', 0, '2026-09-24T01:10:00.000Z', '2026-09-24T01:10:00.000Z')",
    );
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 2');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);

    final outbox = await (db.select(db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    expect(outbox.map((o) => o.Uuid), ['SHIFT1', 'JUAL1'], reason: 'Outbox belum terkirim tidak boleh hilang.');
    expect(outbox.first.Percobaan, 2);
    expect(outbox.last.Status, 'PerluTindakan');
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);

    final shift = (await db.select(db.shift).get()).single;
    expect(shift.KasAwal, '500000.00');
    expect(shift.KasAktual, isNull);
    expect(shift.DitutupPada, isNull);

    // Kolom baru langsung bisa ditulis.
    await (db.update(db.shift)..where((s) => s.Uuid.equals('SHIFT1'))).write(
      const ShiftCompanion(Status: Value('Tertutup'), KasAktual: Value('498000.00'), Selisih: Value('-2000.00')),
    );
    expect((await db.select(db.shift).get()).single.Selisih, '-2000.00');
  });

  test('PRD v1.46 migrasi 3 → 4 hanya menambah kolom Kategori kelompok pajak; outbox & katalog lama utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    // Skema ≤ 9 belum punya tabel absensi lokal (F-18).
    await HapusTabelAbsensi(lama);
    await lama.customStatement('ALTER TABLE "KelompokPajakDetail" DROP COLUMN "Kategori"');
    await HapusTabelVoidRetur(lama);
    await lama.customStatement(
      'INSERT INTO KelompokPajakDetail (UuidKelompokPajak, KodeJenisPajak, DasarPengenaan, Urutan) VALUES '
      "('KP1', 'Ppn', 'Subtotal', 1)",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('JUAL1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-09-24T01:10:00.000Z', '2026-09-24T01:10:00.000Z')",
    );
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 3');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(
      (await db.select(db.outbox).get()).single.Uuid,
      'JUAL1',
      reason: 'Outbox belum terkirim tidak boleh hilang.',
    );
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    final detail = (await db.select(db.kelompokPajakDetail).get()).single;
    expect(detail.KodeJenisPajak, 'Ppn');
    expect(detail.Kategori, isNull, reason: 'Baris lama tanpa kategori → fallback ke kode sampai katalog diperbarui.');

    await db.update(db.kelompokPajakDetail).write(const KelompokPajakDetailCompanion(Kategori: Value('Ppn')));
    expect((await db.select(db.kelompokPajakDetail).get()).single.Kategori, 'Ppn');
  });

  test('F-09 migrasi 4 → 5 hanya menambah tabel void & retur; outbox tertunda & penjualan lama utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    // Skema ≤ 9 belum punya tabel absensi lokal (F-18).
    await HapusTabelAbsensi(lama);
    await HapusTabelVoidRetur(lama);
    await lama.customStatement(
      'INSERT INTO Penjualan (Uuid, Nomor, UuidShift, UuidPengguna, NamaKasir, Kanal, DibuatPada, TanggalBisnis, Status, '
      'Subtotal, TotalDiskon, BiayaLayanan, TotalPajak, Pembulatan, TotalAkhir, TotalDibayar, Kembalian) VALUES '
      "('JUAL1', 'INV/SLB/260924/POS-001-0001', 'SHIFT1', 'STAF1', 'Rina Wulandari', 'BawaPulang', "
      "'2026-09-24T01:10:00.000Z', '2026-09-24', 'Lunas', '61000.00', '0.00', '0.00', '6100.00', '0.00', '67100.00', "
      "'100000.00', '32900.00')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('JUAL1', 'Penjualan.Buat', '{\"Nomor\":\"INV/SLB/260924/POS-001-0001\"}', 'Tertunda', 4, "
      "'2026-09-24T01:10:00.000Z', '2026-09-24T01:10:00.000Z'),"
      "('TUTUP1', 'Shift.Tutup', '{}', 'PerluTindakan', 0, '2026-09-24T09:00:00.000Z', '2026-09-24T09:00:00.000Z')",
    );
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 4');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    final outbox = await (db.select(db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    expect(outbox.map((o) => o.Uuid), ['JUAL1', 'TUTUP1'], reason: 'Outbox belum terkirim tidak boleh hilang.');
    expect(outbox.first.Percobaan, 4);
    expect(outbox.first.Data, '{"Nomor":"INV/SLB/260924/POS-001-0001"}');
    expect(outbox.last.Status, 'PerluTindakan');
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.penjualan).get()).single.TotalAkhir, '67100.00');

    // Tabel baru langsung bisa ditulis.
    await db
        .into(db.nomorUrutReturPenjualan)
        .insert(NomorUrutReturPenjualanCompanion.insert(KodePerangkat: 'POS-001', Tanggal: '260924', Terakhir: 1));
    await db
        .into(db.voidPenjualan)
        .insert(
          VoidPenjualanCompanion.insert(
            Uuid: 'VOID1',
            UuidPenjualan: 'JUAL1',
            UuidShift: 'SHIFT1',
            UuidPengguna: 'STAF1',
            NamaPengguna: 'Rina Wulandari',
            UuidPenyetuju: 'STAF2',
            NamaPenyetuju: 'Budi Santoso',
            Alasan: 'Salah input pesanan',
            DivoidPada: DateTime.utc(2026, 9, 24, 1, 15),
            Nominal: '67100.00',
            RefundTunai: '67100.00',
            RefundNonTunai: '0.00',
          ),
        );
    expect((await db.select(db.voidPenjualan).get()).single.RefundTunai, '67100.00');
    expect((await db.select(db.nomorUrutReturPenjualan).get()).single.Terakhir, 1);
  });

  test(
    'F-07 mode meja migrasi 5 → 6 hanya menambah tabel meja & pesanan terbuka; outbox tertunda & retur utuh',
    () async {
      final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
      addTearDown(() => folder.deleteSync(recursive: true));
      final berkas = File('${folder.path}/kasir.sqlite');

      final lama = BasisDataKasir(NativeDatabase(berkas));
      await lama.customSelect('SELECT 1').get();
      // Skema ≤ 9 belum punya tabel absensi lokal (F-18).
      await HapusTabelAbsensi(lama);
      await HapusTabelMeja(lama);
      await lama.customStatement(
        "INSERT INTO NomorUrutReturPenjualan (KodePerangkat, Tanggal, Terakhir) VALUES ('POS-001', '260924', 3)",
      );
      await lama.customStatement(
        'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
        "('RETUR1', 'ReturPenjualan.Buat', '{\"Nomor\":\"RJ/SLB/260924/POS-001-0003\"}', 'Tertunda', 2, "
        "'2026-09-24T02:00:00.000Z', '2026-09-24T02:00:00.000Z')",
      );
      await HapusTabelDeposit(lama);
      await lama.customStatement('PRAGMA user_version = 5');
      await lama.close();

      final db = BasisDataKasir(NativeDatabase(berkas));
      addTearDown(db.close);
      final outbox = await db.select(db.outbox).get();
      expect(outbox.single.Uuid, 'RETUR1', reason: 'Outbox belum terkirim tidak boleh hilang.');
      expect(outbox.single.Percobaan, 2);
      expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
      expect((await db.select(db.nomorUrutReturPenjualan).get()).single.Terakhir, 3);

      // Tabel baru langsung bisa ditulis.
      await db.into(db.areaMeja).insert(AreaMejaCompanion.insert(Uuid: 'AREA1', Nama: 'Teras'));
      await db.into(db.meja).insert(MejaCompanion.insert(Uuid: 'MEJA1', Nama: 'T-01', UuidArea: const Value('AREA1')));
      await db
          .into(db.pesananTerbuka)
          .insert(
            PesananTerbukaCompanion.insert(
              Uuid: 'PESAN1',
              Nomor: 'OB/SLB/260924/POS-001-0001',
              UuidMeja: const Value('MEJA1'),
              DibukaPada: DateTime.utc(2026, 9, 24, 3),
              Status: 'Terbuka',
              Baris: '[]',
              DiubahPada: DateTime.utc(2026, 9, 24, 3),
            ),
          );
      await db
          .into(db.nomorUrutPesananTerbuka)
          .insert(NomorUrutPesananTerbukaCompanion.insert(KodePerangkat: 'POS-001', Tanggal: '260924', Terakhir: 1));
      expect((await db.select(db.meja).get()).single.Kapasitas, 4);
      expect((await db.select(db.pesananTerbuka).get()).single.JumlahTamu, 1);
    },
  );

  test('F-16a migrasi 6 → 7 hanya menambah tabel pelanggan lokal; pesanan terbuka & outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    // Skema ≤ 9 belum punya tabel absensi lokal (F-18).
    await HapusTabelAbsensi(lama);
    await HapusTabelPelanggan(lama);
    await lama.customStatement(
      "INSERT INTO PesananTerbuka (Uuid, Nomor, DibukaPada, Status, Baris, DiubahPada) VALUES ('PESAN1', "
      "'OB/SLB/260925/POS-001-0001', '2026-09-25T03:00:00.000Z', 'Terbuka', '[]', '2026-09-25T03:00:00.000Z')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('PESAN1', 'PesananTerbuka.Buka', '{}', 'Tertunda', 1, '2026-09-25T03:00:00.000Z', '2026-09-25T03:00:00.000Z')",
    );
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 6');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect((await db.select(db.outbox).get()).single.Jenis, 'PesananTerbuka.Buka');
    expect((await db.select(db.pesananTerbuka).get()).single.Nomor, 'OB/SLB/260925/POS-001-0001');
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    await db
        .into(db.pelangganLokal)
        .insert(
          PelangganLokalCompanion.insert(
            Uuid: 'PLG1',
            Nama: 'Ani Rahmawati',
            NoHpSamar: '0812****7890',
            DipakaiPada: DateTime.utc(2026, 9, 25, 3),
          ),
        );
    expect((await db.select(db.pelangganLokal).get()).single.Nama, 'Ani Rahmawati');
  });

  test('F-16b migrasi 7 → 8 menambah kolom tier pelanggan lokal; pelanggan & outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    // Skema ≤ 9 belum punya tabel absensi lokal (F-18).
    await HapusTabelAbsensi(lama);
    // Skema 7 belum punya kolom tier (8) maupun kredit (9).
    for (final kolom in ['KodeTier', 'NamaTier', 'LimitKredit', 'SisaPiutang', 'HariLewatJatuhTempo']) {
      await lama.customStatement('ALTER TABLE PelangganLokal DROP COLUMN $kolom');
    }
    await lama.customStatement(
      "INSERT INTO PelangganLokal (Uuid, Nama, NoHpSamar, DipakaiPada) VALUES ('PLG1', 'Ani Rahmawati', "
      "'0812****7890', '2026-09-25T03:00:00.000Z')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('PLG1', 'Pelanggan.Buat', '{}', 'Tertunda', 0, '2026-09-25T03:00:00.000Z', '2026-09-25T03:00:00.000Z')",
    );
    await HapusKolomPromoPelanggan(lama);
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 7');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Jenis, 'Pelanggan.Buat');
    final pelanggan = (await db.select(db.pelangganLokal).get()).single;
    expect(pelanggan.Nama, 'Ani Rahmawati');
    expect(pelanggan.KodeTier, isNull);
    await db.update(db.pelangganLokal).write(const PelangganLokalCompanion(KodeTier: Value('GOLD')));
    expect((await db.select(db.pelangganLokal).get()).single.KodeTier, 'GOLD');
  });

  test('F-12 migrasi 8 → 9 menambah kolom kredit pelanggan lokal; tier & outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    // Skema ≤ 9 belum punya tabel absensi lokal (F-18).
    await HapusTabelAbsensi(lama);
    for (final kolom in ['LimitKredit', 'SisaPiutang', 'HariLewatJatuhTempo']) {
      await lama.customStatement('ALTER TABLE PelangganLokal DROP COLUMN $kolom');
    }
    await lama.customStatement(
      "INSERT INTO PelangganLokal (Uuid, Nama, NoHpSamar, DipakaiPada, KodeTier, NamaTier) VALUES ('PLG1', "
      "'Toko Makmur Jaya', '0813****0001', '2026-09-25T03:00:00.000Z', 'GOLD', 'Gold')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('JUAL1', 'Penjualan.Buat', '{}', 'Tertunda', 0, '2026-09-25T03:00:00.000Z', '2026-09-25T03:00:00.000Z')",
    );
    await HapusKolomPromoPelanggan(lama);
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 8');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Jenis, 'Penjualan.Buat');
    final pelanggan = (await db.select(db.pelangganLokal).get()).single;
    expect(pelanggan.KodeTier, 'GOLD');
    expect(pelanggan.SisaPiutang, isNull);
    await db
        .update(db.pelangganLokal)
        .write(const PelangganLokalCompanion(LimitKredit: Value('5000000.00'), SisaPiutang: Value('77000.00')));
    expect((await db.select(db.pelangganLokal).get()).single.LimitKredit, '5000000.00');
  });

  test('F-18 migrasi 9 → 10 menambah tabel absensi lokal; pelanggan & outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await HapusTabelAbsensi(lama);
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('JUAL1', 'Penjualan.Buat', '{}', 'Tertunda', 0, '2026-09-25T03:00:00.000Z', '2026-09-25T03:00:00.000Z')",
    );
    await HapusKolomPromoPelanggan(lama);
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 9');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Jenis, 'Penjualan.Buat');
    await db
        .into(db.absensiLokal)
        .insert(
          AbsensiLokalCompanion.insert(
            Uuid: 'ABS1',
            UuidPengguna: 'STAF1',
            NamaStaf: 'Rina Wulandari',
            MasukPada: DateTime.utc(2026, 9, 25, 1),
          ),
        );
    expect((await db.select(db.absensiLokal).get()).single.NamaStaf, 'Rina Wulandari');
  });

  test('F-12 bagian 2 migrasi 10 → 11 menambah tabel pre-order lokal; absensi & outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await HapusTabelPreOrder(lama);
    await lama.customStatement(
      "INSERT INTO AbsensiLokal (Uuid, UuidPengguna, NamaStaf, MasukPada) VALUES ('ABS1', 'STAF1', 'Rina Wulandari', "
      "'2026-09-25T01:00:00.000Z')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('JUAL1', 'Penjualan.Buat', '{}', 'Tertunda', 0, '2026-09-25T03:00:00.000Z', '2026-09-25T03:00:00.000Z')",
    );
    await HapusKolomPromoPelanggan(lama);
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 10');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Jenis, 'Penjualan.Buat');
    expect((await db.select(db.absensiLokal).get()).single.NamaStaf, 'Rina Wulandari');
    await db
        .into(db.nomorUrutPesananPenjualan)
        .insert(NomorUrutPesananPenjualanCompanion.insert(KodePerangkat: 'POS-001', Tanggal: '260925', Terakhir: 1));
    expect((await db.select(db.nomorUrutPesananPenjualan).get()).single.Terakhir, 1);
  });

  test('F-16c bagian 3 migrasi 11 → 12 menambah data promo pelanggan; pelanggan & outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await HapusKolomPromoPelanggan(lama);
    await lama.customStatement(
      "INSERT INTO PelangganLokal (Uuid, Nama, NoHpSamar, DipakaiPada, KodeTier) VALUES ('PLG1', 'Ani Rahmawati', "
      "'0812****7890', '2026-09-25T03:00:00.000Z', 'GOLD')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('JUAL1', 'Penjualan.Buat', '{}', 'Tertunda', 0, '2026-09-25T03:00:00.000Z', '2026-09-25T03:00:00.000Z')",
    );
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 11');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Jenis, 'Penjualan.Buat');
    final pelanggan = (await db.select(db.pelangganLokal).get()).single;
    expect(pelanggan.KodeTier, 'GOLD');
    expect(pelanggan.HariLahir, isNull);
    expect(pelanggan.JumlahTransaksi, isNull);
    await db
        .update(db.pelangganLokal)
        .write(const PelangganLokalCompanion(HariLahir: Value('09-26'), JumlahTransaksi: Value(3)));
    expect((await db.select(db.pelangganLokal).get()).single.HariLahir, '09-26');
  });

  test('F-16d bagian 1 migrasi 12 → 13 menambah tabel isi deposit lokal; pre-order & outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('PO1', 'PesananPenjualan.Buat', '{}', 'Tertunda', 0, '2026-09-25T03:00:00.000Z', '2026-09-25T03:00:00.000Z')",
    );
    await HapusTabelDeposit(lama);
    await lama.customStatement('PRAGMA user_version = 12');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Jenis, 'PesananPenjualan.Buat');
    await db
        .into(db.isiDepositLokal)
        .insert(
          IsiDepositLokalCompanion.insert(
            Uuid: 'DEP1',
            UuidShift: 'SHIFT1',
            Nomor: 'DEP/SLB/260926/POS-001-0001',
            UuidPelanggan: 'PLG1',
            NamaPelanggan: 'Ani Rahmawati',
            Jumlah: '100000.00',
            UuidMetodePembayaran: 'MTD1',
            JenisMetode: 'Tunai',
            NamaMetode: 'Tunai',
            DibuatPada: DateTime.utc(2026, 9, 26, 3),
          ),
        );
    expect((await db.select(db.isiDepositLokal).get()).single.Jumlah, '100000.00');
    expect(await db.select(db.nomorUrutIsiDeposit).get(), isEmpty);
  });

  test(
    'F-16d bagian 2 migrasi 13 → 14 menambah kolom paket sesi produk & mengosongkan kursor katalog; outbox utuh',
    () async {
      final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
      addTearDown(() => folder.deleteSync(recursive: true));
      final berkas = File('${folder.path}/kasir.sqlite');

      final lama = BasisDataKasir(NativeDatabase(berkas));
      await lama.customSelect('SELECT 1').get();
      await lama.customStatement(
        'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
        "('DEP1', 'Deposit.Isi', '{}', 'Tertunda', 0, '2026-09-26T03:00:00.000Z', '2026-09-26T03:00:00.000Z')",
      );
      await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('KursorKatalog', 'K-123')");
      await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('UuidOutlet', 'OUT1')");
      await lama.customStatement(
        'INSERT INTO Produk (Uuid, Nama, Jenis, Pelacakan, TampilDiPos, Aktif) VALUES '
        "('PRD1', 'Paket Creambath 10x', 'Jasa', 'Tidak', 1, 1)",
      );
      await lama.customStatement('ALTER TABLE Produk DROP COLUMN JumlahSesiPaket');
      await lama.customStatement('PRAGMA user_version = 13');
      await lama.close();

      final db = BasisDataKasir(NativeDatabase(berkas));
      addTearDown(db.close);
      expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
      expect((await db.select(db.outbox).get()).single.Jenis, 'Deposit.Isi');
      expect((await db.select(db.pengaturan).get()).map((p) => p.Kunci), ['UuidOutlet']);
      final produk = (await db.select(db.produk).get()).single;
      expect(produk.JumlahSesiPaket, isNull);
      await db.update(db.produk).write(const ProdukCompanion(JumlahSesiPaket: Value(10)));
      expect((await db.select(db.produk).get()).single.JumlahSesiPaket, 10);
    },
  );

  test(
    'laundry migrasi 14 → 15 menambah kolom Laundry penjualan lokal; penjualan lama & outbox tertunda utuh',
    () async {
      final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
      addTearDown(() => folder.deleteSync(recursive: true));
      final berkas = File('${folder.path}/kasir.sqlite');

      final lama = BasisDataKasir(NativeDatabase(berkas));
      await lama.customSelect('SELECT 1').get();
      await lama.customStatement(
        'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
        "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 0, '2026-09-26T03:00:00.000Z', '2026-09-26T03:00:00.000Z')",
      );
      await lama.customStatement('ALTER TABLE Penjualan DROP COLUMN Laundry');
      await lama.customStatement('PRAGMA user_version = 14');
      await lama.close();

      final db = BasisDataKasir(NativeDatabase(berkas));
      addTearDown(db.close);
      expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
      expect((await db.select(db.outbox).get()).single.Jenis, 'Penjualan.Buat');
      final kolom = await db
          .customSelect("SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Penjualan') WHERE name = 'Laundry'")
          .map((r) => r.read<int>('Jumlah'))
          .getSingle();
      expect(kolom, 1);
    },
  );
  test('audit P0 F-01 migrasi 15 → 16 menambah perangkat pembuat outbox; item tertunda ditandai perangkat aktif, tidak hilang', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Outbox DROP COLUMN UuidPerangkat');
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 2, '2026-09-26T03:00:00.000Z', '2026-09-26T03:00:00.000Z'),"
      "('PJ2', 'Penjualan.Buat', '{}', 'PerluTindakan', 0, '2026-09-26T03:01:00.000Z', '2026-09-26T03:01:00.000Z')",
    );
    await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('UuidPerangkat', 'PRG1')");
    await lama.customStatement('PRAGMA user_version = 15');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    final outbox = await (db.select(db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
    expect(outbox.map((o) => o.Uuid), ['PJ1', 'PJ2']);
    expect(outbox.map((o) => o.UuidPerangkat), ['PRG1', 'PRG1']);
    expect(outbox.first.Percobaan, 2);
  });

  test('F-05f bagian 2 migrasi 16 → 17 menambah tabel bahan terbuang lokal; outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('DROP TABLE BahanTerbuangLokal');
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-09-27T03:00:00.000Z', '2026-09-27T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 16');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    final outbox = (await db.select(db.outbox).get()).single;
    expect((outbox.Uuid, outbox.Percobaan, outbox.UuidPerangkat), ('PJ1', 1, 'PRG1'));
    await db
        .into(db.bahanTerbuangLokal)
        .insert(
          BahanTerbuangLokalCompanion.insert(
            Uuid: 'BT1',
            UuidProduk: 'PRD1',
            NamaProduk: 'Croissant',
            Jumlah: '2',
            NamaSatuan: 'Pcs',
            JumlahDasar: '2',
            Alasan: 'Rusak',
            UuidPengguna: 'STAF1',
            NamaPengguna: 'Rina Wulandari',
            TanggalBisnis: '2026-09-27',
            DibuatPada: DateTime.utc(2026, 9, 27, 3),
          ),
        );
    expect((await db.select(db.bahanTerbuangLokal).get()).single.NamaProduk, 'Croissant');
  });

  test('X8 migrasi 17 → 18 menambah kolom kanal metode pembayaran; metode & outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE MetodePembayaran DROP COLUMN Kanal');
    await lama.customStatement(
      "INSERT INTO MetodePembayaran (Uuid, Jenis, Nama, AdaGambarQris, Urutan) VALUES ('MTD1', 'Tunai', 'Tunai', 0, 1)",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-09-27T03:00:00.000Z', '2026-09-27T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 17');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Uuid, 'PJ1');
    final tunai = (await db.select(db.metodePembayaran).get()).single;
    expect((tunai.Nama, tunai.Kanal), ('Tunai', null));
    await db
        .into(db.metodePembayaran)
        .insert(
          MetodePembayaranCompanion.insert(
            Uuid: 'MTD2',
            Jenis: 'Marketplace',
            Nama: 'GoFood',
            AdaGambarQris: false,
            Urutan: 2,
            Kanal: const Value('GoFood'),
          ),
        );
    expect((await (db.select(db.metodePembayaran)..where((m) => m.Uuid.equals('MTD2'))).getSingle()).Kanal, 'GoFood');
  });

  test('F-17 bagian 3 migrasi 18 → 19 menambah kolom ongkir kena pajak; kursor katalog direset, outbox tertunda utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE KelompokPajakDetail DROP COLUMN KenaBiayaKirim');
    await lama.customStatement("INSERT INTO KelompokPajak (Uuid, Nama) VALUES ('KP1', 'Makan & minum')");
    await lama.customStatement(
      'INSERT INTO KelompokPajakDetail (UuidKelompokPajak, KodeJenisPajak, DasarPengenaan, Urutan) VALUES '
      "('KP1', 'PbjtMakananMinuman', 'SubtotalPlusLayanan', 1)",
    );
    // Kursor katalog harus dihapus supaya sinkron berikutnya memuat ulang kelompok pajak beserta bendera barunya.
    await lama.customStatement(
      "INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('KursorKatalog', '2026-09-01T00:00:00Z')",
    );
    await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('UuidPerangkat', 'PRG1')");
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-09-27T03:00:00.000Z', '2026-09-27T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 18');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Uuid, 'PJ1');
    // Detail lama tetap ada dan berbendera false; kursor katalog hilang, identitas perangkat tidak ikut terhapus.
    final detail = (await db.select(db.kelompokPajakDetail).get()).single;
    expect((detail.KodeJenisPajak, detail.KenaBiayaKirim), ('PbjtMakananMinuman', false));
    final kunci = (await db.select(db.pengaturan).get()).map((p) => p.Kunci).toList();
    expect(kunci, ['UuidPerangkat']);
    await db
        .into(db.kelompokPajakDetail)
        .insert(
          KelompokPajakDetailCompanion.insert(
            UuidKelompokPajak: 'KP1',
            KodeJenisPajak: 'Ppn',
            DasarPengenaan: 'Subtotal',
            KenaBiayaKirim: const Value(true),
          ),
        );
    final ppn = await (db.select(db.kelompokPajakDetail)..where((d) => d.KodeJenisPajak.equals('Ppn'))).getSingle();
    expect(ppn.KenaBiayaKirim, isTrue);
  });
  test('F-17 bagian 3 + F-05h migrasi 19 → 20 menambah ongkir penjualan & nomor seri detail; penjualan lama berongkir nol, outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Penjualan DROP COLUMN BiayaKirim');
    await lama.customStatement('ALTER TABLE Penjualan DROP COLUMN DiskonKirim');
    await lama.customStatement('ALTER TABLE PenjualanDetail DROP COLUMN NomorSeri');
    await lama.customStatement(
      'INSERT INTO Penjualan (Uuid, Nomor, UuidShift, UuidPengguna, NamaKasir, Kanal, DibuatPada, TanggalBisnis, Status, Subtotal, '
      'TotalDiskon, BiayaLayanan, TotalPajak, Pembulatan, TotalAkhir, TotalDibayar, Kembalian) VALUES '
      "('PJ1', 'INV/1', 'SH1', 'U1', 'Rina', 'BawaPulang', '2026-09-27T03:00:00.000Z', '2026-09-27', 'Lunas', '10000.00', '0.00', '0.00', "
      "'0.00', '0.00', '10000.00', '10000.00', '0.00')",
    );
    await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('UuidPerangkat', 'PRG1')");
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-09-27T03:00:00.000Z', '2026-09-27T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 19');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Uuid, 'PJ1');
    final jual = (await db.select(db.penjualan).get()).single;
    expect((jual.Nomor, jual.BiayaKirim, jual.DiskonKirim), ('INV/1', '0.00', '0.00'));
    final kolom = await db
        .customSelect("SELECT name FROM pragma_table_info('PenjualanDetail') WHERE name = 'NomorSeri'")
        .get();
    expect(kolom, hasLength(1));
  });
  test('F-05h migrasi 20 → 21 menambah masa garansi produk & detail penjualan; kursor katalog direset, outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Produk DROP COLUMN MasaGaransiBulan');
    await lama.customStatement('ALTER TABLE PenjualanDetail DROP COLUMN MasaGaransiBulan');
    await lama.customStatement(
      "INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('KursorKatalog', '2026-09-01T00:00:00Z')",
    );
    await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('UuidPerangkat', 'PRG1')");
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-09-27T03:00:00.000Z', '2026-09-27T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 20');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Uuid, 'PJ1');
    for (final tabel in ['Produk', 'PenjualanDetail']) {
      final kolom = await db
          .customSelect("SELECT name FROM pragma_table_info('$tabel') WHERE name = 'MasaGaransiBulan'")
          .get();
      expect(kolom, hasLength(1), reason: tabel);
    }
    final kunci = (await db.select(db.pengaturan).get()).map((p) => p.Kunci).toList();
    expect(kunci, contains('UuidPerangkat'));
    expect(kunci, isNot(contains('KursorKatalog')));
  });
  test('v3.52 migrasi 21 → 22 menambah nomor antrian & nama pemesan penjualan; penjualan lama null, outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Penjualan DROP COLUMN NomorAntrian');
    await lama.customStatement('ALTER TABLE Penjualan DROP COLUMN NamaPemesan');
    await lama.customStatement(
      'INSERT INTO Penjualan (Uuid, Nomor, UuidShift, UuidPengguna, NamaKasir, Kanal, DibuatPada, TanggalBisnis, Status, Subtotal, '
      'TotalDiskon, BiayaLayanan, TotalPajak, Pembulatan, TotalAkhir, TotalDibayar, Kembalian) VALUES '
      "('PJ1', 'INV/1', 'SH1', 'U1', 'Rina', 'BawaPulang', '2026-10-02T03:00:00.000Z', '2026-10-02', 'Lunas', '10000.00', '0.00', '0.00', "
      "'0.00', '0.00', '10000.00', '10000.00', '0.00')",
    );
    await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('UuidPerangkat', 'PRG1')");
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-10-02T03:00:00.000Z', '2026-10-02T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 21');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Uuid, 'PJ1');
    final jual = (await db.select(db.penjualan).get()).single;
    expect((jual.Nomor, jual.NomorAntrian, jual.NamaPemesan), ('INV/1', null, null));
  });

  test('K-9 migrasi 22 → 23 menambah atribut varian produk; kursor katalog dihapus agar katalog dimuat ulang, outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Produk DROP COLUMN AtributVarian');
    await lama.customStatement(
      "INSERT INTO Produk (Uuid, Nama, Jenis, Pelacakan, TampilDiPos, Aktif) VALUES ('KAOS', 'Kaos', 'IndukVarian', 'Tidak', 1, 1)",
    );
    await lama.customStatement(
      "INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('KursorKatalog', '2026-10-01T00:00:00Z')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-10-02T03:00:00.000Z', '2026-10-02T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 22');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Uuid, 'PJ1');
    final produk = (await db.select(db.produk).get()).single;
    expect((produk.Uuid, produk.AtributVarian), ('KAOS', null));
    expect((await db.select(db.pengaturan).get()).map((p) => p.Kunci), isNot(contains('KursorKatalog')));
  });

  test(
    'K-12 migrasi 23 → 24 menambah tanda minta bill & tabel meja perlu dibersihkan; pesanan & outbox utuh',
    () async {
      final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
      addTearDown(() => folder.deleteSync(recursive: true));
      final berkas = File('${folder.path}/kasir.sqlite');

      final lama = BasisDataKasir(NativeDatabase(berkas));
      await lama.customSelect('SELECT 1').get();
      await lama.customStatement('ALTER TABLE PesananTerbuka DROP COLUMN MintaBillPada');
      await lama.customStatement('DROP TABLE MejaPerluDibersihkan');
      await lama.customStatement(
        'INSERT INTO PesananTerbuka (Uuid, Nomor, DibukaPada, Status, Baris, DiubahPada) VALUES '
        "('OB1', 'OB/1', '2026-10-02T03:00:00.000Z', 'Terbuka', '[]', '2026-10-02T03:00:00.000Z')",
      );
      await lama.customStatement(
        'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
        "('OB1', 'PesananTerbuka.Buka', '{}', 'Tertunda', 1, '2026-10-02T03:00:00.000Z', '2026-10-02T03:00:00.000Z', 'PRG1')",
      );
      await lama.customStatement('PRAGMA user_version = 23');
      await lama.close();

      final db = BasisDataKasir(NativeDatabase(berkas));
      addTearDown(db.close);
      expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
      expect((await db.select(db.outbox).get()).single.Uuid, 'OB1');
      final pesanan = (await db.select(db.pesananTerbuka).get()).single;
      expect((pesanan.Uuid, pesanan.MintaBillPada), ('OB1', null));
      expect(await db.select(db.mejaPerluDibersihkan).get(), isEmpty);
    },
  );

  test('K-25 migrasi 24 → 25 menambah tanda harga terbuka produk & memuat ulang katalog; outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Produk DROP COLUMN HargaTerbuka');
    await lama.customStatement(
      "INSERT INTO Produk (Uuid, Nama, Jenis, Pelacakan, TampilDiPos, Aktif) VALUES ('LAIN', 'Barang lain-lain', 'NonStok', 'Tidak', 1, 1)",
    );
    await lama.customStatement(
      "INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('KursorKatalog', '2026-10-01T00:00:00Z')",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 1, '2026-10-02T03:00:00.000Z', '2026-10-02T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 24');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect((await db.select(db.outbox).get()).single.Uuid, 'PJ1');
    final produk = (await db.select(db.produk).get()).single;
    expect((produk.Uuid, produk.HargaTerbuka), ('LAIN', false));
    expect((await db.select(db.pengaturan).get()).map((p) => p.Kunci), isNot(contains('KursorKatalog')));
  });

  test(
    'Modul Salesman bagian 2 migrasi 25 → 26 hanya menambah tabel salesman; outbox tertunda & dokumen lama utuh',
    () async {
      final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
      addTearDown(() => folder.deleteSync(recursive: true));
      final berkas = File('${folder.path}/kasir.sqlite');

      final lama = BasisDataKasir(NativeDatabase(berkas));
      await lama.customSelect('SELECT 1').get();
      for (final tabel in ['PesananGrosirLokal', 'KunjunganSalesLokal', 'PelangganSalesmanLokal']) {
        await lama.customStatement('DROP TABLE $tabel');
      }
      await lama.customStatement(
        'INSERT INTO BahanTerbuangLokal (Uuid, UuidProduk, NamaProduk, Jumlah, NamaSatuan, JumlahDasar, Alasan, '
        "UuidPengguna, NamaPengguna, TanggalBisnis, DibuatPada) VALUES ('BT1', 'PRD1', 'Croissant', '2', 'Pcs', '2', "
        "'Rusak', 'STAF1', 'Rina Wulandari', '2026-10-02', '2026-10-02T03:00:00.000Z')",
      );
      await lama.customStatement(
        'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, KodeGalat, PesanGalat, DibuatPada, BerikutnyaPada, '
        'UuidPerangkat) VALUES '
        "('PJ1', 'Penjualan.Buat', '{\"Nomor\":\"SLB-K01-261002-0001\"}', 'Tertunda', 2, NULL, NULL, "
        "'2026-10-02T03:00:00.000Z', '2026-10-02T03:00:10.000Z', 'PRG1'),"
        "('BT1', 'BahanTerbuang.Catat', '{}', 'PerluTindakan', 1, 'TanpaIzin', 'Tidak berizin', "
        "'2026-10-02T03:01:00.000Z', '2026-10-02T03:01:00.000Z', 'PRG1')",
      );
      await lama.customStatement('PRAGMA user_version = 25');
      await lama.close();

      final db = BasisDataKasir(NativeDatabase(berkas));
      addTearDown(db.close);
      expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
      final outbox = await (db.select(db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      expect(outbox.map((o) => (o.Uuid, o.Status, o.Percobaan, o.UuidPerangkat)), [
        ('PJ1', 'Tertunda', 2, 'PRG1'),
        ('BT1', 'PerluTindakan', 1, 'PRG1'),
      ]);
      expect(outbox.first.Data, '{"Nomor":"SLB-K01-261002-0001"}');
      expect(outbox.last.PesanGalat, 'Tidak berizin');
      expect((await db.select(db.bahanTerbuangLokal).get()).single.NamaProduk, 'Croissant');

      // Tabel baru siap dipakai.
      await db
          .into(db.pelangganSalesmanLokal)
          .insert(
            PelangganSalesmanLokalCompanion.insert(
              Uuid: 'PLG1',
              Nama: 'Toko Kelontong Makmur Jaya Abadi',
              NoHp: const Value('6281355550001'),
              SisaPiutang: '12750000.00',
              JumlahPiutangJatuhTempo: '3250000.00',
            ),
          );
      await db
          .into(db.kunjunganSalesLokal)
          .insert(
            KunjunganSalesLokalCompanion.insert(
              Uuid: 'KNJ1',
              UuidPelanggan: 'PLG1',
              NamaPelanggan: 'Toko Kelontong Makmur Jaya Abadi',
              UuidPengguna: 'STAF9',
              NamaPengguna: 'Dewi Kartika Sari',
              MasukPada: DateTime.utc(2026, 10, 2, 3),
              Latitude: const Value('-7.5666001'),
              Longitude: const Value('110.8166002'),
            ),
          );
      await db
          .into(db.pesananGrosirLokal)
          .insert(
            PesananGrosirLokalCompanion.insert(
              Uuid: 'PG1',
              UuidPelanggan: 'PLG1',
              NamaPelanggan: 'Toko Kelontong Makmur Jaya Abadi',
              UuidPengguna: 'STAF9',
              NamaPengguna: 'Dewi Kartika Sari',
              Baris: '[]',
              JumlahBaris: 0,
              PerkiraanTotal: '0.00',
              DibuatPada: DateTime.utc(2026, 10, 2, 3, 5),
            ),
          );
      final pelanggan = (await db.select(db.pelangganSalesmanLokal).get()).single;
      expect((pelanggan.TerminHari, pelanggan.HariLewatJatuhTempo, pelanggan.LimitKredit), (0, 0, null));
      final kunjungan = (await db.select(db.kunjunganSalesLokal).get()).single;
      expect((kunjungan.KeluarPada, kunjungan.Hasil, kunjungan.Latitude), (null, null, '-7.5666001'));
      expect((await db.select(db.pesananGrosirLokal).get()).single.Uuid, 'PG1');
    },
  );

  test(
    'Bengkel & Apotek bagian 2 migrasi 26 → 27 hanya menambah kolom golongan obat produk serta perintah kerja & resep '
    'penjualan; outbox tertunda, penjualan, & produk lama utuh, kursor katalog dihapus',
    () async {
      final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
      addTearDown(() => folder.deleteSync(recursive: true));
      final berkas = File('${folder.path}/kasir.sqlite');

      final lama = BasisDataKasir(NativeDatabase(berkas));
      await lama.customSelect('SELECT 1').get();
      for (final kolom in ['GolonganObat', 'ObatWajibApotek', 'Prekursor', 'WajibResep']) {
        await lama.customStatement('ALTER TABLE Produk DROP COLUMN $kolom');
      }
      for (final kolom in ['PerintahKerja', 'Resep']) {
        await lama.customStatement('ALTER TABLE Penjualan DROP COLUMN $kolom');
      }
      await lama.customStatement(
        'INSERT INTO Produk (Uuid, Sku, Nama, Jenis, Pelacakan, TampilDiPos, Aktif, HargaTerbuka) VALUES '
        "('AMOX', 'AMX-500', 'Amoxicillin Trihydrate 500 mg Kapsul Strip 10', 'Stok', 'Tidak', 1, 1, 0)",
      );
      await lama.customStatement(
        'INSERT INTO Penjualan (Uuid, Nomor, UuidShift, UuidPengguna, NamaKasir, Kanal, DibuatPada, TanggalBisnis, '
        'Status, Subtotal, TotalDiskon, BiayaLayanan, TotalPajak, Pembulatan, TotalAkhir, TotalDibayar, Kembalian) '
        "VALUES ('PJ1', 'INV/SLB/261002/POS-001-0001', 'SHIFT1', 'STAF1', 'Rina Wulandari', 'BawaPulang', "
        "'2026-10-02T03:00:00.000Z', '2026-10-02', 'Lunas', '1250000.00', '0.00', '0.00', '0.00', '0.00', "
        "'1250000.00', '1250000.00', '0.00')",
      );
      await lama.customStatement(
        'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, KodeGalat, PesanGalat, DibuatPada, BerikutnyaPada, '
        'UuidPerangkat) VALUES '
        "('PJ1', 'Penjualan.Buat', '{\"Nomor\":\"INV/SLB/261002/POS-001-0001\"}', 'Tertunda', 3, NULL, NULL, "
        "'2026-10-02T03:00:00.000Z', '2026-10-02T03:00:10.000Z', 'PRG1'),"
        "('PJ0', 'Penjualan.Buat', '{}', 'PerluTindakan', 1, 'DataTidakValid', 'Data tidak valid', "
        "'2026-10-02T02:00:00.000Z', '2026-10-02T02:00:00.000Z', 'PRG1')",
      );
      await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('KursorKatalog', 'a3Vyc29yLTE')");
      await lama.customStatement('PRAGMA user_version = 26');
      await lama.close();

      final db = BasisDataKasir(NativeDatabase(berkas));
      addTearDown(db.close);
      expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
      final outbox = await (db.select(db.outbox)..orderBy([(o) => OrderingTerm.asc(o.Id)])).get();
      expect(outbox.map((o) => (o.Uuid, o.Status, o.Percobaan, o.UuidPerangkat)), [
        ('PJ1', 'Tertunda', 3, 'PRG1'),
        ('PJ0', 'PerluTindakan', 1, 'PRG1'),
      ]);
      expect(outbox.first.Data, '{"Nomor":"INV/SLB/261002/POS-001-0001"}');
      expect(outbox.last.PesanGalat, 'Data tidak valid');

      final produk = (await db.select(db.produk).get()).single;
      expect(
        (produk.Uuid, produk.GolonganObat, produk.ObatWajibApotek, produk.Prekursor, produk.WajibResep),
        ('AMOX', null, false, false, false),
      );
      final jual = (await db.select(db.penjualan).get()).single;
      expect(
        (jual.Nomor, jual.TotalAkhir, jual.PerintahKerja, jual.Resep),
        ('INV/SLB/261002/POS-001-0001', '1250000.00', null, null),
      );
      expect(
        (await db.select(db.pengaturan).get()).map((p) => p.Kunci),
        isNot(contains('KursorKatalog')),
        reason: 'Katalog dimuat ulang supaya golongan obat lama ikut terunduh.',
      );

      // Kolom baru siap dipakai.
      await (db.update(db.produk)..where((p) => p.Uuid.equals('AMOX'))).write(
        const ProdukCompanion(GolonganObat: Value('Keras'), WajibResep: Value(true)),
      );
      await (db.update(db.penjualan)..where((p) => p.Uuid.equals('PJ1'))).write(
        const PenjualanCompanion(
          PerintahKerja: Value('{"Uuid":"PK1","Nomor":"WO/SLB/2610/0007","NomorPolisi":"AD 1234 XY"}'),
          Resep: Value('{"NomorResep":"RX-77","TanggalResep":"2026-10-02","NamaDokter":"dr. Andi"}'),
        ),
      );
      expect((await db.select(db.produk).get()).single.GolonganObat, 'Keras');
      expect((await db.select(db.penjualan).get()).single.PerintahKerja, contains('WO/SLB/2610/0007'));
    },
  );

  test('Apotek bagian 4 migrasi 27 → 28 menambah kolom racikan detail penjualan; detail lama null, outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE PenjualanDetail DROP COLUMN Racikan');
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 2, '2026-10-02T03:00:00.000Z', '2026-10-02T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 27');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect(
      await db
          .customSelect("SELECT COUNT(*) AS Jumlah FROM pragma_table_info('PenjualanDetail') WHERE name = 'Racikan'")
          .map((r) => r.read<int>('Jumlah'))
          .getSingle(),
      1,
    );
    final outbox = (await db.select(db.outbox).get()).single;
    expect((outbox.Uuid, outbox.Status, outbox.Percobaan), ('PJ1', 'Tertunda', 2));
  });

  test('K-14 migrasi 28 → 29 menambah kolom bagian tagihan penjualan; penjualan lama null, outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Penjualan DROP COLUMN BagianTagihan');
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 2, '2026-10-12T03:00:00.000Z', '2026-10-12T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 28');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    expect(
      await db
          .customSelect("SELECT COUNT(*) AS Jumlah FROM pragma_table_info('Penjualan') WHERE name = 'BagianTagihan'")
          .map((r) => r.read<int>('Jumlah'))
          .getSingle(),
      1,
    );
    final outbox = (await db.select(db.outbox).get()).single;
    expect((outbox.Uuid, outbox.Status, outbox.Percobaan), ('PJ1', 'Tertunda', 2));
  });

  test('K-16 migrasi 29 → 30 menambah kolom favorit produk (bawaan tidak) dan memuat ulang katalog; outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Produk DROP COLUMN Favorit');
    await lama.customStatement(
      'INSERT INTO Produk (Uuid, Nama, Jenis, Pelacakan, TampilDiPos, Aktif) VALUES '
      "('KOPI', 'Es Kopi Susu Aren', 'Resep', 'Tidak', 1, 1)",
    );
    await lama.customStatement("INSERT INTO Pengaturan (Kunci, Nilai) VALUES ('KursorKatalog', 'abc')");
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 2, '2026-10-12T03:00:00.000Z', '2026-10-12T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 29');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    final produk = (await db.select(db.produk).get()).single;
    expect((produk.Uuid, produk.Favorit), ('KOPI', false));
    expect(
      await (db.select(db.pengaturan)..where((p) => p.Kunci.equals('KursorKatalog'))).get(),
      isEmpty,
      reason: 'Kursor dihapus supaya katalog dimuat ulang beserta tanda favorit.',
    );
    final outbox = (await db.select(db.outbox).get()).single;
    expect((outbox.Uuid, outbox.Status, outbox.Percobaan), ('PJ1', 'Tertunda', 2));
  });

  test('POS-03 migrasi 30 → 31 menambah letak meja di denah (belum ditata = null); meja & outbox utuh', () async {
    final folder = Directory.systemTemp.createTempSync('migrasi_kasir_');
    addTearDown(() => folder.deleteSync(recursive: true));
    final berkas = File('${folder.path}/kasir.sqlite');

    final lama = BasisDataKasir(NativeDatabase(berkas));
    await lama.customSelect('SELECT 1').get();
    await lama.customStatement('ALTER TABLE Meja DROP COLUMN PosisiX');
    await lama.customStatement('ALTER TABLE Meja DROP COLUMN PosisiY');
    await lama.customStatement(
      'INSERT INTO Meja (Uuid, Nama, Kapasitas, Bentuk, Urutan) VALUES '
      "('M7', '7', 4, 'Persegi', 0)",
    );
    await lama.customStatement(
      'INSERT INTO Outbox (Uuid, Jenis, Data, Status, Percobaan, DibuatPada, BerikutnyaPada, UuidPerangkat) VALUES '
      "('PJ1', 'Penjualan.Buat', '{}', 'Tertunda', 2, '2026-10-12T03:00:00.000Z', '2026-10-12T03:00:00.000Z', 'PRG1')",
    );
    await lama.customStatement('PRAGMA user_version = 30');
    await lama.close();

    final db = BasisDataKasir(NativeDatabase(berkas));
    addTearDown(db.close);
    expect(await db.customSelect('PRAGMA user_version').map((r) => r.read<int>('user_version')).getSingle(), 31);
    final meja = (await db.select(db.meja).get()).single;
    expect((meja.Uuid, meja.Nama, meja.PosisiX, meja.PosisiY), ('M7', '7', null, null));
    final outbox = (await db.select(db.outbox).get()).single;
    expect((outbox.Uuid, outbox.Status, outbox.Percobaan), ('PJ1', 'Tertunda', 2));
  });
}
