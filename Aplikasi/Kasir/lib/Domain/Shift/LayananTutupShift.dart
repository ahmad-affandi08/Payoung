import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Data/RepositoriDeposit.dart';
import '../../Data/RepositoriKasir.dart';
import '../../Data/RepositoriPenjualan.dart';
import '../../Data/RepositoriPreOrder.dart';
import '../GalatKasir.dart';
import '../Sesi/StafLokal.dart';
import 'LayananShift.dart';

/// Total satu metode pembayaran di laporan shift (tunai = diterima − kembalian).
class MetodeLaporanShift {
  const MetodeLaporanShift({required this.uuid, required this.jenis, required this.nama, required this.jumlah});

  final String uuid;
  final String jenis;
  final String nama;
  final Uang jumlah;

  bool get tunai => jenis == LayananTutupShift.jenisTunai;
}

/// D-40: satu produk terlaris shift (jumlah terjual & total baris, tanpa penjualan yang di-void).
class ProdukLaporanShift {
  const ProdukLaporanShift({required this.nama, required this.jumlah, required this.total});

  final String nama;
  final Kuantitas jumlah;
  final Uang total;
}

/// Laporan shift X (berjalan) / Z (setelah tutup) dari data di perangkat (Rincian F-11). Angka penjualan tanpa
/// penjualan yang di-void; void & retur dilaporkan terpisah (F-09 fase 1): void = penjualan shift ini berstatus `Void`,
/// retur = dokumen retur yang refund-nya keluar dari laci shift ini (sama dengan server `RingkasanPenjualanShift`).
class LaporanShift {
  const LaporanShift({
    required this.shift,
    required this.jumlahTransaksi,
    required this.penjualanKotor,
    required this.totalDiskon,
    required this.totalPajak,
    required this.biayaLayanan,
    required this.pembulatan,
    required this.totalAkhir,
    required this.perMetode,
    required this.tunaiMasukBersih,
    required this.refundTunai,
    required this.jumlahVoid,
    required this.nominalVoid,
    required this.jumlahRetur,
    required this.nominalRetur,
    required this.kasMasuk,
    required this.kasKeluar,
    required this.setoran,
    this.jumlahUangMuka = 0,
    this.nominalUangMuka,
    this.jumlahIsiDeposit = 0,
    this.nominalIsiDeposit,
    this.terlaris = const [],
    this.perJam = const {},
  });

  /// Jumlah produk terlaris yang dimuat [terlaris].
  static const int batasTerlaris = 5;

  /// D-40: produk terlaris shift ini, urut jumlah terjual (maks. [batasTerlaris]).
  final List<ProdukLaporanShift> terlaris;

  /// D-40: total dibayar pelanggan per jam lokal (0–23) dari penjualan yang dihitung; jam tanpa penjualan tidak ada.
  final Map<int, Uang> perJam;

  final BarisShift shift;

  /// F-12 bagian 2: pre-order yang uang mukanya diterima di shift ini (DP sudah ikut [perMetode] & [tunaiMasukBersih]).
  final int jumlahUangMuka;
  final Uang? nominalUangMuka;

  /// F-16d bagian 1: isi deposit pelanggan yang diterima di shift ini (uangnya masuk laci/rekening shift).
  final int jumlahIsiDeposit;
  final Uang? nominalIsiDeposit;
  final int jumlahTransaksi;
  final Uang penjualanKotor;
  final Uang totalDiskon;
  final Uang totalPajak;
  final Uang biayaLayanan;
  final Uang pembulatan;
  final Uang totalAkhir;
  final List<MetodeLaporanShift> perMetode;
  final Uang tunaiMasukBersih;
  final Uang refundTunai;
  final int jumlahVoid;
  final Uang nominalVoid;
  final int jumlahRetur;
  final Uang nominalRetur;
  final Uang kasMasuk;
  final Uang kasKeluar;
  final Uang setoran;

  Uang get kasAwal => Uang.Dari(shift.KasAwal);

  Uang get penjualanBersih => penjualanKotor.Kurangi(totalDiskon);

