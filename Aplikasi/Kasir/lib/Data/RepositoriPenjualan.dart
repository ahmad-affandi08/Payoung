import 'package:drift/drift.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';

import 'BasisData/BasisDataKasir.dart';
import 'PesananMeja.dart';
import 'RepositoriKasir.dart';

/// Dokumen penjualan siap simpan: header, detail, pembayaran, item outbox `Penjualan.Buat`, dan pesanan terbuka yang
/// ditutup pembayaran ini (mode meja; null = penjualan biasa).
class DokumenPenjualan {
  const DokumenPenjualan({
    required this.penjualan,
    required this.detail,
    required this.pembayaran,
    required this.outbox,
    this.uuidPesananTerbuka,
  });

  final PenjualanCompanion penjualan;
  final List<PenjualanDetailCompanion> detail;
  final List<PenjualanPembayaranCompanion> pembayaran;
  final ItemOutbox outbox;
  final String? uuidPesananTerbuka;
}

/// Status penjualan lokal (sama dengan server, keputusan implementasi F-09 v1.47): `Lunas → Void | DireturSebagian |
/// Diretur`, `DireturSebagian → Diretur`.
abstract final class StatusPenjualanLokal {
  static const String lunas = 'Lunas';
  static const String divoid = 'Void';
  static const String direturSebagian = 'DireturSebagian';
  static const String diretur = 'Diretur';
}

/// Dokumen retur siap simpan: header, baris, refund, item outbox `ReturPenjualan.Buat`, dan status baru penjualan
/// asal bila penjualan itu ada di perangkat ini (null = tidak diubah).
class DokumenRetur {
  const DokumenRetur({
    required this.retur,
    required this.detail,
    required this.pembayaran,
    required this.outbox,
    this.statusPenjualanAsal,
  });

  final ReturPenjualanCompanion retur;
  final List<ReturPenjualanDetailCompanion> detail;
  final List<ReturPenjualanPembayaranCompanion> pembayaran;
  final ItemOutbox outbox;
  final String? statusPenjualanAsal;
}

/// Baris riwayat retur perangkat beserta status sinkronnya.
class RiwayatRetur {
  const RiwayatRetur({required this.retur, required this.status, this.pesanGalat});

  final BarisReturPenjualan retur;
  final StatusSinkronPenjualan status;
  final String? pesanGalat;
}

/// Status sinkron satu penjualan, diturunkan dari outbox.
enum StatusSinkronPenjualan { Terkirim, BelumTerkirim, PerluTindakan }

/// Baris riwayat transaksi perangkat.
class RiwayatPenjualan {
  const RiwayatPenjualan({required this.penjualan, required this.status, required this.metode, this.pesanGalat});

  final BarisPenjualan penjualan;
  final StatusSinkronPenjualan status;

  /// Nama metode pembayaran (urut simpan).
  final List<String> metode;
  final String? pesanGalat;
}

/// Penyimpanan penjualan lokal (Rincian F-07c). Nomor urut, penjualan, detail, pembayaran, dan outbox ditulis dalam
/// satu transaksi SQLite (PRD §18.3 no. 3): bila salah satu gagal, tidak ada yang tersimpan dan nomor tidak terpakai.
class RepositoriPenjualan {
  RepositoriPenjualan(this.db, this.repositoriKasir);

  final BasisDataKasir db;
  final RepositoriKasir repositoriKasir;

