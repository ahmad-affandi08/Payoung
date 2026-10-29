import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/KlienPemilik.dart';
import 'FormatTampilan.dart';

/// Saringan tanggal & outlet bersama untuk Beranda, Laporan, dan Shift, berbentuk kapsul putih: tanggal, outlet (bila
/// lebih dari satu), dan tombol segarkan.
class BilahSaringan extends ConsumerWidget {
  const BilahSaringan({super.key, this.outlet = const []});

  final List<OutletRingkas> outlet;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(penyediaSaringan);
    final notifier = ref.read(penyediaSaringan.notifier);
    final hariIni = ref.read(penyediaJam)();
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);

    Widget Kapsul({required IconData ikon, required String label, required VoidCallback? saatTekan}) => Material(
      color: warna.permukaan,
      shape: const StadiumBorder(),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: saatTekan,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16),
            child: Row(
              children: [
                Icon(ikon, size: TokenJarak.ikonSedang, color: warna.brand),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: teks.labelLarge?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );

    final namaOutlet = outlet.where((o) => o.uuid == s.outlet).map((o) => o.nama).firstOrNull ?? 'Semua outlet';
    return Row(
      children: [
        Expanded(
          child: Kapsul(
            ikon: Icons.calendar_today_outlined,
            label: FormatTampilan.TanggalPendek(s.tanggal),
            saatTekan: () async {
              final pilih = await showDatePicker(
                context: context,
                initialDate: s.tanggal,
                firstDate: DateTime(hariIni.year - 2),
                lastDate: hariIni,
                helpText: 'Pilih tanggal',
                cancelText: 'Batal',
                confirmText: 'Pilih',
              );
              if (pilih != null) {
                notifier.AturTanggal(pilih);
              }
            },
          ),
        ),
        if (outlet.length > 1) ...[
          const SizedBox(width: TokenJarak.jarak8),
          Expanded(
            child: PopupMenuButton<String?>(
              tooltip: 'Pilih outlet',
              position: PopupMenuPosition.under,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusTombol)),
              onSelected: notifier.AturOutlet,
              itemBuilder: (_) => [
                const PopupMenuItem<String?>(child: Text('Semua outlet')),
                for (final o in outlet) PopupMenuItem<String?>(value: o.uuid, child: Text(o.nama)),
              ],
              child: IgnorePointer(
                child: Kapsul(ikon: Icons.storefront_outlined, label: namaOutlet, saatTekan: null),
              ),
            ),
          ),
        ],
        const SizedBox(width: TokenJarak.jarak8),
        Tooltip(
          message: 'Segarkan',
          child: Material(
            color: warna.permukaan,
            shape: const CircleBorder(),
            child: InkWell(
              customBorder: const CircleBorder(),
              onTap: () {
                ref
                  ..invalidate(penyediaDasbor)
                  ..invalidate(penyediaLaporan)
                  ..invalidate(penyediaLabaRugi)
                  ..invalidate(penyediaAntiFraud)
                  ..invalidate(penyediaStok)
                  ..invalidate(penyediaShift);
              },
              child: SizedBox(
                width: TokenJarak.targetSentuh,
                height: TokenJarak.targetSentuh,
                child: Icon(Icons.refresh, color: warna.brand),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
