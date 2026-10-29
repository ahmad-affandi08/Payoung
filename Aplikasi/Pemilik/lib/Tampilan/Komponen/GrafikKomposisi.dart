import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Data/KlienPemilik.dart';
import '../FormatTampilan.dart';

/// Satu irisan [GrafikDonat]: [nilai] adalah besaran mentah (bukan persen), [tampilNilai] teks angka yang tertulis di
/// legenda (misal "Rp 1.200.000"); null = legenda hanya memuat nama dan persen.
@immutable
class IrisanKomposisi {
  const IrisanKomposisi({required this.label, required this.nilai, this.tampilNilai});

  final String label;
  final double nilai;
  final String? tampilNilai;

  /// Menyusun irisan dari [baris] omzet: paling banyak [maks] irisan terbesar, sisanya digabung jadi "Lainnya". Baris
  /// berangka nol atau negatif dibuang karena tidak punya porsi.
  static List<IrisanKomposisi> DariBarisNilai(
    List<BarisNilai> baris, {
    int maks = 5,
    String Function(BarisNilai)? label,
    String Function(BarisNilai)? rincian,
  }) {
    final urut = [
      for (final b in baris)
        if ((double.tryParse(b.omzet) ?? 0) > 0) b,
    ]..sort((a, b) => (double.parse(b.omzet)).compareTo(double.parse(a.omzet)));
    final teratas = urut.take(maks).toList();
    final irisan = [
      for (final b in teratas)
        IrisanKomposisi(
          label: label == null ? b.nama : label(b),
          nilai: double.parse(b.omzet),
          tampilNilai: rincian == null ? FormatTampilan.Rupiah(b.omzet) : rincian(b),
        ),
    ];
    final sisa = urut.skip(maks).fold<double>(0, (jumlah, b) => jumlah + double.parse(b.omzet));
    if (sisa > 0) {
      irisan.add(
        IrisanKomposisi(label: 'Lainnya', nilai: sisa, tampilNilai: FormatTampilan.Rupiah(sisa.round().toString())),
      );
    }
    return irisan;
  }
}

/// Warna irisan berurutan dari token (bukan hex lepas); irisan "Lainnya" selalu abu-abu netral.
List<Color> _WarnaIrisan(TokenWarna warna, List<IrisanKomposisi> irisan) {
  final urutan = [warna.brand, warna.aksen, warna.info, warna.sukses, warna.peringatan, warna.bahaya];
  return [
    for (final (i, s) in irisan.indexed)
      if (s.label == 'Lainnya') warna.garisInput else urutan[i % urutan.length],
  ];
}

/// Donat komposisi tanpa pustaka (D-236): cincin tebal dengan celah antar-irisan dan ringkasan di tengah. Hanya dipakai
/// bersama legenda berteks (lihat [PanelKomposisi]) karena warna saja tidak boleh membawa makna (§17.6.11).
class GrafikDonat extends StatelessWidget {
  const GrafikDonat({
    super.key,
    required this.irisan,
    required this.warnaIrisan,
    this.ukuran = 132,
    this.judulPusat,
    this.nilaiPusat,
  });

