import 'dart:async';
import 'dart:convert';

import 'package:klien_api/KlienApi.dart';

import '../Diagnostik/LogLokal.dart';
import '../Perangkat/LayananUjiPerangkat.dart';
import '../../Data/RepositoriKasir.dart';
import '../GalatKasir.dart';
import '../Sesi/LayananPerangkat.dart';

/// Hasil satu putaran sinkron.
class RingkasanSinkron {
  const RingkasanSinkron({
    this.terkirim = 0,
    this.ditolak = 0,
    this.offline = false,
    this.perangkatDicabut = false,
    this.tersambung,
  });

  final int terkirim;

  /// Server terjangkau pada putaran ini: `true` bila server menjawab, `false` bila gagal jaringan, `null` bila tidak
  /// ada yang dikirim (koneksi tidak diperiksa).
  final bool? tersambung;
  final int ditolak;
  final bool offline;
  final bool perangkatDicabut;
}

/// Kirim outbox FIFO per perangkat (PRD §18 no. 4): batch maks. 50, berurutan (shift sebelum mutasinya).
/// `Diterima`/`Duplikat` → dihapus dari outbox; `Ditolak` → "Perlu Tindakan" beserta alasannya. Gagal jaringan/5xx →
/// dijadwalkan ulang dengan mundur eksponensial.
///
/// Audit P0 F-01: setiap item membawa perangkat pembuatnya (`UuidPerangkatAsal`). Perangkat dicabut: selama masa
/// pemulihan server masih menerima outbox (jawaban `PerangkatDicabut`), jadi outbox dikosongkan dulu baru token & data
/// sensitif dihapus. Bila server sudah menolak (403), token dihapus; outbox tetap tersimpan dan dikirim atas nama
/// perangkat asal setelah perangkat ini diaktifkan ulang.
class LayananSinkron {
  LayananSinkron({
    required this.klien,
    required this.repositori,
    required this.perangkat,
    this.ujiPerangkat,
    this.log,
    DateTime Function()? jam,
  }) : _jam = jam ?? DateTime.now;

  static const int ukuranBatch = 50;

  /// Kode penolakan yang sebabnya shift lama di server: buka shift baru, tutup shift, dan penjualan/kas dari shift itu.
  static const Set<String> kodeGalatShift = {'ShiftSudahTerbuka', 'ShiftTidakDikenal', 'ShiftTidakDitemukan'};

  final KlienPos klien;
  final RepositoriKasir repositori;
  final LayananPerangkat perangkat;

  /// v1.96: laporan Wizard Uji Perangkat yang tertunda ikut dikirim setelah outbox kosong.
  final LayananUjiPerangkat? ujiPerangkat;

  /// K-21: galat lokal yang belum terkirim ikut dikirim setelah outbox kosong.
  final LogLokal? log;
  final DateTime Function() _jam;

  bool _berjalan = false;

  Future<RingkasanSinkron> KirimTertunda() async {
    if (_berjalan) {
      return const RingkasanSinkron();
    }
    _berjalan = true;
    var terkirim = 0;
    var ditolak = 0;
    var dijawabServer = false;
    var dicabut = false;

    try {
      while (true) {
        final batch = await repositori.AmbilOutboxSiapKirim(ukuranBatch, _jam());
        if (batch.isEmpty) {
          if (dicabut) {
            await perangkat.CabutLokal();
            return RingkasanSinkron(terkirim: terkirim, ditolak: ditolak, perangkatDicabut: true, tersambung: true);
          }
          await ujiPerangkat?.KirimTertunda();
          // Tidak ditunggu: laporan galat tidak boleh menahan sinkron transaksi.
          if (log case final log?) {
            unawaited(log.KirimTertunda(klien));
          }
          return RingkasanSinkron(terkirim: terkirim, ditolak: ditolak, tersambung: dijawabServer ? true : null);
        }

        final List<HasilItemSinkron> hasil;
        try {
          final jawaban = await klien.KirimSinkron([
            for (final b in batch)
              ItemOutbox(
                jenis: b.Jenis,
                uuid: b.Uuid,
                data: (jsonDecode(b.Data) as Map<String, Object?>),
                uuidPerangkatAsal: b.UuidPerangkat,
              ),
          ]);
          hasil = jawaban.hasil;
          await repositori.CatatSinkron(
            waktu: _jam(),
            waktuServer: jawaban.waktuServer,
            perluTinjauan: jawaban.perluTinjauan,
          );
          if (jawaban.perangkatDicabut && !dicabut) {
            // Masa pemulihan: kirim semua sisa sekarang, termasuk yang sedang menunggu jadwal ulang.
            dicabut = true;
            await repositori.SegerakanTertunda(_jam());
          }
        } on GalatJaringan catch (galat) {
          await repositori.JadwalkanUlang(batch, _jam(), galat.pesan);
          return RingkasanSinkron(terkirim: terkirim, ditolak: ditolak, offline: true, tersambung: false);
        } on GalatApi catch (galat) {
          dijawabServer = true;
          if (galat.kode == 'PermintaanSedangDiproses') {
            // Audit F-12: batch yang sama masih diproses server (kiriman ganda); coba lagi nanti, bukan ditolak.
            await repositori.JadwalkanUlang(batch, _jam(), galat.pesan);
            return RingkasanSinkron(terkirim: terkirim, ditolak: ditolak, tersambung: true);
          }
          if (galat.CekPerangkatDitolak()) {
            await perangkat.CabutLokal();
            return RingkasanSinkron(terkirim: terkirim, ditolak: ditolak, perangkatDicabut: true, tersambung: true);
          }
          if (galat.CekGalatPerantara()) {
            // 4xx dari proxy/WAF/nginx (tanpa badan `Galat` dari server) atau pembatasan laju: bukan salah isi item,
            // jadi jangan menandai seluruh antrean "Perlu tindakan"; coba lagi nanti.
            await repositori.JadwalkanUlang(batch, _jam(), galat.pesan);
            return RingkasanSinkron(terkirim: terkirim, ditolak: ditolak, tersambung: true);
          }
          // Batch ditolak utuh (bentuk permintaan salah): tandai semua agar antrean berikutnya tidak tertahan.
          for (final b in batch) {
            await repositori.TandaiPerluTindakan(b.Uuid, galat.kode, galat.pesan);
          }
          ditolak += batch.length;
          continue;
        }

        dijawabServer = true;
        final selesai = <String>[];
        for (final h in hasil) {
          if (h.status == StatusItemSinkron.Ditolak) {
            await repositori.TandaiPerluTindakan(h.uuid, h.kodeGalat, h.pesanGalat);
            ditolak++;
          } else {
            selesai.add(h.uuid);
          }
        }
        await repositori.HapusOutbox(selesai);
        terkirim += selesai.length;

        // Item yang tidak dijawab server (seharusnya tidak terjadi) dijadwalkan ulang agar tidak hilang.
        final dijawab = hasil.map((h) => h.uuid).toSet();
        final terlewat = batch.where((b) => !dijawab.contains(b.Uuid)).toList();
        if (terlewat.isNotEmpty) {
          await repositori.JadwalkanUlang(terlewat, _jam(), 'Server tidak menjawab item ini.');
          return RingkasanSinkron(terkirim: terkirim, ditolak: ditolak, tersambung: true, perangkatDicabut: dicabut);
        }
      }
    } finally {
      _berjalan = false;
    }
  }

