import 'package:flutter/material.dart';
import 'package:sistem_desain/SistemDesain.dart';

import 'KategoriPengaturan.dart';

/// Lencana status singkat di baris kategori (misal "Siap", "Cek"). Teks selalu tampil, warna hanya penegas.
typedef LencanaKategori = ({String teks, NadaStatus nada});

/// Daftar kategori Pengaturan (D-301). Di layar lebar menjadi panel di kiri dengan kategori aktif bernada merek; di HP
/// menjadi daftar penuh (ketuk satu kategori untuk membuka isinya). Baris kategori memakai [ValueKey] `Kategori-{nama}`.
class DaftarKategoriPengaturan extends StatelessWidget {
  const DaftarKategoriPengaturan({
    super.key,
    required this.aktif,
    required this.saatPilih,
    this.lencana = const {},
    this.ringkasan = const {},
    this.penuh = false,
  });

  /// Kategori yang sedang dibuka (null di HP saat daftar belum memilih apa pun).
  final KategoriPengaturan? aktif;
  final ValueChanged<KategoriPengaturan> saatPilih;
  final Map<KategoriPengaturan, LencanaKategori> lencana;

  /// Keterangan baris yang menimpa teks bawaan (misal "POS-001 | v1.0.2").
  final Map<KategoriPengaturan, String> ringkasan;

  /// HP: baris lebih tinggi dengan panah di kanan.
  final bool penuh;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    return KotakPanel(
      anak: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final kategori in KategoriPengaturan.values)
            Semantics(
              button: true,
              selected: kategori == aktif,
              label: kategori.label,
              child: InkWell(
                key: ValueKey('Kategori-${kategori.name}'),
                borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
                onTap: () => saatPilih(kategori),
                child: Container(
                  constraints: BoxConstraints(minHeight: penuh ? 64 : TokenJarak.targetSentuh + 4),
                  padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak8, vertical: TokenJarak.jarak8),
                  decoration: BoxDecoration(
                    color: kategori == aktif ? warna.brandTint : null,
                    borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
                  ),
                  child: Row(
                    children: [
                      Icon(kategori.ikon, color: kategori == aktif ? warna.brand : warna.teksSekunder),
                      const SizedBox(width: TokenJarak.jarak12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              kategori.label,
                              style: teks.titleSmall?.copyWith(color: kategori == aktif ? warna.brand : null),
                            ),
                            Text(
                              ringkasan[kategori] ?? kategori.ringkas,
                              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ],
                        ),
                      ),
                      if (lencana[kategori] case final l?) ...[
                        const SizedBox(width: TokenJarak.jarak8),
                        Flexible(
                          flex: 0,
                          child: LencanaTeks(teks: l.teks, label: '${kategori.label}: ${l.teks}', nada: l.nada),
                        ),
                      ],
                      if (penuh) Icon(Icons.chevron_right, color: warna.teksSekunder),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