  /// D-40: rata-rata dibayar per transaksi (dibulatkan ke rupiah); nol bila belum ada transaksi.
  Uang get rataRataTransaksi => jumlahTransaksi == 0
      ? Uang.Nol()
      : Uang.DariDesimal(
          Decimal.fromBigInt(
            BagiBulat(
              totalAkhir.KeDesimal().shift(Uang.skala).toBigInt(),
              BigInt.from(jumlahTransaksi * 100),
              ModePembulatan.SetengahMenjauhiNol,
            ),
          ),
        );

  bool get tertutup => shift.Status == StatusShiftLokal.tertutup;

  /// Kas seharusnya dari data saat ini (lihat [LayananTutupShift.HitungKasSeharusnya]).
  Uang get kasSeharusnya => LayananTutupShift.HitungKasSeharusnya(
    kasAwal: kasAwal,
    tunaiMasukBersih: tunaiMasukBersih,
    kasMasuk: kasMasuk,
    kasKeluar: kasKeluar,
    setoran: setoran,
    refundTunai: refundTunai,
  );

  /// Total non-tunai sistem per metode (pencocokan slip).
  Uang AmbilJumlahMetode(String uuid) =>
      perMetode.where((m) => m.uuid == uuid).fold(Uang.Nol(), (t, m) => t.Tambah(m.jumlah));
}

/// Hasil hitung selisih sebelum tutup shift.
class PratinjauTutupShift {
  const PratinjauTutupShift({required this.kasSeharusnya, required this.kasAktual, required this.toleransi});

  final Uang kasSeharusnya;
  final Uang kasAktual;
  final Uang toleransi;

  /// Kas aktual − kas seharusnya (minus = kurang, plus = lebih).
  Uang get selisih => kasAktual.Kurangi(kasSeharusnya);

  Uang get selisihMutlak => selisih.BernilaiNegatif() ? Uang.Nol().Kurangi(selisih) : selisih;

  /// |selisih| > toleransi → wajib alasan + penyetuju ber-izin `shift.selisih.setujui`.
  bool get butuhPersetujuan => selisihMutlak.Bandingkan(toleransi) > 0;
}

/// Tutup shift & laporan shift di perangkat (Rincian F-11), berlaku offline. Aturan sama dengan server agar kasir
/// langsung tahu bila ditolak: pesanan tertahan diselesaikan dulu, BR-06.2 hanya pemilik shift/supervisor di shift
/// bukan bersama, pecahan = kas aktual, |selisih| > `ToleransiSelisihKas` → alasan (≥ 5 karakter) + penyetuju ber-izin
/// `shift.selisih.setujui` (kasir yang sendiri ber-izin menyetujui dirinya, sama dengan diskon F-07). Shift + entri
/// outbox `Shift.Tutup` disimpan dalam satu transaksi SQLite; outbox FIFO mengirimnya setelah penjualan & kas shift.
class LayananTutupShift {
  LayananTutupShift({
    required this.repositori,
    required this.repositoriPenjualan,
    this.repositoriPreOrder,
    this.repositoriDeposit,
    PembuatUlid? ulid,
    DateTime Function()? jam,
  }) : _ulid = ulid ?? PembuatUlid(),
       _jam = jam ?? DateTime.now;

  /// F-12 bagian 2: uang muka pre-order yang diterima di shift; null = tidak dihitung.
  final RepositoriPreOrder? repositoriPreOrder;
  final RepositoriDeposit? repositoriDeposit;

  static const String jenisTunai = 'Tunai';

  /// Status penjualan void lokal (F-09): tidak dihitung sebagai penjualan, tetapi tunainya sempat masuk laci.
  static const String statusVoid = StatusPenjualanLokal.divoid;

  static const int panjangAlasanMinimal = 5;

  final RepositoriKasir repositori;
  final RepositoriPenjualan repositoriPenjualan;
  final PembuatUlid _ulid;
  final DateTime Function() _jam;

