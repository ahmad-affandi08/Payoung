import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:http/http.dart' as http;
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart' show KanalPenjualan, Uang;
import 'package:sistem_desain/SistemDesain.dart' show TokenWarna;

import '../Data/Printer/InfoPerangkatPlatform.dart';
import '../Data/CacheGambarProduk.dart';
import '../Data/KameraBuktiPlatform.dart';
import '../Data/KameraSwafotoPlatform.dart';
import '../Data/PemindaiQrPlatform.dart';
import '../Data/LayarPelanggan/PabrikLayarPelanggan.dart';
import '../Data/RepositoriAbsensi.dart';
import '../Domain/Diagnostik/LogLokal.dart';
import '../Domain/Karyawan/LayananAbsensi.dart';
import '../Domain/Perangkat/KameraBukti.dart';
import '../Domain/Perangkat/KameraSwafoto.dart';
import '../Domain/Perangkat/PemindaiQr.dart';
import '../Data/BasisData/BasisDataKasir.dart';
import '../Data/PengubahLogoStruk.dart';
import '../Data/Printer/PemindaiPrinterPlatform.dart';
import '../Data/PenjagaLayarWakelock.dart';
import '../Data/PenyimpanRahasia.dart';
import '../Data/PesananMeja.dart';
import '../Data/RepositoriKasir.dart';
import '../Data/RepositoriKatalog.dart';
import '../Data/RepositoriPelanggan.dart';
import '../Data/RepositoriPenjualan.dart';
import '../Data/RepositoriDeposit.dart';
import '../Data/RepositoriPreOrder.dart';
import '../Data/RepositoriPersediaan.dart';
import '../Data/RepositoriPesananMeja.dart';
import '../Domain/Dapur/LayananDapur.dart';
import '../Domain/GalatKasir.dart';
import '../Domain/Katalog/KatalogLokal.dart';
import '../Domain/Katalog/LayananKatalog.dart';
import '../Domain/Katalog/LayananKetersediaan.dart';
import '../Domain/Katalog/LayananStokTersedia.dart';
import '../Domain/Meja/LayananPesanSendiri.dart';
import '../Domain/Meja/LayananPesananMeja.dart';
import '../Domain/Pelanggan/LayananDeposit.dart';
import '../Domain/Pelanggan/LayananSesi.dart';
import '../Domain/Pelanggan/LayananPelanggan.dart';
import '../Domain/Penjualan/LayananRingkasanHarian.dart';
import '../Domain/Penjualan/Keranjang.dart';
import '../Domain/Penjualan/KonteksPenjualan.dart';
import '../Domain/Penjualan/LayananPenjualan.dart';
import '../Domain/Penjualan/LayananLaundry.dart';
import '../Domain/Penjualan/LayananPesananOnline.dart';
import '../Domain/Penjualan/LayananPreOrder.dart';
import '../Domain/Penjualan/LayananPerintahKerja.dart';
import '../Domain/Penjualan/LayananReservasi.dart';
import '../Domain/Penjualan/LayananQrisDinamis.dart';
import '../Domain/Struk/LayananKirimStruk.dart';
import '../Domain/Penjualan/LayananVoucher.dart';
import '../Domain/Penjualan/LayananReturPenjualan.dart';
import '../Domain/Penjualan/LayananReturTanpaStruk.dart';
import '../Domain/Penjualan/LayananVoidPenjualan.dart';
import '../Domain/Perangkat/LayananLayarPelanggan.dart';
import '../Domain/Persediaan/LayananInfoBatch.dart';
import '../Domain/Persediaan/LayananBahanTerbuang.dart';
import '../Domain/Persediaan/LayananGudang.dart';
import '../Domain/Perangkat/LayananUjiPerangkat.dart';
import '../Domain/Perangkat/PengaturanPerangkat.dart';
import '../Domain/Perangkat/PenjagaLayarMenyala.dart';
import '../Domain/Pin/PemverifikasiPinOffline.dart';
import '../Domain/Sesi/LayananMasuk.dart';
import '../Domain/Sesi/LayananPerangkat.dart';
import '../Domain/Sesi/LayananPersetujuanJarakJauh.dart';
import '../Domain/Sesi/StafLokal.dart';
import '../Domain/Dapur/LayananTiketDapur.dart';
import '../Domain/Shift/LayananBukaLaci.dart';
import '../Domain/Shift/LayananShift.dart';
import '../Domain/Shift/LayananTutupShift.dart';
import '../Domain/Sinkron/LayananSinkron.dart';
import '../Domain/Struk/LayananStruk.dart';
import '../Domain/Struk/PemindaiPrinter.dart';
import '../Domain/Struk/ProfilPrinter.dart';
import 'Lingkungan.dart';

/// Penyedia dependensi (Riverpod). Basis data, secure storage, dan klien HTTP di-override di `Persiapan.dart` dan
/// di test.
final penyediaBasisData = Provider<BasisDataKasir>((ref) => throw UnimplementedError('Override penyediaBasisData'));
final penyediaFolderAplikasi = Provider<Directory?>((ref) => null);

/// K-23 (POS-18): mode latihan per sesi aplikasi (tidak disimpan; kembali mati saat aplikasi dibuka ulang) supaya
/// transaksi nyata tidak ikut hilang karena lupa mematikannya.
class ModeLatihan extends Notifier<bool> {
  @override
  bool build() => false;

  void Atur(bool aktif) => state = aktif;
}

final penyediaModeLatihan = NotifierProvider<ModeLatihan, bool>(ModeLatihan.new);

/// D-66: rel navigasi Ruang Kerja mulai tertutup (ikon saja). Test lain meng-override ke `false` supaya label menu
/// tetap terlihat tanpa mengetuk tombol lebarkan dulu; test khusus rel memeriksa bawaan ini.
final penyediaRelAwalDiciutkan = Provider<bool>((ref) => true);

/// Audit kemudahan pakai #25 (D-38): persetujuan PIN supervisor untuk diskon & tempo berlaku sementara
/// ([berlaku]) bagi kasir yang sama, supaya transaksi beruntun tidak meminta PIN berulang. Void, retur, kas keluar,
/// buka laci, shift, dan obat keras **tidak** memakai ini (tetap PIN setiap kali). Hanya di memori; hilang saat aplikasi
/// ditutup.
class PersetujuanSementara extends Notifier<Map<String, ({StafLokal staf, String uuidKasir, DateTime sampai})>> {
  static const Duration berlaku = Duration(minutes: 5);

  @override
  Map<String, ({StafLokal staf, String uuidKasir, DateTime sampai})> build() => const {};

  void Catat(String kunci, StafLokal staf, String uuidKasir) =>
      state = {...state, kunci: (staf: staf, uuidKasir: uuidKasir, sampai: ref.read(penyediaJam)().add(berlaku))};

  /// Penyetuju yang masih berlaku untuk [kunci] & kasir [uuidKasir] (null bila tidak ada/kedaluwarsa/kasir lain).
  StafLokal? Ambil(String kunci, String uuidKasir) {
    final catatan = state[kunci];
    if (catatan == null || catatan.uuidKasir != uuidKasir || !ref.read(penyediaJam)().isBefore(catatan.sampai)) {
      return null;
    }
    return catatan.staf;
  }
}

final penyediaPersetujuanSementara =
    NotifierProvider<PersetujuanSementara, Map<String, ({StafLokal staf, String uuidKasir, DateTime sampai})>>(
      PersetujuanSementara.new,
    );

/// K-21: log lokal & laporan galat (null bila folder aplikasi tidak tersedia, misalnya di test widget).
final penyediaLogLokal = Provider<LogLokal?>((ref) {
  final folder = ref.watch(penyediaFolderAplikasi);
  return folder == null ? null : LogLokal(folder: folder, jam: ref.watch(penyediaJam));
});
final penyediaRahasia = Provider<PenyimpanRahasia>((ref) => PenyimpanRahasiaAman());
final penyediaKlienHttp = Provider<http.Client>((ref) => http.Client());
final penyediaLingkungan = Provider<Lingkungan>((ref) => Lingkungan.Dev);

/// D-35: alamat server toko sendiri yang tersimpan saat aplikasi dibuka (diisi `Persiapan` dari secure storage).
final penyediaAlamatServerAwal = Provider<Uri?>((ref) => null);

/// D-35 edisi Lisensi: alamat server toko sendiri (null = alamat bawaan build). Diganti saat aktivasi; klien API
/// ikut dibuat ulang karena menonton penyedia ini.
class AlamatServer extends Notifier<Uri?> {
  @override
  Uri? build() => ref.watch(penyediaAlamatServerAwal);

  Future<void> Atur(Uri? alamat) async {
    await ref.read(penyediaRahasia).Tulis(PenyimpanRahasia.kunciAlamatServer, alamat?.toString() ?? '');
    state = alamat;
  }
}

final penyediaAlamatServer = NotifierProvider<AlamatServer, Uri?>(AlamatServer.new);
final penyediaPlatform = Provider<String>((ref) => 'Android');
final penyediaJam = Provider<DateTime Function()>((ref) => DateTime.now);

/// Layar tetap menyala selama shift terbuka (§17.2.7). Test memakai tiruan.
final penyediaPenjagaLayar = Provider<PenjagaLayarMenyala>((ref) => const PenjagaLayarWakelock());

final penyediaRepositori = Provider<RepositoriKasir>((ref) => RepositoriKasir(ref.watch(penyediaBasisData)));

