import 'package:flutter/material.dart';
import 'package:klien_api/KlienApi.dart' show KonfigurasiAplikasi;

import '../../Domain/Salesman/LayananSalesman.dart';
import '../../Domain/Sesi/StafLokal.dart';

/// Tujuan area kerja di rel navigasi (PRD §17.2.7). Urutan tampil mengikuti daftar item ([ItemNavigasi.semua],
/// [ItemNavigasi.pelayan], [ItemNavigasi.salesman]).
enum TujuanRuangKerja { Jual, Meja, Riwayat, Stok, Salesman, Kas, Shift, StatusSinkron, Pengaturan }

/// Satu item rel navigasi. Item hanya tampil bila [modul] aktif (null = inti, selalu aktif) dan kasir punya salah satu
/// [izin] (kosong = semua kasir). Maksimal 8 item (§17.2.7); bila lebih, item [bolehDilepas] dilepas lebih dulu
/// sehingga Sinkron & Pengaturan tidak pernah terpotong.
@immutable
class ItemNavigasi {
  const ItemNavigasi({
    required this.tujuan,
    required this.label,
    required this.ikon,
    required this.ikonAktif,
    this.izin = const [],
    this.modul,
    this.sektor,
    this.bolehDilepas = false,
    this.labelRingkas,
  });

  final TujuanRuangKerja tujuan;
  final String label;
  final IconData ikon;
  final IconData ikonAktif;
  final List<String> izin;
  final String? modul;

  /// D-183: awalan kode sektor outlet yang memakai item ini (`WHS` mencakup `WHS-DST`); null = semua sektor. Sektor
  /// outlet yang belum diketahui (server lama) tetap menampilkan item, mengikuti `KonteksPenjualan.CekSesuaiSektor`.
  final List<String>? sektor;

  /// Item tambahan yang dilepas dulu saat rel melebihi [batasItem] (mis. Salesman bagi pemilik restoran bermode meja).
  final bool bolehDilepas;

  /// Label di bilah navigasi bawah HP bila [label] tidak muat satu baris di enam tab selebar 360dp (mis. "Atur").
  final String? labelRingkas;

  static const int batasItem = 8;

  /// Menu Stok: bahan terbuang (F-05f bagian 2) dan modul Gudang (POS-25: terima barang, transfer masuk, opname).
  static const List<String> izinStok = [
    IzinKasir.persediaanTerbuangCatat,
    IzinKasir.persediaanKelola,
    IzinKasir.pembelianKelola,
  ];

  /// D-183: sektor yang mengelola stok barang (menu Stok). Jasa umum dan usaha jasa profesional/kontraktor tidak.
  static const List<String> sektorBerstok = KonfigurasiAplikasi.sektorBerstok;

  /// D-183: sektor yang punya salesman keliling dan kanvas (grosir, distribusi, bahan bangunan, manufaktur).
  static const List<String> sektorSalesman = ['WHS', 'RTL-BLD', 'BIZ-MFG'];

  /// Kode modul Meja: aktif bila mode meja outlet aktif (F-07 mode meja fase 1).
  static const String modulMeja = 'Meja';

