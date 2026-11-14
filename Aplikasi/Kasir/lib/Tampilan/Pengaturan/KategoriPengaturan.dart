import 'package:flutter/material.dart';

/// Kategori halaman Pengaturan kasir (D-301): satu daftar panjang berisi belasan kartu dibagi enam kategori supaya
/// kasir menemukan pengaturan dari nama pekerjaannya (printer, tampilan, keamanan), bukan dengan menggulir.
enum KategoriPengaturan {
  perangkat('Perangkat', 'Identitas, kasir, dan data kasir', Icons.tablet_android_outlined),
  printer('Printer & struk', 'Printer, kegunaan, dan cetak otomatis', Icons.print_outlined),
  tampilan('Tampilan & jual', 'Ukuran, keranjang, pemindai, layar pelanggan', Icons.desktop_windows_outlined),
  keamanan('Keamanan', 'Kunci otomatis dan mode latihan', Icons.lock_outline),
  data('Data & sinkron', 'Data kasir dan uji perangkat', Icons.storage_outlined),
  bantuan('Bantuan & log', 'Hubungi tim Payoung dan log galat', Icons.help_outline);

  const KategoriPengaturan(this.label, this.ringkas, this.ikon);

  final String label;
  final String ringkas;
  final IconData ikon;
}
