import 'dart:convert';
import 'dart:typed_data';

import 'package:qr/qr.dart';

import 'DokumenStruk.dart';
import 'TataLetakStruk.dart';

/// Perintah ESC/POS yang dipakai (subset yang didukung hampir semua printer thermal 58/80 mm).
abstract final class PerintahEscPos {
  static const List<int> inisialisasi = [0x1B, 0x40];
  static const List<int> rataKiri = [0x1B, 0x61, 0x00];
  static const List<int> rataTengah = [0x1B, 0x61, 0x01];
  static const List<int> tebalNyala = [0x1B, 0x45, 0x01];
  static const List<int> tebalMati = [0x1B, 0x45, 0x00];
  static const List<int> ukuranNormal = [0x1D, 0x21, 0x00];
  static const List<int> ukuranGanda = [0x1D, 0x21, 0x11];

  /// `ESC p 0 t1 t2`: pulsa laci kas di pin 2 (50 ms nyala, 500 ms jeda).
  static const List<int> bukaLaci = [0x1B, 0x70, 0x00, 0x19, 0xFA];

  /// Baris kosong bawaan sebelum potong/sobek. Dulu 4 baris, terlalu panjang: `GS V 66 0` sendiri sudah mendorong
  /// kertas ke posisi pisau, jadi umpan tambahan hanya menambah kertas kosong.
  static const int umpanAkhirBawaan = 2;

  /// Umpan terbanyak yang bisa diatur (baris).
  static const int umpanAkhirMaksimal = 8;

  /// `ESC d n` (n baris kosong; dilewati bila 0) lalu `GS V 66 0`: dorong kertas ke pisau, potong sebagian.
  static List<int> PotongKertas([int umpan = umpanAkhirBawaan]) => [
    if (umpan > 0) ...[0x1B, 0x64, umpan.clamp(0, umpanAkhirMaksimal)],
    0x1D,
    0x56,
    0x42,
    0x00,
  ];

  static List<int> get potongKertas => PotongKertas();
  static const int barisBaru = 0x0A;
}

/// Mengubah [DokumenStruk] menjadi byte ESC/POS. Teks melewati [TataLetakStruk] (sudah ASCII & selebar kertas).
abstract final class PengodeEscPos {
  static Uint8List Kodekan(
    DokumenStruk dokumen,
    LebarKertas lebar, {
    int umpanAkhir = PerintahEscPos.umpanAkhirBawaan,
  }) {
    final keluaran = BytesBuilder(copy: false)
      ..add(PerintahEscPos.inisialisasi);
    if (dokumen.bukaLaci) {
      keluaran.add(PerintahEscPos.bukaLaci);
    }
    for (final baris in TataLetakStruk.Susun(dokumen, lebar)) {
      switch (baris) {
        case BarisCetakTeks(:final teks, :final tebal, :final besar):
          if (tebal) keluaran.add(PerintahEscPos.tebalNyala);
          if (besar) keluaran.add(PerintahEscPos.ukuranGanda);
          keluaran
            ..add(ascii.encode(teks))
            ..addByte(PerintahEscPos.barisBaru);
          if (besar) keluaran.add(PerintahEscPos.ukuranNormal);
          if (tebal) keluaran.add(PerintahEscPos.tebalMati);
        case BarisCetakQr(:final data, :final ukuranModul):
          // Raster, bukan perintah QR bawaan `GS ( k`: printer thermal murah tidak mengenal perintah itu dan mencetak
          // isi QR sebagai teks (tautan struk digital tampil sebagai tulisan, bukan kode QR).
          keluaran
            ..add(PerintahEscPos.rataKiri)
            ..add(KodekanQrRaster(data, ukuranModul, lebar))
            ..addByte(PerintahEscPos.barisBaru);
        case BarisCetakGambar(:final gambar):
          keluaran
            ..add(PerintahEscPos.rataTengah)
            ..add(KodekanGambar(gambar, lebar))
            ..add(PerintahEscPos.rataKiri);
      }
    }
    if (dokumen.potong) {
      keluaran.add(PerintahEscPos.PotongKertas(umpanAkhir));
    }
    return keluaran.takeBytes();
  }

  /// Hanya pulsa laci (tombol "Buka laci" tanpa mencetak).
  static Uint8List KodekanBukaLaci() => Uint8List.fromList([
    ...PerintahEscPos.inisialisasi,
    ...PerintahEscPos.bukaLaci,
  ]);

