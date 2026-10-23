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
    this.tanpaLatar = false,
  });

  final List<ItemBilahStatus> item;
  final VoidCallback? saatDiketuk;

  /// Petunjuk pembaca layar untuk aksi ketuk.
  final String petunjuk;

  /// Varian gelap & ringkas: menempel di bawah bilah atas merek (bukan bilah bawah), tinggi 28dp, tanpa aksi ketuk.
  /// Dipakai Ruang Kerja supaya status tetap terlihat tanpa menambah pita putih di kaki layar.
  final bool gelap;

  /// Tanpa latar & tanpa bantalan: hanya deretan ikon + teks setinggi teksnya, untuk ditempel di dalam bilah lain
  /// (D-16: baris status di bawah nama outlet pada bilah atas Ruang Kerja). Teks tidak dipotong satu per satu;
  /// seluruh deretan diskalakan turun bersama bila ruangnya kurang.
  final bool tanpaLatar;

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
    final tinggi = tanpaLatar ? 0.0 : (gelap ? 28.0 : TokenJarak.targetSentuh);
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
      final baris = Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            penanda.ikon,
            size: TokenJarak.ikonKecil,
            color: gelap ? warna.permukaan.withValues(alpha: 0.7) : AmbilWarnaNada(warna, penanda.nada),
          ),
          const SizedBox(width: TokenJarak.jarak4),
          // Saat menempel di bilah lain, teks tidak dipotong satu per satu: seluruh deretan diskalakan bersama
          // supaya tidak ada item yang berakhir "…" sementara sisa lebar masih ada.
          if (tanpaLatar)
            Text(penanda.teks, style: gaya, maxLines: 1, softWrap: false)
          else
            Flexible(
              child: Text(penanda.teks, style: gaya, maxLines: 1, overflow: TextOverflow.ellipsis, softWrap: false),
            ),
        ],
      );
      anak.add(tanpaLatar ? baris : Flexible(child: baris));
    }

    final deret = Row(mainAxisSize: tanpaLatar ? MainAxisSize.min : MainAxisSize.max, children: anak);
    final isi = Container(
      constraints: BoxConstraints(minHeight: tinggi),
      padding: EdgeInsets.symmetric(horizontal: tanpaLatar ? 0 : TokenJarak.jarak16),
      decoration: tanpaLatar
          ? null
          : BoxDecoration(
              border: Border(
                bottom: gelap
                    ? BorderSide(color: warna.permukaan.withValues(alpha: 0.08), width: TokenJarak.tebalGaris)
                    : BorderSide.none,
                top: gelap ? BorderSide.none : BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
              ),
            ),
      alignment: Alignment.centerLeft,
      child: tanpaLatar
          ? FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: deret)
          : deret,
    );

    if (gelap) {
      if (tanpaLatar) {
        return Semantics(
          container: true,
          label: item.map((i) => i.teks).join(' | '),
          excludeSemantics: true,
          child: isi,
        );
      }
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