  /// Kas seharusnya = kas awal + penjualan tunai bersih + kas masuk − kas keluar − setoran − refund tunai (void &
  /// retur). Sama dengan server (`LaporanShift`).
  static Uang HitungKasSeharusnya({
    required Uang kasAwal,
    required Uang tunaiMasukBersih,
    required Uang kasMasuk,
    required Uang kasKeluar,
    required Uang setoran,
    required Uang refundTunai,
  }) => kasAwal.Tambah(tunaiMasukBersih).Tambah(kasMasuk).Kurangi(kasKeluar).Kurangi(setoran).Kurangi(refundTunai);

  Future<bool> CekTutupButa() async => await repositori.AmbilPengaturan(KunciPengaturan.tutupShiftButa) != '0';

  Future<Uang> AmbilToleransi() async {
    final teks = await repositori.AmbilPengaturan(KunciPengaturan.toleransiSelisihKas);
    return Uang.Dari(teks == null || teks.isEmpty ? DataAwal.toleransiSelisihKasBawaan : teks);
  }

  Future<LaporanShift> SusunLaporan(String uuidShift) async {
    final shift = await repositori.CariShift(uuidShift);
    if (shift == null) {
      throw const GalatKasir('ShiftTidakDitemukan', 'Shift tidak ditemukan di perangkat ini.');
    }
    final dokumen = await repositoriPenjualan.AmbilDokumenShift(uuidShift);
    final mutasi = await repositori.AmbilMutasi(uuidShift);
    final voidRetur = await repositoriPenjualan.AmbilVoidReturShift(uuidShift);

    final dihitung = dokumen.penjualan.where((p) => p.Status != statusVoid).toList();
    final uuidDihitung = {for (final p in dihitung) p.Uuid};
    final kembalian = {for (final p in dokumen.penjualan) p.Uuid: Uang.Dari(p.Kembalian)};

    // Tunai bersih semua penjualan (termasuk yang nanti di-void: uangnya sempat masuk laci).
    var tunaiMasuk = Uang.Nol();
    final perMetode = <String, MetodeLaporanShift>{};
    for (final b in dokumen.pembayaran) {
      final bersih = b.Jenis == jenisTunai
          ? Uang.Dari(b.Jumlah).Kurangi(kembalian[b.UuidPenjualan]!)
          : Uang.Dari(b.Jumlah);
      if (b.Jenis == jenisTunai) {
        tunaiMasuk = tunaiMasuk.Tambah(bersih);
      }
      if (uuidDihitung.contains(b.UuidPenjualan)) {
        final lama = perMetode[b.UuidMetodePembayaran];
        perMetode[b.UuidMetodePembayaran] = MetodeLaporanShift(
          uuid: b.UuidMetodePembayaran,
          jenis: b.Jenis,
          nama: lama?.nama ?? b.NamaMetode,
          jumlah: (lama?.jumlah ?? Uang.Nol()).Tambah(bersih),
        );
      }
    }

    // F-12 bagian 2: uang muka pre-order yang diterima di shift ini masuk laci/rekening (tanpa kembalian).
    final uangMuka = await repositoriPreOrder?.AmbilShift(uuidShift) ?? const <BarisPesananPenjualanLokal>[];
    for (final p in uangMuka) {
      final jumlah = Uang.Dari(p.UangMuka);
      if (p.JenisMetode == jenisTunai) {
        tunaiMasuk = tunaiMasuk.Tambah(jumlah);
      }
      final lama = perMetode[p.UuidMetodePembayaran];
      perMetode[p.UuidMetodePembayaran] = MetodeLaporanShift(
        uuid: p.UuidMetodePembayaran,
        jenis: p.JenisMetode,
        nama: lama?.nama ?? p.NamaMetode,
        jumlah: (lama?.jumlah ?? Uang.Nol()).Tambah(jumlah),
      );
    }

    // F-16d bagian 1: isi deposit yang diterima di shift ini juga masuk laci/rekening (tanpa kembalian).
    final isiDeposit = await repositoriDeposit?.AmbilShift(uuidShift) ?? const <BarisIsiDepositLokal>[];
    for (final i in isiDeposit) {
      final jumlah = Uang.Dari(i.Jumlah);
      if (i.JenisMetode == jenisTunai) {
        tunaiMasuk = tunaiMasuk.Tambah(jumlah);
      }
      final lama = perMetode[i.UuidMetodePembayaran];
      perMetode[i.UuidMetodePembayaran] = MetodeLaporanShift(
        uuid: i.UuidMetodePembayaran,
        jenis: i.JenisMetode,
        nama: lama?.nama ?? i.NamaMetode,
        jumlah: (lama?.jumlah ?? Uang.Nol()).Tambah(jumlah),
      );
    }

    Uang Jumlahkan(Iterable<String> nilai) => nilai.fold(Uang.Nol(), (t, n) => t.Tambah(Uang.Dari(n)));
    Uang JumlahMutasi(String jenis) => Jumlahkan(mutasi.where((m) => m.Jenis == jenis).map((m) => m.Jumlah));
    final void_ = dokumen.penjualan.where((p) => p.Status == statusVoid);
    final metodeUrut = perMetode.values.toList()
      ..sort((a, b) => a.tunai == b.tunai ? a.nama.compareTo(b.nama) : (a.tunai ? -1 : 1));

    // D-40: produk terlaris & penjualan per jam, dari penjualan yang dihitung saja (void tidak ikut).
    final perProduk = <String, ProdukLaporanShift>{};
    for (final d in dokumen.detail.where((d) => uuidDihitung.contains(d.UuidPenjualan))) {
      final lama = perProduk[d.UuidProduk];
      perProduk[d.UuidProduk] = ProdukLaporanShift(
        nama: lama?.nama ?? d.NamaProduk,
        jumlah: (lama?.jumlah ?? Kuantitas.Nol()).Tambah(Kuantitas.Dari(d.Jumlah)),
        total: (lama?.total ?? Uang.Nol()).Tambah(Uang.Dari(d.TotalBaris)),
      );
    }
    final terlaris = perProduk.values.toList()
      ..sort((a, b) {
        final banding = b.jumlah.compareTo(a.jumlah);
        return banding != 0 ? banding : b.total.Bandingkan(a.total);
      });
    final perJam = <int, Uang>{};
    for (final p in dihitung) {
      final jam = p.DibuatPada.toLocal().hour;
      perJam[jam] = (perJam[jam] ?? Uang.Nol()).Tambah(Uang.Dari(p.TotalAkhir));
    }

    return LaporanShift(
      shift: shift,
      terlaris: terlaris.take(LaporanShift.batasTerlaris).toList(),
      perJam: perJam,
      jumlahTransaksi: dihitung.length,
      penjualanKotor: Jumlahkan(
        dokumen.detail.where((d) => uuidDihitung.contains(d.UuidPenjualan)).map((d) => d.Bruto),
      ),
      totalDiskon: Jumlahkan(dihitung.map((p) => p.TotalDiskon)),
      totalPajak: Jumlahkan(dihitung.map((p) => p.TotalPajak)),
      biayaLayanan: Jumlahkan(dihitung.map((p) => p.BiayaLayanan)),
      pembulatan: Jumlahkan(dihitung.map((p) => p.Pembulatan)),
      totalAkhir: Jumlahkan(dihitung.map((p) => p.TotalAkhir)),
      perMetode: metodeUrut,
      tunaiMasukBersih: tunaiMasuk,
      // F-09: Σ refund tunai void (shift penjualan = shift void) & retur yang keluar dari laci shift ini.
      refundTunai: Jumlahkan([
        ...voidRetur.void_.map((v) => v.RefundTunai),
        ...voidRetur.retur.map((r) => r.RefundTunai),
      ]),
      jumlahVoid: void_.length,
      nominalVoid: Jumlahkan(void_.map((p) => p.TotalAkhir)),
      jumlahRetur: voidRetur.retur.length,
      nominalRetur: Jumlahkan(voidRetur.retur.map((r) => r.TotalRefund)),
      jumlahUangMuka: uangMuka.length,
      nominalUangMuka: Jumlahkan(uangMuka.map((p) => p.UangMuka)),
      jumlahIsiDeposit: isiDeposit.length,
      nominalIsiDeposit: Jumlahkan(isiDeposit.map((i) => i.Jumlah)),
      kasMasuk: JumlahMutasi(JenisMutasi.masuk),
      kasKeluar: JumlahMutasi(JenisMutasi.keluar),
      setoran: JumlahMutasi(JenisMutasi.setoran),
    );
  }