final penyediaKlienPos = Provider<KlienPos>((ref) {
  final rahasia = ref.watch(penyediaRahasia);
  return KlienPos(
    alamatDasar: ref.watch(penyediaLingkungan).AmbilAlamatServer(tersimpan: ref.watch(penyediaAlamatServer)),
    versiAplikasi: '1.0.0',
    ambilToken: () => rahasia.Baca(PenyimpanRahasia.kunciToken),
    klien: ref.watch(penyediaKlienHttp),
    // P-10 BR-P10.2: server tahu perangkat mana yang masih menyimpan transaksi belum terkirim.
    ambilJumlahOutbox: () => ref.read(penyediaRepositori).HitungJumlahTertunda(),
    // BR-02.3: penolakan token perangkat mengakhiri sesi dari permintaan mana pun, bukan hanya alur sinkron &
    // data awal. Katalog, promo, data meja, dan konfigurasi sengaja menelan galat server agar kasir tidak
    // terganggu — tanpa kait ini perangkat yang sudah dicabut tetap bisa dipakai berjualan.
    saatPerangkatDitolak: (galat) => ref.read(penyediaSesi.notifier).TanganiPenolakanPerangkat(galat.pesan),
  );
});

final penyediaCacheGambarProduk = Provider<CacheGambarProduk>(
  (ref) => CacheGambarProduk(klien: ref.watch(penyediaKlienPos), folderAplikasi: ref.watch(penyediaFolderAplikasi)),
);

final penyediaGambarProduk = FutureProvider.family<Uint8List?, String>(
  (ref, url) => ref.watch(penyediaCacheGambarProduk).Ambil(url),
);

final penyediaLayananPerangkat = Provider<LayananPerangkat>(
  (ref) => LayananPerangkat(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositori),
    rahasia: ref.watch(penyediaRahasia),
    platform: ref.watch(penyediaPlatform),
    jam: ref.watch(penyediaJam),
    ubahLogo: ref.watch(penyediaPengubahLogo),
  ),
);

/// PRD v1.79: dekode logo struk (mesin gambar Flutter); test domain boleh menggantinya.
final penyediaPengubahLogo = Provider<Future<GambarMonokrom?> Function(Uint8List byte)?>((ref) => UbahLogoKeMonokrom);

/// Verifikasi PIN offline (Argon2id). Test widget menggantinya dengan tiruan; kriptografi asli diuji di test domain.
final penyediaPemverifikasiPin = Provider<PemverifikasiPinOffline>((ref) => const PemverifikasiPinOffline());

final penyediaLayananMasuk = Provider<LayananMasuk>(
  (ref) => LayananMasuk(
    repositori: ref.watch(penyediaRepositori),
    rahasia: ref.watch(penyediaRahasia),
    klien: ref.watch(penyediaKlienPos),
    pemverifikasi: ref.watch(penyediaPemverifikasiPin),
    jam: ref.watch(penyediaJam),
  ),
);

final penyediaLayananShift = Provider<LayananShift>(
  (ref) => LayananShift(repositori: ref.watch(penyediaRepositori), jam: ref.watch(penyediaJam)),
);

final penyediaLayananTutupShift = Provider<LayananTutupShift>(
  (ref) => LayananTutupShift(
    repositori: ref.watch(penyediaRepositori),
    repositoriPenjualan: ref.watch(penyediaRepositoriPenjualan),
    repositoriPreOrder: ref.watch(penyediaRepositoriPreOrder),
    repositoriDeposit: ref.watch(penyediaRepositoriDeposit),
    jam: ref.watch(penyediaJam),
  ),
);

final penyediaLayananSinkron = Provider<LayananSinkron>(
  (ref) => LayananSinkron(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositori),
    perangkat: ref.watch(penyediaLayananPerangkat),
    ujiPerangkat: ref.watch(penyediaLayananUjiPerangkat),
    log: ref.watch(penyediaLogLokal),
    jam: ref.watch(penyediaJam),
  ),
);

/// v1.96: info merek/model perangkat (test menggantinya dengan tiruan) dan Wizard Uji Perangkat.
final penyediaSumberInfoPerangkat = Provider<SumberInfoPerangkat>((ref) => const InfoPerangkatPlatform());

final penyediaLayananUjiPerangkat = Provider<LayananUjiPerangkat>(
  (ref) => LayananUjiPerangkat(
    repositori: ref.watch(penyediaRepositori),
    klien: ref.watch(penyediaKlienPos),
    info: ref.watch(penyediaSumberInfoPerangkat),
    jam: ref.watch(penyediaJam),
  ),
);

final penyediaRepositoriKatalog = Provider<RepositoriKatalog>((ref) => RepositoriKatalog(ref.watch(penyediaBasisData)));

final penyediaRepositoriPenjualan = Provider<RepositoriPenjualan>(
  (ref) => RepositoriPenjualan(ref.watch(penyediaBasisData), ref.watch(penyediaRepositori)),
);

/// Cetak struk (PRD v1.79): transport printer dari profil (test menggantinya dengan printer tiruan).
final penyediaPemindaiPrinter = Provider<PemindaiPrinter>((ref) => const PemindaiPrinterPlatform());

final penyediaPembuatTransport = Provider<PembuatTransport>((ref) => ref.watch(penyediaPemindaiPrinter).BuatTransport);

final penyediaLayananStruk = Provider<LayananStruk>(
  (ref) => LayananStruk(
    repositori: ref.watch(penyediaRepositori),
    penjualan: ref.watch(penyediaRepositoriPenjualan),
    pembuatTransport: ref.watch(penyediaPembuatTransport),
  ),
);

/// Cetak struk bagian 4: buka laci manual tanpa transaksi yang dicatat (§19.2).
final penyediaLayananBukaLaci = Provider<LayananBukaLaci>(
  (ref) => LayananBukaLaci(
    repositori: ref.watch(penyediaRepositori),
    struk: ref.watch(penyediaLayananStruk),
    jam: ref.watch(penyediaJam),
  ),
);

/// Cetak struk bagian 4c: tiket dapur per stasiun di printer.
final penyediaLayananTiketDapur = Provider<LayananTiketDapur>(
  (ref) => LayananTiketDapur(
    repositori: ref.watch(penyediaRepositori),
    struk: ref.watch(penyediaLayananStruk),
    penjualan: ref.watch(penyediaRepositoriPenjualan),
  ),
);

enum KeadaanPrinter { BelumDiatur, Siap, Mencetak, Gagal }

/// Keadaan printer untuk bilah status & layar (§17.2.7: status printer selalu terlihat).
class StatusPrinter {
  const StatusPrinter({this.profil, this.keadaan = KeadaanPrinter.BelumDiatur, this.pesan});

  final ProfilPrinter? profil;
  final KeadaanPrinter keadaan;

  /// Pesan galat terakhir (keadaan `Gagal`).
  final String? pesan;
}

/// Profil & keadaan printer perangkat ini. Setiap aksi cetak mengembalikan pesan galat (null = berhasil) agar layar
/// bisa menampilkannya di tempat; keadaan `Gagal` bertahan sampai cetak berikutnya berhasil.
class PengaturPrinter extends Notifier<StatusPrinter> {
  var _diubah = false;

  @override
  StatusPrinter build() {
    unawaited(_Muat());
    return const StatusPrinter();
  }

  Future<void> _Muat() async {
    final profil = await ref.read(penyediaLayananStruk).AmbilProfil();
    if (!_diubah) {
      state = StatusPrinter(profil: profil, keadaan: profil == null ? KeadaanPrinter.BelumDiatur : KeadaanPrinter.Siap);
    }
  }

  Future<void> SimpanProfil(ProfilPrinter profil) async {
    _diubah = true;
    await profil.Simpan(ref.read(penyediaRepositori));
    state = StatusPrinter(profil: profil, keadaan: KeadaanPrinter.Siap);
  }

  Future<void> HapusProfil() async {
    _diubah = true;
    await ProfilPrinter.Hapus(ref.read(penyediaRepositori));
    state = const StatusPrinter();
  }

  Future<String?> CetakPenjualan(
    String uuidPenjualan, {
    bool cetakUlang = false,
    String? namaPelanggan,
    String? labelPoin,
  }) => _Jalankan(
    (l) => l.CetakPenjualan(uuidPenjualan, cetakUlang: cetakUlang, namaPelanggan: namaPelanggan, labelPoin: labelPoin),
  );

  /// v1.97: cadangan saat printer thermal bermasalah: struk dibuka di dialog printer sistem (PDF/AirPrint/driver OS).
  /// Keadaan printer thermal di bilah status tidak diubah.
  Future<String?> CetakPenjualanLewatSistem(
    String uuidPenjualan, {
    bool cetakUlang = false,
    String? namaPelanggan,
    String? labelPoin,
  }) async {
    try {
      await ref
          .read(penyediaLayananStruk)
          .CetakPenjualan(
            uuidPenjualan,
            cetakUlang: cetakUlang,
            namaPelanggan: namaPelanggan,
            labelPoin: labelPoin,
            lewat: ProfilPrinter.Sistem(state.profil?.lebar ?? LebarKertas.Mm58),
          );
      return null;
    } on GalatPrinter catch (galat) {
      return galat.pesan;
    } on GalatKasir catch (galat) {
      return galat.pesan;
    }
  }

  /// Cetak otomatis setelah bayar (plus buka laci bila tunai), sekali per transaksi walau layar selesai dibangun ulang.
  /// Printer belum diatur/otomatis mati = tidak mencetak dan keadaan tidak berubah.
  Future<({bool dicetak, String? galat})> CetakSetelahBayar(
    String uuidPenjualan, {
    String? namaPelanggan,
    String? labelPoin,
  }) async {
    if (!_sudahOtomatis.add(uuidPenjualan) || !await ref.read(penyediaLayananStruk).CekCetakOtomatis()) {
      return (dicetak: false, galat: null);
    }
    final galat = await _Jalankan(
      (l) => l.CetakSetelahBayar(uuidPenjualan, namaPelanggan: namaPelanggan, labelPoin: labelPoin),
    );
    return (dicetak: galat == null, galat: galat);
  }

