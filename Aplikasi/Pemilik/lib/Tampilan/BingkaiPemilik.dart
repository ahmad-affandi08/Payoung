import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/NotifikasiPush.dart';
import 'Komponen/KomponenModern.dart';
import 'LayarBeranda.dart';
import 'LayarLainnya.dart';
import 'LayarLaporan.dart';
import 'LayarNotifikasi.dart';
import 'LayarPerangkat.dart';
import 'LayarPersetujuan.dart';

/// Bingkai Aplikasi Owner (D-231): empat tab Beranda | Laporan | Persetujuan (lencana jumlah menunggu) | Lainnya di
/// bilah navigasi kapsul yang mengambang (mode kepadatan Nyaman, §17.6). Tiap tab membawa judulnya sendiri. Shift,
/// perangkat, karyawan, insight, aksi cepat, bantuan, dan akun ada di tab Lainnya; pusat notifikasi dari lonceng
/// di Beranda.
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

  static const _tujuan = [
    (Icons.home_outlined, Icons.home, 'Beranda'),
    (Icons.bar_chart_outlined, Icons.bar_chart, 'Laporan'),
    (Icons.approval_outlined, Icons.approval, 'Persetujuan'),
    (Icons.menu, Icons.menu, 'Lainnya'),
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
    // D-183: usaha tanpa kasir tidak punya daftar perangkat; tautan itu dibiarkan di tab sekarang.
    final tanpaKasir = ref.read(penyediaKonfigurasiAplikasi).value?.hanyaPerusahaan ?? false;
    switch (pesan.data['Tautan']) {
      case 'persetujuan':
        setState(() => _indeks = _indeksPersetujuan);
      case 'perangkat' when !tanpaKasir:
        unawaited(BukaHalaman(context, judul: 'Perangkat kasir', isi: const LayarPerangkat()));
    }
  }

  void _BukaNotifikasi() => unawaited(BukaNotifikasi(context));

  @override
  Widget build(BuildContext context) {
    final menunggu =
        (ref.watch(penyediaPersetujuan).value?.length ?? 0) +
        (ref.watch(penyediaDokumenPersetujuan).value?.where((d) => d.bisaDisetujui).length ?? 0);
    final indeks = _indeks.clamp(0, _tujuan.length - 1);
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: IndexedStack(
          index: indeks,
          children: const [LayarBeranda(), LayarLaporan(), LayarPersetujuan(), LayarLainnya()],
        ),
      ),
      bottomNavigationBar: BilahNavigasiMengambang(
        indeks: indeks,
        butir: [
          for (final (i, (ikon, ikonAktif, label)) in _tujuan.indexed)
            ButirNavigasi(
              ikon: ikon,
              ikonAktif: ikonAktif,
              label: label,
              lencana: i == _indeksPersetujuan ? menunggu : 0,
            ),
        ],
        saatPilih: (i) {
          if (i == _indeksPersetujuan) {
            ref
              ..invalidate(penyediaPersetujuan)
              ..invalidate(penyediaDokumenPersetujuan);
          }
          setState(() => _indeks = i);
        },
      ),
    );
  }
}
