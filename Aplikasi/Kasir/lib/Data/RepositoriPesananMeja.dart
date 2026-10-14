import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:klien_api/KlienApi.dart';

import 'BasisData/BasisDataKasir.dart';
import 'PesananMeja.dart';
import 'RepositoriKasir.dart';

/// Penyimpanan meja & pesanan terbuka lokal (F-07 mode meja fase 1). Setiap perubahan pesanan menulis baris lokal +
/// entri outbox `PesananTerbuka.*` dalam satu transaksi SQLite (PRD §18.3 no. 3). Snapshot server menimpa salinan
/// lokal, kecuali pesanan yang masih punya perubahan tertunda di outbox (perubahan perangkat ini belum sampai server).
class RepositoriPesananMeja {
  RepositoriPesananMeja(this.db, this.repositoriKasir);

  final BasisDataKasir db;
  final RepositoriKasir repositoriKasir;

  // Meja -------------------------------------------------------------------------------------------------------------

  /// Ganti area & meja dengan data server terbaru dan simpan status mode meja outlet.
  Future<void> SimpanDataMeja(DataMejaPos data) => db.transaction(() async {
    await db.delete(db.areaMeja).go();
    await db.delete(db.meja).go();
    await db.batch((b) {
      b.insertAll(db.areaMeja, [
        for (final a in data.area) AreaMejaCompanion.insert(Uuid: a.uuid, Nama: a.nama, Urutan: Value(a.urutan)),
      ]);
      b.insertAll(db.meja, [
        for (final m in data.meja)
          MejaCompanion.insert(
            Uuid: m.uuid,
            Nama: m.nama,
            UuidArea: Value(m.uuidArea),
            Kapasitas: Value(m.kapasitas),
            Bentuk: Value(m.bentuk),
            Urutan: Value(m.urutan),
          ),
      ]);
    });
    await repositoriKasir.SimpanPengaturan(KunciPengaturan.modeMejaAktif, data.modeMejaAktif ? '1' : '0');
    // D-144: minimum charge per meja (disimpan sebagai pengaturan, tanpa mengubah skema meja lokal).
    await repositoriKasir.SimpanPengaturan(
      KunciPengaturan.minimumChargeMeja,
      jsonEncode({
        'UuidProduk': data.uuidProdukMinimumCharge,
        'Meja': {
          for (final m in data.meja)
            if (m.minimumCharge != null) m.uuid: m.minimumCharge,
        },
      }),
    );
    await repositoriKasir.SimpanPengaturan(
      KunciPengaturan.ruteDapur,
      jsonEncode({
        'Stasiun': [
          for (final s in data.stasiunDapur) {'Uuid': s.uuid, 'Nama': s.nama},
        ],
        'Bawaan': data.uuidStasiunBawaan,
        'Kategori': data.kategoriStasiun,
      }),
    );
  });

  Stream<List<BarisAreaMeja>> PantauArea() =>
      (db.select(db.areaMeja)..orderBy([(a) => OrderingTerm.asc(a.Urutan), (a) => OrderingTerm.asc(a.Nama)])).watch();

  Stream<List<BarisMeja>> PantauMeja() =>
      (db.select(db.meja)..orderBy([(m) => OrderingTerm.asc(m.Urutan), (m) => OrderingTerm.asc(m.Nama)])).watch();

  Future<BarisMeja?> CariMeja(String uuid) => (db.select(db.meja)..where((m) => m.Uuid.equals(uuid))).getSingleOrNull();

  // Pesanan ----------------------------------------------------------------------------------------------------------

  /// Pesanan berstatus `Terbuka`, terlama dulu.
  Stream<List<PesananMeja>> PantauPesananTerbuka() =>
      (db.select(db.pesananTerbuka)
            ..where((p) => p.Status.equals(StatusPesananMeja.terbuka))
            ..orderBy([(p) => OrderingTerm.asc(p.DibukaPada)]))
          .watch()
          .map((daftar) => daftar.map(PesananMeja.DariBaris).toList());

