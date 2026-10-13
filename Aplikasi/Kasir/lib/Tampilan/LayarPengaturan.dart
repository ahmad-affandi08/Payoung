import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Domain/Perangkat/PemindaiKamera.dart';
import '../Domain/Perangkat/PengaturanPerangkat.dart';
import '../Domain/Sesi/StafLokal.dart';
import 'BagianBantuan.dart';
import 'BagianLogPerangkat.dart';
import 'Komponen/AbsensiSaya.dart';
import 'LembarMutasiKas.dart';
import 'RuangKerja/IsiAreaKerja.dart';
import 'Struk/BagianLayarPelanggan.dart';
import 'Struk/BagianPrinter.dart';
import 'Struk/BagianUjiPerangkat.dart';

/// Pengaturan perangkat kasir (PRD §17.2.7): printer struk (v1.79), printer dapur per stasiun (v1.87), ukuran tampilan, posisi keranjang, kunci otomatis,
/// perbarui data kasir dari back-office, dan log & laporan galat (K-21). Tersimpan lokal di perangkat ini dan langsung berlaku.
class LayarPengaturan extends ConsumerStatefulWidget {
  const LayarPengaturan({super.key});

  @override
  ConsumerState<LayarPengaturan> createState() => _LayarPengaturanState();
}

class _LayarPengaturanState extends ConsumerState<LayarPengaturan> {
  bool _memperbarui = false;
  String? _pesanData;

  Future<void> _Simpan(PengaturanPerangkat baru) => ref.read(penyediaPengaturanPerangkat.notifier).Simpan(baru);

  /// Audit kemudahan pakai #32: menyalakan mode latihan butuh PIN supervisor (izin `shift.selisih.setujui`) supaya
  /// penjualan sungguhan tidak hilang karena kasir lupa; kasir yang sudah ber-izin (atau Pemilik) tidak ditanya lagi.
  /// Mematikan selalu bebas.
  Future<void> _AturModeLatihan(BuildContext context, WidgetRef ref, bool nilai) async {
    if (nilai && !(ref.read(penyediaSesi).kasir?.PunyaIzin(IzinKasir.shiftSelisihSetujui) ?? false)) {
      final penyetuju = await showDialog<StafLokal>(
        context: context,
        builder: (_) => const DialogPinSupervisor(
          izin: IzinKasir.shiftSelisihSetujui,
          judul: 'Nyalakan mode latihan',
          pesan: 'Selama mode latihan, penjualan tidak disimpan. Pilih supervisor yang menyetujui.',
        ),
      );
      if (penyetuju == null || !mounted) {
        return;
      }
    }
    ref.read(penyediaModeLatihan.notifier).Atur(nilai);
  }

  Future<void> _PerbaruiData() async {
    setState(() {
      _memperbarui = true;
      _pesanData = null;
    });
    await ref.read(penyediaSesi.notifier).SegarkanData();
    if (!mounted) {
      return;
    }
    final koneksi = ref.read(penyediaKoneksi);
    setState(() {
      _memperbarui = false;
      _pesanData = koneksi == StatusKoneksi.Offline
          ? 'Belum tersambung ke server. Data kasir terakhir tetap dipakai.'
          : 'Data kasir sudah diperbarui.';
    });
  }

