import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:klien_api/KlienApi.dart';

import 'BasisData/BasisDataKasir.dart';

/// Semua baris katalog lokal sekaligus (dibaca `KatalogLokal`).
class IsiKatalogLokal {
  const IsiKatalogLokal({
    required this.kategori,
    required this.satuan,
    required this.kelompokPajak,
    required this.kelompokPajakDetail,
    required this.produk,
    required this.produkSatuan,
    required this.produkBarcode,
    required this.daftarHarga,
    required this.produkHarga,
    required this.kelompokPilihan,
    required this.pilihan,
    required this.produkKelompokPilihan,
  });

  final List<BarisKategori> kategori;
  final List<BarisSatuan> satuan;
  final List<BarisKelompokPajak> kelompokPajak;
  final List<BarisKelompokPajakDetail> kelompokPajakDetail;
  final List<BarisProduk> produk;
  final List<BarisProdukSatuan> produkSatuan;
  final List<BarisProdukBarcode> produkBarcode;
  final List<BarisDaftarHarga> daftarHarga;
  final List<BarisProdukHarga> produkHarga;
  final List<BarisKelompokPilihan> kelompokPilihan;
  final List<BarisPilihan> pilihan;
  final List<BarisProdukKelompokPilihan> produkKelompokPilihan;
}

/// Penyimpanan katalog lokal (Rincian F-07c): katalog lengkap mengganti semua tabel katalog, delta menimpa baris per
/// Uuid dan menerapkan penghapusan (`Produk.Dihapus` & bagian `Terhapus`). Semua dalam satu transaksi agar katalog
/// tidak pernah setengah diperbarui.
class RepositoriKatalog {
  RepositoriKatalog(this.db);

  final BasisDataKasir db;

  Future<void> GantiKatalog(KatalogPos katalog) => db.transaction(() async {
    for (final tabel in <TableInfo<Table, Object?>>[
      db.kategori,
      db.satuan,
      db.kelompokPajak,
      db.kelompokPajakDetail,
      db.produk,
      db.produkSatuan,
      db.produkBarcode,
      db.daftarHarga,
      db.produkHarga,
      db.kelompokPilihan,
      db.pilihan,
      db.produkKelompokPilihan,
    ]) {
      await db.delete(tabel).go();
    }
    await _Tulis(katalog);
  });

  Future<void> TerapkanDelta(KatalogPos katalog) => db.transaction(() async {
    await _Tulis(katalog);

    final dihapus = katalog.produk.where((p) => p.dihapus).map((p) => p.uuid).toList();
    if (dihapus.isNotEmpty) {
      await (db.delete(db.produk)..where((t) => t.Uuid.isIn(dihapus))).go();
      await (db.delete(db.produkSatuan)..where((t) => t.UuidProduk.isIn(dihapus))).go();
      await (db.delete(db.produkBarcode)..where((t) => t.UuidProduk.isIn(dihapus))).go();
      await (db.delete(db.produkHarga)..where((t) => t.UuidProduk.isIn(dihapus))).go();
      await (db.delete(db.produkKelompokPilihan)..where((t) => t.UuidProduk.isIn(dihapus))).go();
    }

    for (final t in katalog.terhapus) {
      switch (t.entitas) {
        case 'Kategori':
          await (db.delete(db.kategori)..where((b) => b.Uuid.equals(t.uuid))).go();
        case 'Satuan':
          await (db.delete(db.satuan)..where((b) => b.Uuid.equals(t.uuid))).go();
        case 'ProdukSatuan':
          await (db.delete(db.produkSatuan)..where((b) => b.Uuid.equals(t.uuid))).go();
          await (db.delete(db.produkHarga)..where((b) => b.UuidProdukSatuan.equals(t.uuid))).go();
        case 'ProdukBarcode':
          await (db.delete(db.produkBarcode)..where((b) => b.Uuid.equals(t.uuid))).go();
        case 'ProdukHarga':
          await (db.delete(db.produkHarga)..where((b) => b.Uuid.equals(t.uuid))).go();
        case 'KelompokPilihan':
          await (db.delete(db.kelompokPilihan)..where((b) => b.Uuid.equals(t.uuid))).go();
          await (db.delete(db.pilihan)..where((b) => b.UuidKelompokPilihan.equals(t.uuid))).go();
          await (db.delete(db.produkKelompokPilihan)..where((b) => b.UuidKelompokPilihan.equals(t.uuid))).go();
        case 'Pilihan':
          await (db.delete(db.pilihan)..where((b) => b.Uuid.equals(t.uuid))).go();
        case 'ProdukKelompokPilihan':
          await (db.delete(db.produkKelompokPilihan)..where((b) => b.Uuid.equals(t.uuid))).go();
        default:
          // Entitas yang tidak disimpan POS fase 1 (misal PaketProdukDetail) diabaikan.
          break;
      }
    }
  });