  /// Hitung selisih untuk [kasAktual] terhadap kas seharusnya shift saat ini.
  Future<PratinjauTutupShift> Pratinjau(String uuidShift, Uang kasAktual) async => PratinjauTutupShift(
    kasSeharusnya: (await SusunLaporan(uuidShift)).kasSeharusnya,
    kasAktual: kasAktual,
    toleransi: await AmbilToleransi(),
  );

  /// Pemeriksaan sebelum formulir tutup shift dibuka: izin, BR-06.2, dan pesanan tertahan.
  Future<void> PeriksaBolehTutup(BarisShift shift, StafLokal penutup) async {
    if (!penutup.PunyaIzin(IzinKasir.penjualanBuat)) {
      throw GalatKasir('TanpaIzin', '${penutup.nama} tidak punya izin menutup shift.');
    }
    if (!shift.Bersama && penutup.uuid != shift.DibukaOleh && !penutup.PunyaIzin(IzinKasir.shiftSelisihSetujui)) {
      throw GalatKasir(
        'BukanShiftSendiri',
        'Shift ini milik ${shift.NamaKasir}. Hanya kasir pemilik shift atau supervisor yang bisa menutupnya.',
      );
    }
    final tertahan = await repositoriPenjualan.HitungPesananTertahan();
    if (tertahan > 0) {
      throw GalatKasir(
        'PesananTertahan',
        'Masih ada $tertahan pesanan tertahan. Selesaikan atau batalkan dulu sebelum menutup shift.',
      );
    }
  }