  final Set<String> _sudahOtomatis = {};

  /// Cetak dokumen kasir selain struk penjualan (bukti void, nota retur, laporan shift); galat → pesan untuk kasir.
  Future<String?> CetakDokumen(Future<void> Function(LayananStruk layanan) aksi) => _Jalankan(aksi);

  /// Cetak otomatis sekali per dokumen [kunci] (misal Uuid retur) bila cetak otomatis aktif. Null = tidak dicetak.
  Future<({bool dicetak, String? galat})> CetakDokumenOtomatis(
    String kunci,
    Future<void> Function(LayananStruk layanan) aksi,
  ) async {
    if (!_sudahOtomatis.add(kunci) || !await ref.read(penyediaLayananStruk).CekCetakOtomatis()) {
      return (dicetak: false, galat: null);
    }
    final galat = await _Jalankan(aksi);
    return (dicetak: galat == null, galat: galat);
  }

  /// Cetak uji untuk isian yang mungkin belum disimpan: keadaan bilah status tidak diubah.
  Future<String?> CetakUji(ProfilPrinter profil) async {
    try {
      await ref.read(penyediaLayananStruk).CetakUji(profil);
      return null;
    } on GalatPrinter catch (galat) {
      return galat.pesan;
    }
  }

  Future<String?> _Jalankan(Future<void> Function(LayananStruk layanan) aksi) async {
    state = StatusPrinter(profil: state.profil, keadaan: KeadaanPrinter.Mencetak);
    try {
      await aksi(ref.read(penyediaLayananStruk));
      state = StatusPrinter(
        profil: state.profil,
        keadaan: state.profil == null ? KeadaanPrinter.BelumDiatur : KeadaanPrinter.Siap,
      );
      return null;
    } on GalatPrinter catch (galat) {
      state = StatusPrinter(profil: state.profil, keadaan: KeadaanPrinter.Gagal, pesan: galat.pesan);
      return galat.pesan;
    } on GalatKasir catch (galat) {
      state = StatusPrinter(profil: state.profil, keadaan: KeadaanPrinter.Gagal, pesan: galat.pesan);
      return galat.pesan;
    }
  }
}

final penyediaPrinter = NotifierProvider<PengaturPrinter, StatusPrinter>(PengaturPrinter.new);

/// F-12 bagian 2: pre-order + uang muka dari perangkat ini.
final penyediaRepositoriPreOrder = Provider<RepositoriPreOrder>(
  (ref) => RepositoriPreOrder(ref.watch(penyediaBasisData), ref.watch(penyediaRepositori)),
);

final penyediaRepositoriDeposit = Provider<RepositoriDeposit>(
  (ref) => RepositoriDeposit(ref.watch(penyediaBasisData), ref.watch(penyediaRepositori)),
);

final penyediaLayananDeposit = Provider<LayananDeposit>(
  (ref) => LayananDeposit(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositoriDeposit),
    repositoriKasir: ref.watch(penyediaRepositori),
    jam: ref.watch(penyediaJam),
  ),
);

final penyediaRepositoriPersediaan = Provider<RepositoriPersediaan>(
  (ref) => RepositoriPersediaan(ref.watch(penyediaBasisData), ref.watch(penyediaRepositori)),
);

/// F-05f bagian 2: catat bahan/menu terbuang dari perangkat (offline).
final penyediaLayananBahanTerbuang = Provider<LayananBahanTerbuang>(
  (ref) => LayananBahanTerbuang(repositori: ref.watch(penyediaRepositoriPersediaan), jam: ref.watch(penyediaJam)),
);

/// K-19: info batch & kedaluwarsa produk ber-batch (online, disimpan sebentar).
final penyediaLayananInfoBatch = Provider<LayananInfoBatch>(
  (ref) => LayananInfoBatch(klien: ref.watch(penyediaKlienPos), jam: ref.watch(penyediaJam)),
);

/// X4 persetujuan jarak jauh (online): minta, pantau, batalkan.
final penyediaLayananPersetujuanJarakJauh = Provider<LayananPersetujuanJarakJauh>(
  (ref) => LayananPersetujuanJarakJauh(klien: ref.watch(penyediaKlienPos), repositori: ref.watch(penyediaRepositori)),
);

/// POS-25 modul Gudang (online): terima barang, transfer masuk, hitung stok opname.
final penyediaLayananGudang = Provider<LayananGudang>(
  (ref) => LayananGudang(klien: ref.watch(penyediaKlienPos), repositori: ref.watch(penyediaRepositori)),
);

/// Catatan bahan terbuang perangkat ini pada tanggal bisnis outlet hari ini, terbaru dulu, dengan status kirim.
final penyediaBahanTerbuangHariIni = StreamProvider<List<RiwayatBahanTerbuang>>((ref) async* {
  final konteks = await ref.watch(penyediaKonteksPenjualan.future);
  yield* ref
      .watch(penyediaRepositoriPersediaan)
      .PantauBahanTerbuang(konteks.HitungTanggalBisnis(ref.read(penyediaJam)()));
});

final penyediaLayananSesi = Provider<LayananSesi>(
  (ref) => LayananSesi(
    klien: ref.watch(penyediaKlienPos),
    repositoriKasir: ref.watch(penyediaRepositori),
    jam: ref.watch(penyediaJam),
  ),
);

/// F-17: pesanan toko online yang ditagihkan kasir.
final penyediaLayananPesananOnline = Provider<LayananPesananOnline>(
  (ref) => LayananPesananOnline(klien: ref.watch(penyediaKlienPos), penjualan: ref.watch(penyediaLayananPenjualan)),
);

final penyediaLayananPreOrder = Provider<LayananPreOrder>(
  (ref) => LayananPreOrder(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositori),
    repositoriPreOrder: ref.watch(penyediaRepositoriPreOrder),
    penjualan: ref.watch(penyediaLayananPenjualan),
    jam: ref.watch(penyediaJam),
  ),
);

final penyediaLayananLaundry = Provider<LayananLaundry>(
  (ref) => LayananLaundry(klien: ref.watch(penyediaKlienPos), jam: ref.watch(penyediaJam)),
);

final penyediaLayananReservasi = Provider<LayananReservasi>(
  (ref) => LayananReservasi(klien: ref.watch(penyediaKlienPos), penjualan: ref.watch(penyediaLayananPenjualan)),
);

/// Bengkel bagian 2: perintah kerja siap tagih (online) → keranjang.
final penyediaLayananPerintahKerja = Provider<LayananPerintahKerja>(
  (ref) => LayananPerintahKerja(klien: ref.watch(penyediaKlienPos), penjualan: ref.watch(penyediaLayananPenjualan)),
);

final penyediaLayananKetersediaan = Provider<LayananKetersediaan>(
  (ref) => LayananKetersediaan(klien: ref.watch(penyediaKlienPos)),
);

/// F-17 BR-17.2: Uuid produk yang ditandai habis di outlet perangkat. Dimuat saat online (bersama pembaruan katalog) dan
/// disalin ke pengaturan lokal; saat offline (termasuk setelah aplikasi dimulai ulang) memakai salinan terakhir, bukan
/// daftar kosong yang membuat semua produk tampil tersedia lagi.
class PengaturProdukHabis extends Notifier<Set<String>> {
  @override
  Set<String> build() => const <String>{};

  Future<void> Muat() async {
    final baru = await ref.read(penyediaLayananKetersediaan).Muat();
    if (baru != null) {
      state = baru;
      await _Simpan();

      return;
    }

    if (state.isEmpty) {
      state = await _AmbilTersimpan();
    }
  }

  Future<void> Ubah(String uuidProduk, {required bool habis, required StafLokal kasir}) async {
    final akhir = await ref.read(penyediaLayananKetersediaan).Ubah(uuidProduk, habis: habis, kasir: kasir);
    state = akhir ? <String>{...state, uuidProduk} : (Set<String>.of(state)..remove(uuidProduk));
    await _Simpan();
  }

  Future<void> _Simpan() =>
      ref.read(penyediaRepositori).SimpanPengaturan(KunciPengaturan.produkHabis, jsonEncode(state.toList()..sort()));

  Future<Set<String>> _AmbilTersimpan() async {
    final teks = await ref.read(penyediaRepositori).AmbilPengaturan(KunciPengaturan.produkHabis);
    if (teks == null) {
      return const <String>{};
    }
    try {
      return (jsonDecode(teks) as List<Object?>).whereType<String>().toSet();
    } on FormatException {
      return const <String>{};
    }
  }
}

final penyediaProdukHabis = NotifierProvider<PengaturProdukHabis, Set<String>>(PengaturProdukHabis.new);

/// BR-05.2: penghitung perubahan salinan stok tersedia (salinan baru diambil atau penjualan lokal berubah). Tampilan
/// yang menandai produk "Habis" memantaunya supaya ikut dihitung ulang; nilainya tidak bermakna selain berubah.
class PengaturVersiStokTersedia extends Notifier<int> {
  @override
  int build() => 0;

  void Naik() => state = state + 1;
}

final penyediaVersiStokTersedia = NotifierProvider<PengaturVersiStokTersedia, int>(PengaturVersiStokTersedia.new);