  Future<void> _Tulis(KatalogPos k) async {
    // Detail kelompok pajak dikirim utuh per kelompok: ganti detail kelompok yang ikut dikirim.
    final uuidKelompokPajak = k.kelompokPajak.map((p) => p.uuid).toList();
    if (uuidKelompokPajak.isNotEmpty) {
      await (db.delete(db.kelompokPajakDetail)..where((t) => t.UuidKelompokPajak.isIn(uuidKelompokPajak))).go();
    }

    await db.batch((b) {
      b.insertAllOnConflictUpdate(db.kategori, [
        for (final x in k.kategori)
          KategoriCompanion.insert(Uuid: x.uuid, UuidInduk: Value(x.uuidInduk), Nama: x.nama, Urutan: Value(x.urutan)),
      ]);
      b.insertAllOnConflictUpdate(db.satuan, [
        for (final x in k.satuan)
          SatuanCompanion.insert(
            Uuid: x.uuid,
            Nama: x.nama,
            Simbol: Value(x.simbol),
            BolehDesimal: Value(x.bolehDesimal),
          ),
      ]);
      b.insertAllOnConflictUpdate(db.kelompokPajak, [
        for (final x in k.kelompokPajak)
          KelompokPajakCompanion.insert(Uuid: x.uuid, Nama: x.nama, Kategori: Value(x.kategori)),
      ]);
      b.insertAllOnConflictUpdate(db.kelompokPajakDetail, [
        for (final x in k.kelompokPajak)
          for (final p in x.pajak)
            KelompokPajakDetailCompanion.insert(
              UuidKelompokPajak: x.uuid,
              KodeJenisPajak: p.kodeJenisPajak,
              DasarPengenaan: p.dasarPengenaan,
              Urutan: Value(p.urutan),
              Kategori: Value(p.kategori),
              KenaBiayaKirim: Value(p.kenaBiayaKirim),
            ),
      ]);
      b.insertAllOnConflictUpdate(db.produk, [
        for (final x in k.produk.where((p) => !p.dihapus))
          ProdukCompanion.insert(
            Uuid: x.uuid,
            Sku: Value(x.sku),
            Nama: x.nama,
            NamaStruk: Value(x.namaStruk),
            Jenis: x.jenis,
            UuidKategori: Value(x.uuidKategori),
            UuidSatuanDasar: Value(x.uuidSatuanDasar),
            Pelacakan: x.pelacakan,
            UuidKelompokPajak: Value(x.uuidKelompokPajak),
            HargaTermasukPajak: Value(x.hargaTermasukPajak),
            TampilDiPos: x.tampilDiPos,
            Favorit: Value(x.favorit),
            UuidInduk: Value(x.uuidInduk),
            UrlGambarKecil: Value(x.urlGambarKecil),
            Aktif: x.aktif,
            JumlahSesiPaket: Value(x.jumlahSesiPaket),
            MasaGaransiBulan: Value(x.masaGaransiBulan),
            AtributVarian: Value(x.atributVarian == null ? null : jsonEncode(x.atributVarian)),
            HargaTerbuka: Value(x.hargaTerbuka),
            GolonganObat: Value(x.golonganObat),
            ObatWajibApotek: Value(x.obatWajibApotek),
            Prekursor: Value(x.prekursor),
            WajibResep: Value(x.wajibResep),
          ),
      ]);
      b.insertAllOnConflictUpdate(db.produkSatuan, [
        for (final x in k.produkSatuan)
          ProdukSatuanCompanion.insert(
            Uuid: x.uuid,
            UuidProduk: x.uuidProduk,
            UuidSatuan: x.uuidSatuan,
            KonversiKeDasar: x.konversiKeDasar,
            DefaultJual: x.defaultJual,
          ),
      ]);
      b.insertAllOnConflictUpdate(db.produkBarcode, [
        for (final x in k.produkBarcode)
          ProdukBarcodeCompanion.insert(
            Uuid: x.uuid,
            UuidProduk: x.uuidProduk,
            UuidProdukSatuan: Value(x.uuidProdukSatuan),
            Barcode: x.barcode,
          ),
      ]);
      b.insertAllOnConflictUpdate(db.daftarHarga, [
        for (final x in k.daftarHarga)
          DaftarHargaCompanion.insert(
            Uuid: x.uuid,
            Nama: x.nama,
            UuidOutlet: Value(x.uuidOutlet == null ? null : jsonEncode(x.uuidOutlet)),
            Kanal: Value(x.kanal),
            TierPelanggan: Value(x.tierPelanggan),
            MulaiPada: Value(x.mulaiPada == null ? null : DateTime.tryParse(x.mulaiPada!)?.toUtc()),
            SelesaiPada: Value(x.selesaiPada == null ? null : DateTime.tryParse(x.selesaiPada!)?.toUtc()),
            Prioritas: x.prioritas,
            Aktif: x.aktif,
          ),
      ]);
      b.insertAllOnConflictUpdate(db.produkHarga, [
        for (final x in k.produkHarga)
          ProdukHargaCompanion.insert(
            Uuid: x.uuid,
            UuidProduk: x.uuidProduk,
            UuidProdukSatuan: x.uuidProdukSatuan,
            UuidDaftarHarga: Value(x.uuidDaftarHarga),
            JumlahMinimum: x.jumlahMinimum,
            Harga: x.harga,
          ),
      ]);
      b.insertAllOnConflictUpdate(db.kelompokPilihan, [
        for (final x in k.kelompokPilihan)
          KelompokPilihanCompanion.insert(
            Uuid: x.uuid,
            Nama: x.nama,
            MinimalPilih: x.minimalPilih,
            MaksimalPilih: Value(x.maksimalPilih),
            Urutan: x.urutan,
          ),
      ]);
      b.insertAllOnConflictUpdate(db.pilihan, [
        for (final x in k.pilihan)
          PilihanCompanion.insert(
            Uuid: x.uuid,
            UuidKelompokPilihan: x.uuidKelompokPilihan,
            Nama: x.nama,
            Harga: x.harga,
            UuidProdukBahan: Value(x.uuidProdukBahan),
            Jumlah: Value(x.jumlah),
            Aktif: x.aktif,
            Urutan: x.urutan,
          ),
      ]);
      b.insertAllOnConflictUpdate(db.produkKelompokPilihan, [
        for (final x in k.produkKelompokPilihan)
          ProdukKelompokPilihanCompanion.insert(
            Uuid: x.uuid,
            UuidProduk: x.uuidProduk,
            UuidKelompokPilihan: x.uuidKelompokPilihan,
            Urutan: x.urutan,
          ),
      ]);
    });
  }

