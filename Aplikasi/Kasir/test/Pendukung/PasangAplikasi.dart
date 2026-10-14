import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kasir/Tampilan/Jual/UmpanBalikPindai.dart';
import 'package:kasir/Aplikasi/AplikasiKasir.dart';
import 'package:kasir/Aplikasi/Lingkungan.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Aplikasi/PenyediaSalesman.dart';
import 'package:kasir/Domain/Diagnostik/LogLokal.dart';
import 'package:kasir/Domain/Perangkat/KameraBukti.dart';
import 'package:kasir/Domain/Pembaruan/PemasangPembaruan.dart';
import 'package:kasir/Domain/Perangkat/KameraSwafoto.dart';
import 'package:kasir/Domain/Perangkat/LayananLayarPelanggan.dart';
import 'package:kasir/Domain/Perangkat/PemindaiKamera.dart';
import 'package:kasir/Domain/Perangkat/PemindaiQr.dart';
import 'package:kasir/Domain/Perangkat/PenentuLokasi.dart';
import 'package:kasir/Domain/Perangkat/PenjagaLayarMenyala.dart';
import 'package:kasir/Domain/Pin/PemverifikasiPinOffline.dart';
import 'package:klien_api/KlienApi.dart';

import 'LingkunganUji.dart';

/// Pasang aplikasi utuh dengan basis data memori, secure storage memori, server tiruan, dan penjaga layar tiruan.
/// [ukuran] = ukuran layar logis (bawaan 1280×900 dp).
Future<void> PasangAplikasi(
  WidgetTester tester,
  LingkunganUji u, {
  Lingkungan lingkungan = Lingkungan.Produksi,
  Size ukuran = const Size(1280, 900),
  PenjagaLayarTiruan? penjagaLayar,
  KameraSwafoto? kamera,
  KameraBukti? kameraBukti,
  PemindaiQr? pemindaiQr,
  PemindaiKameraTiruan? pemindaiKamera,
  UmpanBalikPindai? umpanBalikPindai,
  LogLokal? logLokal,
  PenentuLokasi penentuLokasi = const PenentuLokasiTidakAda(),
  // POS-23: pemasang pembaruan tiruan (bawaan: tanpa pemasang sendiri, seperti Android/iOS).
  PemasangPembaruan pemasangPembaruan = const PemasangPembaruanTidakAda(),
  // D-66: bawaan aplikasi = rel tertutup; test layar memakai rel terbuka agar label menu bisa diketuk.
  bool relAwalDiciutkan = false,
}) async {
  // Ukuran logis juga untuk MediaQuery (tata letak ruang kerja memakai lebar layar), bukan hanya permukaan render.
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = ukuran;
  addTearDown(tester.view.reset);
  await tester.binding.setSurfaceSize(ukuran);
  addTearDown(() => tester.binding.setSurfaceSize(null));
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        penyediaRelAwalDiciutkan.overrideWithValue(relAwalDiciutkan),
        penyediaBasisData.overrideWithValue(u.db),
        penyediaRahasia.overrideWithValue(u.rahasia),
        penyediaKlienHttp.overrideWithValue(u.server.BuatKlien()),
        penyediaJam.overrideWithValue(() => u.jam),
        penyediaLingkungan.overrideWithValue(lingkungan),
        penyediaPemverifikasiPin.overrideWithValue(const PemverifikasiPinTiruan()),
        penyediaPenjagaLayar.overrideWithValue(penjagaLayar ?? PenjagaLayarTiruan()),
        penyediaKameraSwafoto.overrideWithValue(kamera ?? KameraSwafotoTiruan(tersedia: false)),
        penyediaKameraBukti.overrideWithValue(kameraBukti ?? KameraBuktiTiruan(tersedia: false)),
        penyediaPemindaiQr.overrideWithValue(pemindaiQr ?? PemindaiQrTiruan(tersedia: false)),
        penyediaPemindaiKamera.overrideWithValue(pemindaiKamera ?? PemindaiKameraTiruan(tersedia: false)),
        penyediaPemindaiPrinter.overrideWithValue(u.pemindai),
        penyediaUmpanBalikPindai.overrideWithValue(umpanBalikPindai ?? const UmpanBalikPindai()),
        penyediaLogLokal.overrideWithValue(logLokal),
        // Modul Salesman bagian 2: lokasi tidak pernah memanggil plugin platform di test.
        penyediaPenentuLokasi.overrideWithValue(penentuLokasi),
        penyediaPemasangPembaruan.overrideWithValue(pemasangPembaruan),
        penyediaPembuatLayarPelanggan.overrideWithValue(
          (p) => p.aktif ? u.layarPelanggan : const LayarPelangganTidakAda(),
        ),
        penyediaModeLayarPelanggan.overrideWithValue(const [
          ModeLayarPelanggan.mati,
          ModeLayarPelanggan.layarKedua,
          ModeLayarPelanggan.vfd,
        ]),
      ],
      child: AplikasiKasir(lingkungan: lingkungan),
    ),
  );
  await Tunggu(tester);
}