  final List<IrisanKomposisi> irisan;
  final List<Color> warnaIrisan;
  final double ukuran;
  final String? judulPusat;
  final String? nilaiPusat;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return SizedBox(
      width: ukuran,
      height: ukuran,
      child: Stack(
        alignment: Alignment.center,
        children: [
          CustomPaint(
            size: Size.square(ukuran),
            painter: _PelukisDonat(nilai: [for (final s in irisan) s.nilai], warna: warnaIrisan, alas: warna.garis),
          ),
          Padding(
            padding: EdgeInsets.all(ukuran * 0.2),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (judulPusat != null)
                  Text(judulPusat!, maxLines: 1, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                if (nilaiPusat != null)
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(nilaiPusat!, style: teks.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _PelukisDonat extends CustomPainter {
  _PelukisDonat({required this.nilai, required this.warna, required this.alas});

  final List<double> nilai;
  final List<Color> warna;
  final Color alas;

  @override
  void paint(Canvas kanvas, Size ukuran) {
    final tebal = ukuran.width * 0.17;
    final kotak = Rect.fromLTWH(tebal / 2, tebal / 2, ukuran.width - tebal, ukuran.height - tebal);
    final seluruhnya = nilai.fold<double>(0, (a, b) => a + b);
    final lukisan = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = tebal;
    if (seluruhnya <= 0) {
      kanvas.drawArc(kotak, 0, math.pi * 2, false, lukisan..color = alas);
      return;
    }
    // Celah kecil antar-irisan; irisan tunggal berupa cincin penuh tanpa celah.
    final celah = nilai.length > 1 ? 0.04 : 0.0;
    var sudut = -math.pi / 2;
    for (final (i, v) in nilai.indexed) {
      final sapuan = v / seluruhnya * math.pi * 2;
      final isi = math.max(sapuan - celah, 0.001);
      kanvas.drawArc(kotak, sudut + celah / 2, isi, false, lukisan..color = warna[i]);
      sudut += sapuan;
    }
  }

  @override
  bool shouldRepaint(_PelukisDonat lama) =>
      lama.alas != alas || !_SamaDaftar(lama.nilai, nilai) || !_SamaDaftar(lama.warna, warna);

  static bool _SamaDaftar<T>(List<T> a, List<T> b) {
    if (a.length != b.length) {
      return false;
    }
    for (var i = 0; i < a.length; i++) {
      if (a[i] != b[i]) {
        return false;
      }
    }
    return true;
  }
}

/// Donat + legend berteks: setiap baris legend memuat penanda warna, nama, persen, dan nilai sehingga komposisi terbaca
/// tanpa warna. Di layar sempit, donat berada di atas legend.
class PanelKomposisi extends StatelessWidget {
  const PanelKomposisi({super.key, required this.irisan, this.judulPusat, this.nilaiPusat, this.ukuranDonat = 132});

  final List<IrisanKomposisi> irisan;
  final String? judulPusat;
  final String? nilaiPusat;
  final double ukuranDonat;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final warnaIrisan = _WarnaIrisan(warna, irisan);
    final seluruhnya = irisan.fold<double>(0, (a, b) => a + b.nilai);

    final ringkasanSemantik = [
      for (final s in irisan)
        '${s.label} ${(seluruhnya == 0 ? 0 : s.nilai / seluruhnya * 100).round()} persen${s.tampilNilai == null ? '' : ', ${s.tampilNilai}'}',
    ].join('; ');

    final donat = Semantics(
      label: 'Komposisi: $ringkasanSemantik',
      image: true,
      excludeSemantics: true,
      child: GrafikDonat(
        irisan: irisan,
        warnaIrisan: warnaIrisan,
        ukuran: ukuranDonat,
        judulPusat: judulPusat,
        nilaiPusat: nilaiPusat,
      ),
    );
    final legenda = Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (final (i, s) in irisan.indexed)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
            child: Row(
              children: [
                Container(
                  width: 12,
                  height: 12,
                  decoration: BoxDecoration(color: warnaIrisan[i], shape: BoxShape.circle),
                ),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        s.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w700),
                      ),
                      if (s.tampilNilai case final String nilai)
                        Text(nilai, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                    ],
                  ),
                ),
                const SizedBox(width: TokenJarak.jarak8),
                Text(
                  '${(seluruhnya == 0 ? 0 : s.nilai / seluruhnya * 100).round()}%',
                  style: teks.labelLarge?.copyWith(fontWeight: FontWeight.w800),
                ),
              ],
            ),
          ),
      ],
    );
    return LayoutBuilder(
      builder: (context, batas) {
        if (batas.maxWidth < 300) {
          return Column(
            children: [
              donat,
              const SizedBox(height: TokenJarak.jarak12),
              legenda,
            ],
          );
        }
        return Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            donat,
            const SizedBox(width: TokenJarak.jarak16),
            Expanded(child: legenda),
          ],
        );
      },
    );
  }
}