  Future<IsiKatalogLokal> Muat() async => IsiKatalogLokal(
    kategori: await (db.select(
      db.kategori,
    )..orderBy([(t) => OrderingTerm.asc(t.Urutan), (t) => OrderingTerm.asc(t.Nama)])).get(),
    satuan: await db.select(db.satuan).get(),
    kelompokPajak: await db.select(db.kelompokPajak).get(),
    kelompokPajakDetail: await (db.select(db.kelompokPajakDetail)..orderBy([(t) => OrderingTerm.asc(t.Urutan)])).get(),
    produk: await (db.select(db.produk)..orderBy([(t) => OrderingTerm.asc(t.Nama)])).get(),
    produkSatuan: await db.select(db.produkSatuan).get(),
    produkBarcode: await db.select(db.produkBarcode).get(),
    daftarHarga: await db.select(db.daftarHarga).get(),
    produkHarga: await db.select(db.produkHarga).get(),
    kelompokPilihan: await (db.select(db.kelompokPilihan)..orderBy([(t) => OrderingTerm.asc(t.Urutan)])).get(),
    pilihan: await (db.select(db.pilihan)..orderBy([(t) => OrderingTerm.asc(t.Urutan)])).get(),
    produkKelompokPilihan: await (db.select(
      db.produkKelompokPilihan,
    )..orderBy([(t) => OrderingTerm.asc(t.Urutan)])).get(),
  );

  Future<List<BarisTarifPajak>> AmbilTarifPajak() => db.select(db.tarifPajak).get();

  /// F-16c: kategori per produk (kondisi promo kategori).
  Future<Map<String, String>> AmbilKategoriProduk() async => {
    for (final p in await db.select(db.produk).get())
      if (p.UuidKategori != null) p.Uuid: p.UuidKategori!,
  };

  Future<List<BarisMetodePembayaran>> AmbilMetodePembayaran() => (db.select(
    db.metodePembayaran,
  )..orderBy([(t) => OrderingTerm.asc(t.Urutan), (t) => OrderingTerm.asc(t.Nama)])).get();
}