/// Beri waktu untuk kerja async (SQLite, Argon2id): bergantian menunggu waktu nyata dan memajukan waktu palsu test,
/// sehingga future yang dimulai dari ketukan (zona waktu palsu) maupun dari luar sama-sama selesai.
Future<void> Tunggu(WidgetTester tester, [Duration lama = const Duration(milliseconds: 300)]) async {
  final putaran = (lama.inMilliseconds / 20).ceil().clamp(1, 1000);
  for (var i = 0; i < putaran; i++) {
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 5)));
    await tester.pump(const Duration(milliseconds: 20));
  }
}

/// Lepas pohon widget lalu tutup basis data. Stream Drift hidup di zona waktu palsu test, jadi penutupan dijalankan di
/// zona yang sama sambil memompa frame (menutup di `runAsync` akan menunggu selamanya).
Future<void> Lepas(WidgetTester tester, LingkunganUji u) async {
  await tester.pumpWidget(const SizedBox.shrink());
  var selesai = false;
  unawaited(u.Tutup().then((_) => selesai = true));
  for (var i = 0; i < 200 && !selesai; i++) {
    await tester.pump(const Duration(milliseconds: 20));
  }
  expect(selesai, isTrue, reason: 'Basis data uji harus tertutup bersih.');
}

/// Ketuk PIN 6 digit di `PapanPin`.
/// Memilih kasir di dropdown "Nama kasir" layar login (D-60): buka daftar, lalu ketuk nama di daftar terbuka.
Future<void> PilihKasir(WidgetTester tester, String nama) async {
  await tester.tap(find.byKey(const ValueKey('PilihKasir')));
  await tester.pumpAndSettle();
  await tester.tap(find.text(nama).last);
  await tester.pumpAndSettle();
}

Future<void> KetikPin(WidgetTester tester, String pin) async {
  for (final angka in pin.split('')) {
    await tester.tap(find.widgetWithText(OutlinedButton, angka).last);
    await tester.pump();
  }
  await Tunggu(tester);
}

/// Verifier PIN tiruan untuk test widget: PIN benar bila sama dengan PIN kasus vektor yang garamnya cocok. Menghindari
/// Argon2id di zona waktu palsu test widget; kriptografi asli diuji `PemverifikasiPinOffline_test.dart`.
class PemverifikasiPinTiruan extends PemverifikasiPinOffline {
  const PemverifikasiPinTiruan();

  @override
  Future<bool> Verifikasi({
    required String pin,
    required PinTerbungkus terbungkus,
    required String kunciPerangkatBase64,
    required ParameterPin parameter,
  }) async {
    for (final kasus in (vektorPin['Kasus']! as List<Object?>).cast<Map<String, Object?>>()) {
      if (kasus['Garam'] == terbungkus.garam) {
        return kasus['Pin'] == pin;
      }
    }
    return false;
  }
}

/// Penjaga layar tiruan: mencatat apakah layar sedang diminta tetap menyala.
class PenjagaLayarTiruan implements PenjagaLayarMenyala {
  bool menyala = false;

  @override
  Future<void> Aktifkan() async => menyala = true;

  @override
  Future<void> Nonaktifkan() async => menyala = false;
}

/// Kamera swafoto tiruan (F-18): [foto] null = pengguna membatalkan.
/// Pemindai QR tiruan: `tersedia` mengatur apakah tombol Pindai muncul, `hasil` isi QR yang dikembalikan
/// (null = pengguna membatalkan).
class PemindaiQrTiruan implements PemindaiQr {
  PemindaiQrTiruan({this.tersedia = true, this.hasil});

  final bool tersedia;
  final String? hasil;
  int dipanggil = 0;

  @override
  bool CekTersedia() => tersedia;

  @override
  Future<String?> Pindai(
    BuildContext context, {
    String judul = 'Pindai kode QR',
    String petunjuk = 'Arahkan kamera ke kode QR di back-office.',
  }) async {
    dipanggil++;
    return hasil;
  }
}