/// BR-05.2: salinan sisa stok Toko di perangkat (tersimpan di pengaturan lokal, dimuat saat layanan dibuat) yang
/// dipakai pencegah stok di keranjang. Diperbarui lewat `PengaturSesi.SegarkanStokTersedia`.
final penyediaLayananStokTersedia = Provider<LayananStokTersedia>((ref) {
  final layanan = LayananStokTersedia(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositori),
    repositoriPenjualan: ref.watch(penyediaRepositoriPenjualan),
    jam: ref.watch(penyediaJam),
  );
  layanan.saatBerubah = () => ref.read(penyediaVersiStokTersedia.notifier).Naik();
  ref.onDispose(layanan.Berhenti);
  unawaited(layanan.Mulai());
  return layanan;
});

final penyediaLayananKatalog = Provider<LayananKatalog>(
  (ref) => LayananKatalog(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositori),
    repositoriKatalog: ref.watch(penyediaRepositoriKatalog),
    jam: ref.watch(penyediaJam),
  ),
);

final penyediaLayananPenjualan = Provider<LayananPenjualan>(
  (ref) => LayananPenjualan(
    repositori: ref.watch(penyediaRepositori),
    repositoriPenjualan: ref.watch(penyediaRepositoriPenjualan),
    repositoriPelanggan: ref.watch(penyediaRepositoriPelanggan),
    stokTersedia: ref.watch(penyediaLayananStokTersedia),
    jam: ref.watch(penyediaJam),
  ),
);

/// Void transaksi di shift yang sama (F-09 fase 1).
final penyediaLayananVoid = Provider<LayananVoidPenjualan>(
  (ref) => LayananVoidPenjualan(
    repositori: ref.watch(penyediaRepositori),
    repositoriPenjualan: ref.watch(penyediaRepositoriPenjualan),
    jam: ref.watch(penyediaJam),
  ),
);

/// Retur penjualan dari struk (F-09 fase 1, cari struk online).
final penyediaLayananRetur = Provider<LayananReturPenjualan>(
  (ref) => LayananReturPenjualan(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositori),
    repositoriPenjualan: ref.watch(penyediaRepositoriPenjualan),
    jam: ref.watch(penyediaJam),
  ),
);

/// K28: retur tanpa struk (offline, PIN penyetuju ber-izin `penjualan.retur.tanpa-struk`).
final penyediaLayananReturTanpaStruk = Provider<LayananReturTanpaStruk>(
  (ref) => LayananReturTanpaStruk(
    repositori: ref.watch(penyediaRepositori),
    repositoriPenjualan: ref.watch(penyediaRepositoriPenjualan),
    layananPenjualan: ref.watch(penyediaLayananPenjualan),
    jam: ref.watch(penyediaJam),
  ),
);

/// Katalog lokal di memori (dibangun ulang setelah katalog diperbarui: `ref.invalidate(penyediaKatalog)`).
/// K-16: produk terlaris perangkat ini dalam 30 hari tanggal bisnis terakhir (Uuid produk, terlaris dulu).
final penyediaProdukTerlaris = StreamProvider<List<String>>((ref) async* {
  final k = await ref.watch(penyediaKonteksPenjualan.future);
  final sejak = k.HitungTanggalBisnis(ref.read(penyediaJam)().subtract(const Duration(days: 30)));
  yield* ref.watch(penyediaRepositoriPenjualan).PantauProdukTerlaris(sejakTanggal: sejak);
});

final penyediaKatalog = FutureProvider<KatalogLokal>(
  (ref) async => KatalogLokal.Bangun(await ref.watch(penyediaRepositoriKatalog).Muat()),
);

/// Pengaturan jual dari data awal tersimpan (outlet, pajak, diskon, pembulatan, metode bayar).
final penyediaKonteksPenjualan = FutureProvider<KonteksPenjualan>(
  (ref) => KonteksPenjualan.Muat(ref.watch(penyediaRepositori), ref.watch(penyediaRepositoriKatalog)),
);

final penyediaPesananTertahan = StreamProvider<List<BarisPesananTertahan>>(
  (ref) => ref.watch(penyediaRepositoriPenjualan).PantauPesananTertahan(),
);

/// Riwayat penjualan perangkat pada tanggal bisnis hari ini beserta status sinkron.
/// K-24: tanggal bisnis yang dilihat di Riwayat (`YYYY-MM-DD`); null = hari ini.
class TanggalRiwayat extends Notifier<String?> {
  @override
  String? build() => null;

  void Atur(String? tanggal) => state = tanggal;
}

final penyediaTanggalRiwayat = NotifierProvider<TanggalRiwayat, String?>(TanggalRiwayat.new);

/// K-24: ringkasan akhir hari outlet dari server (online).
final penyediaLayananRingkasanHarian = Provider<LayananRingkasanHarian>(
  (ref) => LayananRingkasanHarian(klien: ref.watch(penyediaKlienPos)),
);

/// Riwayat penjualan perangkat ini pada tanggal Riwayat yang dipilih (bawaan hari ini).
final penyediaRiwayatHariIni = StreamProvider<List<RiwayatPenjualan>>((ref) async* {
  final konteks = await ref.watch(penyediaKonteksPenjualan.future);
  final tanggal = ref.watch(penyediaTanggalRiwayat) ?? konteks.HitungTanggalBisnis(ref.read(penyediaJam)());
  yield* ref.watch(penyediaRepositoriPenjualan).PantauRiwayat(tanggal);
});

/// Penjualan tunai bersih (uang tunai diterima − kembalian) sebuah shift, untuk perkiraan kas di laci.
final penyediaTunaiShift = StreamProvider.family<Uang, String>(
  (ref, uuidShift) => ref.watch(penyediaRepositoriPenjualan).PantauTunaiBersihShift(uuidShift),
);

/// Refund tunai (void & retur, F-09) yang keluar dari laci sebuah shift.
final penyediaRefundTunaiShift = StreamProvider.family<Uang, String>(
  (ref, uuidShift) => ref.watch(penyediaRepositoriPenjualan).PantauRefundTunaiShift(uuidShift),
);

/// Jumlah dokumen void & retur sebuah shift (pemicu hitung ulang laporan shift).
final penyediaJumlahVoidReturShift = StreamProvider.family<int, String>(
  (ref, uuidShift) => ref.watch(penyediaRepositoriPenjualan).PantauJumlahVoidReturShift(uuidShift),
);

/// Retur perangkat pada tanggal bisnis hari ini beserta status sinkron (F-09).
final penyediaReturHariIni = StreamProvider<List<RiwayatRetur>>((ref) async* {
  final konteks = await ref.watch(penyediaKonteksPenjualan.future);
  yield* ref
      .watch(penyediaRepositoriPenjualan)
      .PantauReturTanggal(ref.watch(penyediaTanggalRiwayat) ?? konteks.HitungTanggalBisnis(ref.read(penyediaJam)()));
});

/// Laporan shift X/Z (F-11) dari data perangkat; dihitung ulang saat penjualan, kas, void, atau retur shift berubah.
final penyediaLaporanShift = FutureProvider.family<LaporanShift, String>((ref, uuidShift) async {
  ref.watch(penyediaShift(uuidShift));
  ref.watch(penyediaMutasiShift(uuidShift));
  ref.watch(penyediaTunaiShift(uuidShift));
  ref.watch(penyediaRefundTunaiShift(uuidShift));
  ref.watch(penyediaJumlahVoidReturShift(uuidShift));
  return ref.watch(penyediaLayananTutupShift).SusunLaporan(uuidShift);
});

/// Satu shift lokal (status & kolom tutup), untuk menghitung ulang laporan saat shift ditutup.
final penyediaShift = StreamProvider.family<BarisShift?, String>(
  (ref, uuidShift) => ref.watch(penyediaRepositori).PantauShift(uuidShift),
);

/// Shift yang baru ditutup dan laporan Z-nya belum ditutup kasir (layar Laporan Z sebelum buka shift berikutnya).
final penyediaLaporanZTertunda = StreamProvider<String?>(
  (ref) => ref.watch(penyediaLayananTutupShift).PantauLaporanZTertunda(),
);

/// Keranjang yang sedang dibangun di layar Jual (bertahan saat pindah menu atau ganti kasir).
///
/// v3.51: transaksi baru memakai jenis pesanan bawaan outlet (misal Makan di tempat di kafe); Bawa pulang tetap
/// `kanal == null` seperti sebelumnya.
///
/// v3.54 (K-4, §17.2.7 "ingatan kerja"): keranjang penjualan langsung yang belum dibayar disimpan ke SQLite
/// (pengaturan `DrafKeranjang`, JSON yang sama dengan pesanan tertahan) 300 ms setelah berubah, dan dipulihkan saat
/// aplikasi dibuka lagi — aplikasi tertutup, baterai habis, atau dipaksa berhenti tidak menghapus pesanan pembeli.
/// Pesanan meja tidak ikut disimpan (barisnya sudah tersimpan di pesanan terbuka).
class PengaturKeranjang extends Notifier<Keranjang> {
  static const String kunciDraf = 'DrafKeranjang';
  static const Duration jedaSimpan = Duration(milliseconds: 300);

  KanalPenjualan? _bawaan;
  Timer? _pewaktuSimpan;
  bool _sudahDipulihkan = false;

  @override
  Keranjang build() {
    ref.onDispose(() => _pewaktuSimpan?.cancel());
    unawaited(Future<void>.microtask(_Pulihkan));
    return Keranjang.kosong;
  }

  void Ganti(Keranjang keranjang) => _Atur(keranjang);

  void Kosongkan() => _Atur(_AmbilKosong());

  void _Atur(Keranjang keranjang) {
    _sudahDipulihkan = true;
    state = keranjang;
    _JadwalkanSimpan();
  }

  Keranjang _AmbilKosong() =>
      _bawaan == null || _bawaan == KanalPenjualan.BawaPulang ? Keranjang.kosong : Keranjang(kanal: _bawaan);