  Stream<PesananMeja?> PantauPesanan(String uuid) => (db.select(
    db.pesananTerbuka,
  )..where((p) => p.Uuid.equals(uuid))).watchSingleOrNull().map((b) => b == null ? null : PesananMeja.DariBaris(b));

  Future<PesananMeja?> CariPesanan(String uuid) async {
    final baris = await (db.select(db.pesananTerbuka)..where((p) => p.Uuid.equals(uuid))).getSingleOrNull();
    return baris == null ? null : PesananMeja.DariBaris(baris);
  }

  /// Pesanan terbuka lain di [uuidMeja] (satu meja satu pesanan terbuka di fase 1; gabung meja menyusul).
  Future<PesananMeja?> CariPesananDiMeja(String uuidMeja) async {
    final baris =
        await (db.select(db.pesananTerbuka)
              ..where((p) => p.UuidMeja.equals(uuidMeja) & p.Status.equals(StatusPesananMeja.terbuka))
              ..limit(1))
            .getSingleOrNull();
    return baris == null ? null : PesananMeja.DariBaris(baris);
  }

  /// Ambil nomor urut pesanan berikutnya ([tanggal] `YYMMDD`), lalu simpan pesanan baru + outbox `PesananTerbuka.Buka`.
  Future<PesananMeja> SimpanPesananBaru({
    required String kodePerangkat,
    required String tanggal,
    required DateTime sekarang,
    required ({PesananTerbukaCompanion pesanan, ItemOutbox outbox}) Function(int urut) susun,
  }) => db.transaction(() async {
    final lama = await (db.select(
      db.nomorUrutPesananTerbuka,
    )..where((n) => n.KodePerangkat.equals(kodePerangkat) & n.Tanggal.equals(tanggal))).getSingleOrNull();
    final urut = (lama?.Terakhir ?? 0) + 1;
    await db
        .into(db.nomorUrutPesananTerbuka)
        .insertOnConflictUpdate(
          NomorUrutPesananTerbukaCompanion.insert(KodePerangkat: kodePerangkat, Tanggal: tanggal, Terakhir: urut),
        );
    final dokumen = susun(urut);
    await db.into(db.pesananTerbuka).insert(dokumen.pesanan);
    await repositoriKasir.TambahOutbox(dokumen.outbox, sekarang);
    return PesananMeja.DariBaris(
      await (db.select(db.pesananTerbuka)..where((p) => p.Uuid.equals(dokumen.pesanan.Uuid.value))).getSingle(),
    );
  });

  /// Ubah pesanan lokal lewat [ubah] (baris & header) dan tambahkan [outbox] dalam satu transaksi. Pesanan yang sudah
  /// tidak terbuka ditolak dengan `null`.
  Future<PesananMeja?> UbahPesanan(
    String uuid,
    PesananTerbukaCompanion Function(PesananMeja pesanan) ubah,
    List<ItemOutbox> outbox,
    DateTime sekarang,
  ) => db.transaction(() async {
    final pesanan = await CariPesanan(uuid);
    if (pesanan == null || pesanan.status != StatusPesananMeja.terbuka) {
      return null;
    }
    await (db.update(db.pesananTerbuka)..where((p) => p.Uuid.equals(uuid))).write(ubah(pesanan));
    for (final item in outbox) {
      await repositoriKasir.TambahOutbox(item, sekarang);
    }
    return CariPesanan(uuid);
  });

  /// v1.99 pisah/gabung: ubah dua pesanan terbuka ([uuidAsal] & [uuidTujuan]) dan tambahkan [outbox] dalam satu
  /// transaksi. Salah satu sudah tidak terbuka → `null`.
  Future<({PesananMeja asal, PesananMeja tujuan})?> UbahDuaPesanan(
    String uuidAsal,
    String uuidTujuan,
    ({PesananTerbukaCompanion asal, PesananTerbukaCompanion tujuan}) Function(PesananMeja asal, PesananMeja tujuan)
    ubah,
    List<ItemOutbox> outbox,
    DateTime sekarang,
  ) => db.transaction(() async {
    final asal = await CariPesanan(uuidAsal);
    final tujuan = await CariPesanan(uuidTujuan);
    if (asal == null ||
        tujuan == null ||
        asal.status != StatusPesananMeja.terbuka ||
        tujuan.status != StatusPesananMeja.terbuka) {
      return null;
    }
    final hasil = ubah(asal, tujuan);
    await (db.update(db.pesananTerbuka)..where((p) => p.Uuid.equals(uuidAsal))).write(hasil.asal);
    await (db.update(db.pesananTerbuka)..where((p) => p.Uuid.equals(uuidTujuan))).write(hasil.tujuan);
    for (final item in outbox) {
      await repositoriKasir.TambahOutbox(item, sekarang);
    }
    return (asal: (await CariPesanan(uuidAsal))!, tujuan: (await CariPesanan(uuidTujuan))!);
  });