  /// Shift perangkat ini yang masih terbuka di server. Offline atau galat server → daftar kosong (tidak bisa dipastikan).
  Future<List<ShiftTerbukaServerPos>> AmbilShiftTerbukaServer() async {
    try {
      return await klien.AmbilShiftTerbukaServer();
    } on GalatJaringan {
      return const [];
    } on GalatApi {
      return const [];
    }
  }

  /// BR-06.1 per kasir: shift terbuka [uuidKasir] di perangkat lain. Hanya bisa dijawab saat online; offline, lambat
  /// (lebih dari [batasCekShiftLain]), atau galat server → daftar kosong, dan shift tetap dibuka lalu diperiksa server
  /// (ditandai `PerluTinjauan`), supaya membuka shift tidak pernah tertahan oleh jaringan.
  Future<List<ShiftKasirPerangkatLainPos>> CariShiftKasirDiPerangkatLain(String uuidKasir) async {
    try {
      return await klien.CariShiftKasirDiPerangkatLain(uuidKasir).timeout(batasCekShiftLain);
    } on TimeoutException {
      return const [];
    } on FormatException {
      return const [];
    } on GalatJaringan {
      return const [];
    } on GalatApi {
      return const [];
    }
  }

  static const Duration batasCekShiftLain = Duration(seconds: 3);

  /// Kirim ulang semua penolakan karena shift (urutan terlama dulu) tanpa menutup apa pun: dipakai saat shift lama di
  /// server ternyata sudah ditutup (misal dari back-office).
  Future<void> JadwalkanUlangPenolakanShift() async {
    await repositori.CobaLagiPerluTindakan(kodeGalatShift, _jam());
  }

  /// Tutup paksa shift lama di server atas persetujuan [uuidPenyetuju] (PIN supervisor sudah diperiksa), lalu jadwalkan
  /// ulang semua item outbox yang ditolak karena shift itu (`ShiftSudahTerbuka`, `ShiftTidakDikenal`) berurutan dari
  /// yang terlama, supaya buka-tutup shift yang tertahan ikut terkirim tanpa diketuk satu per satu.
  Future<void> TutupShiftLamaServer({
    required String uuidShift,
    required String uuidPenyetuju,
    required String alasan,
  }) async {
    final rapi = alasan.trim();
    if (rapi.runes.length < 5) {
      throw const GalatKasir('AlasanDiperlukan', 'Tulis alasan minimal 5 huruf.');
    }
    try {
      await klien.TutupPaksaShift(uuidShift: uuidShift, uuidPenyetuju: uuidPenyetuju, alasan: rapi);
    } on GalatJaringan {
      throw const GalatKasir('Offline', 'Belum tersambung ke server. Tutup shift lama butuh internet.');
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    }
    await repositori.CobaLagiPerluTindakan(kodeGalatShift, _jam());
  }

  /// Audit P0 F-01: server menyatakan perangkat dicabut (misal saat unduh data awal). Kirim sisa outbox selama masa
  /// pemulihan, lalu hapus token & data sensitif. Offline = token dipertahankan agar sisa outbox bisa dikirim nanti
  /// (mengembalikan `false`); outbox tidak pernah dihapus.
  Future<bool> SelesaikanPencabutan() async {
    if (_berjalan) {
      // Sinkron lain sedang berjalan dan akan menghapus token sendiri setelah outbox kosong.
      return false;
    }
    await repositori.SegerakanTertunda(_jam());
    final hasil = await KirimTertunda();
    if (hasil.offline) {
      return false;
    }
    if (!hasil.perangkatDicabut) {
      await perangkat.CabutLokal();
    }
    return true;
  }
}