  /// Dipanggil saat konteks penjualan dimuat/berubah. Keranjang yang masih kosong dan belum memilih jenis pesanan
  /// langsung ikut bawaan baru; keranjang yang sedang diisi tidak diubah.
  void AturKanalBawaan(KanalPenjualan? bawaan) {
    if (bawaan == _bawaan) {
      return;
    }
    final lama = _bawaan;
    _bawaan = bawaan;
    final k = state;
    if (k.CekKosong &&
        k.pesananMeja == null &&
        k.praPesan == null &&
        k.reservasi == null &&
        k.perintahKerja == null &&
        k.kanal == lama) {
      state = _AmbilKosong();
    }
  }

  Future<void> _Pulihkan() async {
    final String? teks;
    try {
      teks = await ref.read(penyediaRepositori).AmbilPengaturan(kunciDraf);
    } on Object {
      return;
    }
    // Kasir sudah mulai mengisi sebelum draf terbaca: yang di layar yang menang.
    if (_sudahDipulihkan || teks == null || teks.isEmpty) {
      return;
    }
    try {
      final draf = Keranjang.DariJson(jsonDecode(teks) as Map<String, Object?>);
      if (!draf.CekKosong) {
        state = draf;
      }
    } on Object {
      // Draf dari versi aplikasi yang tidak bisa dibaca: dibuang, bukan membuat layar Jual gagal.
      unawaited(ref.read(penyediaRepositori).HapusPengaturan(kunciDraf));
    }
  }

  void _JadwalkanSimpan() {
    _pewaktuSimpan?.cancel();
    _pewaktuSimpan = Timer(jedaSimpan, () => unawaited(SimpanSekarang()));
  }

  /// Tulis draf sekarang (juga dipakai test). Keranjang kosong atau pesanan meja = draf dihapus.
  Future<void> SimpanSekarang() async {
    _pewaktuSimpan?.cancel();
    final k = state;
    final repositori = ref.read(penyediaRepositori);
    try {
      if (k.CekKosong || k.pesananMeja != null || k.tukar != null) {
        await repositori.HapusPengaturan(kunciDraf);
      } else {
        await repositori.SimpanPengaturan(kunciDraf, jsonEncode(k.KeJson()));
      }
    } on Object {
      // Basis data sedang ditutup (aplikasi keluar/test selesai): draf terakhir tetap yang tersimpan sebelumnya.
    }
  }
}

final penyediaKeranjang = NotifierProvider<PengaturKeranjang, Keranjang>(PengaturKeranjang.new);

// Mode meja (F-07 mode meja & F-10b fase 1) ----------------------------------------------------------------------------

final penyediaRepositoriPesananMeja = Provider<RepositoriPesananMeja>(
  (ref) => RepositoriPesananMeja(ref.watch(penyediaBasisData), ref.watch(penyediaRepositori)),
);

final penyediaLayananPesananMeja = Provider<LayananPesananMeja>(
  (ref) => LayananPesananMeja(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositori),
    repositoriMeja: ref.watch(penyediaRepositoriPesananMeja),
    jam: ref.watch(penyediaJam),
  ),
);

/// QRIS dinamis lewat gerbang pembayaran aktif (v2.05, wajib online).
final penyediaLayananQrisDinamis = Provider<LayananQrisDinamis>(
  (ref) => LayananQrisDinamis(klien: ref.watch(penyediaKlienPos)),
);

/// Struk digital lewat WhatsApp/email (v2.05, wajib online).
final penyediaLayananKirimStruk = Provider<LayananKirimStruk>(
  (ref) => LayananKirimStruk(klien: ref.watch(penyediaKlienPos)),
);

/// Isi QRIS dinamis yang sedang menunggu dibayar, untuk ditampilkan juga di layar pelanggan (null = tidak ada).
class PengaturQrisLayarPelanggan extends Notifier<String?> {
  @override
  String? build() => null;

  void Atur(String? isiQr) => state = isiQr;
}

final penyediaQrisLayarPelanggan = NotifierProvider<PengaturQrisLayarPelanggan, String?>(
  PengaturQrisLayarPelanggan.new,
);

/// F-17 self-order (v2.02): pesanan QR meja yang menunggu konfirmasi staf.
final penyediaLayananPesanSendiri = Provider<LayananPesanSendiri>(
  (ref) => LayananPesanSendiri(
    klien: ref.watch(penyediaKlienPos),
    pesananMeja: ref.watch(penyediaLayananPesananMeja),
    repositoriMeja: ref.watch(penyediaRepositoriPesananMeja),
    penjualan: ref.watch(penyediaLayananPenjualan),
  ),
);

/// Daftar pesanan QR menunggu konfirmasi (ditarik bersama pesanan terbuka tiap 7 detik saat online; kosong bila
/// offline atau fitur belum aktif).
class PengaturPesanSendiri extends Notifier<List<PesananSendiriPos>> {
  @override
  List<PesananSendiriPos> build() => const [];

  Future<void> Tarik() async {
    try {
      state = await ref.read(penyediaLayananPesanSendiri).AmbilMenunggu();
    } on GalatApi catch (galat) {
      // Fitur belum aktif / endpoint belum ada di server lama: tidak ada pesanan QR.
      if (galat.statusHttp == 403 || galat.statusHttp == 404) {
        state = const [];
      }
    }
  }

  void Hapus(String uuid) => state = [
    for (final p in state)
      if (p.uuid != uuid) p,
  ];
}

final penyediaPesanSendiri = NotifierProvider<PengaturPesanSendiri, List<PesananSendiriPos>>(PengaturPesanSendiri.new);

/// Mode meja outlet aktif (dari `GET /api/pos/v1/meja`): menampilkan menu Meja di rel navigasi.
final penyediaModeMeja = StreamProvider<bool>(
  (ref) => ref.watch(penyediaRepositori).PantauPengaturan(KunciPengaturan.modeMejaAktif).map((n) => n == '1'),
);

/// K-8: mode kasir bawaan outlet (template sektor); null bila belum ada. Menentukan beranda ruang kerja (Meja) dan
/// tampilan katalog otomatis (Retail/Grosir → daftar).
final penyediaModeKasir = StreamProvider<String?>(
  (ref) => ref
      .watch(penyediaRepositori)
      .PantauPengaturan(KunciPengaturan.modeKasirBawaan)
      .map((n) => n == null || n.isEmpty ? null : n),
);

/// Jenis perangkat dari aktivasi: `Kds` membuka layar dapur, selain itu ruang kerja kasir.
final penyediaJenisPerangkat = StreamProvider<String>(
  (ref) => ref.watch(penyediaRepositori).PantauPengaturan(KunciPengaturan.jenisPerangkat).map((n) => n ?? 'Kasir'),
);

final penyediaAreaMeja = StreamProvider<List<BarisAreaMeja>>(
  (ref) => ref.watch(penyediaRepositoriPesananMeja).PantauArea(),
);

final penyediaMeja = StreamProvider<List<BarisMeja>>((ref) => ref.watch(penyediaRepositoriPesananMeja).PantauMeja());

/// K-12: meja yang perlu dibersihkan setelah tagihannya dibayar (Uuid meja → sejak).
final penyediaMejaPerluDibersihkan = StreamProvider<Map<String, DateTime>>(
  (ref) => ref.watch(penyediaRepositoriPesananMeja).PantauMejaPerluDibersihkan(),
);

final penyediaPesananTerbuka = StreamProvider<List<PesananMeja>>(
  (ref) => ref.watch(penyediaRepositoriPesananMeja).PantauPesananTerbuka(),
);

final penyediaPesananMeja = StreamProvider.family<PesananMeja?, String>(
  (ref, uuid) => ref.watch(penyediaRepositoriPesananMeja).PantauPesanan(uuid),
);

// Pelanggan (F-16a) ----------------------------------------------------------------------------------------------------

/// F-18: kamera swafoto absensi (tiruan di test).
final penyediaKameraSwafoto = Provider<KameraSwafoto>((ref) => KameraSwafotoPlatform());

/// K-18: kamera belakang untuk foto bukti kas masuk/keluar (tiruan di test).
final penyediaKameraBukti = Provider<KameraBukti>((ref) => KameraBuktiPlatform());

/// F-02 langkah 5: pemindai QR kode aktivasi (tiruan di test). Tidak tersedia di Windows.
final penyediaPemindaiQr = Provider<PemindaiQr>((ref) => PemindaiQrPlatform());

final penyediaRepositoriAbsensi = Provider<RepositoriAbsensi>(
  (ref) => RepositoriAbsensi(ref.watch(penyediaBasisData), ref.watch(penyediaRepositori)),
);

/// F-18: absen masuk/keluar staf dengan PIN + swafoto.
final penyediaLayananAbsensi = Provider<LayananAbsensi>(
  (ref) => LayananAbsensi(
    repositori: ref.watch(penyediaRepositoriAbsensi),
    kamera: ref.watch(penyediaKameraSwafoto),
    jam: ref.watch(penyediaJam),
  ),
);

final penyediaRepositoriPelanggan = Provider<RepositoriPelanggan>(
  (ref) => RepositoriPelanggan(ref.watch(penyediaBasisData), ref.watch(penyediaRepositori)),
);

/// F-16c bagian 2: voucher keranjang (wajib online).
final penyediaLayananVoucher = Provider<LayananVoucher>((ref) => LayananVoucher(klien: ref.watch(penyediaKlienPos)));

final penyediaLayananPelanggan = Provider<LayananPelanggan>(
  (ref) => LayananPelanggan(
    klien: ref.watch(penyediaKlienPos),
    repositori: ref.watch(penyediaRepositoriPelanggan),
    jam: ref.watch(penyediaJam),
  ),
);

