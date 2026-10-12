import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

/// Arah kamera pemindai barcode: belakang (bawaan, untuk barang di tangan) atau depan (HP/tablet ditaruh di meja).
enum ArahKamera {
  Belakang('Kamera belakang'),
  Depan('Kamera depan');

  const ArahKamera(this.label);

  final String label;

  ArahKamera get lawan => this == ArahKamera.Belakang ? ArahKamera.Depan : ArahKamera.Belakang;
}

/// Keadaan sesi kamera untuk ditampilkan di strip pindai (siap, arah, senter, galat).
@immutable
class KeadaanPemindaiKamera {
  const KeadaanPemindaiKamera({
    this.siap = false,
    this.arah = ArahKamera.Belakang,
    this.jumlahKamera = 0,
    this.senterTersedia = false,
    this.senterNyala = false,
    this.galat,
  });

  /// Kamera sudah menyala dan membaca gambar.
  final bool siap;
  final ArahKamera arah;

  /// Jumlah kamera di perangkat (0 = belum diketahui). Ganti kamera hanya bermakna bila lebih dari satu.
  final int jumlahKamera;
  final bool senterTersedia;
  final bool senterNyala;

  /// Pesan untuk kasir bila kamera tidak bisa dipakai (izin ditolak, kamera dipakai aplikasi lain); null = baik.
  final String? galat;

  bool get bisaGantiKamera => jumlahKamera > 1;

  KeadaanPemindaiKamera copyWith({
    bool? siap,
    ArahKamera? arah,
    int? jumlahKamera,
    bool? senterTersedia,
    bool? senterNyala,
    String? galat,
  }) => KeadaanPemindaiKamera(
    siap: siap ?? this.siap,
    arah: arah ?? this.arah,
    jumlahKamera: jumlahKamera ?? this.jumlahKamera,
    senterTersedia: senterTersedia ?? this.senterTersedia,
    senterNyala: senterNyala ?? this.senterNyala,
    galat: galat ?? this.galat,
  );
}

/// Satu sesi pemindaian kamera yang terus terbuka: kasir memindai banyak barang berturut-turut tanpa membuka ulang.
/// Dibedakan dari [PemindaiQr] (sekali pindai lalu layar menutup): sesi ini memancarkan setiap kode yang terbaca
/// sampai [Tutup] dipanggil.
abstract class SesiPemindaiKamera {
  /// Kode (barcode/QR) yang terbaca, sudah dipangkas dan tidak kosong. Kode yang sama bisa terpancar berulang
  /// selama masih terlihat kamera; penyaringan jeda ditangani pemakai.
  Stream<String> get kodeTerbaca;

  ValueListenable<KeadaanPemindaiKamera> get keadaan;

  /// Pindah kamera depan ↔ belakang tanpa menutup sesi.
  Future<void> GantiKamera();

  /// Nyalakan/matikan senter (hanya kamera belakang perangkat yang punya senter).
  Future<void> UbahSenter();

  /// Pratinjau kamera, memenuhi ruang yang diberikan.
  Widget BangunPratinjau(BuildContext context);

  /// Matikan kamera dan lepaskan sumber dayanya.
  Future<void> Tutup();
}

/// Pembuka sesi kamera pemindai. Abstraksi agar tampilan tidak memanggil paket platform langsung dan test memakai
/// tiruan (seperti [PemindaiQr] dan [KameraSwafoto]). [CekTersedia] false (Windows) = tombol kamera tidak ditampilkan;
/// pemindai USB/Bluetooth dan ketikan manual tetap jalan.
abstract class PabrikPemindaiKamera {
  bool CekTersedia();

  SesiPemindaiKamera Buka({required ArahKamera arah});
}
