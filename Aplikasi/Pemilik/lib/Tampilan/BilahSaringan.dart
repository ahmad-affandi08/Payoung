import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/KlienPemilik.dart';
import 'FormatTampilan.dart';

/// Saringan tanggal & outlet bersama untuk Beranda, Laporan, dan Shift.
class BilahSaringan extends ConsumerWidget {
  const BilahSaringan({super.key, this.outlet = const []});

  final List<OutletRingkas> outlet;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(penyediaSaringan);
    final notifier = ref.read(penyediaSaringan.notifier);
    final hariIni = ref.read(penyediaJam)();
    return Wrap(
      spacing: TokenJarak.jarak8,
      runSpacing: TokenJarak.jarak8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: OutlinedButton.icon(
            icon: const Icon(Icons.calendar_today_outlined),
            label: Text(FormatTampilan.Tanggal(s.tanggal)),
            onPressed: () async {
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
        if (outlet.length > 1)
          DropdownButton<String?>(
            value: outlet.any((o) => o.uuid == s.outlet) ? s.outlet : null,
            hint: const Text('Semua outlet'),
            items: [
              const DropdownMenuItem<String?>(child: Text('Semua outlet')),
              for (final o in outlet) DropdownMenuItem<String?>(value: o.uuid, child: Text(o.nama)),
            ],
            onChanged: notifier.AturOutlet,
          ),
        IconButton(
          tooltip: 'Segarkan',
          onPressed: () {
            ref
              ..invalidate(penyediaDasbor)
              ..invalidate(penyediaLaporan)
              ..invalidate(penyediaLabaRugi)
              ..invalidate(penyediaAntiFraud)
              ..invalidate(penyediaStok)
              ..invalidate(penyediaShift);
          },
          icon: const Icon(Icons.refresh),
        ),
      ],
    );
  }
}