class KameraSwafotoTiruan implements KameraSwafoto {
  KameraSwafotoTiruan({this.tersedia = true, this.foto});

  final bool tersedia;
  Uint8List? foto;
  int dipanggil = 0;

  @override
  bool CekTersedia() => tersedia;

  @override
  Future<Uint8List?> Ambil() async {
    dipanggil++;
    return foto;
  }
}

class KameraBuktiTiruan implements KameraBukti {
  KameraBuktiTiruan({this.tersedia = true, this.foto});

  final bool tersedia;
  Uint8List? foto;
  int dipanggil = 0;

  @override
  bool CekTersedia() => tersedia;

  @override
  Future<Uint8List?> Ambil() async {
    dipanggil++;
    return foto;
  }
}

/// Pilih penyetuju di `DialogPinSupervisor`. Bila hanya satu staf yang berhak, ia sudah terpilih otomatis (audit
/// kemudahan pakai #25) dan papan PIN-nya langsung tampil; yang dipastikan di sini adalah papan PIN [nama].
Future<void> PilihPenyetuju(WidgetTester tester, String nama) async {
  final tombol = find.widgetWithText(OutlinedButton, nama);
  if (tombol.evaluate().isNotEmpty) {
    await tester.tap(tombol.first);
    await tester.pump();
  }
  expect(find.text('PIN $nama'), findsOneWidget);
}

/// Pabrik pemindai kamera tiruan untuk layar Jual: [tersedia] false = tombol kamera tidak muncul (Windows),
/// [jumlahKamera] 1 = perangkat hanya punya satu kamera, [galat] = kamera gagal dibuka (izin ditolak).
/// Setiap [Buka] membuat sesi baru yang dicatat di [sesi] supaya test bisa mengirim kode dan memeriksa penutupannya.
class PemindaiKameraTiruan implements PabrikPemindaiKamera {
  PemindaiKameraTiruan({this.tersedia = true, this.jumlahKamera = 2, this.galat});

  final bool tersedia;
  final int jumlahKamera;
  final String? galat;
  final List<SesiPemindaiKameraTiruan> sesi = [];

  /// Sesi yang masih terbuka (terakhir dibuka), atau null.
  SesiPemindaiKameraTiruan? get aktif => sesi.where((s) => !s.tertutup).lastOrNull;

  @override
  bool CekTersedia() => tersedia;

  @override
  SesiPemindaiKameraTiruan Buka({required ArahKamera arah}) {
    final baru = SesiPemindaiKameraTiruan(arahAwal: arah, jumlahKamera: jumlahKamera, galat: galat);
    sesi.add(baru);
    return baru;
  }
}

class SesiPemindaiKameraTiruan implements SesiPemindaiKamera {
  SesiPemindaiKameraTiruan({required this.arahAwal, required int jumlahKamera, String? galat})
    : _keadaan = ValueNotifier(
        KeadaanPemindaiKamera(
          siap: galat == null,
          arah: arahAwal,
          jumlahKamera: jumlahKamera,
          senterTersedia: arahAwal == ArahKamera.Belakang,
          galat: galat,
        ),
      );

  final ArahKamera arahAwal;
  final ValueNotifier<KeadaanPemindaiKamera> _keadaan;
  final StreamController<String> _kode = StreamController<String>.broadcast(sync: true);
  bool tertutup = false;
  int jumlahGanti = 0;
  int jumlahSenter = 0;

  /// Kamera mengirim [kode] seolah barcode terbaca.
  void Kirim(String kode) => _kode.add(kode);

  @override
  Stream<String> get kodeTerbaca => _kode.stream;

  @override
  ValueListenable<KeadaanPemindaiKamera> get keadaan => _keadaan;

  @override
  Future<void> GantiKamera() async {
    jumlahGanti++;
    final arah = _keadaan.value.arah.lawan;
    _keadaan.value = _keadaan.value.copyWith(
      arah: arah,
      senterTersedia: arah == ArahKamera.Belakang,
      senterNyala: false,
    );
  }

  @override
  Future<void> UbahSenter() async {
    jumlahSenter++;
    _keadaan.value = _keadaan.value.copyWith(senterNyala: !_keadaan.value.senterNyala);
  }

  @override
  Widget BangunPratinjau(BuildContext context) =>
      const ColoredBox(key: ValueKey('PratinjauKameraTiruan'), color: Colors.black);

  @override
  Future<void> Tutup() async {
    tertutup = true;
    await _kode.close();
  }
}