  Future<LaporanShift> TutupShift({
    required BarisShift shift,
    required StafLokal penutup,
    required Uang kasAktual,
    Map<int, int>? pecahan,
    Map<String, Uang> nonTunai = const {},
    String? alasan,
    StafLokal? penyetuju,
  }) async {
    if ((await repositori.CariShift(shift.Uuid))?.Status != StatusShiftLokal.terbuka) {
      throw const GalatKasir('ShiftSudahDitutup', 'Shift ini sudah ditutup.');
    }
    await PeriksaBolehTutup(shift, penutup);

    if (kasAktual.BernilaiNegatif()) {
      throw const GalatKasir('KasAktualTidakValid', 'Kas aktual tidak boleh minus.');
    }

    final pecahanTerisi = pecahan == null
        ? const <MapEntry<int, int>>[]
        : (pecahan.entries.where((e) => e.value > 0).toList()..sort((a, b) => b.key.compareTo(a.key)));
    if (pecahanTerisi.isNotEmpty) {
      final total = pecahanTerisi.fold(Uang.Nol(), (t, e) => t.Tambah(BarisPecahan(e.key, e.value).AmbilTotal()));
      if (!total.SamaDengan(kasAktual)) {
        throw GalatKasir(
          'PecahanTidakSesuai',
          'Jumlah hitungan pecahan (${total.FormatRupiah()}) tidak sama dengan kas aktual (${kasAktual.FormatRupiah()}).',
        );
      }
    }

    for (final jumlah in nonTunai.values) {
      if (jumlah.BernilaiNegatif()) {
        throw const GalatKasir('JumlahTidakValid', 'Total non-tunai tidak boleh minus.');
      }
    }

    final pratinjau = await Pratinjau(shift.Uuid, kasAktual);
    final alasanRapi = alasan?.trim();
    final teksAlasan = alasanRapi == null || alasanRapi.isEmpty ? null : alasanRapi;
    if (pratinjau.butuhPersetujuan) {
      final selisih = pratinjau.selisih.FormatRupiah();
      final toleransi = pratinjau.toleransi.FormatRupiah();
      if ((teksAlasan?.length ?? 0) < panjangAlasanMinimal) {
        throw GalatKasir(
          'AlasanDiperlukan',
          'Selisih $selisih melebihi toleransi $toleransi. Tulis alasan minimal 5 huruf.',
        );
      }
      if (penyetuju == null) {
        throw GalatKasir(
          'PersetujuanDiperlukan',
          'Selisih $selisih melebihi toleransi $toleransi. Wajib disetujui supervisor dengan PIN.',
        );
      }
    }
    if (penyetuju != null && !penyetuju.PunyaIzin(IzinKasir.shiftSelisihSetujui)) {
      throw GalatKasir('PenyetujuTidakBerwenang', '${penyetuju.nama} tidak punya izin menyetujui selisih kas.');
    }

    final sekarang = _jam().toUtc();
    final dataPecahan = pecahanTerisi.isEmpty
        ? null
        : [
            for (final e in pecahanTerisi) {'Nominal': '${e.key}', 'Jumlah': e.value},
          ];
    final dataNonTunai = [
      for (final e in nonTunai.entries) {'UuidMetodePembayaran': e.key, 'Jumlah': e.value.KeString()},
    ];

    await repositori.SimpanTutupShift(
      shift.Uuid,
      ShiftCompanion(
        Status: const Value(StatusShiftLokal.tertutup),
        DitutupOleh: Value(penutup.uuid),
        NamaPenutup: Value(penutup.nama),
        DitutupPada: Value(sekarang),
        KasSeharusnya: Value(pratinjau.kasSeharusnya.KeString()),
        KasAktual: Value(kasAktual.KeString()),
        Selisih: Value(pratinjau.selisih.KeString()),
        PecahanKasAkhir: Value(dataPecahan == null ? null : jsonEncode(dataPecahan)),
        NonTunaiDilaporkan: Value(jsonEncode(dataNonTunai)),
        AlasanSelisih: Value(teksAlasan),
        UuidPenyetujuSelisih: Value(penyetuju?.uuid),
      ),
      ItemOutbox(
        jenis: 'Shift.Tutup',
        uuid: _ulid.Buat(),
        data: BuatDataOutbox(
          uuidShift: shift.Uuid,
          uuidPengguna: penutup.uuid,
          ditutupPada: sekarang,
          kasAktual: kasAktual,
          pecahan: dataPecahan,
          nonTunai: dataNonTunai,
          alasan: teksAlasan,
          uuidPenyetuju: penyetuju?.uuid,
          kasSeharusnya: pratinjau.kasSeharusnya,
          selisih: pratinjau.selisih,
        ),
      ),
      sekarang,
    );

    return SusunLaporan(shift.Uuid);
  }

