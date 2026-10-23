import 'package:flutter/material.dart';

import '../Token/TokenJarak.dart';
import '../Token/TokenWarna.dart';

/// Nada status (PRD §17.6.3): warna hanya penanda, teks tetap wajib ada.
enum NadaStatus { Netral, Sukses, Peringatan, Bahaya, Info }

/// Satu penanda di [BilahStatus]: ikon + teks singkat.
@immutable
class ItemBilahStatus {
  const ItemBilahStatus({required this.ikon, required this.teks, this.nada = NadaStatus.Netral});

  final IconData ikon;
  final String teks;
  final NadaStatus nada;
}

/// Bilah status permanen di bawah ruang kerja (PRD §17.2.7, §17.6.5): koneksi, tertunda sinkron, printer, shift.
/// Satu baris, selalu terlihat; teks panjang dipotong dengan elipsis di layar sempit. Seluruh bilah bisa diketuk untuk
/// membuka detail.
class BilahStatus extends StatelessWidget {
  const BilahStatus({
    super.key,
    required this.item,
    this.saatDiketuk,
    this.petunjuk = 'Buka detail status',
    this.gelap = false,
  });

  final List<ItemBilahStatus> item;
  final VoidCallback? saatDiketuk;

  /// Petunjuk pembaca layar untuk aksi ketuk.
  final String petunjuk;

  /// Varian gelap & ringkas: menempel di bawah bilah atas merek (bukan bilah bawah), tinggi 28dp, tanpa aksi ketuk.
  /// Dipakai Ruang Kerja supaya status tetap terlihat tanpa menambah pita putih di kaki layar.
  final bool gelap;

  static Color AmbilWarnaNada(TokenWarna warna, NadaStatus nada) => switch (nada) {
    NadaStatus.Netral => warna.teksSekunder,
    NadaStatus.Sukses => warna.sukses,
    NadaStatus.Peringatan => warna.peringatan,
    NadaStatus.Bahaya => warna.bahaya,
    NadaStatus.Info => warna.info,
  };

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final tinggi = gelap ? 28.0 : TokenJarak.targetSentuh;
    final gaya = Theme.of(context).textTheme.bodySmall?.copyWith(
      color: gelap ? warna.permukaan.withValues(alpha: 0.84) : warna.teksUtama,
      fontSize: gelap ? 11 : null,
    );
    final anak = <Widget>[];
    for (var i = 0; i < item.length; i++) {
      if (i > 0) {
        anak.add(SizedBox(width: gelap ? TokenJarak.jarak8 : TokenJarak.jarak16));
        if (gelap) {
          anak.add(Text('|', style: gaya?.copyWith(color: warna.permukaan.withValues(alpha: 0.32))));
          anak.add(const SizedBox(width: TokenJarak.jarak8));
        }
      }
      final penanda = item[i];
      anak.add(
        Flexible(
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                penanda.ikon,
                size: TokenJarak.ikonKecil,
                color: gelap ? warna.permukaan.withValues(alpha: 0.7) : AmbilWarnaNada(warna, penanda.nada),
              ),
              const SizedBox(width: TokenJarak.jarak4),
              Flexible(
                child: Text(penanda.teks, style: gaya, maxLines: 1, overflow: TextOverflow.ellipsis, softWrap: false),
              ),
            ],
          ),
        ),
      );
    }

    final isi = Container(
      constraints: BoxConstraints(minHeight: tinggi),
      padding: EdgeInsets.symmetric(horizontal: gelap ? TokenJarak.jarak16 : TokenJarak.jarak16),
      decoration: BoxDecoration(
        border: Border(
          bottom: gelap
              ? BorderSide(color: warna.permukaan.withValues(alpha: 0.08), width: TokenJarak.tebalGaris)
              : BorderSide.none,
          top: gelap ? BorderSide.none : BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
        ),
      ),
      alignment: Alignment.centerLeft,
      child: Row(children: anak),
    );

    if (gelap) {
      return Semantics(
        container: true,
        label: item.map((i) => i.teks).join(' | '),
        excludeSemantics: true,
        child: Material(color: warna.brandGelap, child: isi),
      );
    }

    return Semantics(
      container: true,
      button: saatDiketuk != null,
      hint: saatDiketuk == null ? null : petunjuk,
      child: Material(
        color: warna.permukaan,
        child: InkWell(onTap: saatDiketuk, child: isi),
      ),
    );
  }
}