/// Layar dapur (KDS) untuk perangkat berjenis `Kds`.
final penyediaLayananDapur = Provider<LayananDapur>(
  (ref) => LayananDapur(klien: ref.watch(penyediaKlienPos), repositori: ref.watch(penyediaRepositori)),
);

/// Keranjang yang ditampilkan & dibayar: pada pesanan meja = baris tersimpan pesanan + baris baru (draf).
final penyediaKeranjangEfektif = Provider<Keranjang>((ref) {
  final draf = ref.watch(penyediaKeranjang);
  final uuid = draf.pesananMeja?.uuid;
  if (uuid == null) {
    return draf;
  }
  final pesanan = ref.watch(penyediaPesananMeja(uuid)).value;
  final katalog = ref.watch(penyediaKatalog).value ?? KatalogLokal.kosong;
  return LayananPesananMeja.SusunKeranjangEfektif(draf, pesanan, katalog);
});

final penyediaShiftAktif = StreamProvider<BarisShift?>((ref) => ref.watch(penyediaRepositori).PantauShiftAktif());

final penyediaMutasiShift = StreamProvider.family<List<BarisMutasiKas>, String>(
  (ref, uuidShift) => ref.watch(penyediaRepositori).PantauMutasi(uuidShift),
);

final penyediaJumlahTertunda = StreamProvider<int>((ref) => ref.watch(penyediaRepositori).PantauJumlahTertunda());

/// D-40: antrean kirim per jenis (layar Status sinkron).
final penyediaRingkasanTertunda = StreamProvider<List<({String jenis, int jumlah})>>(
  (ref) => ref.watch(penyediaRepositori).PantauRingkasanTertunda(),
);

/// K-17: waktu item tertunda tertua (null = tidak ada).
final penyediaOutboxTertua = StreamProvider<DateTime?>((ref) => ref.watch(penyediaRepositori).PantauOutboxTertua());

/// K-17: pengaturan hasil sinkron (sinkron terakhir, selisih jam, penjualan ditinjau).
final penyediaPengaturanSinkron = StreamProvider<Map<String, String>>(
  (ref) => ref.watch(penyediaRepositori).PantauPengaturanSinkron(),
);

/// K-17: penjualan perangkat ini yang ditandai tinjauan back-office (nomor dokumen; Uuid bila sudah tidak ada lokal).
final penyediaPenjualanDitinjau = FutureProvider<List<String>>((ref) async {
  final pengaturan = await ref.watch(penyediaPengaturanSinkron.future);
  final isi = pengaturan[KunciPengaturan.penjualanPerluTinjauan];
  if (isi == null || isi.isEmpty) {
    return const [];
  }
  final uuid = await ref.read(penyediaRepositori).AmbilPenjualanPerluTinjauan();
  final repo = ref.read(penyediaRepositoriPenjualan);
  return [for (final u in uuid) (await repo.CariPenjualan(u))?.Nomor ?? u];
});

final penyediaPerluTindakan = StreamProvider<List<BarisOutbox>>(
  (ref) => ref.watch(penyediaRepositori).PantauPerluTindakan(),
);

/// F-18: staf pelayan baris penjualan (komisi) dari data awal.
final penyediaKaryawanPos = FutureProvider<List<KaryawanPos>>((ref) => ref.watch(penyediaRepositori).AmbilKaryawan());

final penyediaStaf = FutureProvider<List<StafLokal>>(
  (ref) async => (await ref.watch(penyediaRepositori).AmbilStaf()).map(StafLokal.DariBaris).toList(),
);

final penyediaKategori = FutureProvider.family<List<BarisKategoriKas>, String>(
  (ref, jenis) => ref.watch(penyediaRepositori).AmbilKategori(jenis),
);

/// Gambar QRIS statis metode pembayaran: disimpan di memori supaya tetap tampil saat offline, dan dibuang setiap data
/// awal diperbarui (`SegarkanData`, `SegarkanDataAwalBerkala`) supaya QRIS yang diganti pemilik tidak tampil usang.
final penyediaGambarQris = FutureProvider.family<Uint8List, String>(
  (ref, uuidMetode) => ref.watch(penyediaKlienPos).AmbilGambarQris(uuidMetode),
);

/// Identitas outlet & perangkat untuk kepala layar.
final penyediaIdentitas = FutureProvider<({String outlet, String perangkat})>((ref) async {
  final repo = ref.watch(penyediaRepositori);
  return (
    outlet: await repo.AmbilPengaturan(KunciPengaturan.namaOutlet) ?? '',
    perangkat: await repo.AmbilPengaturan(KunciPengaturan.kodePerangkat) ?? '',
  );
});

/// Pengaturan lokal perangkat (ukuran tampilan, posisi keranjang, kunci otomatis). Dimuat dari tabel `Pengaturan`;
/// sebelum selesai dimuat memakai nilai bawaan.
class PengaturPengaturanPerangkat extends Notifier<PengaturanPerangkat> {
  var _diubah = false;

  @override
  PengaturanPerangkat build() {
    unawaited(_Muat());
    return const PengaturanPerangkat();
  }

  Future<void> _Muat() async {
    final dimuat = await PengaturanPerangkat.Muat(ref.read(penyediaRepositori));
    if (!_diubah) {
      state = dimuat;
    }
  }

  Future<void> Simpan(PengaturanPerangkat baru) async {
    _diubah = true;
    state = baru;
    await baru.Simpan(ref.read(penyediaRepositori));
  }
}

final penyediaPengaturanPerangkat = NotifierProvider<PengaturPengaturanPerangkat, PengaturanPerangkat>(
  PengaturPengaturanPerangkat.new,
);

/// v2.01: pembuat adaptor layar pelanggan menurut pengaturan (test mengganti dengan tiruan).
typedef PembuatLayarPelanggan = PortLayarPelanggan Function(PengaturanLayarPelanggan pengaturan);

final penyediaPembuatLayarPelanggan = Provider<PembuatLayarPelanggan>((ref) {
  const w = TokenWarna.bawaan;
  final warna = {
    'Latar': w.permukaan.toARGB32(),
    'Teks': w.teksUtama.toARGB32(),
    'TeksSekunder': w.teksSekunder.toARGB32(),
    'Aksen': w.brandGelap.toARGB32(),
  };
  return (pengaturan) => PabrikLayarPelanggan.Buat(pengaturan, warna);
});

/// Mode layar pelanggan yang didukung platform ini (Mati selalu ada).
final penyediaModeLayarPelanggan = Provider<List<String>>((ref) => PabrikLayarPelanggan.AmbilModeTersedia());

/// Layar pelanggan perangkat ini (PRD §17.2.5a, v2.01). Galat layar pelanggan tidak pernah mengganggu transaksi:
/// [Tampilkan] mengembalikan pesan galat (untuk tombol uji di Pengaturan) dan tidak melempar.
class PengaturLayarPelanggan extends Notifier<PengaturanLayarPelanggan> {
  PortLayarPelanggan _port = const LayarPelangganTidakAda();
  var _diubah = false;

  @override
  PengaturanLayarPelanggan build() {
    unawaited(_Muat());
    ref.onDispose(() => unawaited(_port.Tutup().catchError((Object _) {})));
    return const PengaturanLayarPelanggan();
  }

  Future<void> _Muat() async {
    final dimuat = await PengaturanLayarPelanggan.Muat(ref.read(penyediaRepositori));
    if (!_diubah) {
      state = dimuat;
      _port = ref.read(penyediaPembuatLayarPelanggan)(dimuat);
    }
  }

  Future<String?> Simpan(PengaturanLayarPelanggan baru) async {
    _diubah = true;
    await _port.Tutup().catchError((Object _) {});
    final lengkap = baru.Salin(namaToko: baru.namaToko.isEmpty ? state.namaToko : baru.namaToko);
    state = lengkap;
    _port = ref.read(penyediaPembuatLayarPelanggan)(lengkap);
    await lengkap.Simpan(ref.read(penyediaRepositori));
    return Tampilkan(PenyusunLayarPelanggan.Siaga(lengkap.namaToko));
  }

  /// null = berhasil (atau layar pelanggan mati).
  Future<String?> Tampilkan(IsiLayarPelanggan isi) async {
    if (!state.aktif) {
      return null;
    }
    try {
      await _port.Tampilkan(isi);
      return null;
    } on GalatPrinter catch (galat) {
      return galat.pesan;
    } on Object catch (galat) {
      return 'Layar pelanggan tidak bisa dipakai: $galat';
    }
  }
}

final penyediaLayarPelanggan = NotifierProvider<PengaturLayarPelanggan, PengaturanLayarPelanggan>(
  PengaturLayarPelanggan.new,
);

/// Koneksi ke server menurut hasil sinkron terakhir (bilah status ruang kerja).
enum StatusKoneksi { BelumDiketahui, Online, Offline }

class PengaturKoneksi extends Notifier<StatusKoneksi> {
  @override
  StatusKoneksi build() => StatusKoneksi.BelumDiketahui;

  void Tandai(StatusKoneksi status) => state = status;
}

final penyediaKoneksi = NotifierProvider<PengaturKoneksi, StatusKoneksi>(PengaturKoneksi.new);

/// P-10 (§14.6): konfigurasi aplikasi terakhir dari server (versi terbaru/minimal, catatan rilis, flag fitur). Null =
/// belum pernah berhasil dibaca (offline): aplikasi tetap berjalan. Diperiksa saat data disegarkan dan paling sering
/// tiap [selangPeriksa] dari putaran sinkron.
class PengaturKonfigurasiAplikasi extends Notifier<KonfigurasiAplikasi?> {
  static const Duration selangPeriksa = Duration(minutes: 15);