  /// Data item outbox `Shift.Tutup` sesuai kontrak PRD "Rincian F-11".
  static Map<String, Object?> BuatDataOutbox({
    required String uuidShift,
    required String uuidPengguna,
    required DateTime ditutupPada,
    required Uang kasAktual,
    required List<Map<String, Object>>? pecahan,
    required List<Map<String, String>> nonTunai,
    required String? alasan,
    required String? uuidPenyetuju,
    required Uang kasSeharusnya,
    required Uang selisih,
  }) => {
    'UuidShift': uuidShift,
    'UuidPengguna': uuidPengguna,
    'DitutupPada': ditutupPada.toUtc().toIso8601String(),
    'KasAktual': kasAktual.KeString(),
    'PecahanKasAkhir': pecahan,
    'NonTunai': nonTunai,
    'Alasan': alasan,
    'UuidPenyetuju': uuidPenyetuju,
    'Ringkasan': {'KasSeharusnya': kasSeharusnya.KeString(), 'Selisih': selisih.KeString()},
  };

  /// Uuid shift yang laporan Z-nya belum ditutup kasir (null bila tidak ada).
  Stream<String?> PantauLaporanZTertunda() =>
      repositori.PantauPengaturan(KunciPengaturan.laporanZTertunda).map((n) => n == null || n.isEmpty ? null : n);

  Future<void> SelesaikanLaporanZ() => repositori.SimpanPengaturan(KunciPengaturan.laporanZTertunda, '');
}
