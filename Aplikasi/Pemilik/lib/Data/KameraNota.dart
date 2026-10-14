import 'dart:io';
import 'dart:typed_data';

import 'package:image_picker/image_picker.dart';

/// Kamera belakang untuk foto nota pengeluaran di aksi cepat Owner (OWN-07). Abstraksi supaya tampilan tidak memanggil
/// paket platform langsung dan test memakai tiruan. [Tersedia] false (mis. emulator tanpa kamera) = tombol foto
/// disembunyikan; pengeluaran tetap bisa dicatat tanpa foto.
abstract class KameraNota {
  bool Tersedia();

  /// JPEG terkompres (sisi terpanjang ≤ 1280 px); null = dibatalkan pengguna.
  Future<Uint8List?> Ambil();
}

class KameraNotaPlatform implements KameraNota {
  KameraNotaPlatform([ImagePicker? pemilih]) : _pemilih = pemilih ?? ImagePicker();

  final ImagePicker _pemilih;

  @override
  bool Tersedia() => Platform.isAndroid || Platform.isIOS;

  @override
  Future<Uint8List?> Ambil() async {
    final berkas = await _pemilih.pickImage(
      source: ImageSource.camera,
      preferredCameraDevice: CameraDevice.rear,
      maxWidth: 1280,
      maxHeight: 1280,
      imageQuality: 55,
      requestFullMetadata: false,
    );
    return berkas?.readAsBytes();
  }
}

class KameraNotaTidakAda implements KameraNota {
  const KameraNotaTidakAda();

  @override
  bool Tersedia() => false;

  @override
  Future<Uint8List?> Ambil() async => null;
}