  /// Ambil nomor urut berikutnya ([tanggal] `YYMMDD`), susun dokumen dengan nomor itu, lalu simpan semuanya.
  Future<DokumenPenjualan> SimpanPenjualan({
    required String kodePerangkat,
    required String tanggal,
    required DateTime sekarang,
    required DokumenPenjualan Function(int urut) susun,
  }) => db.transaction(() async {
    final lama = await (db.select(
      db.nomorUrutPenjualan,
    )..where((n) => n.KodePerangkat.equals(kodePerangkat) & n.Tanggal.equals(tanggal))).getSingleOrNull();
    final urut = (lama?.Terakhir ?? 0) + 1;
    await db
        .into(db.nomorUrutPenjualan)
        .insertOnConflictUpdate(
          NomorUrutPenjualanCompanion.insert(KodePerangkat: kodePerangkat, Tanggal: tanggal, Terakhir: urut),
        );

    final dokumen = susun(urut);
    await db.into(db.penjualan).insert(dokumen.penjualan);
    await db.batch((b) {
      b.insertAll(db.penjualanDetail, dokumen.detail);
      b.insertAll(db.penjualanPembayaran, dokumen.pembayaran);
    });
    await repositoriKasir.TambahOutbox(dokumen.outbox, sekarang);
    final uuidPesanan = dokumen.uuidPesananTerbuka;
    if (uuidPesanan != null) {
      await (db.update(db.pesananTerbuka)..where((p) => p.Uuid.equals(uuidPesanan))).write(
        PesananTerbukaCompanion(Status: const Value(StatusPesananMeja.dibayar), DiubahPada: Value(sekarang)),
      );
      // K-12: meja yang tagihannya dibayar perlu dibersihkan sebelum dipakai tamu berikutnya.
      final pesanan = await (db.select(db.pesananTerbuka)..where((p) => p.Uuid.equals(uuidPesanan))).getSingleOrNull();
      if (pesanan?.UuidMeja case final uuidMeja?) {
        await db
            .into(db.mejaPerluDibersihkan)
            .insertOnConflictUpdate(MejaPerluDibersihkanCompanion.insert(UuidMeja: uuidMeja, Sejak: sekarang));
      }
    }
    return dokumen;
  });

  /// K-16: produk yang paling sering dijual di perangkat ini sejak [sejakTanggal] (`YYYY-MM-DD`, tanggal bisnis),
  /// diurutkan menurut jumlah transaksi yang memuatnya (bukan kuantitas, agar tidak menjumlah desimal di SQLite) lalu
  /// penjualan terakhir. Penjualan void dilewati. Ikut berubah saat penjualan baru tersimpan.
  Stream<List<String>> PantauProdukTerlaris({required String sejakTanggal, int batas = 12}) => db
      .customSelect(
        'SELECT d.UuidProduk AS Uuid, COUNT(DISTINCT d.UuidPenjualan) AS Kali, MAX(p.DibuatPada) AS Terakhir '
        'FROM PenjualanDetail d JOIN Penjualan p ON p.Uuid = d.UuidPenjualan '
        'WHERE p.Status != ? AND p.TanggalBisnis >= ? '
        'GROUP BY d.UuidProduk ORDER BY Kali DESC, Terakhir DESC LIMIT ?',
        variables: [
          Variable.withString(StatusPenjualanLokal.divoid),
          Variable.withString(sejakTanggal),
          Variable.withInt(batas),
        ],
        readsFrom: {db.penjualan, db.penjualanDetail},
      )
      .watch()
      .map((baris) => [for (final b in baris) b.read<String>('Uuid')]);

  /// BR-05.2: berubah setiap penjualan/detail/outbox ditulis (simpan, void, kirim, tolak). Dipakai layanan stok
  /// tersedia untuk menghitung ulang penjualan lokal yang belum tercakup salinan stok server.
  Stream<void> PantauPerubahanPenjualan() =>
      db.customSelect('SELECT 1', readsFrom: {db.penjualan, db.penjualanDetail, db.outbox}).watch().map((_) {});

  /// BR-05.2: Uuid penjualan perangkat ini yang masih menunggu di outbox (belum terkirim atau perlu tindakan).
  Future<Set<String>> AmbilUuidPenjualanTertunda() async {
    final kueri = db.select(db.penjualan).join([innerJoin(db.outbox, db.outbox.Uuid.equalsExp(db.penjualan.Uuid))]);
    return {for (final b in await kueri.get()) b.readTable(db.penjualan).Uuid};
  }