  DateTime? _terakhir;

  @override
  KonfigurasiAplikasi? build() => null;

  Future<void> Periksa({bool paksa = false}) async {
    final sekarang = ref.read(penyediaJam)();
    final terakhir = _terakhir;
    if (!paksa && terakhir != null && sekarang.difference(terakhir) < selangPeriksa) {
      return;
    }
    _terakhir = sekarang;
    try {
      final konfigurasi = await ref.read(penyediaKlienPos).AmbilKonfigurasiAplikasi();
      state = konfigurasi;
      // Fitur paket yang diubah di konsol ikut sampai ke perangkat tanpa menunggu "Perbarui data kasir".
      await ref.read(penyediaLayananPersetujuanJarakJauh).SimpanKeadaan(konfigurasi.persetujuanJarakJauh);
    } on GalatJaringan {
      _terakhir = null;
    } on GalatApi {
      // Perangkat dicabut / galat server: ditangani alur sinkron; konfigurasi lama tetap dipakai.
    }
  }
}

final penyediaKonfigurasiAplikasi = NotifierProvider<PengaturKonfigurasiAplikasi, KonfigurasiAplikasi?>(
  PengaturKonfigurasiAplikasi.new,
);

enum TahapSesi { Memuat, BelumAktif, PilihKasir, Masuk }

/// Kunci layar ruang kerja (§17.2.7): `Terkunci` = buka dengan PIN kasir yang sama atau ganti kasir; `GantiKasir` =
/// pilih kasir lain + PIN (dari ketuk nama kasir), bisa dibatalkan. Shift tetap terbuka pada keduanya.
enum KeadaanKunci { Bebas, Terkunci, GantiKasir }

class KeadaanSesi {
  const KeadaanSesi(this.tahap, {this.kasir, this.pesan, this.kunci = KeadaanKunci.Bebas});

  final TahapSesi tahap;
  final StafLokal? kasir;
  final KeadaanKunci kunci;

  /// Pesan penting untuk ditampilkan sekali (misal perangkat dicabut).
  final String? pesan;
}

/// Alur sesi kasir: belum aktif → pilih kasir & PIN → masuk (buka shift / shift berjalan).
class PengaturSesi extends Notifier<KeadaanSesi> {
  /// Penjaga agar pencabutan hanya ditangani sekali: kaitnya bisa terpicu beberapa permintaan sekaligus.
  bool _sedangDicabut = false;

  /// Kapan daftar staf (izin & verifier PIN) terakhir disegarkan di latar oleh [SegarkanStafBilaPerlu].
  DateTime? _stafDisegarkanPada;

  @override
  KeadaanSesi build() {
    unawaited(_Muat());
    return const KeadaanSesi(TahapSesi.Memuat);
  }

  Future<void> _Muat() async {
    final aktif = await ref.read(penyediaLayananPerangkat).CekSudahAktif();
    state = KeadaanSesi(aktif ? TahapSesi.PilihKasir : TahapSesi.BelumAktif);
    if (aktif) {
      unawaited(SegarkanData());
    }
  }

  /// [alamatServer] (D-35): server toko sendiri dari QR/ketikan; disimpan sebelum aktivasi agar klien API memakainya.
  Future<void> Aktifkan(String kode, {Uri? alamatServer}) async {
    if (alamatServer != null) {
      await ref.read(penyediaAlamatServer.notifier).Atur(alamatServer);
    }
    await ref.read(penyediaLayananPerangkat).Aktifkan(kode);
    _sedangDicabut = false;
    ref.invalidate(penyediaStaf);
    ref.invalidate(penyediaKaryawanPos);
    ref.invalidate(penyediaIdentitas);
    ref.invalidate(penyediaKonteksPenjualan);
    // BR-05.2: salinan stok milik outlet sebelumnya tidak boleh berlaku di outlet ini.
    await ref.read(penyediaLayananStokTersedia).Kosongkan();
    state = const KeadaanSesi(TahapSesi.PilihKasir);
    unawaited(PerbaruiKatalog());
    unawaited(PerbaruiDataMeja());
  }

  /// [sebelumMasuk] dipanggil setelah PIN benar & sebelum ruang kerja terbuka (misal tawaran absen masuk, audit #31).
  Future<void> Masuk(StafLokal staf, String pin, {Future<void> Function(StafLokal kasir)? sebelumMasuk}) async {
    final kasir = await ref.read(penyediaLayananMasuk).Masuk(staf, pin);
    await sebelumMasuk?.call(kasir);
    // PIN diverifikasi lokal supaya kasir tetap bisa masuk tanpa internet (BR-06.3), jadi pencabutan perangkat
    // tidak ketahuan dari PIN saja. Keputusan pemilik produk (v2.64): saat online, masuk **diblokir** — galatnya
    // dilempar supaya papan PIN menyebut alasannya, sementara kait penolakan token membawa aplikasi kembali ke
    // layar aktivasi. Offline (atau server tidak menjawab dalam batas waktu): masuk tetap diizinkan.
    final tersambung = await ref.read(penyediaLayananPerangkat).PeriksaMasihBerlaku();
    ref.read(penyediaKoneksi.notifier).Tandai(tersambung ? StatusKoneksi.Online : StatusKoneksi.Offline);
    state = KeadaanSesi(TahapSesi.Masuk, kasir: kasir);
  }

  void Keluar() => state = const KeadaanSesi(TahapSesi.PilihKasir);

  /// Kunci ruang kerja tanpa menutup shift (kunci cepat, kunci otomatis, atau ganti kasir).
  void Kunci({bool gantiKasir = false}) {
    if (state.tahap != TahapSesi.Masuk) {
      return;
    }
    if (state.kunci == KeadaanKunci.Terkunci && gantiKasir) {
      return;
    }
    state = KeadaanSesi(
      TahapSesi.Masuk,
      kasir: state.kasir,
      kunci: gantiKasir ? KeadaanKunci.GantiKasir : KeadaanKunci.Terkunci,
    );
  }

  /// Batal ganti kasir (hanya dari ketuk nama kasir; layar terkunci tetap butuh PIN).
  void BatalGantiKasir() {
    if (state.tahap == TahapSesi.Masuk && state.kunci == KeadaanKunci.GantiKasir) {
      state = KeadaanSesi(TahapSesi.Masuk, kasir: state.kasir);
    }
  }

  /// Perbarui data awal (staf, kategori, pengaturan) bila online; perangkat dicabut → kembali ke aktivasi.
  Future<void> SegarkanData() async {
    try {
      final tersambung = await ref.read(penyediaLayananPerangkat).SegarkanDataAwal();
      ref.read(penyediaKoneksi.notifier).Tandai(tersambung ? StatusKoneksi.Online : StatusKoneksi.Offline);
      ref.invalidate(penyediaStaf);
      ref.invalidate(penyediaKaryawanPos);
      ref.invalidate(penyediaKategori);
      ref.invalidate(penyediaKonteksPenjualan);
      ref.invalidate(penyediaIdentitas);
      ref.invalidate(penyediaGambarQris);
      if (tersambung) {
        await PerbaruiKatalog();
        await PerbaruiDataMeja();
        await ref.read(penyediaKonfigurasiAplikasi.notifier).Periksa(paksa: true);
        await SegarkanStokTersedia();
      }
    } on GalatKasir catch (galat) {
      await _TanganiDicabut(galat.pesan);
    } on GalatApi {
      // Galat server lain: tetap pakai data lokal terakhir.
      ref.read(penyediaKoneksi.notifier).Tandai(StatusKoneksi.Online);
    }
  }

  /// Segarkan daftar staf (PIN, izin, status aktif) di latar supaya perubahan dari back-office, misalnya PIN diganti
  /// atau akses dicabut, tidak menunggu kasir menekan "Perbarui data". PIN diverifikasi lokal (BR-06.3), jadi tanpa ini
  /// PIN lama tetap diterima sampai data awal diunduh ulang. Hanya berjalan di layar pilih kasir atau saat terkunci,
  /// yaitu sebelum PIN diketik, dan tidak mengganggu transaksi yang sedang berjalan. [paksa] melewati jeda [jeda].
  Future<void> SegarkanStafBilaPerlu({bool paksa = false, Duration jeda = const Duration(minutes: 5)}) async {
    final menungguPin =
        state.tahap == TahapSesi.PilihKasir || (state.tahap == TahapSesi.Masuk && state.kunci != KeadaanKunci.Bebas);
    if (_sedangDicabut || !menungguPin) {
      return;
    }
    final sekarang = ref.read(penyediaJam)();
    final terakhir = _stafDisegarkanPada;
    if (!paksa && terakhir != null && sekarang.difference(terakhir) < jeda) {
      return;
    }
    _stafDisegarkanPada = sekarang;
    try {
      // Status koneksi sengaja tidak disentuh: itu urusan pemeriksaan perangkat dan sinkron.
      final tersambung = await ref.read(penyediaLayananPerangkat).SegarkanDataAwal();
      if (tersambung) {
        ref.invalidate(penyediaStaf);
        ref.invalidate(penyediaKaryawanPos);
        ref.invalidate(penyediaIdentitas);
      }
    } on GalatKasir catch (galat) {
      await _TanganiDicabut(galat.pesan);
    } on Object {
      // Galat lain: tetap pakai data lokal terakhir; dicoba lagi pada putaran berikutnya.
      return;
    }
  }

  DateTime? _dataAwalBerkalaPada;