  /// QR sebagai gambar raster selebar kertas (QR di tengah, zona tenang 2 modul): jalan di semua printer ESC/POS
  /// yang bisa mencetak gambar `GS v 0`, termasuk yang tidak mengenal perintah QR bawaan. Ukuran modul mengecil
  /// otomatis bila QR terlalu lebar untuk kertas.
  static List<int> KodekanQrRaster(
    String data,
    int ukuranModul,
    LebarKertas lebar,
  ) {
    final qr = QrImage(
      QrCode.fromData(data: data, errorCorrectLevel: QrErrorCorrectLevel.M),
    );
    final jumlah = qr.moduleCount;
    const zonaTenang = 2;
    final modulMaksimal = lebar.titik ~/ (jumlah + 2 * zonaTenang);
    final modulDiminta = ukuranModul.clamp(1, 16);
    final modul = modulMaksimal < 1
        ? 1
        : (modulDiminta > modulMaksimal ? modulMaksimal : modulDiminta);
    final sisi = (jumlah + 2 * zonaTenang) * modul;
    final lebarGambar = lebar.titik;
    final kiri = (lebarGambar - sisi) ~/ 2 < 0 ? 0 : (lebarGambar - sisi) ~/ 2;
    final titik = Uint8List(lebarGambar * sisi);
    for (var y = 0; y < sisi; y++) {
      final baris = y ~/ modul - zonaTenang;
      if (baris < 0 || baris >= jumlah) {
        continue;
      }
      for (var x = 0; x < sisi; x++) {
        final kolom = x ~/ modul - zonaTenang;
        if (kolom >= 0 &&
            kolom < jumlah &&
            qr.isDark(baris, kolom) &&
            kiri + x < lebarGambar) {
          titik[y * lebarGambar + kiri + x] = 1;
        }
      }
    }
    return KodekanGambar(GambarMonokrom(lebarGambar, sisi, titik), lebar);
  }

  /// QR model 2, koreksi galat M: `GS ( k` fungsi 165 (model), 167 (ukuran), 169 (koreksi), 180 (simpan), 181 (cetak).
  static List<int> KodekanQr(String data, int ukuranModul) {
    final isi = utf8.encode(data);
    final panjang = isi.length + 3;
    return [
      ...[0x1D, 0x28, 0x6B, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00],
      ...[0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x43, ukuranModul],
      ...[0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x45, 0x31],
      ...[
        0x1D,
        0x28,
        0x6B,
        panjang & 0xFF,
        panjang >> 8,
        0x31,
        0x50,
        0x30,
        ...isi,
      ],
      ...[0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x51, 0x30],
    ];
  }

  /// Tinggi paling banyak (baris titik) dalam satu perintah `GS v 0`. Spesifikasi mengizinkan sampai 2047 baris lewat
  /// dua byte tinggi, tetapi banyak printer thermal murah hanya membaca byte rendah (atau menolak ≥ 256 baris): gambar
  /// jangkung seperti logo persegi 256 titik dianggap tinggi 0 dan seluruh datanya tercetak sebagai huruf acak di
  /// atas struk. Gambar lebih tinggi dipecah menjadi beberapa blok tersambung yang masing-masing aman di semua printer,
  /// dan blok yang kecil juga tidak membanjiri buffer printer.
  static const int barisMaksimalPerBlok = 120;

  /// Gambar raster `GS v 0` (mode normal): 1 bit per titik, bit tertinggi = titik paling kiri. Dipotong ke lebar kertas
  /// dan dipecah per [barisMaksimalPerBlok] baris (tinggi selalu muat satu byte, byte tinggi atas = 0).
  static List<int> KodekanGambar(GambarMonokrom gambar, LebarKertas lebar) {
    final lebarTitik = gambar.lebar > lebar.titik ? lebar.titik : gambar.lebar;
    final lebarByte = (lebarTitik + 7) ~/ 8;
    final keluaran = <int>[];
    for (var awal = 0; awal < gambar.tinggi; awal += barisMaksimalPerBlok) {
      final tinggiBlok = gambar.tinggi - awal < barisMaksimalPerBlok
          ? gambar.tinggi - awal
          : barisMaksimalPerBlok;
      final data = Uint8List(lebarByte * tinggiBlok);
      for (var y = 0; y < tinggiBlok; y++) {
        for (var x = 0; x < lebarTitik; x++) {
          if (gambar.titik[(awal + y) * gambar.lebar + x] == 1) {
            data[y * lebarByte + (x >> 3)] |= 0x80 >> (x & 7);
          }
        }
      }
      keluaran
        ..addAll([0x1D, 0x76, 0x30, 0x00])
        ..addAll([
          lebarByte & 0xFF,
          lebarByte >> 8,
          tinggiBlok & 0xFF,
          tinggiBlok >> 8,
        ])
        ..addAll(data);
    }
    return keluaran;
  }
}