  /// BR-05.2: baris detail penjualan lokal (bukan `Void`) yang **mungkin belum tercakup** salinan stok server, yaitu
  /// salah satu dari: dibuat pada/setelah [sejak] (jam perangkat), masih di outbox sekarang (belum terkirim atau
  /// ditolak), atau termasuk [uuidTertundaSaatAmbil] (masih di outbox ketika salinan diminta, jadi belum dilihat
  /// server walau sekarang sudah terkirim). Tiap baris membawa konversi satuan jualnya ke satuan dasar (null = 1).
  Future<List<({String uuidProduk, String? uuidProdukSatuan, String jumlah, String? konversiKeDasar})>>
  AmbilDetailBelumTercakup({required DateTime sejak, Set<String> uuidTertundaSaatAmbil = const {}}) async {
    final belumTercakup = <Expression<bool>>[
      db.penjualan.DibuatPada.isBiggerOrEqualValue(sejak.toUtc()),
      db.outbox.Uuid.isNotNull(),
      if (uuidTertundaSaatAmbil.isNotEmpty) db.penjualan.Uuid.isIn(uuidTertundaSaatAmbil),
    ];
    final kueri = db.select(db.penjualanDetail).join([
      innerJoin(db.penjualan, db.penjualan.Uuid.equalsExp(db.penjualanDetail.UuidPenjualan)),
      leftOuterJoin(db.outbox, db.outbox.Uuid.equalsExp(db.penjualan.Uuid)),
      leftOuterJoin(db.produkSatuan, db.produkSatuan.Uuid.equalsExp(db.penjualanDetail.UuidProdukSatuan)),
    ])..where(db.penjualan.Status.equals(StatusPenjualanLokal.divoid).not() & belumTercakup.reduce((a, b) => a | b));
    return [
      for (final b in await kueri.get())
        (
          uuidProduk: b.readTable(db.penjualanDetail).UuidProduk,
          uuidProdukSatuan: b.readTable(db.penjualanDetail).UuidProdukSatuan,
          jumlah: b.readTable(db.penjualanDetail).Jumlah,
          konversiKeDasar: b.readTableOrNull(db.produkSatuan)?.KonversiKeDasar,
        ),
    ];
  }

  /// P-04: jumlah penjualan lokal yang **belum tercakup hitungan kuota transaksi server**, yaitu dibuat pada/setelah
  /// [sejak] (jam perangkat), masih di outbox sekarang, atau termasuk [uuidTertundaSaatAmbil] (masih di outbox ketika
  /// konfigurasi dibaca). Semua status ikut dihitung, termasuk `Void`, sama dengan server: membatalkan transaksi tidak
  /// mengembalikan kuota. [tanggalBisnisAwal]/[tanggalBisnisAkhir] (`YYYY-MM-DD`, inklusif) membatasi pada bulan atau hari
  /// yang kuotanya dihitung, supaya penjualan tertunda milik hari/bulan lain tidak ikut.
  Future<int> HitungPenjualanBelumTercakupKuota({
    required DateTime sejak,
    Set<String> uuidTertundaSaatAmbil = const {},
    String? tanggalBisnisAwal,
    String? tanggalBisnisAkhir,
  }) async {
    final belumTercakup = <Expression<bool>>[
      db.penjualan.DibuatPada.isBiggerOrEqualValue(sejak.toUtc()),
      db.outbox.Uuid.isNotNull(),
      if (uuidTertundaSaatAmbil.isNotEmpty) db.penjualan.Uuid.isIn(uuidTertundaSaatAmbil),
    ];
    final jumlah = db.penjualan.Uuid.count();
    final kueri =
        db.selectOnly(db.penjualan).join([leftOuterJoin(db.outbox, db.outbox.Uuid.equalsExp(db.penjualan.Uuid))])
          ..addColumns([jumlah])
          ..where(
            [
              belumTercakup.reduce((a, b) => a | b),
              if (tanggalBisnisAwal != null) db.penjualan.TanggalBisnis.isBiggerOrEqualValue(tanggalBisnisAwal),
              if (tanggalBisnisAkhir != null) db.penjualan.TanggalBisnis.isSmallerOrEqualValue(tanggalBisnisAkhir),
            ].reduce((a, b) => a & b),
          );
    return (await kueri.map((r) => r.read(jumlah) ?? 0).getSingle());
  }

