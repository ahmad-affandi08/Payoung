import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/NotifikasiPush.dart';
import 'LayarBeranda.dart';
import 'LayarLaporan.dart';
import 'LayarNotifikasi.dart';
import 'LayarPerangkat.dart';
import 'LayarPersetujuan.dart';
import 'LayarShift.dart';

/// Bingkai Aplikasi Owner: bilah atas (nama usaha, ganti usaha, keluar) dan navigasi bawah Beranda | Laporan |
/// Persetujuan (lencana jumlah menunggu) | Shift | Perangkat (mode kepadatan Nyaman, §17.6), dengan pusat
/// notifikasi dari ikon lonceng di bilah atas.
class BingkaiPemilik extends ConsumerStatefulWidget {
  const BingkaiPemilik({super.key});

  @override
  ConsumerState<BingkaiPemilik> createState() => _BingkaiPemilikState();
}

class _BingkaiPemilikState extends ConsumerState<BingkaiPemilik> {
  var _indeks = 0;
  Timer? _pantau;
  final List<StreamSubscription<Object?>> _langgananPush = [];

  static const int _indeksPersetujuan = 2;
  static const int _indeksPerangkat = 4;

  static const _tujuan = [
    (Icons.home_outlined, Icons.home, 'Beranda'),
    (Icons.bar_chart_outlined, Icons.bar_chart, 'Laporan'),
    (Icons.approval_outlined, Icons.approval, 'Persetujuan'),
    (Icons.schedule_outlined, Icons.schedule, 'Shift'),
    (Icons.point_of_sale_outlined, Icons.point_of_sale, 'Perangkat'),
  ];

  @override
  void initState() {
    super.initState();
    final selang = ref.read(penyediaSelangPantauPersetujuan);
    if (selang != null) {
      _pantau = Timer.periodic(selang, (_) {
        ref
          ..invalidate(penyediaPersetujuan)
          ..invalidate(penyediaDokumenPersetujuan);
      });
    }
    unawaited(_SiapkanPush());
  }

  @override
  void dispose() {
    _pantau?.cancel();
    for (final langganan in _langgananPush) {
      unawaited(langganan.cancel());
    }
    super.dispose();
  }

  Future<void> _SiapkanPush() async {
    final push = ref.read(penyediaNotifikasiPush);
    _langgananPush
      ..add(push.tokenBerubah.listen((token) => unawaited(_DaftarkanToken(token))))
      ..add(
        push.pesanMasuk.listen((pesan) {
          ref.invalidate(penyediaNotifikasi);
          ref
            ..invalidate(penyediaPersetujuan)
            ..invalidate(penyediaDokumenPersetujuan);
          if (mounted && pesan.judul != null) {
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(pesan.judul!)));
          }
        }),
      )
      ..add(push.pesanDibuka.listen(_BukaPesan));

    try {
      final token = await push.AmbilToken();
      if (token != null) await _DaftarkanToken(token);
      final awal = await push.AmbilPesanAwal();
      if (awal != null) _BukaPesan(awal);
    } on Object {
      // Push tidak menghalangi fungsi utama aplikasi saat layanan belum tersedia.
    }
  }

  Future<void> _DaftarkanToken(String token) => ref
      .read(penyediaKlien)
      .DaftarkanTokenNotifikasi(
        token: token,
        platform: defaultTargetPlatform == TargetPlatform.iOS ? 'Ios' : 'Android',
        namaPerangkat: PengaturSesi.namaPerangkat,
      );

  void _BukaPesan(PesanPush pesan) {
    if (!mounted) return;
    ref.invalidate(penyediaNotifikasi);
    ref
      ..invalidate(penyediaPersetujuan)
      ..invalidate(penyediaDokumenPersetujuan);
    if (pesan.data['Tautan'] == 'notifikasi') {
      _BukaNotifikasi();
      return;
    }
    setState(() {
      _indeks = switch (pesan.data['Tautan']) {
        'persetujuan' => _indeksPersetujuan,
        'perangkat' => _indeksPerangkat,
        _ => _indeks,
      };
    });
  }

  void _BukaNotifikasi() {
    unawaited(
      Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => Scaffold(
            appBar: AppBar(title: const Text('Notifikasi')),
            body: const LayarNotifikasi(),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final sesi = ref.watch(penyediaSesi);
    final notifier = ref.read(penyediaSesi.notifier);
    final menunggu =
        (ref.watch(penyediaPersetujuan).value?.length ?? 0) +
        (ref.watch(penyediaDokumenPersetujuan).value?.where((d) => d.bisaDisetujui).length ?? 0);
    final belumDibaca = ref.watch(penyediaNotifikasi).value?.belumDibaca ?? 0;
    final warna = TokenWarna.AmbilDari(context);
    return Scaffold(
      appBar: AppBar(
        backgroundColor: warna.brandGelap,
        foregroundColor: warna.permukaan,
        surfaceTintColor: warna.brandGelap,
        title: Text(sesi.namaTenant ?? 'Payoung Owner', style: const TextStyle(fontWeight: FontWeight.w700)),
        actions: [
          IconButton(
            tooltip: belumDibaca > 0 ? 'Notifikasi ($belumDibaca belum dibaca)' : 'Notifikasi',
            onPressed: _BukaNotifikasi,
            icon: belumDibaca > 0
                ? Badge(label: Text('$belumDibaca'), child: const Icon(Icons.notifications_outlined))
                : const Icon(Icons.notifications_outlined),
          ),
          PopupMenuButton<String>(
            tooltip: 'Akun',
            icon: const Icon(Icons.account_circle_outlined),
            onSelected: (pilih) => pilih == 'ganti' ? notifier.GantiTenant() : unawaited(notifier.Keluar()),
            itemBuilder: (_) => [
              if (sesi.tenant.length > 1) const PopupMenuItem(value: 'ganti', child: Text('Ganti usaha')),
              const PopupMenuItem(value: 'keluar', child: Text('Keluar')),
            ],
          ),
        ],
      ),
      body: IndexedStack(
        index: _indeks,
        children: const [LayarBeranda(), LayarLaporan(), LayarPersetujuan(), LayarShift(), LayarPerangkat()],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _indeks,
        onDestinationSelected: (i) {
          if (i == _indeksPersetujuan) {
            ref
              ..invalidate(penyediaPersetujuan)
              ..invalidate(penyediaDokumenPersetujuan);
          }
          setState(() => _indeks = i);
        },
        destinations: [
          for (final (i, (ikon, ikonAktif, label)) in _tujuan.indexed)
            NavigationDestination(
              icon: i == _indeksPersetujuan && menunggu > 0
                  ? Badge(label: Text('$menunggu'), child: Icon(ikon))
                  : Icon(ikon),
              selectedIcon: i == _indeksPersetujuan && menunggu > 0
                  ? Badge(label: Text('$menunggu'), child: Icon(ikonAktif))
                  : Icon(ikonAktif),
              label: label,
              tooltip: i == _indeksPersetujuan && menunggu > 0 ? '$label ($menunggu menunggu)' : label,
            ),
        ],
      ),
    );
  }
}