  static String SusunJsonBaris(List<BarisPesananMeja> baris) => jsonEncode([for (final b in baris) b.KeJson()]);

  /// Uuid pesanan yang masih punya item outbox tertunda (`PesananTerbuka.*`, atau `Penjualan.Buat` yang menutupnya).
  Future<Set<String>> AmbilUuidPesananTertunda() async {
    final baris = await (db.select(
      db.outbox,
    )..where((o) => o.Jenis.like('PesananTerbuka.%') | o.Jenis.equals('Penjualan.Buat'))).get();
    final hasil = <String>{};
    for (final b in baris) {
      if (b.Jenis == 'PesananTerbuka.Buka') {
        hasil.add(b.Uuid);
        continue;
      }
      final data = jsonDecode(b.Data);
      if (data is! Map<String, Object?>) {
        continue;
      }
      final uuid = data['UuidPesanan'] ?? data['UuidPesananTerbuka'];
      if (uuid is String) {
        hasil.add(uuid);
      }
      // v1.99 PindahBaris: pesanan tujuan juga menunggu perubahan ini.
      final tujuan = data['UuidTujuan'];
      if (tujuan is String) {
        hasil.add(tujuan);
      }
    }
    return hasil;
  }

  /// Terapkan snapshot server: pesanan server menimpa salinan lokal; pesanan lokal `Terbuka` yang tidak ada di server
  /// dan tidak tertunda dihapus (sudah ditutup di perangkat lain lebih dari 12 jam lalu); pesanan `Ditutup` dihapus.
  /// Pesanan dengan perubahan tertunda di outbox dibiarkan sampai perubahan itu terkirim.
  Future<void> TerapkanSnapshot(SnapshotPesananTerbuka snapshot, DateTime sekarang) => db.transaction(() async {
    final tertunda = await AmbilUuidPesananTertunda();
    final diServer = <String>{};
    for (final p in snapshot.pesanan) {
      diServer.add(p.uuid);
      if (tertunda.contains(p.uuid)) {
        continue;
      }
      await db
          .into(db.pesananTerbuka)
          .insertOnConflictUpdate(
            PesananTerbukaCompanion.insert(
              Uuid: p.uuid,
              Nomor: p.nomor,
              UuidMeja: Value(p.uuidMeja),
              NamaMeja: Value(p.namaMeja),
              Label: Value(p.label),
              JumlahTamu: Value(p.jumlahTamu),
              DibukaOleh: Value(p.dibukaOleh),
              DibukaPada: p.dibukaPada ?? sekarang,
              Status: StatusPesananMeja.terbuka,
              DikunciBayar: Value(p.dikunciBayar),
              MintaBillPada: Value(p.mintaBillPada),
              Baris: SusunJsonBaris(p.baris.map(BarisPesananMeja.DariServer).toList()),
              DiubahPada: sekarang,
            ),
          );
    }
    // Ditutup (dibayar/dibatalkan) di server, termasuk oleh perangkat lain: hapus dari layar. Pesanan yang ditutup di
    // perangkat ini tetap tersembunyi karena `Penjualan.Buat`/`Batal`-nya masih tertunda (dilewati di atas).
    final ditutup = snapshot.ditutup.map((d) => d.uuid).toList();
    if (ditutup.isNotEmpty) {
      await (db.delete(db.pesananTerbuka)..where((p) => p.Uuid.isIn(ditutup))).go();
    }
    final lokal = await (db.select(db.pesananTerbuka)..where((p) => p.Status.equals(StatusPesananMeja.terbuka))).get();
    final usang = [
      for (final p in lokal)
        if (!diServer.contains(p.Uuid) && !tertunda.contains(p.Uuid)) p.Uuid,
    ];
    if (usang.isNotEmpty) {
      await (db.delete(db.pesananTerbuka)..where((p) => p.Uuid.isIn(usang))).go();
    }
    // Pesanan yang sudah ditutup di perangkat ini dan perubahannya sudah terkirim tidak perlu disimpan lagi.
    final tutupLokal = await (db.select(
      db.pesananTerbuka,
    )..where((p) => p.Status.equals(StatusPesananMeja.terbuka).not())).get();
    final selesai = [
      for (final p in tutupLokal)
        if (!tertunda.contains(p.Uuid)) p.Uuid,
    ];
    if (selesai.isNotEmpty) {
      await (db.delete(db.pesananTerbuka)..where((p) => p.Uuid.isIn(selesai))).go();
    }
    await _TerapkanMejaPerluDibersihkan(snapshot, tertunda, tutupLokal);
  });