  Future<BarisPenjualan?> CariPenjualan(String uuid) =>
      (db.select(db.penjualan)..where((p) => p.Uuid.equals(uuid))).getSingleOrNull();

  /// Penjualan terakhir yang dibuat perangkat ini (untuk "Cetak ulang struk terakhir" di layar Jual).
  Future<BarisPenjualan?> AmbilPenjualanTerakhir() =>
      (db.select(db.penjualan)
            ..orderBy([(p) => OrderingTerm.desc(p.DibuatPada)])
            ..limit(1))
          .getSingleOrNull();

  Future<List<BarisPenjualanDetail>> AmbilDetail(String uuidPenjualan) =>
      (db.select(db.penjualanDetail)
            ..where((d) => d.UuidPenjualan.equals(uuidPenjualan))
            ..orderBy([(d) => OrderingTerm.asc(d.Urutan)]))
          .get();

  Future<List<BarisPenjualanPembayaran>> AmbilPembayaran(String uuidPenjualan) =>
      (db.select(db.penjualanPembayaran)..where((p) => p.UuidPenjualan.equals(uuidPenjualan))).get();

  /// Riwayat penjualan perangkat pada [tanggalBisnis] (`YYYY-MM-DD`), terbaru dulu, dengan status sinkron.
  Stream<List<RiwayatPenjualan>> PantauRiwayat(String tanggalBisnis) {
    final kueri = db.select(db.penjualan).join([leftOuterJoin(db.outbox, db.outbox.Uuid.equalsExp(db.penjualan.Uuid))])
      ..where(db.penjualan.TanggalBisnis.equals(tanggalBisnis))
      ..orderBy([OrderingTerm.desc(db.penjualan.DibuatPada)]);
    return kueri.watch().asyncMap((baris) async {
      final uuid = baris.map((b) => b.readTable(db.penjualan).Uuid).toList();
      final pembayaran = uuid.isEmpty
          ? const <BarisPenjualanPembayaran>[]
          : await (db.select(db.penjualanPembayaran)..where((p) => p.UuidPenjualan.isIn(uuid))).get();
      return [
        for (final b in baris)
          () {
            final p = b.readTable(db.penjualan);
            final o = b.readTableOrNull(db.outbox);
            return RiwayatPenjualan(
              penjualan: p,
              status: _StatusDariOutbox(o),
              metode: pembayaran.where((x) => x.UuidPenjualan == p.Uuid).map((x) => x.NamaMetode).toList(),
              pesanGalat: o?.PesanGalat,
            );
          }(),
      ];
    });
  }

  /// Tunai bersih penjualan sebuah shift: Σ pembayaran tunai − Σ kembalian (kas di laci, F-06).
  Stream<Uang> PantauTunaiBersihShift(String uuidShift) {
    final kueri = db.select(db.penjualan)..where((p) => p.UuidShift.equals(uuidShift));
    return kueri.watch().asyncMap((daftar) async {
      if (daftar.isEmpty) {
        return Uang.Nol();
      }
      final tunai = await (db.select(
        db.penjualanPembayaran,
      )..where((b) => b.UuidPenjualan.isIn(daftar.map((p) => p.Uuid)) & b.Jenis.equals('Tunai'))).get();
      final diterima = tunai.fold(Uang.Nol(), (total, b) => total.Tambah(Uang.Dari(b.Jumlah)));
      return daftar.fold<Uang>(diterima, (total, p) => total.Kurangi(Uang.Dari(p.Kembalian)));
    });
  }