  @override
  Widget build(BuildContext context) {
    final pengaturan = ref.watch(penyediaPengaturanPerangkat);
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final keterangan = teks.bodySmall;

    // Padat (v4.11): tiap bagian satu panel bergaris dengan jarak 8dp, bukan blok lepas berjarak 24dp.
    Widget Bagian(String judul, String penjelasan, Widget kontrol) => Padding(
      padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
      child: Material(
        color: warna.permukaan,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
          borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
        ),
        child: Padding(
          padding: const EdgeInsets.all(TokenJarak.jarak12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(judul, style: teks.titleSmall),
              const SizedBox(height: 2),
              Text(penjelasan, style: keterangan),
              const SizedBox(height: TokenJarak.jarak8),
              kontrol,
            ],
          ),
        ),
      ),
    );

    // F-18: bagian Absensi hanya untuk toko yang memakai data karyawan (sama dengan tawaran absen masuk setelah PIN).
    final pakaiAbsensi = ref.watch(penyediaKaryawanPos).value?.isNotEmpty ?? false;

    return IsiAreaKerja(
      judul: 'Pengaturan',
      // Belasan bagian berdiri sendiri: di layar lebar dibagi dua kolom, bukan satu gulungan panjang.
      kolomGanda: true,
      anak: [
        if (pakaiAbsensi)
          Bagian(
            'Absensi',
            'Catat jam masuk dan pulang Anda dari sini. PIN tidak diminta lagi; swafoto diminta bila perangkat berkamera. '
                'Tetap tercatat saat offline.',
            const PanelAbsensiSaya(),
          ),
        Bagian(
          'Printer',
          'Semua printer perangkat ini ada di satu daftar. Centang kegunaan tiap printer: struk kasir, tiket '
              'dapur, atau tiket bar. Sambungan: LAN/Wi-Fi, Bluetooth, USB, atau printer bawaan mesin kasir (Sunmi, iMin). '
              'Isi kepala & kaki struk diatur di back-office.',
          const BagianPrinter(),
        ),
        Bagian(
          'Ukuran tampilan',
          'Besar memperbesar semua teks 15% agar mudah dibaca dari jarak jauh.',
          SegmentedButton<UkuranTampilan>(
            showSelectedIcon: false,
            segments: [for (final u in UkuranTampilan.values) ButtonSegment(value: u, label: Text(u.label))],
            selected: {pengaturan.ukuran},
            onSelectionChanged: (pilihan) => _Simpan(pengaturan.copyWith(ukuran: pilihan.single)),
          ),
        ),
        Bagian(
          'Posisi keranjang',
          'Sisi layar untuk keranjang di layar jual, misalnya kiri untuk kasir kidal.',
          SegmentedButton<PosisiKeranjang>(
            showSelectedIcon: false,
            segments: [for (final p in PosisiKeranjang.values) ButtonSegment(value: p, label: Text(p.label))],
            selected: {pengaturan.posisiKeranjang},
            onSelectionChanged: (pilihan) => _Simpan(pengaturan.copyWith(posisiKeranjang: pilihan.single)),
          ),
        ),
        Bagian(
          'Tampilan katalog',
          'Otomatis mengikuti mode kasir outlet: daftar ringkas untuk toko retail & grosir, ubin bergambar untuk lainnya.',
          SegmentedButton<TampilanKatalog>(
            key: const ValueKey('TampilanKatalog'),
            showSelectedIcon: false,
            segments: [for (final t in TampilanKatalog.values) ButtonSegment(value: t, label: Text(t.label))],
            selected: {pengaturan.tampilanKatalog},
            onSelectionChanged: (pilihan) => _Simpan(pengaturan.copyWith(tampilanKatalog: pilihan.single)),
          ),
        ),
        Bagian(
          'Bunyi & getar saat memindai',
          'Bunyi pendek dan getar menandai pindaian berhasil atau gagal. Matikan bila mengganggu.',
          Row(
            children: [
              Switch(
                key: const ValueKey('UmpanBalikPindai'),
                value: pengaturan.umpanBalikPindai,
                onChanged: (nilai) => _Simpan(pengaturan.copyWith(umpanBalikPindai: nilai)),
              ),
              const SizedBox(width: TokenJarak.jarak8),
              Text(pengaturan.umpanBalikPindai ? 'Hidup' : 'Mati'),
            ],
          ),
        ),
        if (ref.watch(penyediaPemindaiKamera).CekTersedia())
          Bagian(
            'Kamera pemindai barcode',
            'Kamera yang dibuka tombol kamera di kolom cari layar Jual. Bisa diganti kapan saja dari strip kamera.',
            SegmentedButton<ArahKamera>(
              key: const ValueKey('KameraPindai'),
              showSelectedIcon: false,
              segments: [for (final a in ArahKamera.values) ButtonSegment(value: a, label: Text(a.label))],
              selected: {pengaturan.kameraPindai},
              onSelectionChanged: (pilihan) => _Simpan(pengaturan.copyWith(kameraPindai: pilihan.single)),
            ),
          ),
        Bagian(
          'Kunci otomatis',
          'Layar terkunci bila perangkat tidak disentuh selama waktu ini. Shift tetap terbuka.',
          SizedBox(
            width: 240,
            child: DropdownButtonFormField<int>(
              key: ValueKey(pengaturan.menitKunciOtomatis),
              initialValue: pengaturan.menitKunciOtomatis,
              decoration: const InputDecoration(labelText: 'Kunci setelah diam'),
              items: [
                for (final menit in {...PengaturanPerangkat.pilihanMenitKunci, pengaturan.menitKunciOtomatis})
                  DropdownMenuItem(value: menit, child: Text('$menit menit')),
              ],
              onChanged: (menit) => menit == null ? null : _Simpan(pengaturan.copyWith(menitKunciOtomatis: menit)),
            ),
          ),
        ),
        Bagian(
          'Data kasir',
          'Ambil daftar kasir, PIN offline, dan kategori kas terbaru dari back-office.',
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                height: TokenJarak.targetSentuh,
                child: OutlinedButton(
                  onPressed: _memperbarui ? null : _PerbaruiData,
                  child: Text(_memperbarui ? 'Memperbarui…' : 'Perbarui data kasir'),
                ),
              ),
              if (_pesanData != null)
                Padding(
                  padding: const EdgeInsets.only(top: TokenJarak.jarak8),
                  child: Text(_pesanData!, style: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
                ),
            ],
          ),
        ),
        Bagian(
          'Layar pelanggan',
          'Tampilkan item, total, dan kembalian ke pelanggan lewat layar kedua mesin kasir, monitor HDMI, atau layar VFD.',
          const BagianLayarPelanggan(),
        ),
        Bagian(
          'Uji perangkat',
          'Periksa printer, pemotong kertas, laci, dan pemindai. Hasilnya membantu tim dukungan bila ada kendala.',
          const BagianUjiPerangkat(),
        ),
        Bagian(
          'Bantuan',
          'Hubungi tim Payoung langsung dari sini. Outlet, versi aplikasi, dan transaksi yang belum terkirim ikut tercatat otomatis. '
              'Perlu internet.',
          const BagianBantuan(),
        ),
        Bagian(
          'Log & laporan galat',
          'Galat aplikasi dicatat di perangkat tanpa data pribadi dan dikirim ke tim dukungan saat online.',
          const BagianLogPerangkat(),
        ),
        Bagian(
          'Mode latihan',
          'Untuk melatih kasir baru: transaksi dihitung seperti biasa tetapi tidak disimpan, tidak dikirim ke server, '
              'tidak mengubah stok & kas, dan tidak dicetak. Menyalakannya butuh PIN supervisor. Mati lagi saat aplikasi '
              'dibuka ulang.',
          Row(
            children: [
              Switch(
                key: const ValueKey('ModeLatihan'),
                value: ref.watch(penyediaModeLatihan),
                onChanged: (nilai) => unawaited(_AturModeLatihan(context, ref, nilai)),
              ),
              const SizedBox(width: TokenJarak.jarak8),
              Text(ref.watch(penyediaModeLatihan) ? 'Hidup' : 'Mati'),
            ],
          ),
        ),
      ],
    );
  }
}
