import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:pemilik/Aplikasi/AplikasiPemilik.dart';
import 'package:pemilik/Aplikasi/Lingkungan.dart';
import 'package:pemilik/Aplikasi/Penyedia.dart';
import 'package:pemilik/Aplikasi/TanpaCobaUlang.dart';
import 'package:pemilik/Data/PenyimpanSesi.dart';

/// Server tiruan `/api/pemilik/v1`: [penangan] per permintaan, semua permintaan dicatat.
class ServerTiruan {
  final List<http.Request> permintaan = [];
  late Future<http.Response> Function(http.Request) penangan;

  http.Client BuatKlien() => MockClient((p) {
    permintaan.add(p);
    return penangan(p);
  });
}

http.Response JsonUji(Object isi, [int status = 200]) =>
    http.Response(jsonEncode(isi), status, headers: {'content-type': 'application/json'});

Future<void> PasangPemilik(
  WidgetTester tester, {
  required ServerTiruan server,
  required PenyimpanSesiMemori sesi,
  Size ukuran = const Size(360, 740),
  Lingkungan lingkungan = Lingkungan.Produksi,
  List<Override> tambahan = const [],
  DateTime Function()? jam,
}) async {
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = ukuran;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      retry: TanpaCobaUlangOtomatis,
      overrides: [
        penyediaLingkungan.overrideWithValue(lingkungan),
        penyediaKlienHttp.overrideWithValue(server.BuatKlien()),
        penyediaPenyimpanSesi.overrideWithValue(sesi),
        penyediaJam.overrideWithValue(jam ?? () => DateTime(2026, 9, 26, 15)),
        // Tanpa pemantau berkala di test (timer tidak boleh tertinggal); muat ulang lewat tab/tarik.
        penyediaSelangPantauPersetujuan.overrideWithValue(null),
        penyediaSelangKonfigurasi.overrideWithValue(null),
        ...tambahan,
      ],
      child: AplikasiPemilik(lingkungan: lingkungan),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, Object?> DasborUji({String omzet = '1250000.00'}) => {
  'Tanggal': '2026-09-26',
  'Outlet': [
    {'Uuid': 'O1', 'Nama': 'Solo Baru'},
    {'Uuid': 'O2', 'Nama': 'Kartasura'},
  ],
  'Ringkasan': {
    'Omzet': omzet,
    'LabaKotor': '480000.00',
    'Transaksi': 42,
    'RataRata': '29762.00',
    'OmzetKemarin': '1000000.00',
    'OmzetMingguLalu': '1500000.00',
  },
  'PerOutlet': [
    {'Uuid': 'O1', 'Nama': 'Solo Baru', 'Omzet': '800000.00', 'Transaksi': 30},
    {'Uuid': 'O2', 'Nama': 'Kartasura', 'Omzet': '450000.00', 'Transaksi': 12},
  ],
  'PerJam': [
    {'Jam': 9, 'Omzet': '100000.00'},
    {'Jam': 12, 'Omzet': '700000.00'},
    {'Jam': 15, 'Omzet': '450000.00'},
  ],
  'ProdukTeratas': [
    {'Nama': 'Es Kopi Susu Aren', 'Jumlah': '25.0000', 'Omzet': '450000.00'},
  ],
  'PerluTindakan': [
    {'Jenis': 'SelisihKas', 'Judul': 'Selisih kas shift Rina', 'Keterangan': 'Kurang Rp 20.000 di Solo Baru'},
  ],
};