  /// Semua penjualan sebuah shift beserta detail & pembayarannya (laporan shift X/Z, F-11).
  Future<
    ({List<BarisPenjualan> penjualan, List<BarisPenjualanDetail> detail, List<BarisPenjualanPembayaran> pembayaran})
  >
  AmbilDokumenShift(String uuidShift) async {
    final penjualan = await (db.select(db.penjualan)..where((p) => p.UuidShift.equals(uuidShift))).get();
    if (penjualan.isEmpty) {
      return (
        penjualan: penjualan,
        detail: const <BarisPenjualanDetail>[],
        pembayaran: const <BarisPenjualanPembayaran>[],
      );
    }
    final uuid = penjualan.map((p) => p.Uuid).toList();
    return (
      penjualan: penjualan,
      detail: await (db.select(db.penjualanDetail)..where((d) => d.UuidPenjualan.isIn(uuid))).get(),
      pembayaran: await (db.select(db.penjualanPembayaran)..where((b) => b.UuidPenjualan.isIn(uuid))).get(),
    );
  }

  // Void & retur (F-09 fase 1) ----------------------------------------------------------------------------------------

  /// Void: status penjualan `Lunas → Void` + dokumen `VoidPenjualan` + outbox `Penjualan.Void` dalam satu transaksi.
  /// Penjualan yang sudah tidak `Lunas` (di-void/diretur di antaranya) → galat dan tidak ada yang tersimpan.
  Future<void> SimpanVoid(VoidPenjualanCompanion dokumen, ItemOutbox item, DateTime sekarang) =>
      db.transaction(() async {
        final diubah =
            await (db.update(db.penjualan)..where(
                  (p) => p.Uuid.equals(dokumen.UuidPenjualan.value) & p.Status.equals(StatusPenjualanLokal.lunas),
                ))
                .write(const PenjualanCompanion(Status: Value(StatusPenjualanLokal.divoid)));
        if (diubah != 1) {
          throw StateError('Penjualan ${dokumen.UuidPenjualan.value} tidak berstatus Lunas.');
        }
        await db.into(db.voidPenjualan).insert(dokumen);
        await repositoriKasir.TambahOutbox(item, sekarang);
      });

  Future<BarisVoidPenjualan?> CariVoid(String uuidPenjualan) =>
      (db.select(db.voidPenjualan)..where((v) => v.UuidPenjualan.equals(uuidPenjualan))).getSingleOrNull();

  Future<BarisPenjualan?> CariPenjualanNomor(String nomor) =>
      (db.select(db.penjualan)..where((p) => p.Nomor.equals(nomor))).getSingleOrNull();

  /// Penjualan perangkat ini (bukan void) yang nomornya memuat [kata], terbaru dulu; [kata] kosong = terbaru. Dipakai
  /// layar retur supaya struk bisa dipilih tanpa mengetik nomor utuh, juga saat server tidak menjawab.
  Future<List<BarisPenjualan>> CariPenjualanNomorMirip(String kata, {int batas = 10}) {
    // Karakter pengganti LIKE dibuang dari ketikan supaya "%" atau "_" tidak mencocokkan semua nomor.
    final aman = kata.replaceAll('%', '').replaceAll('_', '');
    return (db.select(db.penjualan)
          ..where(
            (p) =>
                p.Status.equals(StatusPenjualanLokal.divoid).not() &
                (aman.isEmpty ? const Constant(true) : p.Nomor.like('%$aman%')),
          )
          ..orderBy([(p) => OrderingTerm.desc(p.DibuatPada)])
          ..limit(batas))
        .get();
  }

  /// Apakah dokumen [uuid] masih menunggu di outbox (belum terkirim atau perlu tindakan).
  Future<bool> CekMasihDiOutbox(String uuid) async =>
      (await (db.select(db.outbox)..where((o) => o.Uuid.equals(uuid))).getSingleOrNull()) != null;