  /// Audit kesegaran data (P1): data awal (PIN & izin staf, batas diskon/retur/tempo, metode bayar, tarif pajak,
  /// kategori kas, gambar QRIS) tidak boleh menunggu kasir menekan "Perbarui data kasir" atau mengunci layar. Dipanggil
  /// dari layar Jual saat keranjang kosong, jadi tidak mengubah konteks hitung di tengah transaksi. Hanya saat online;
  /// paling sering sekali per [jeda].
  Future<void> SegarkanDataAwalBerkala({Duration jeda = const Duration(minutes: 10)}) async {
    if (_sedangDicabut || state.tahap != TahapSesi.Masuk || ref.read(penyediaKoneksi) != StatusKoneksi.Online) {
      return;
    }
    final sekarang = ref.read(penyediaJam)();
    final terakhir = _dataAwalBerkalaPada;
    if (terakhir != null && sekarang.difference(terakhir) < jeda) {
      return;
    }
    _dataAwalBerkalaPada = sekarang;
    try {
      final tersambung = await ref.read(penyediaLayananPerangkat).SegarkanDataAwal();
      if (tersambung) {
        ref.invalidate(penyediaStaf);
        ref.invalidate(penyediaKaryawanPos);
        ref.invalidate(penyediaKategori);
        ref.invalidate(penyediaKonteksPenjualan);
        ref.invalidate(penyediaIdentitas);
        ref.invalidate(penyediaGambarQris);
      }
    } on GalatKasir catch (galat) {
      await _TanganiDicabut(galat.pesan);
    } on Object {
      // Galat lain: data lokal terakhir tetap dipakai; dicoba lagi pada putaran berikutnya.
      return;
    }
  }

  DateTime? _stafUntukPinPada;

  /// Audit kesegaran data (P2): daftar staf (PIN, izin, status aktif) disegarkan saat dialog PIN penyetuju dibuka, supaya
  /// PIN yang baru diganti atau izin yang baru dicabut berlaku di perangkat ini juga. Yang disegarkan hanya data staf
  /// (bukan konteks hitung), jadi aman di tengah transaksi. Offline atau gagal = data lokal. Paling sering sekali per
  /// [jeda].
  Future<void> SegarkanStafUntukPin({Duration jeda = const Duration(seconds: 30)}) async {
    if (_sedangDicabut || state.tahap != TahapSesi.Masuk) {
      return;
    }
    final sekarang = ref.read(penyediaJam)();
    final terakhir = _stafUntukPinPada;
    if (terakhir != null && sekarang.difference(terakhir) < jeda) {
      return;
    }
    _stafUntukPinPada = sekarang;
    try {
      final tersambung = await ref.read(penyediaLayananPerangkat).SegarkanDataAwal();
      if (tersambung) {
        ref.invalidate(penyediaStaf);
        ref.invalidate(penyediaKaryawanPos);
      }
    } on GalatKasir catch (galat) {
      await _TanganiDicabut(galat.pesan);
    } on Object {
      return;
    }
  }

  /// Unduh katalog (lengkap/delta) lalu bangun ulang katalog di memori bila berubah (Rincian F-07c).
  Future<HasilPerbaruiKatalog> PerbaruiKatalog() async {
    final layanan = ref.read(penyediaLayananKatalog);
    final hasil = await layanan.Perbarui();
    if (hasil == HasilPerbaruiKatalog.Lengkap || hasil == HasilPerbaruiKatalog.Delta) {
      await layanan.PerbaruiPromo();
      ref.invalidate(penyediaKatalog);
      // F-16c: promo & kategori produk ikut konteks penjualan.
      ref.invalidate(penyediaKonteksPenjualan);
    }
    if (hasil == HasilPerbaruiKatalog.Offline) {
      ref.read(penyediaKoneksi.notifier).Tandai(StatusKoneksi.Offline);
    }
    return hasil;
  }

  /// Unduh data meja outlet (mode meja). Galat server tidak menghentikan kerja kasir.
  Future<void> PerbaruiDataMeja() async {
    try {
      await ref.read(penyediaLayananPesananMeja).PerbaruiDataMeja();
    } on GalatApi {
      return;
    }
  }

  /// Kirim outbox; perangkat dicabut → kembali ke aktivasi.
  Future<RingkasanSinkron> Sinkronkan() async {
    final hasil = await ref.read(penyediaLayananSinkron).KirimTertunda();
    if (hasil.tersambung != null) {
      ref.read(penyediaKoneksi.notifier).Tandai(hasil.tersambung! ? StatusKoneksi.Online : StatusKoneksi.Offline);
    }
    if (hasil.perangkatDicabut) {
      final sisa = await ref.read(penyediaRepositori).HitungJumlahTertunda();
      _Dicabut(
        sisa == 0
            ? 'Perangkat ini sudah dicabut dari back-office. Semua data sudah terkirim.'
            : 'Perangkat ini sudah dicabut dari back-office. $sisa data belum terkirim dan tetap tersimpan; data dikirim '
                  'atas nama perangkat ini setelah aktivasi ulang.',
      );
    } else if (hasil.tersambung != false) {
      // P-10: versi & flag diperiksa paling sering tiap 15 menit, tidak saat offline.
      await ref.read(penyediaKonfigurasiAplikasi.notifier).Periksa();
      // BR-05.2: setelah outbox benar-benar kosong, server sudah memuat semua penjualan perangkat ini, jadi salinan
      // stok diambil lagi (penjualan lokal yang tadinya dihitung terpisah kini tercakup).
      if (hasil.tersambung == true &&
          hasil.terkirim > 0 &&
          await ref.read(penyediaRepositori).HitungJumlahTertunda() == 0) {
        unawaited(SegarkanStokTersedia());
      }
    }
    return hasil;
  }

  /// BR-05.2: ambil salinan sisa stok Toko dari server untuk pencegah stok di keranjang. Hanya saat online (offline =
  /// salinan terakhir tetap dipakai); galat server tidak melempar.
  Future<void> SegarkanStokTersedia() async {
    if (ref.read(penyediaKoneksi) != StatusKoneksi.Online) {
      return;
    }
    await ref.read(penyediaLayananStokTersedia).Segarkan();
  }

  /// §18.3 butir 6 (K-6): kirim outbox sekarang juga, termasuk item yang sedang menunggu jadwal coba ulang. Dipakai saat
  /// aplikasi kembali ke depan dan saat koneksi pulih, karena jeda coba ulang bisa sampai 5 menit dan transaksi offline
  /// tidak perlu menunggu selama itu begitu jaringan kembali. Diabaikan sebelum perangkat aktif.
  Future<RingkasanSinkron?> SinkronkanSegera() async {
    if (_sedangDicabut || state.tahap == TahapSesi.BelumAktif || state.tahap == TahapSesi.Memuat) {
      return null;
    }
    await ref.read(penyediaRepositori).SegerakanTertunda(ref.read(penyediaJam)());
    return Sinkronkan();
  }

  /// BR-02.3: pemeriksaan keabsahan perangkat di latar, dipanggil berkala dan saat aplikasi kembali ke depan.
  ///
  /// Pencabutan harus mengembalikan aplikasi ke layar aktivasi tanpa menunggu kasir melakukan apa pun — shift
  /// terbuka tetapi sepi, atau aplikasi hanya berdiri di layar pilih kasir, tetap terdeteksi.
  ///
  /// Hasil panggilannya sendiri tidak dipakai selain untuk status koneksi: sinyal pencabutan datang lewat kait
  /// `KlienPos.saatPerangkatDitolak`, yang berjalan sebelum jawaban server sempat diurai. Karena itu jawaban aneh
  /// dari server pun aman diabaikan di sini — bukan galat yang disembunyikan, melainkan galat yang memang bukan
  /// urusan pemeriksaan ini.
  Future<void> PeriksaPerangkat() async {
    if (_sedangDicabut || state.tahap == TahapSesi.BelumAktif || state.tahap == TahapSesi.Memuat) {
      return;
    }
    try {
      final tersambung = await ref.read(penyediaLayananPerangkat).PeriksaMasihBerlaku();
      ref.read(penyediaKoneksi.notifier).Tandai(tersambung ? StatusKoneksi.Online : StatusKoneksi.Offline);
    } on Object {
      return;
    }
  }

  /// BR-02.3: server menolak token perangkat ini di permintaan mana pun (kait `KlienPos.saatPerangkatDitolak`).
  /// Dipanggil dari dalam permintaan yang sedang gagal, jadi kerjanya dijadwalkan dan hanya sekali per sesi.
  void TanganiPenolakanPerangkat(String pesan) {
    if (_sedangDicabut || state.tahap == TahapSesi.BelumAktif) {
      return;
    }
    _sedangDicabut = true;
    unawaited(_TanganiDicabut(pesan));
  }

  /// Audit P0 F-01: perangkat dicabut. Kirim sisa outbox dulu (masa pemulihan), baru kembali ke aktivasi.
  Future<void> _TanganiDicabut(String pesan) async {
    final selesai = await ref.read(penyediaLayananSinkron).SelesaikanPencabutan();
    final sisa = await ref.read(penyediaRepositori).HitungJumlahTertunda();
    _Dicabut(
      selesai && sisa == 0
          ? pesan
          : '$pesan $sisa data belum terkirim dan tetap tersimpan. Data dikirim atas nama perangkat ini setelah '
                'aktivasi ulang, atau saat aplikasi dibuka lagi dalam keadaan online.',
    );
  }

  void _Dicabut(String pesan) {
    ref.invalidate(penyediaStaf);
    ref.invalidate(penyediaKaryawanPos);
    state = KeadaanSesi(TahapSesi.BelumAktif, pesan: pesan);
  }
}

final penyediaSesi = NotifierProvider<PengaturSesi, KeadaanSesi>(PengaturSesi.new);
