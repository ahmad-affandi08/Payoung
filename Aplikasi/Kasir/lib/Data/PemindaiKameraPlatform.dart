import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../Domain/Perangkat/PemindaiKamera.dart';

/// Sesi pemindai kamera dengan paket `mobile_scanner` (Android & iOS; tidak mendukung Windows). Semua format barcode
/// 1D dan 2D dibaca, jadi EAN-13 pabrik, Code 128 buatan sendiri, maupun QR sama-sama terpindai.
class PemindaiKameraPlatform implements PabrikPemindaiKamera {
  @override
  bool CekTersedia() => Platform.isAndroid || Platform.isIOS;

  @override
  SesiPemindaiKamera Buka({required ArahKamera arah}) => _SesiPemindaiKameraPlatform(arah);
}

class _SesiPemindaiKameraPlatform implements SesiPemindaiKamera {
  _SesiPemindaiKameraPlatform(ArahKamera arah)
    : _kendali = MobileScannerController(
        facing: arah == ArahKamera.Depan ? CameraFacing.front : CameraFacing.back,
        // Kode yang sama dipancarkan tiap 300 ms selama terlihat; jeda antarpindai diatur pemakai sesi ini.
        detectionTimeoutMs: 300,
      ) {
    _keadaan = ValueNotifier(_Petakan(_kendali.value));
    _kendali.addListener(_SaatBerubah);
  }

  final MobileScannerController _kendali;
  late final ValueNotifier<KeadaanPemindaiKamera> _keadaan;
  bool _tertutup = false;

  @override
  ValueListenable<KeadaanPemindaiKamera> get keadaan => _keadaan;

  @override
  Stream<String> get kodeTerbaca => _kendali.barcodes
      .map(
        (tangkapan) => tangkapan.barcodes
            .map((b) => b.rawValue?.trim())
            .firstWhere((nilai) => nilai != null && nilai.isNotEmpty, orElse: () => null),
      )
      .where((nilai) => nilai != null)
      .cast<String>();

  void _SaatBerubah() {
    if (!_tertutup) {
      _keadaan.value = _Petakan(_kendali.value);
    }
  }

  static KeadaanPemindaiKamera _Petakan(MobileScannerState nilai) {
    final galat = nilai.error;
    return KeadaanPemindaiKamera(
      siap: nilai.isRunning && galat == null,
      arah: nilai.cameraDirection == CameraFacing.front ? ArahKamera.Depan : ArahKamera.Belakang,
      jumlahKamera: nilai.availableCameras ?? 0,
      senterTersedia: nilai.torchState != TorchState.unavailable,
      senterNyala: nilai.torchState == TorchState.on,
      galat: galat == null
          ? null
          : galat.errorCode == MobileScannerErrorCode.permissionDenied
          ? 'Izin kamera belum diberikan. Izinkan akses kamera untuk aplikasi ini di pengaturan perangkat.'
          : 'Kamera tidak bisa dibuka. Tutup aplikasi lain yang memakai kamera, lalu coba lagi.',
    );
  }

  @override
  Future<void> GantiKamera() async {
    try {
      await _kendali.switchCamera();
    } on MobileScannerException {
      // Perpindahan gagal: keadaan galat tampil lewat pendengar controller.
    }
  }

  @override
  Future<void> UbahSenter() async {
    try {
      await _kendali.toggleTorch();
    } on MobileScannerException {
      // Senter tidak tersedia pada kamera ini; tombolnya mengikuti keadaan controller.
    }
  }

  @override
  Widget BangunPratinjau(BuildContext context) => MobileScanner(
    controller: _kendali,
    fit: BoxFit.cover,
    tapToFocus: true,
    errorBuilder: (_, _) => const SizedBox.shrink(),
  );

  @override
  Future<void> Tutup() async {
    if (_tertutup) {
      return;
    }
    _tertutup = true;
    _kendali.removeListener(_SaatBerubah);
    _keadaan.dispose();
    await _kendali.dispose();
  }
}
