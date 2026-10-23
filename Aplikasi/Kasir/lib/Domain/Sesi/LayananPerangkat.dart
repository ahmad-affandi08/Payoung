import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:klien_api/KlienApi.dart';

import '../../Data/PenyimpanRahasia.dart';
import '../../Data/RepositoriKasir.dart';
import '../GalatKasir.dart';

/// Aktivasi perangkat dan data awal (F-02 langkah 5, F-06). Token & kunci PIN offline masuk secure storage;
/// identitas perangkat & outlet (bukan rahasia) masuk tabel `Pengaturan`.
class LayananPerangkat {
  LayananPerangkat({
    required this.klien,
    required this.repositori,
    required this.rahasia,
    required this.platform,
    DateTime Function()? jam,
    this.ubahLogo,
  }) : _jam = jam ?? DateTime.now;

  final KlienPos klien;
  final RepositoriKasir repositori;
  final PenyimpanRahasia rahasia;
  final String platform;
  final DateTime Function() _jam;

  /// PRD v1.79: dekode logo usaha ke 1 bit untuk struk (null = logo tidak diunduh, misal di test domain).
  final Future<GambarMonokrom?> Function(Uint8List byte)? ubahLogo;

  Future<bool> CekSudahAktif() async => (await rahasia.Baca(PenyimpanRahasia.kunciToken)) != null;

  /// Batas tunggu pemeriksaan perangkat saat kasir masuk. Sengaja pendek: kasir menunggu di depan pelanggan, dan
  /// jaringan yang lambat tidak boleh menahannya lebih lama dari ini.
  static const Duration batasPeriksaMasuk = Duration(seconds: 6);

  /// Keputusan pemilik produk (v2.64): saat online, perangkat yang sudah dicabut tidak boleh masuk sama sekali.
  ///
  /// Memakai `konfigurasi-aplikasi` karena itu endpoint bertoken paling murah dan **tidak** ikut dijaga
  /// `PastikanLanggananPosAktif`, jadi yang diuji benar-benar keabsahan perangkat, bukan status langganan.
  ///
  /// `true` = server menjawab dan perangkat masih berlaku. `false` = server tidak terjangkau (offline atau
  /// melewati [batasPeriksaMasuk]); masuk tetap diizinkan karena kasir harus bisa bekerja tanpa internet
  /// (BR-06.3). Perangkat dicabut = lempar `GalatKasir`; token belum dihapus di sini, penanganannya lewat kait
  /// `KlienPos.saatPerangkatDitolak` seperti jalur pencabutan lain.
  Future<bool> PeriksaMasihBerlaku() async {
    try {
      await klien.AmbilKonfigurasiAplikasi().timeout(batasPeriksaMasuk);
      return true;
    } on TimeoutException {
      return false;
    } on GalatJaringan {
      return false;
    } on GalatApi catch (galat) {
      if (galat.CekPerangkatDitolak()) {
        throw GalatKasir(galat.kode, galat.pesan);
      }
      // Galat server lain bukan urusan kasir yang sedang masuk.
      return true;
    }
  }