  // Status layanan meja (K-12) ---------------------------------------------------------------------------------------

  static const String jenisMejaBersih = 'Meja.Bersih';

  /// Meja yang perlu dibersihkan per Uuid meja.
  Stream<Map<String, DateTime>> PantauMejaPerluDibersihkan() =>
      db.select(db.mejaPerluDibersihkan).watch().map((daftar) => {for (final m in daftar) m.UuidMeja: m.Sejak});

  /// Hapus tanda meja + outbox `Meja.Bersih` dalam satu transaksi.
  Future<void> TandaiBersih(String uuidMeja, ItemOutbox outbox, DateTime sekarang) => db.transaction(() async {
    await (db.delete(db.mejaPerluDibersihkan)..where((m) => m.UuidMeja.equals(uuidMeja))).go();
    await repositoriKasir.TambahOutbox(outbox, sekarang);
  });

  /// Uuid meja dengan `Meja.Bersih` yang belum terkirim.
  Future<Set<String>> AmbilUuidMejaBersihTertunda() async {
    final baris = await (db.select(db.outbox)..where((o) => o.Jenis.equals(jenisMejaBersih))).get();
    return {
      for (final b in baris)
        if (jsonDecode(b.Data) case {'UuidMeja': final String uuid}) uuid,
    };
  }

  /// Daftar server menggantikan salinan lokal, kecuali: meja yang `Meja.Bersih`-nya belum terkirim tetap bersih, dan
  /// meja yang pesanannya baru dibayar di perangkat ini (penjualan belum terkirim) tetap perlu dibersihkan.
  Future<void> _TerapkanMejaPerluDibersihkan(
    SnapshotPesananTerbuka snapshot,
    Set<String> tertunda,
    List<BarisPesananTerbuka> tutupLokal,
  ) async {
    final bersihTertunda = await AmbilUuidMejaBersihTertunda();
    final dibayarLokal = {
      for (final p in tutupLokal)
        if (tertunda.contains(p.Uuid) && p.Status == StatusPesananMeja.dibayar && p.UuidMeja != null) p.UuidMeja!,
    };
    final lokal = await db.select(db.mejaPerluDibersihkan).get();
    final hasil = <String, DateTime>{
      for (final m in lokal)
        if (dibayarLokal.contains(m.UuidMeja)) m.UuidMeja: m.Sejak,
    };
    for (final m in snapshot.mejaPerluDibersihkan) {
      final ada = hasil[m.uuidMeja];
      if (ada == null || ada.isBefore(m.sejak)) {
        hasil[m.uuidMeja] = m.sejak;
      }
    }
    hasil.removeWhere((uuid, _) => bersihTertunda.contains(uuid));
    await db.delete(db.mejaPerluDibersihkan).go();
    await db.batch((b) {
      b.insertAll(db.mejaPerluDibersihkan, [
        for (final e in hasil.entries) MejaPerluDibersihkanCompanion.insert(UuidMeja: e.key, Sejak: e.value),
      ]);
    });
  }
}
