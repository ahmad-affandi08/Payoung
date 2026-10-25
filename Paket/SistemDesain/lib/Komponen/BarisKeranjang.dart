import 'package:flutter/material.dart';
import 'package:inti/Inti.dart';

import '../Token/TokenJarak.dart';
import '../Token/TokenWarna.dart';
import 'LencanaTeks.dart';
import 'TeksUang.dart';

/// Satu baris keranjang (PRD §17.2.7): nama & total di baris atas, rincian (satuan, pilihan, catatan, diskon) dan
/// tombol kurang/tambah di baris bawah (kotak 36dp, area ketuk 44dp). Ketuk area nama untuk mengubah item.
class BarisKeranjang extends StatelessWidget {
  const BarisKeranjang({
    super.key,
    required this.nama,
    required this.jumlah,
    required this.total,
    required this.saatDiketuk,
    required this.saatTambah,
    required this.saatKurang,
    this.rincian = const [],
    this.disorot = false,
    this.lencana,
    this.catatan,
  });

  /// Ukuran kotak tombol kurang/tambah yang terlihat, dan tinggi/lebar area ketuknya (dp).
  static const double ukuranTombol = 36;
  static const double tinggiTekan = 44;

  /// Lama transisi sorot (§17.2.7 prinsip 4: sorot baris keranjang 150 ms).
  static const Duration durasiSorot = Duration(milliseconds: 150);

  final String nama;

  /// Jumlah terformat (misal "2" atau "1,5").
  final String jumlah;
  final Uang total;
  final List<String> rincian;
  final VoidCallback saatDiketuk;
  final VoidCallback saatTambah;
  final VoidCallback saatKurang;

  /// K-15: baris baru saja bertambah lewat pindaian (latar diwarnai sebentar).
  final bool disorot;

  /// Tanda singkat sebelum nama (misal golongan obat "K"); null = tanpa lencana.
  final LencanaTeks? lencana;

  /// Catatan dapur untuk baris ini. Null/kosong = tampilkan ajakan "+ Catatan" supaya kasir tahu baris bisa
  /// diketuk untuk menambah catatan.
  final String? catatan;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final adaCatatan = catatan != null && catatan!.trim().isNotEmpty;

    // Kotak yang terlihat 36dp (tidak "gede"), tetapi area ketuknya 44dp agar tetap nyaman dipakai kasir.
    Widget TombolJumlah({
      required IconData ikon,
      required String tooltip,
      required VoidCallback aksi,
      bool utama = false,
    }) => IconButton(
      tooltip: tooltip,
      onPressed: aksi,
      padding: EdgeInsets.zero,
      constraints: const BoxConstraints.tightFor(width: tinggiTekan, height: tinggiTekan),
      icon: Container(
        width: ukuranTombol,
        height: ukuranTombol,
        decoration: BoxDecoration(
          color: utama ? warna.brand : warna.permukaan,
          border: Border.all(color: utama ? warna.brand : warna.garis),
          borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
        ),
        child: Icon(ikon, size: TokenJarak.ikonSedang, color: utama ? warna.permukaan : warna.brand),
      ),
    );

    return AnimatedContainer(
      duration: durasiSorot,
      decoration: BoxDecoration(
        // Sorot pindai memakai apricot 18% + garis tepi kiri 3dp: brand 12% hanya 1,20:1 di atas putih.
        color: disorot ? warna.aksen.withValues(alpha: 0.18) : warna.permukaan,
        border: Border(
          left: BorderSide(color: disorot ? warna.aksen : Colors.transparent, width: 3),
          bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
        ),
      ),
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          onTap: saatDiketuk,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(TokenJarak.jarak16, TokenJarak.jarak8, TokenJarak.jarak8, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (lencana case final LencanaTeks tanda) ...[tanda, const SizedBox(width: TokenJarak.jarak8)],
                    Expanded(
                      child: Text(nama, maxLines: 2, overflow: TextOverflow.ellipsis, style: teks.labelLarge),
                    ),
                    const SizedBox(width: TokenJarak.jarak8),
                    Padding(
                      padding: const EdgeInsets.only(right: TokenJarak.jarak8),
                      child: TeksUang(total, gaya: teks.labelLarge?.copyWith(fontWeight: FontWeight.w700)),
                    ),
                  ],
                ),
                const SizedBox(height: 3),
                if (adaCatatan)
                  Row(
                    children: [
                      Icon(Icons.edit_note, size: TokenJarak.ikonKecil, color: warna.teksSekunder),
                      const SizedBox(width: TokenJarak.jarak4),
                      Expanded(
                        child: Text(
                          'Catatan: $catatan',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: teks.bodySmall,
                        ),
                      ),
                    ],
                  )
                else
                  // Ajakan berteks, bukan warna saja: kasir baru tahu baris bisa diketuk untuk menulis catatan.
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Container(
                      key: const ValueKey('AjakanCatatan'),
                      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak8, vertical: 2),
                      decoration: BoxDecoration(
                        border: Border.all(color: warna.garis),
                        borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.note_add_outlined, size: TokenJarak.ikonKecil, color: warna.brand),
                          const SizedBox(width: 2),
                          Text(
                            'Catatan',
                            style: teks.bodySmall?.copyWith(color: warna.brand, fontWeight: FontWeight.w600),
                          ),
                        ],
                      ),
                    ),
                  ),
                Row(
                  children: [
                    Expanded(
                      child: rincian.isEmpty
                          ? const SizedBox.shrink()
                          : Text(
                              rincian.join(' | '),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: teks.bodySmall,
                            ),
                    ),
                    TombolJumlah(ikon: Icons.remove, tooltip: 'Kurangi $nama', aksi: saatKurang),
                    ConstrainedBox(
                      constraints: const BoxConstraints(minWidth: 28),
                      child: Text(
                        jumlah,
                        textAlign: TextAlign.center,
                        style: teks.labelLarge?.copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
                      ),
                    ),
                    TombolJumlah(ikon: Icons.add, tooltip: 'Tambah $nama', aksi: saatTambah, utama: true),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