  Future<void> Aktifkan(String kode) async {
    // Spasi & tanda hubung dibuang dulu, sama seperti KodeAktivasi::Normalkan() di server: kode boleh ditulis
    // "A7K9-M2QT" atau "a7k9 m2qt" tanpa ditolak di perangkat sebelum sempat dikirim.
    final bersih = kode.trim().replaceAll(RegExp(r'[\s-]+'), '').toUpperCase();
    if (!RegExp(r'^[A-Za-z0-9]{6,20}$').hasMatch(bersih)) {
      throw const GalatKasir('KodeTidakValid', 'Masukkan kode aktivasi dari back-office menu Perangkat.');
    }

    final HasilAktivasi hasil;
    try {
      hasil = await klien.AktifkanPerangkat(kode: bersih, platform: platform);
    } on GalatApi catch (galat) {
      throw GalatKasir(galat.kode, galat.pesan);
    } on GalatJaringan {
      throw const GalatKasir('Offline', 'Aktivasi butuh internet. Sambungkan perangkat lalu coba lagi.');
    }

    await rahasia.Tulis(PenyimpanRahasia.kunciToken, hasil.tokenPerangkat);
    if (hasil.kunciPinOffline != null) {
      await rahasia.Tulis(PenyimpanRahasia.kunciPin, hasil.kunciPinOffline!);
    }
    await repositori.SimpanPengaturan(KunciPengaturan.uuidPerangkat, hasil.uuidPerangkat);
    await repositori.SimpanPengaturan(KunciPengaturan.kodePerangkat, hasil.kodePerangkat);
    await repositori.SimpanPengaturan(KunciPengaturan.namaPerangkat, hasil.namaPerangkat);
    await repositori.SimpanPengaturan(KunciPengaturan.namaOutlet, hasil.namaOutlet);
    await repositori.SimpanPengaturan(KunciPengaturan.uuidOutlet, hasil.uuidOutlet);
    await repositori.SimpanPengaturan(KunciPengaturan.namaUsaha, hasil.namaUsaha);
    await repositori.SimpanPengaturan(KunciPengaturan.jenisPerangkat, hasil.jenisPerangkat);
    try {
      await SegarkanDataAwal();
    } on GalatApi {
      // Aktivasi sudah berhasil (token tersimpan, kode sekali pakai terpakai). Data awal yang gagal diunduh sekarang
      // (langganan ditangguhkan, 429, 5xx) diulang otomatis di layar pilih kasir; layar aktivasi tidak boleh macet.
    }
  }

  /// Unduh data awal terbaru. Offline = pakai data lokal terakhir (tidak melempar). Perangkat dicabut = lempar
  /// `PerangkatDicabut`; token belum dihapus agar outbox tertunda masih bisa dikirim dulu
  /// (`LayananSinkron.SelesaikanPencabutan`, audit P0 F-01).
  Future<bool> SegarkanDataAwal() async {
    try {
      final data = await klien.AmbilDataAwal();
      await repositori.SimpanDataAwal(data, _jam());
      await _SegarkanLogo(data.struk);
      await _SegarkanLogoOutlet(data.outlet?.adaLogo ?? false);
      return true;
    } on GalatJaringan {
      return false;
    } on GalatApi catch (galat) {
      if (galat.CekPerangkatDitolak()) {
        throw GalatKasir(galat.kode, galat.pesan);
      }
      rethrow;
    }
  }

  /// Logo struk disimpan 1 bit di `Pengaturan` agar bisa dicetak offline. Gagal unduh/dekode = logo lama dipakai
  /// (struk tetap tercetak tanpa atau dengan logo lama); logo dimatikan/dihapus = logo lokal dihapus.
  Future<void> _SegarkanLogo(StrukPos? struk) async {
    final ubah = ubahLogo;
    if (ubah == null || struk == null) {
      return;
    }
    if (!struk.adaLogo) {
      await repositori.SimpanPengaturan(KunciPengaturan.logoStruk, '');
      return;
    }
    try {
      final byte = await klien.AmbilLogoStruk();
      final gambar = byte == null ? null : await ubah(byte);
      if (byte == null || gambar != null) {
        await repositori.SimpanPengaturan(KunciPengaturan.logoStruk, gambar == null ? '' : jsonEncode(gambar.KeJson()));
      }
    } on GalatJaringan {
      return;
    } on GalatApi {
      return;
    }
  }

  /// D-16: logo merek outlet disimpan apa adanya (base64) untuk bilah atas Ruang Kerja, supaya tetap tampil offline.
  /// Gagal unduh = logo lama dipakai; server tidak mengirim logo = logo lokal dikosongkan.
  Future<void> _SegarkanLogoOutlet(bool adaLogo) async {
    if (!adaLogo) {
      await repositori.SimpanPengaturan(KunciPengaturan.logoOutlet, '');
      return;
    }
    try {
      final byte = await klien.AmbilLogoOutlet();
      await repositori.SimpanPengaturan(KunciPengaturan.logoOutlet, byte == null ? '' : base64Encode(byte));
    } on GalatJaringan {
      return;
    } on GalatApi {
      return;
    }
  }

  /// Hapus rahasia & data PIN lokal (perangkat dicabut). Transaksi & outbox yang belum terkirim tidak dihapus: setelah
  /// aktivasi ulang, outbox dikirim atas nama perangkat asalnya (audit P0 F-01).
  Future<void> CabutLokal() async {
    await rahasia.HapusSemua();
    await repositori.HapusDataSensitif();
  }
}
