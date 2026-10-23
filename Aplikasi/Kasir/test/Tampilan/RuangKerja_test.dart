import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:inti/Inti.dart';
import 'package:kasir/Aplikasi/Penyedia.dart';
import 'package:kasir/Data/RepositoriKasir.dart';
import 'package:kasir/Domain/Penjualan/Keranjang.dart';
import 'package:kasir/Domain/Perangkat/PengaturanPerangkat.dart';
import 'package:kasir/Tampilan/RuangKerja/BilahAtasRuangKerja.dart';
import 'package:kasir/Tampilan/RuangKerja/LayarKunci.dart';
import 'package:kasir/Tampilan/RuangKerja/RuangKerja.dart';
import 'package:kasir/Tampilan/RuangKerja/SidebarRuangKerja.dart';
import 'package:kasir/Tampilan/RuangKerja/TemaNavigasiRuangKerja.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Pendukung/LingkunganUji.dart';
import '../Pendukung/MuatFont.dart';
import '../Pendukung/PasangAplikasi.dart';

/// Ruang Kerja Kasir (PRD §17.2.7, D-16): bingkai di 360/800/1280dp, panel tugas, kunci cepat & otomatis, ganti kasir
/// tanpa menutup shift, dan pengaturan perangkat.
void main() {
  const ukuranHp = Size(360, 740);
  const ukuranTablet = Size(800, 1280);
  const ukuranDesktop = Size(1280, 900);

  /// Perangkat aktif, shift Rina sudah terbuka (Rp 500.000), server offline; masuk sebagai Rina.
  Future<LingkunganUji> MasukRuangKerja(
    WidgetTester tester, {
    Size ukuran = ukuranDesktop,
    PenjagaLayarTiruan? penjagaLayar,
    Map<String, String> pengaturan = const {},
    bool relAwalDiciutkan = false,
  }) async {
    final u = LingkunganUji.Buat();
    await tester.runAsync(() async {
      await u.SiapkanAktif();
      await u.repositori.SimpanPengaturan(KunciPengaturan.namaOutlet, 'Kopi Senja Solo Baru');
      await u.repositori.SimpanPengaturan(KunciPengaturan.kodePerangkat, 'POS-001');
      for (final e in pengaturan.entries) {
        await u.repositori.SimpanPengaturan(e.key, e.value);
      }
      await u.shift.BukaShift(kasir: await u.Staf('Rina Wulandari'), kasAwal: Uang.DariBulat(500000));
    });
    u.server.penangan = (_) async => throw http.ClientException('offline');
    await PasangAplikasi(tester, u, ukuran: ukuran, penjagaLayar: penjagaLayar, relAwalDiciutkan: relAwalDiciutkan);
    await PilihKasir(tester, 'Rina Wulandari');
    await tester.pump();
    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    expect(find.byType(RuangKerja), findsOneWidget);
    return u;
  }

  Future<String?> BacaPengaturan(WidgetTester tester, LingkunganUji u, String kunci) =>
      tester.runAsync<String?>(() => u.repositori.AmbilPengaturan(kunci));

  for (final (nama, ukuran) in [('360', ukuranHp), ('800', ukuranTablet), ('1280', ukuranDesktop)]) {
    testWidgets('bingkai di lebar $nama dp: navigasi, beranda Jual, bilah status, panel kas, layar menyala', (
      tester,
    ) async {
      final penjagaLayar = PenjagaLayarTiruan();
      final u = await MasukRuangKerja(tester, ukuran: ukuran, penjagaLayar: penjagaLayar);

      // Bilah atas: logo outlet di kiri, outlet | perangkat di sebelahnya, logo Payoung di tengah, kasir & tombol
      // kunci di kanan. Di HP (< 600dp) bilahnya diringkas: nama kasir pindah ke petunjuk tombol (dan tetap ada di
      // layar Shift), jam mengikuti jam sistem yang persis di atasnya — supaya logo bisa berada di tengah tanpa
      // memotong nama outlet.
      final lega = ukuran.width >= 600;
      expect(find.byType(LogoMerek), findsOneWidget, reason: 'Logo Payoung ada di bilah atas di setiap lebar.');
      expect(find.text('Kopi Senja Solo Baru | POS-001'), findsOneWidget);
      // D-16: tanpa logo outlet yang tampil ikon toko di kiri bilah.
      expect(
        find.descendant(of: find.byType(BilahAtasRuangKerja), matching: find.byIcon(Icons.storefront_outlined)),
        findsOneWidget,
      );
      expect(find.byTooltip('Ganti kasir'), findsOneWidget);
      expect(find.text('Rina Wulandari'), lega ? findsOneWidget : findsNothing);
      expect(lega ? find.text('Kunci') : find.byTooltip('Kunci'), findsOneWidget);

      // Rel kiri di ≥ 600dp, bilah navigasi bawah di < 600dp. Beranda = Jual.
      expect(find.byType(SidebarRuangKerja), ukuran.width >= 600 ? findsOneWidget : findsNothing);
      expect(find.byType(NavigationBar), ukuran.width >= 600 ? findsNothing : findsOneWidget);
      expect(find.text('Katalog belum ada di perangkat ini.'), findsOneWidget);
      for (final label in ['Jual', 'Riwayat', 'Kas', 'Shift', 'Sinkron', lega ? 'Pengaturan' : 'Atur']) {
        expect(find.text(label), findsOneWidget, reason: 'Item navigasi $label');
      }

      // Bilah status selalu terlihat: koneksi, tertunda, printer, jam buka shift. D-16: di layar lebar isinya
      // berada di bawah nama outlet pada bilah atas; di layar yang lebih sempit di bilah status tersendiri.
      expect(find.text('Offline'), findsOneWidget);
      expect(find.text('1 belum terkirim'), findsOneWidget);
      expect(find.text('Printer belum diatur'), findsOneWidget);
      expect(find.textContaining(RegExp(r'^Shift \d{2}\.\d{2}$')), findsOneWidget);
      final statusDiBilah = ukuran.width >= BilahAtasRuangKerja.lebarStatus;
      for (final teks in ['Offline', '1 belum terkirim', 'Printer belum diatur']) {
        expect(
          find.descendant(of: find.byType(BilahAtasRuangKerja), matching: find.text(teks)),
          statusDiBilah ? findsOneWidget : findsNothing,
          reason: 'Status "$teks" di bawah nama outlet pada bilah atas di lebar $nama dp.',
        );
      }

      // Layar tetap menyala selama shift terbuka.
      expect(penjagaLayar.menyala, isTrue);

      // Kas → Kas masuk: panel samping di ≥ 1024dp, lembar bawah di bawahnya; area kerja tetap di belakangnya.
      await tester.tap(find.text('Kas'));
      await Tunggu(tester);
      await tester.tap(find.widgetWithText(OutlinedButton, 'Kas masuk'));
      await Tunggu(tester);
      final panel = tester.widget<PanelTugas>(find.byType(PanelTugas));
      expect(panel.judul, 'Kas masuk');
      expect(panel.tataLetak, ukuran.width >= 1024 ? TataLetakPanel.Samping : TataLetakPanel.Lembar);
      expect(find.widgetWithText(TextField, 'Jumlah'), findsOneWidget);
      expect(find.text('Kas awal'), findsOneWidget, reason: 'Layar Kas tetap ada di bawah panel.');
      await tester.tap(find.byTooltip('Tutup'));
      await Tunggu(tester);
      expect(find.byType(PanelTugas), findsNothing);

      // Bilah status menempel di bilah atas (bukan kaki layar); detail dibuka dari menu Sinkron.
      await tester.tap(find.text('Sinkron'));
      await Tunggu(tester);
      expect(find.text('Status sinkron'), findsOneWidget);
      expect(find.text('1 data belum terkirim.'), findsOneWidget);

      await Lepas(tester, u);
      expect(penjagaLayar.menyala, isFalse, reason: 'Penjaga layar dilepas saat ruang kerja ditutup.');
    });
  }

  testWidgets('bingkai memakai warna merek: bilah atas, rel, dan bilah bawah; logo di tengah bilah atas', (
    tester,
  ) async {
    final warna = TokenWarna.bawaan;

    for (final (nama, ukuran) in [('1280', ukuranDesktop), ('800', ukuranTablet), ('360', ukuranHp)]) {
      final u = await MasukRuangKerja(tester, ukuran: ukuran);

      // Bilah atas: latar merek gelap dengan teks & ikon putih (kontras ±10:1).
      final bilah = tester.widget<Material>(
        find.descendant(of: find.byType(BilahAtasRuangKerja), matching: find.byType(Material)).first,
      );
      expect(bilah.color, warna.brandGelap, reason: 'Bilah atas memakai warna merek di lebar $nama.');

      // Logo tepat di tengah lebar layar, apa pun panjang nama outlet dan tombol di kanannya.
      final tengahLogo = tester.getCenter(find.byType(LogoMerek)).dx;
      expect(
        tengahLogo,
        moreOrLessEquals(ukuran.width / 2, epsilon: 1),
        reason: 'Logo di tengah bilah atas di lebar $nama.',
      );

      // Rel (≥ 600dp) atau bilah bawah (< 600dp) memakai warna merek yang sama, jadi bingkai terbaca satu kerangka.
      if (ukuran.width >= 600) {
        final latarSidebar = tester.widget<Material>(
          find.descendant(of: find.byType(SidebarRuangKerja), matching: find.byType(Material)).first,
        );
        expect(latarSidebar.color, warna.brandGelap, reason: 'Sidebar memakai warna merek di lebar $nama.');
        final pilAktif = tester.widget<Material>(find.byKey(const ValueKey('ItemSidebar.Jual')));
        expect(pilAktif.color, warna.aksen, reason: 'Item aktif berupa pil apricot (D-66).');
        final ikonAktif = tester.widget<Icon>(
          find.descendant(of: find.byKey(const ValueKey('ItemSidebar.Jual')), matching: find.byType(Icon)),
        );
        expect(ikonAktif.color, warna.brandGelap, reason: 'Ikon item aktif gelap di atas pil apricot.');
        final ikonPasif = tester.widget<Icon>(
          find.descendant(of: find.byKey(const ValueKey('ItemSidebar.Riwayat')), matching: find.byType(Icon)),
        );
        expect(ikonPasif.color, warna.permukaan.withValues(alpha: TemaNavigasiRuangKerja.opasitasPasif));
      } else {
        final temaBilah = NavigationBarTheme.of(tester.element(find.byType(NavigationBar)));
        expect(temaBilah.backgroundColor, warna.brandGelap, reason: 'Bilah bawah memakai warna merek.');
      }

      await Lepas(tester, u);
    }
  });

  for (final (nama, ukuran) in [('1280', ukuranDesktop), ('360', ukuranHp)]) {
    testWidgets('logo outlet tampil di kiri bilah atas, setinggi logo Payoung (D-16) di lebar $nama dp', (
      tester,
    ) async {
      // PNG 1×1 sungguhan: bait gambar asli dari data awal (bukan diubah 1 bit seperti logo struk).
      const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
      final u = await MasukRuangKerja(tester, ukuran: ukuran);
      // Data awal uji tidak membawa logo outlet (dan menyegarkan data awal mengosongkannya), jadi logo ditulis
      // sesudah masuk — sama seperti hasil unduhan `LayananPerangkat`.
      await tester.runAsync(() => u.repositori.SimpanPengaturan(KunciPengaturan.logoOutlet, png));
      // Logo dibaca dari basis data lokal lewat stream, jadi tunggu satu putaran.
      await Tunggu(tester);
      expect(await tester.runAsync(() => u.repositori.AmbilPengaturan(KunciPengaturan.logoOutlet)), png);
      final bilah = find.byType(BilahAtasRuangKerja);
      expect(bilah, findsOneWidget);
      expect(find.descendant(of: bilah, matching: find.byType(Image)), findsNWidgets(2));
      // Logo Payoung juga `Image`, jadi yang dicari khusus gambar outlet hasil unduhan (label semantiknya sendiri).
      final gambar = find.descendant(
        of: bilah,
        matching: find.byWidgetPredicate((w) => w is Image && w.semanticLabel == 'Logo outlet'),
      );
      expect(gambar, findsOneWidget);
      expect(find.descendant(of: bilah, matching: find.byIcon(Icons.storefront_outlined)), findsNothing);
      expect(tester.getCenter(gambar).dx, lessThan(ukuran.width / 2));
      expect(
        tester.getSize(gambar).height,
        moreOrLessEquals(tester.getSize(find.byType(LogoMerek)).height, epsilon: 0.5),
        reason: 'Logo outlet setinggi logo Payoung (30dp).',
      );

      await Lepas(tester, u);
    });
  }

  for (final (nama, ukuran) in [('1280', ukuranDesktop), ('360', ukuranHp)]) {
    testWidgets('golden bingkai ruang kerja di lebar $nama dp', (tester) async {
      await MuatFontMerek();
      final u = await MasukRuangKerja(tester, ukuran: ukuran);
      await expectLater(find.byType(RuangKerja), matchesGoldenFile('Golden/RuangKerja$nama.png'));
      await Lepas(tester, u);
    });
  }

  testWidgets('sidebar mulai tertutup (ikon saja) dan bisa dilebarkan lalu diciutkan lagi', (tester) async {
    final u = await MasukRuangKerja(tester, relAwalDiciutkan: true);
    var sidebar = tester.widget<SidebarRuangKerja>(find.byType(SidebarRuangKerja));
    expect(sidebar.diciutkan, isTrue, reason: 'Bawaan: menu samping tertutup.');
    expect(sidebar.susunan, SusunanSidebar.IkonSaja);
    expect(find.text('Riwayat'), findsNothing, reason: 'Tertutup: hanya ikon, nama menu lewat tooltip.');
    expect(find.byTooltip('Riwayat'), findsOneWidget);

    await tester.tap(find.byTooltip('Lebarkan menu'));
    await Tunggu(tester);
    sidebar = tester.widget<SidebarRuangKerja>(find.byType(SidebarRuangKerja));
    expect(sidebar.diciutkan, isFalse);
    expect(sidebar.susunan, SusunanSidebar.Penuh, reason: 'Lebar 1280dp: ikon dan label sebaris.');
    expect(find.text('Riwayat'), findsOneWidget);

    await tester.tap(find.byTooltip('Ciutkan menu'));
    await Tunggu(tester);
    sidebar = tester.widget<SidebarRuangKerja>(find.byType(SidebarRuangKerja));
    expect(sidebar.diciutkan, isTrue);
    expect(find.text('Riwayat'), findsNothing);
    await Lepas(tester, u);
  });

  for (final (nama, ukuran) in [('1280', ukuranDesktop), ('800', ukuranTablet)]) {
    testWidgets(
      'label item aktif sidebar terbaca di lebar $nama dp (label di dalam pil apricot, bukan di latar gelap)',
      (tester) async {
        final warna = TokenWarna.bawaan;
        final u = await MasukRuangKerja(tester, ukuran: ukuran);

        // Regresi aduan penguji: saat menu terbuka, label item aktif memakai warna merek gelap; kalau pil apricot hanya
        // menutup ikon, tulisannya jatuh di atas latar rel yang juga gelap dan hilang.
        await tester.tap(find.text('Kas'));
        await Tunggu(tester);
        final pil = find.byKey(const ValueKey('ItemSidebar.Kas'));
        expect(tester.widget<Material>(pil).color, warna.aksen);
        final label = find.descendant(of: pil, matching: find.text('Kas'));
        expect(label, findsOneWidget, reason: 'Label item aktif ada di dalam pil apricot.');
        expect(tester.widget<Text>(label).style?.color, warna.brandGelap);
        expect(tester.getRect(label).overlaps(tester.getRect(pil)), isTrue);
        expect(tester.getRect(pil).contains(tester.getRect(label).center), isTrue);

        // Item tidak aktif tetap putih redup di atas latar merek.
        final labelPasif = tester.widget<Text>(find.text('Riwayat'));
        expect(labelPasif.style?.color, warna.permukaan.withValues(alpha: TemaNavigasiRuangKerja.opasitasPasif));
        await Lepas(tester, u);
      },
    );
  }

  testWidgets('kunci otomatis setelah diam 15 menit (bawaan); sentuhan mengulang hitungan; buka dengan PIN sama', (
    tester,
  ) async {
    final u = await MasukRuangKerja(tester);

    await tester.pump(const Duration(minutes: 14));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsNothing);

    // Sentuhan = aktivitas: hitungan diam mulai dari nol.
    await tester.tap(find.text('Katalog belum ada di perangkat ini.'));
    await tester.pump(const Duration(minutes: 14));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsNothing);

    await tester.pump(const Duration(minutes: 1, seconds: 1));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsOneWidget);
    expect(find.text('Terkunci | Rina Wulandari'), findsOneWidget);
    expect(find.text('Kopi Senja Solo Baru'), findsOneWidget);
    expect(
      find.text('Katalog belum ada di perangkat ini.'),
      findsNothing,
      reason: 'Area kerja tersembunyi saat terkunci.',
    );

    await KetikPin(tester, '111111');
    expect(find.textContaining('PIN salah'), findsOneWidget);
    expect(find.byType(LayarKunci), findsOneWidget);

    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    expect(find.byType(LayarKunci), findsNothing);
    expect(find.text('Katalog belum ada di perangkat ini.'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('kunci otomatis memakai waktu dari pengaturan perangkat', (tester) async {
    final u = await MasukRuangKerja(tester, pengaturan: {KunciPengaturan.menitKunciOtomatis: '1'});
    await tester.pump(const Duration(seconds: 50));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsNothing);
    await tester.pump(const Duration(seconds: 15));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('tidak mengunci selama keranjang berisi (transaksi/QRIS berjalan); kunci setelah keranjang kosong', (
    tester,
  ) async {
    final u = await MasukRuangKerja(tester, pengaturan: {KunciPengaturan.menitKunciOtomatis: '1'});
    final wadah = ProviderScope.containerOf(tester.element(find.byType(RuangKerja)));
    wadah
        .read(penyediaKeranjang.notifier)
        .Ganti(
          Keranjang(
            baris: [
              ItemKeranjang(
                uuid: '01K5BARIS00000000000000001',
                uuidProduk: '01K5PRODUK0000000000000001',
                nama: 'Americano',
                uuidProdukSatuan: null,
                namaSatuan: null,
                bolehDesimal: false,
                jumlah: Kuantitas.DariBulat(1),
                hargaSatuan: Uang.DariBulat(18000),
              ),
            ],
          ),
        );
    await tester.pump(const Duration(minutes: 3));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsNothing, reason: 'Transaksi berjalan tidak boleh terputus kunci otomatis.');

    wadah.read(penyediaKeranjang.notifier).Ganti(Keranjang.kosong);
    await tester.pump(const Duration(minutes: 1, seconds: 5));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('kunci cepat menjaga panel & isi area kerja tetap utuh setelah dibuka', (tester) async {
    final u = await MasukRuangKerja(tester);
    await tester.tap(find.text('Kas'));
    await Tunggu(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Kas masuk'));
    await Tunggu(tester);
    await tester.enterText(find.widgetWithText(TextField, 'Jumlah'), '75000');

    await tester.tap(find.text('Kunci'));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsOneWidget);
    expect(find.byType(PanelTugas), findsNothing);

    await KetikPin(tester, KasusPin(0)['Pin']! as String);
    expect(find.byType(PanelTugas), findsOneWidget);
    expect(find.widgetWithText(TextField, '75.000'), findsOneWidget);
    await Lepas(tester, u);
  });

  testWidgets('ganti kasir dari layar kunci tanpa menutup shift', (tester) async {
    final u = await MasukRuangKerja(tester);
    final shiftAwal = (await tester.runAsync(u.repositori.AmbilShiftAktif))!;

    await tester.tap(find.text('Kunci'));
    await Tunggu(tester);
    await tester.tap(find.text('Ganti kasir'));
    await Tunggu(tester);
    expect(find.text('Siapa yang bertugas?'), findsOneWidget);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Budi Santoso'));
    await tester.pump();
    expect(find.text('PIN Budi Santoso'), findsOneWidget);
    await KetikPin(tester, KasusPin(1)['Pin']! as String);

    expect(find.byType(LayarKunci), findsNothing);
    expect(find.text('Budi Santoso'), findsOneWidget, reason: 'Kasir aktif di bilah atas berganti.');
    expect(find.text('Rina Wulandari'), findsNothing);

    final shift = await tester.runAsync(u.repositori.AmbilShiftAktif);
    expect(shift, isNotNull, reason: 'Shift tetap terbuka.');
    expect(shift!.Uuid, shiftAwal.Uuid);
    expect(shift.DibukaOleh, shiftAwal.DibukaOleh);
    await Lepas(tester, u);
  });

  // Di lebar lega, karena test ini memastikan nama kasir yang tampil di bilah atas ikut berganti; di HP bilahnya
  // hanya menampilkan ikon (ganti kasir dari HP diuji lewat 'ganti kasir dari layar kunci').
  testWidgets('ketuk nama kasir → ganti kasir; bisa dibatalkan tanpa PIN', (tester) async {
    final u = await MasukRuangKerja(tester);
    await tester.tap(find.byTooltip('Ganti kasir'));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsOneWidget);
    expect(find.text('Siapa yang bertugas?'), findsOneWidget);

    await tester.tap(find.text('Batal'));
    await Tunggu(tester);
    expect(find.byType(LayarKunci), findsNothing);
    expect(find.text('Rina Wulandari'), findsOneWidget);

    await tester.tap(find.byTooltip('Ganti kasir'));
    await Tunggu(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Budi Santoso'));
    await tester.pump();
    await KetikPin(tester, KasusPin(1)['Pin']! as String);
    expect(find.text('Budi Santoso'), findsOneWidget);
    expect(await tester.runAsync(u.repositori.AmbilShiftAktif), isNotNull);
    await Lepas(tester, u);
  });

  testWidgets('pengaturan perangkat tersimpan lokal dan ukuran Besar memperbesar teks 1,15×', (tester) async {
    final u = await MasukRuangKerja(tester, ukuran: ukuranTablet);
    await tester.tap(find.text('Pengaturan'));
    await Tunggu(tester);
    expect(find.text('Perbarui data kasir'), findsOneWidget);

    double Skala() => MediaQuery.textScalerOf(tester.element(find.text('Ukuran tampilan'))).scale(100) / 100;
    expect(Skala(), closeTo(1, 0.001));

    await tester.tap(find.text('Besar'));
    await Tunggu(tester);
    expect(Skala(), closeTo(1.15, 0.001));
    expect(await BacaPengaturan(tester, u, KunciPengaturan.ukuranTampilan), 'Besar');

    await tester.tap(find.text('Kiri'));
    await Tunggu(tester);
    expect(await BacaPengaturan(tester, u, KunciPengaturan.posisiKeranjang), 'Kiri');

    await tester.tap(find.text('15 menit'));
    await Tunggu(tester);
    await tester.tap(find.text('10 menit').last);
    await Tunggu(tester);
    expect(await BacaPengaturan(tester, u, KunciPengaturan.menitKunciOtomatis), '10');

    final dimuat = await tester.runAsync(() => PengaturanPerangkat.Muat(u.repositori));
    expect(dimuat!.ukuran, UkuranTampilan.Besar);
    expect(dimuat.posisiKeranjang, PosisiKeranjang.Kiri);
    expect(dimuat.menitKunciOtomatis, 10);
    await Lepas(tester, u);
  });

  testWidgets('tombol kembali menutup panel lalu kembali ke beranda Jual, tidak keluar dari ruang kerja', (
    tester,
  ) async {
    final u = await MasukRuangKerja(tester);
    await tester.tap(find.text('Kas'));
    await Tunggu(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Setoran'));
    await Tunggu(tester);
    expect(find.byType(PanelTugas), findsOneWidget);

    await tester.binding.handlePopRoute();
    await Tunggu(tester);
    expect(find.byType(PanelTugas), findsNothing);
    expect(find.text('Kas awal'), findsOneWidget);

    await tester.binding.handlePopRoute();
    await Tunggu(tester);
    expect(find.text('Katalog belum ada di perangkat ini.'), findsOneWidget);
    expect(find.byType(RuangKerja), findsOneWidget);
    await Lepas(tester, u);
  });

  test('pengaturan perangkat: nilai bawaan dan nilai tidak dikenal kembali ke bawaan', () async {
    final u = LingkunganUji.Buat();
    final bawaan = await PengaturanPerangkat.Muat(u.repositori);
    expect(bawaan.ukuran, UkuranTampilan.Normal);
    expect(bawaan.posisiKeranjang, PosisiKeranjang.Kanan);
    expect(bawaan.menitKunciOtomatis, 15);
    expect(UkuranTampilan.Besar.skalaTeks, 1.15);

    await u.repositori.SimpanPengaturan(KunciPengaturan.ukuranTampilan, 'Raksasa');
    await u.repositori.SimpanPengaturan(KunciPengaturan.menitKunciOtomatis, '0');
    final rusak = await PengaturanPerangkat.Muat(u.repositori);
    expect(rusak.ukuran, UkuranTampilan.Normal);
    expect(rusak.menitKunciOtomatis, 15);
    await u.Tutup();
  });
}
