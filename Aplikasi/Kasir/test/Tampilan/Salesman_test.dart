import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Tampilan/LayarBukaShift.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:kasir/Tampilan/Salesman/BagianRiwayatSalesman.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/KatalogUji.dart';
import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/PasangAplikasi.dart';
import '../Pendukung/SalesmanUji.dart';

/// Modul Salesman bagian 2 (§9.7, SLS-11): ruang kerja Salesman di aplikasi Kasir — tanpa shift, pelanggan offline,
/// piutang online, kunjungan berlokasi, ambil pesanan dengan perkiraan harga, dan riwayat; di 360/800/1280 dp.
void main() {
  /// Server tiruan: data awal + katalog, pelanggan/stok/piutang/kunjungan salesman, sinkron menerima semua item.
  /// Item sinkron yang diterima dicatat urut di [diterima].
  Future<LingkunganUji> Siapkan(
    WidgetTester tester,
    Size ukuran, {
    String jenisPerangkat = 'Salesman',
    bool budiSalesman = false,
    List<Map<String, Object?>>? diterima,
    PenentuLokasiTiruan? lokasi,
    bool bukaShiftBudi = false,
  }) async {
    final u = LingkunganUji.Buat();
    final dataAwal = DataAwalSalesmanUji(budiSalesman: budiSalesman);
    await tester.runAsync(() async {
      await u.SiapkanAktif(dataAwal: dataAwal);
      await u.repositori.SimpanPengaturan(KunciPengaturan.jenisPerangkat, jenisPerangkat);
      if (bukaShiftBudi) {
        await u.shift.BukaShift(kasir: await u.Staf('Budi Santoso'), kasAwal: Uang.DariBulat(500000));
      }
    });
    u.server.penangan = (p) async {
      final jalur = p.url.path;
      if (jalur.endsWith('/data-awal')) {
        return JsonUji(dataAwal);
      }
      if (jalur.endsWith('/katalog')) {
        return JsonUji(KatalogUji());
      }
      if (jalur.endsWith('/salesman/pelanggan')) {
        return JsonUji({'Pelanggan': PelangganSalesmanUji(), 'Halaman': 1, 'AdaBerikutnya': false});
      }
      if (jalur.endsWith('/piutang')) {
        return JsonUji(PiutangSalesmanUji());
      }
      if (jalur.endsWith('/salesman/stok')) {
        return JsonUji({
          'Stok': [
            {'UuidProduk': UuidUji.roti, 'JumlahTersedia': '480.0000'},
          ],
          'DiambilPada': '2026-09-24T00:30:00Z',
        });
      }
      if (jalur.endsWith('/salesman/kunjungan')) {
        return JsonUji({'Tanggal': '2026-09-24', 'Kunjungan': <Object?>[]});
      }
      if (jalur.endsWith('/sinkron/kirim')) {
        final item = ((jsonDecode(p.body) as Map<String, Object?>)['Item']! as List<Object?>)
            .cast<Map<String, Object?>>();
        diterima?.addAll(item);
        return JsonUji({
          'Hasil': [
            for (final i in item) {'Uuid': i['Uuid'], 'Jenis': i['Jenis'], 'Status': 'Diterima', 'Galat': null},
          ],
        });
      }
      throw http.ClientException('offline');
    };
    await PasangAplikasi(tester, u, ukuran: ukuran, penentuLokasi: lokasi ?? PenentuLokasiTiruan());
    await Tunggu(tester, const Duration(milliseconds: 600));
    return u;
  }

  Future<void> Masuk(WidgetTester tester, String nama, int indeksPin) async {
    await PilihKasir(tester, nama);
    await tester.pump();
    await KetikPin(tester, KasusPin(indeksPin)['Pin']! as String);
    await Tunggu(tester, const Duration(milliseconds: 600));
  }

  Future<void> Ketuk(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await Tunggu(tester, const Duration(milliseconds: 60));
    await tester.tap(finder);
    await Tunggu(tester);
  }

  for (final ukuran in const [Size(360, 740), Size(800, 1280), Size(1280, 900)]) {
    final lebar = ukuran.width.toInt();
    testWidgets('HP salesman ($lebar dp): tanpa shift, pelanggan offline, kunjungan berlokasi, pesanan, riwayat', (
      tester,
    ) async {
      final diterima = <Map<String, Object?>>[];
      final lokasi = PenentuLokasiTiruan();
      final u = await Siapkan(tester, ukuran, diterima: diterima, lokasi: lokasi);
      await Masuk(tester, 'Dewi Kartika Sari', 2);

      // Ruang kerja Salesman langsung terbuka: tanpa buka shift, tanpa Jual/Kas/Shift di navigasi.
      expect(find.byType(RuangKerja), findsOneWidget);
      expect(find.byType(LayarBukaShift), findsNothing);
      expect(find.text('Mode salesman'), findsOneWidget);
      for (final label in ['Jual', 'Kas', 'Shift', 'Riwayat transaksi hari ini']) {
        expect(find.text(label), findsNothing, reason: '$label tidak ada di mode Salesman');
      }
      expect(find.text('Sinkron'), findsOneWidget);
      expect(NavPengaturan(), findsOneWidget);

      // Pelanggan diunduh otomatis lalu tampil dari cache: tier, piutang lewat jatuh tempo berteks, kunjungan terakhir.
      expect(find.text('Toko Kelontong Makmur Jaya Abadi Sentosa'), findsOneWidget);
      expect(find.text('Lewat jatuh tempo 12 hari | Rp 3.250.000'), findsOneWidget);
      expect(find.text('Terakhir dikunjungi 20 Sep 2026'), findsOneWidget);
      expect(find.text('Belum pernah dikunjungi'), findsOneWidget);
      expect(find.textContaining('2 pelanggan | terakhir diperbarui'), findsOneWidget);
      expect(
        u.server.permintaan.where((p) => p.url.path.endsWith('/salesman/pelanggan')).single.headers['X-Id-Kasir'],
        UuidSalesmanUji.dewi,
      );

      // Cari lokal (nomor HP berawalan 0).
      await tester.enterText(find.byKey(const ValueKey('CariPelangganSalesman')), '0812 9999');
      await Tunggu(tester);
      expect(find.text('Warung Bu Sri'), findsOneWidget);
      expect(find.text('Toko Kelontong Makmur Jaya Abadi Sentosa'), findsNothing);
      await tester.enterText(find.byKey(const ValueKey('CariPelangganSalesman')), '');
      await Tunggu(tester);

      // Rincian: nomor & alamat bisa dipilih, posisi kredit, piutang online.
      await Ketuk(tester, find.text('Toko Kelontong Makmur Jaya Abadi Sentosa'));
      expect(find.text('6281355550001'), findsOneWidget);
      expect(find.text('Jl. Slamet Riyadi No. 212, Purwosari, Laweyan, Surakarta'), findsOneWidget);
      expect(find.text('Rp 12.250.000'), findsOneWidget, reason: 'Sisa limit = 25 jt − 12,75 jt');
      expect(find.text('FJ/SLB/2609/0004'), findsOneWidget);
      expect(find.textContaining('lewat 12 hari'), findsOneWidget);

      // Mulai kunjungan: lokasi sekali, banner berjalan.
      await Ketuk(tester, find.text('Mulai kunjungan'));
      await Tunggu(tester);
      expect(lokasi.dipanggil, 1);
      expect(find.text('Berkunjung ke Toko Kelontong Makmur Jaya Abadi Sentosa'), findsOneWidget);
      expect(find.textContaining('Lokasi tercatat (±12 m)'), findsWidgets);

      // Ambil pesanan: cari roti, 12 pcs → perkiraan harga bertingkat Rp 132.000.
      await Ketuk(tester, find.text('Ambil pesanan').first);
      expect(find.byType(PanelTugas), findsOneWidget);
      expect(find.textContaining('Bagian dari kunjungan yang sedang berjalan'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('CariProdukPesanan')), 'roti');
      await Tunggu(tester);
      expect(find.text('Susu UHT 1 Liter'), findsNothing);
      await Ketuk(tester, find.text('Roti Tawar Gandum').last);
      await tester.enterText(find.byKey(const ValueKey('Jumlah-${UuidUji.roti}-${UuidUji.psRotiPcs}')), '12');
      await Tunggu(tester);
      expect(find.text('Rp 132.000'), findsOneWidget);
      expect(find.text('Perkiraan. Harga final dari kantor'), findsOneWidget);
      expect(find.textContaining('Stok kantor ± 480 Pcs'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('CatatanPesanan')), 'Kirim Kamis pagi');
      await Tunggu(tester);
      await Ketuk(tester, find.text('Kirim pesanan'));
      await Tunggu(tester, const Duration(milliseconds: 400));
      expect(find.byType(PanelTugas), findsNothing);

      // Selesai kunjungan: hasil bawaan "Pesanan dibuat" karena ada pesanan.
      await Ketuk(tester, find.text('Selesai kunjungan').first);
      final chip = tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Pesanan dibuat'));
      expect(chip.selected, isTrue);
      await Ketuk(tester, find.text('Simpan kunjungan'));
      await Tunggu(tester, const Duration(milliseconds: 400));
      expect(find.textContaining('Berkunjung ke'), findsNothing);

      // Server menerima pesanan (tanpa harga) lebih dulu, lalu kunjungan yang menautkannya.
      expect(diterima.map((i) => i['Jenis']), ['PesananGrosir.Buat', 'Kunjungan.Catat']);
      final pesanan = diterima.first['Data']! as Map<String, Object?>;
      final kunjungan = diterima.last['Data']! as Map<String, Object?>;
      expect(pesanan['Baris'], [
        {'UuidProduk': UuidUji.roti, 'UuidSatuan': UuidUji.psRotiPcs, 'Jumlah': '12.0000'},
      ]);
      expect((pesanan['Catatan'], pesanan['UuidKunjungan']), ('Kirim Kamis pagi', diterima.last['Uuid']));
      expect(
        (kunjungan['Hasil'], kunjungan['UuidPesananGrosir'], kunjungan['Latitude'], kunjungan['Longitude']),
        ('PesananDibuat', diterima.first['Uuid'], '-7.5666001', '110.8166002'),
      );

      // Riwayat: pesanan & kunjungan dengan status kirim berteks.
      await Ketuk(tester, find.text('Riwayat'));
      expect(find.text('Pesanan | Toko Kelontong Makmur Jaya Abadi Sentosa'), findsOneWidget);
      expect(find.text('Kunjungan | Toko Kelontong Makmur Jaya Abadi Sentosa'), findsOneWidget);
      expect(find.textContaining('1 produk | perkiraan Rp 132.000'), findsOneWidget);
      expect(find.text('Terkirim'), findsNWidgets(2));
      await tester.scrollUntilVisible(
        find.text('Belum ada kunjungan yang diterima kantor hari ini'),
        200,
        scrollable: find.descendant(of: find.byType(BagianRiwayatSalesman), matching: find.byType(Scrollable)).first,
      );
      expect(find.text('Belum ada kunjungan yang diterima kantor hari ini'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await Lepas(tester, u);
    });
  }

  testWidgets('offline tanpa cache & tanpa lokasi: keadaan kosong jelas, kunjungan tetap tercatat', (tester) async {
    final u = await Siapkan(tester, const Size(800, 1280), lokasi: PenentuLokasiTiruan(tersedia: false));
    u.server.penangan = (_) async => throw http.ClientException('offline');
    await Masuk(tester, 'Dewi Kartika Sari', 2);
    expect(find.textContaining('Belum ada data pelanggan di perangkat ini'), findsOneWidget);
    expect(find.text('Belum pernah diperbarui'), findsOneWidget);

    // Cache dari unduhan sebelumnya, lalu kunjungan tanpa lokasi saat offline.
    await tester.runAsync(
      () => u.repositoriSalesman.GantiPelanggan([for (final p in PelangganSalesmanUji()) _Pos(p)], u.jam),
    );
    await Tunggu(tester);
    await Ketuk(tester, find.text('Warung Bu Sri'));
    expect(find.textContaining('Offline. Rincian piutang hanya bisa dilihat saat online'), findsOneWidget);
    await Ketuk(tester, find.text('Mulai kunjungan'));
    await Tunggu(tester);
    expect(find.textContaining('Lokasi tidak tersedia'), findsWidgets);
    await Ketuk(tester, find.text('Selesai kunjungan').first);
    await Ketuk(tester, find.widgetWithText(ChoiceChip, 'Toko tutup'));
    await Ketuk(tester, find.text('Simpan kunjungan'));
    await Tunggu(tester, const Duration(milliseconds: 400));

    final outbox = await tester.runAsync(
      () => u.repositori.AmbilOutboxSiapKirim(50, u.jam.add(const Duration(days: 1))),
    );
    final data = jsonDecode(outbox!.single.Data) as Map<String, Object?>;
    expect(data['Hasil'], 'TokoTutup');
    expect(data.containsKey('Latitude'), isFalse);

    await Ketuk(tester, find.text('Riwayat'));
    expect(find.text('Belum terkirim'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.textContaining('Offline. Daftar dari kantor tampil saat online'),
      200,
      scrollable: find.descendant(of: find.byType(BagianRiwayatSalesman), matching: find.byType(Scrollable)).first,
    );
    expect(find.textContaining('Offline. Daftar dari kantor tampil saat online'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('HP kasir: pengguna yang hanya salesman masuk ke mode Salesman tanpa buka shift', (tester) async {
    final u = await Siapkan(tester, const Size(800, 1280), jenisPerangkat: 'Kasir');
    await Masuk(tester, 'Dewi Kartika Sari', 2);
    expect(find.byType(LayarBukaShift), findsNothing);
    expect(find.text('Mode salesman'), findsOneWidget);
    expect(find.text('Toko Kelontong Makmur Jaya Abadi Sentosa'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('HP kasir: kasir yang juga salesman melihat menu Salesman di rel, shift tetap berjalan', (tester) async {
    final u = await Siapkan(
      tester,
      const Size(1280, 900),
      jenisPerangkat: 'Kasir',
      budiSalesman: true,
      bukaShiftBudi: true,
    );
    await Masuk(tester, 'Budi Santoso', 1);
    expect(find.textContaining(RegExp(r'^Shift \d{2}\.\d{2}$')), findsOneWidget);
    expect(find.text('Mode salesman'), findsNothing);
    await Ketuk(tester, find.text('Salesman').first);
    await Tunggu(tester, const Duration(milliseconds: 400));
    expect(find.text('Toko Kelontong Makmur Jaya Abadi Sentosa'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await Lepas(tester, u);
  });

  testWidgets('HP salesman, pengguna tanpa izin salesman: keadaan tanpa izin berteks', (tester) async {
    final u = await Siapkan(tester, const Size(360, 740));
    await Masuk(tester, 'Rina Wulandari', 0);
    expect(find.textContaining('Rina Wulandari tidak punya izin salesman'), findsOneWidget);
    expect(u.server.permintaan.where((p) => p.url.path.contains('/salesman/')), isEmpty);
    await Lepas(tester, u);
  });
}

PelangganSalesmanPos _Pos(Map<String, Object?> json) => PelangganSalesmanPos.DariJson(json);