  /// Apakah ada retur lokal atas [uuidPenjualanAsal] yang belum diterima server (masih di outbox).
  Future<bool> CekReturBelumTerkirim(String uuidPenjualanAsal) async {
    final kueri = db.select(db.returPenjualan).join([
      innerJoin(db.outbox, db.outbox.Uuid.equalsExp(db.returPenjualan.Uuid)),
    ])..where(db.returPenjualan.UuidPenjualanAsal.equals(uuidPenjualanAsal));
    return (await kueri.get()).isNotEmpty;
  }

  /// K28: Σ `TotalRefund` retur tanpa struk (tanpa penjualan asal) perangkat ini pada [tanggalBisnis] `YYYY-MM-DD`.
  Future<Uang> HitungReturTanpaStrukHari(String tanggalBisnis) async {
    final baris = await (db.select(
      db.returPenjualan,
    )..where((r) => r.UuidPenjualanAsal.equals('') & r.TanggalBisnis.equals(tanggalBisnis))).get();
    return baris.fold<Uang>(Uang.Nol(), (total, r) => total.Tambah(Uang.Dari(r.TotalRefund)));
  }

  /// Ambil nomor urut retur berikutnya ([tanggal] `YYMMDD`), susun dokumen dengan nomor itu, lalu simpan retur + baris
  /// + refund + status penjualan asal lokal + outbox dalam satu transaksi (nomor tidak terpakai bila gagal).
  Future<DokumenRetur> SimpanRetur({
    required String kodePerangkat,
    required String tanggal,
    required DateTime sekarang,
    required DokumenRetur Function(int urut) susun,
  }) => db.transaction(() async {
    final lama = await (db.select(
      db.nomorUrutReturPenjualan,
    )..where((n) => n.KodePerangkat.equals(kodePerangkat) & n.Tanggal.equals(tanggal))).getSingleOrNull();
    final urut = (lama?.Terakhir ?? 0) + 1;
    await db
        .into(db.nomorUrutReturPenjualan)
        .insertOnConflictUpdate(
          NomorUrutReturPenjualanCompanion.insert(KodePerangkat: kodePerangkat, Tanggal: tanggal, Terakhir: urut),
        );

    final dokumen = susun(urut);
    await db.into(db.returPenjualan).insert(dokumen.retur);
    await db.batch((b) {
      b.insertAll(db.returPenjualanDetail, dokumen.detail);
      b.insertAll(db.returPenjualanPembayaran, dokumen.pembayaran);
    });
    final status = dokumen.statusPenjualanAsal;
    if (status != null) {
      await (db.update(db.penjualan)..where(
            (p) =>
                p.Uuid.equals(dokumen.retur.UuidPenjualanAsal.value) &
                p.Status.isIn([StatusPenjualanLokal.lunas, StatusPenjualanLokal.direturSebagian]),
          ))
          .write(PenjualanCompanion(Status: Value(status)));
    }
    await repositoriKasir.TambahOutbox(dokumen.outbox, sekarang);
    return dokumen;
  });

  Future<BarisReturPenjualan?> CariRetur(String uuidRetur) =>
      (db.select(db.returPenjualan)..where((r) => r.Uuid.equals(uuidRetur))).getSingleOrNull();

  Future<List<BarisReturPenjualanDetail>> AmbilDetailRetur(String uuidRetur) =>
      (db.select(db.returPenjualanDetail)..where((d) => d.UuidReturPenjualan.equals(uuidRetur))).get();

  Future<List<BarisReturPenjualanPembayaran>> AmbilPembayaranRetur(String uuidRetur) =>
      (db.select(db.returPenjualanPembayaran)..where((d) => d.UuidReturPenjualan.equals(uuidRetur))).get();

  /// Void (shift penjualan) & retur (shift refund) sebuah shift, untuk laporan X/Z dan kas seharusnya.
  Future<({List<BarisVoidPenjualan> void_, List<BarisReturPenjualan> retur})> AmbilVoidReturShift(
    String uuidShift,
  ) async => (
    void_: await (db.select(db.voidPenjualan)..where((v) => v.UuidShift.equals(uuidShift))).get(),
    retur: await (db.select(db.returPenjualan)..where((r) => r.UuidShift.equals(uuidShift))).get(),
  );

