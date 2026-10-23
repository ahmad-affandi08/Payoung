import 'package:flutter/material.dart';
import 'package:inti/Inti.dart';

import '../Token/TokenJarak.dart';
import '../Token/TokenTipografi.dart';
import '../Token/TokenWarna.dart';
import 'LencanaTeks.dart';
import 'TeksUang.dart';

/// Ubin produk di katalog layar Jual (PRD §17.2.3, §17.2.7): area gambar persegi di atas — foto produk bila ada,
/// inisial di atas latar netral bila belum — lalu nama maksimal dua baris dan harga tabular.
///
/// Ubin tidak lagi berlebar/tinggi tetap. Sebelumnya lebarnya dibatasi 176dp dan tingginya dikunci 136dp, sehingga
/// di tablet 10" katalog hanya muat dua kolom pendek dan layar terasa kosong. Sekarang jumlah kolom ditentukan
/// [HitungKolom] dari lebar area katalog dan ubin membagi habis lebarnya, jadi grid selalu penuh sampai tepi.
///
/// [keterangan] penanda singkat (misal "Ada pilihan") yang tampil sebagai chip berikon di atas gambar — ikon +
/// teks, bukan warna saja, supaya tetap terbaca bila warna dihapus (§17.6.11). [nonaktif] = produk belum bisa
/// dijual (tetap bisa diketuk agar kasir melihat alasannya).
class UbinProduk extends StatelessWidget {
  const UbinProduk({
    super.key,
    required this.nama,
    required this.harga,
    required this.saatDiketuk,
    this.keterangan,
    this.nonaktif = false,
    this.gambar,
    this.saatDitahan,
    this.lencana,
  });

  /// Tinggi blok teks di bawah gambar: nama dua baris + harga, tanpa saling tabrak di ubin tersempit.
  static const double tinggiTeks = 88;

  /// Lebar ubin minimum yang masih nyaman disentuh & terbaca; dipakai [HitungKolom] sebagai batas bawah.
  static const double lebarMinimum = 104;

  /// Jumlah kolom katalog menurut lebar areanya (sudah dikurangi padding). Tangga ini yang menggantikan batas lebar
  /// ubin tetap: di tablet 10" (±390dp bersih) jadi 3 kolom, di desktop (±790dp) jadi 5.
  static int HitungKolom(double lebarArea) => switch (lebarArea) {
    < 360 => 2,
    < 560 => 3,
    < 768 => 4,
    < 1024 => 5,
    _ => 6,
  };

  /// Rasio lebar:tinggi ubin untuk grid berjumlah kolom tetap; gambar persegi + [tinggiTeks].
  static double HitungRasio(double lebarUbin) => lebarUbin / (lebarUbin + tinggiTeks);

  final String nama;

  /// Null = harga belum diatur.
  final Uang? harga;
  final VoidCallback saatDiketuk;
  final String? keterangan;
  final bool nonaktif;

  /// Foto produk bila sudah tersedia di perangkat; null = pakai inisial.
  final Widget? gambar;

  /// Aksi tahan-lama (misal tandai habis); null = ubin tidak punya aksi tambahan.
  final VoidCallback? saatDitahan;

  /// Tanda singkat di pojok kiri atas gambar (misal golongan obat "K"); null = tanpa lencana.
  final LencanaTeks? lencana;

  /// Inisial dua huruf pertama kata (misal "Es Kopi Susu" → "EK").
  static String AmbilInisial(String nama) {
    final kata = nama.trim().split(RegExp(r'\s+')).where((k) => k.isNotEmpty).toList();
    if (kata.isEmpty) {
      return '?';
    }
    final huruf = kata.take(2).map((k) => k.characters.first.toUpperCase()).join();
    return huruf;
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final warnaTeks = nonaktif ? warna.teksSekunder : warna.teksUtama;
    final hargaTeks = harga;
    return Semantics(
      button: true,
      label: [
        nama,
        ?lencana?.label,
        hargaTeks == null ? 'harga belum diatur' : hargaTeks.FormatRupiah(),
        ?keterangan,
      ].join(', '),
      excludeSemantics: true,
      onLongPress: saatDitahan,
      child: Material(
        color: warna.permukaan,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
          borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: saatDiketuk,
          onLongPress: saatDitahan,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AspectRatio(
                aspectRatio: 1,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    // Area gambar memakai nada sendiri (`ubin`), bukan `latar`: dulu keduanya sama sehingga ubin
                    // produk tanpa foto menyatu dengan kanvas dan batasnya hilang.
                    ColoredBox(
                      color: warna.ubin,
                      child:
                          gambar ??
                          Center(
                            child: Text(
                              AmbilInisial(nama),
                              style: teks.headlineSmall?.copyWith(color: warna.teksSekunder, fontFamily: fontMono),
                            ),
                          ),
                    ),
                    if (lencana case final LencanaTeks tanda)
                      Positioned(left: TokenJarak.jarak4, top: TokenJarak.jarak4, child: tanda),
                    if (keterangan case final String tanda)
                      Positioned(
                        left: TokenJarak.jarak4,
                        bottom: TokenJarak.jarak4,
                        right: TokenJarak.jarak4,
                        child: _Tanda(tanda: tanda, nonaktif: nonaktif),
                      ),
                  ],
                ),
              ),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.all(TokenJarak.jarak8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(
                          nama,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: teks.bodyMedium?.copyWith(color: warnaTeks),
                        ),
                      ),
                      if (hargaTeks == null)
                        Text(
                          'Harga belum diatur',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: teks.bodySmall?.copyWith(color: warna.peringatan),
                        )
                      else
                        // Nominal besar (misal paket Rp 1.000.000) di ubin sempit tetap satu baris: mengecil, tidak meluap.
                        FittedBox(
                          fit: BoxFit.scaleDown,
                          alignment: Alignment.centerLeft,
                          child: TeksUang(
                            hargaTeks,
                            rataKanan: false,
                            // Harga adalah angka kedua terpenting di layar Jual: 16/700, bukan 14/600 yang tenggelam
                            // di bawah nama produk.
                            gaya: teks.bodyLarge?.copyWith(color: warnaTeks, fontWeight: FontWeight.w700),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Chip penanda di atas gambar: ikon + teks, bukan warna saja (§17.6.11).
class _Tanda extends StatelessWidget {
  const _Tanda({required this.tanda, required this.nonaktif});

  final String tanda;
  final bool nonaktif;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak4, vertical: 2),
      decoration: BoxDecoration(
        color: warna.permukaan,
        border: Border.all(color: warna.garis, width: TokenJarak.tebalGaris),
        borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            nonaktif ? Icons.block : Icons.tune,
            size: TokenJarak.ikonKecil,
            color: nonaktif ? warna.bahaya : warna.teksSekunder,
          ),
          const SizedBox(width: 2),
          Flexible(
            child: Text(
              tanda,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
            ),
          ),
        ],
      ),
    );
  }
}