  /// Daftar lengkap item. Modul berikutnya ditambah di sini sesuai urutan §17.2.7 (setelah tujuannya ada di
  /// [TujuanRuangKerja]): Order tersimpan (pesanan tertahan fase 1 dibuka dari layar Jual; pesanan terbuka lewat Meja),
  /// Pelanggan (modul `Pelanggan`).
  static const List<ItemNavigasi> semua = [
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Jual,
      label: 'Jual',
      ikon: Icons.point_of_sale_outlined,
      ikonAktif: Icons.point_of_sale,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Meja,
      label: 'Meja',
      ikon: Icons.table_restaurant_outlined,
      ikonAktif: Icons.table_restaurant,
      modul: modulMeja,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Riwayat,
      label: 'Riwayat',
      ikon: Icons.receipt_long_outlined,
      ikonAktif: Icons.receipt_long,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Stok,
      label: 'Stok',
      ikon: Icons.inventory_2_outlined,
      ikonAktif: Icons.inventory_2,
      izin: izinStok,
      sektor: sektorBerstok,
    ),
    // Modul Salesman bagian 2: kasir yang juga salesman (toko kecil memakai satu HP) membukanya dari rel kasir.
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Salesman,
      label: 'Salesman',
      ikon: Icons.storefront_outlined,
      ikonAktif: Icons.storefront,
      izin: [IzinSalesman.kunjungan],
      sektor: sektorSalesman,
      bolehDilepas: true,
    ),
    ItemNavigasi(tujuan: TujuanRuangKerja.Kas, label: 'Kas', ikon: Icons.payments_outlined, ikonAktif: Icons.payments),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Shift,
      label: 'Shift',
      ikon: Icons.schedule_outlined,
      ikonAktif: Icons.schedule,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.StatusSinkron,
      label: 'Sinkron',
      ikon: Icons.cloud_sync_outlined,
      ikonAktif: Icons.cloud_sync,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Pengaturan,
      label: 'Pengaturan',
      labelRingkas: 'Atur',
      ikon: Icons.settings_outlined,
      ikonAktif: Icons.settings,
    ),
  ];

  /// Mode Pelayan (v2.00, perangkat berjenis `Pelayan`, tanpa shift & kas): Meja sebagai beranda, Pesanan (layar Jual
  /// tanpa bayar), Stok (F-05f bagian 2, hanya dengan izin), Sinkron, Pengaturan.
  static const List<ItemNavigasi> pelayan = [
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Meja,
      label: 'Meja',
      ikon: Icons.table_restaurant_outlined,
      ikonAktif: Icons.table_restaurant,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Jual,
      label: 'Pesanan',
      ikon: Icons.restaurant_menu_outlined,
      ikonAktif: Icons.restaurant_menu,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Stok,
      label: 'Stok',
      ikon: Icons.inventory_2_outlined,
      ikonAktif: Icons.inventory_2,
      izin: izinStok,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.StatusSinkron,
      label: 'Sinkron',
      ikon: Icons.cloud_sync_outlined,
      ikonAktif: Icons.cloud_sync,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Pengaturan,
      label: 'Pengaturan',
      labelRingkas: 'Atur',
      ikon: Icons.settings_outlined,
      ikonAktif: Icons.settings,
    ),
  ];

  /// Mode Salesman (Modul Salesman bagian 2; perangkat berjenis `Salesman` atau pengguna yang hanya berizin salesman,
  /// tanpa shift & kas): Salesman sebagai beranda, Stok (hanya dengan izin), Sinkron, Pengaturan.
  static const List<ItemNavigasi> salesman = [
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Salesman,
      label: 'Salesman',
      ikon: Icons.storefront_outlined,
      ikonAktif: Icons.storefront,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Stok,
      label: 'Stok',
      ikon: Icons.inventory_2_outlined,
      ikonAktif: Icons.inventory_2,
      izin: izinStok,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.StatusSinkron,
      label: 'Sinkron',
      ikon: Icons.cloud_sync_outlined,
      ikonAktif: Icons.cloud_sync,
    ),
    ItemNavigasi(
      tujuan: TujuanRuangKerja.Pengaturan,
      label: 'Pengaturan',
      labelRingkas: 'Atur',
      ikon: Icons.settings_outlined,
      ikonAktif: Icons.settings,
    ),
  ];

  /// Item yang tampil untuk [kasir] dengan [modulAktif] (kode modul langganan tenant). Lebih dari [batasItem]: item
  /// [bolehDilepas] dilepas dari belakang dulu, baru sisanya dipotong.
  static List<ItemNavigasi> Saring(
    StafLokal kasir, {
    Set<String> modulAktif = const {},
    List<ItemNavigasi> daftar = semua,
    bool Function(List<String> awalan)? cekSektor,
  }) {
    final hasil = daftar
        .where(
          (i) =>
              (i.modul == null || modulAktif.contains(i.modul)) &&
              (i.izin.isEmpty || i.izin.any(kasir.PunyaIzin)) &&
              (i.sektor == null || cekSektor == null || cekSektor(i.sektor!)),
        )
        .toList();
    while (hasil.length > batasItem) {
      final indeks = hasil.lastIndexWhere((i) => i.bolehDilepas);
      if (indeks < 0) {
        break;
      }
      hasil.removeAt(indeks);
    }
    return hasil.take(batasItem).toList();
  }
}