  /// Refund tunai yang keluar dari laci [uuidShift]: Σ `VoidPenjualan.RefundTunai` + Σ `ReturPenjualan.RefundTunai`
  /// (sama dengan server `RingkasanPenjualanShift`). Dijumlah di Dart agar tetap desimal eksak.
  Stream<Uang> PantauRefundTunaiShift(String uuidShift) => db
      .customSelect(
        'SELECT RefundTunai FROM VoidPenjualan WHERE UuidShift = ?1 '
        'UNION ALL SELECT RefundTunai FROM ReturPenjualan WHERE UuidShift = ?1',
        variables: [Variable.withString(uuidShift)],
        readsFrom: {db.voidPenjualan, db.returPenjualan},
      )
      .watch()
      .map((baris) => baris.fold(Uang.Nol(), (t, b) => t.Tambah(Uang.Dari(b.read<String>('RefundTunai')))));

  /// Jumlah dokumen void + retur sebuah shift (pemicu hitung ulang laporan shift).
  Stream<int> PantauJumlahVoidReturShift(String uuidShift) => db
      .customSelect(
        'SELECT (SELECT COUNT(*) FROM VoidPenjualan WHERE UuidShift = ?1) + '
        '(SELECT COUNT(*) FROM ReturPenjualan WHERE UuidShift = ?1) AS Jumlah',
        variables: [Variable.withString(uuidShift)],
        readsFrom: {db.voidPenjualan, db.returPenjualan},
      )
      .watchSingle()
      .map((b) => b.read<int>('Jumlah'));

  /// Retur perangkat pada [tanggalBisnis] (`YYYY-MM-DD`), terbaru dulu, dengan status sinkron.
  Stream<List<RiwayatRetur>> PantauReturTanggal(String tanggalBisnis) {
    final kueri =
        db.select(db.returPenjualan).join([leftOuterJoin(db.outbox, db.outbox.Uuid.equalsExp(db.returPenjualan.Uuid))])
          ..where(db.returPenjualan.TanggalBisnis.equals(tanggalBisnis))
          ..orderBy([OrderingTerm.desc(db.returPenjualan.DibuatPada)]);
    return kueri.watch().map(
      (baris) => [
        for (final b in baris)
          RiwayatRetur(
            retur: b.readTable(db.returPenjualan),
            status: _StatusDariOutbox(b.readTableOrNull(db.outbox)),
            pesanGalat: b.readTableOrNull(db.outbox)?.PesanGalat,
          ),
      ],
    );
  }

  static StatusSinkronPenjualan _StatusDariOutbox(BarisOutbox? o) => o == null
      ? StatusSinkronPenjualan.Terkirim
      : o.Status == StatusOutbox.perluTindakan
      ? StatusSinkronPenjualan.PerluTindakan
      : StatusSinkronPenjualan.BelumTerkirim;

  Future<int> HitungPesananTertahan() async {
    final jumlah = db.pesananTertahan.Uuid.count();
    return (await (db.selectOnly(db.pesananTertahan)..addColumns([jumlah])).getSingle()).read(jumlah) ?? 0;
  }

  // Pesanan tertahan (lokal, tidak dikirim) ----------------------------------------------------------------------------

  Future<void> SimpanPesananTertahan(PesananTertahanCompanion pesanan) =>
      db.into(db.pesananTertahan).insertOnConflictUpdate(pesanan);

  Stream<List<BarisPesananTertahan>> PantauPesananTertahan() =>
      (db.select(db.pesananTertahan)..orderBy([(p) => OrderingTerm.asc(p.DibuatPada)])).watch();

  Future<BarisPesananTertahan?> CariPesananTertahan(String uuid) =>
      (db.select(db.pesananTertahan)..where((p) => p.Uuid.equals(uuid))).getSingleOrNull();

  Future<void> HapusPesananTertahan(String uuid) =>
      (db.delete(db.pesananTertahan)..where((p) => p.Uuid.equals(uuid))).go();
}
