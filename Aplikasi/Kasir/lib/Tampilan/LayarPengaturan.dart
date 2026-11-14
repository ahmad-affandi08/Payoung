import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Aplikasi/VersiAplikasi.dart';
import '../Data/RepositoriKasir.dart';
import '../Domain/Perangkat/PemindaiKamera.dart';
import '../Domain/Perangkat/PengaturanPerangkat.dart';
import '../Domain/Sesi/StafLokal.dart';
import 'BagianBantuan.dart';
import 'BagianLogPerangkat.dart';
import 'Komponen/FormatWaktu.dart';
import 'LembarMutasiKas.dart';
import 'Pengaturan/BagianPerangkat.dart';
import 'Pengaturan/DaftarKategoriPengaturan.dart';
import 'Pengaturan/DialogUbahPin.dart';
import 'Pengaturan/KategoriPengaturan.dart';
import 'Pengaturan/KesiapanPerangkat.dart';
import 'RuangKerja/BagianKerja.dart';
import 'RuangKerja/IsiAreaKerja.dart';
import 'Struk/BagianLayarPelanggan.dart';
import 'Struk/BagianPrinter.dart';
import 'Struk/BagianUjiPerangkat.dart';

/// Pengaturan perangkat kasir (PRD §17.2.7, D-301): enam kategori (Perangkat, Printer & struk, Tampilan & jual,
/// Keamanan, Data & sinkron, Bantuan & log). Di layar lebar kategori ada di panel kiri dan isinya di kanan, dengan
/// panel kesiapan perangkat di paling kanan (D-40); di HP daftar kategori tampil penuh dan satu kategori dibuka dengan
/// diketuk (tombol kembali di kepala). Semua pengaturan tersimpan lokal di perangkat ini dan langsung berlaku.
class LayarPengaturan extends ConsumerStatefulWidget {
  const LayarPengaturan({super.key});

  /// Area kerja minimum untuk panel kategori di samping isi; di bawah ini kategori jadi daftar penuh (HP).
  static const double lebarMinimumDuaKolom = 700;

  @override
  ConsumerState<LayarPengaturan> createState() => _LayarPengaturanState();
}

class _LayarPengaturanState extends ConsumerState<LayarPengaturan> {
  /// null = belum memilih (HP menampilkan daftar kategori; layar lebar membuka Perangkat).
  KategoriPengaturan? _kategori;

  Future<void> _Simpan(PengaturanPerangkat baru) => ref.read(penyediaPengaturanPerangkat.notifier).Simpan(baru);

  /// Audit kemudahan pakai #32: menyalakan mode latihan butuh PIN supervisor (izin `shift.selisih.setujui`) supaya
  /// penjualan sungguhan tidak hilang karena kasir lupa; kasir yang sudah ber-izin (atau Pemilik) tidak ditanya lagi.
  /// Mematikan selalu bebas.
  Future<void> _UbahPin(BuildContext context, StafLokal kasir) async {
    final berhasil = await showDialog<bool>(
      context: context,
      builder: (_) => DialogUbahPin(kasir: kasir),
    );
    if (berhasil == true && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('PIN sudah diganti.')));
    }
  }

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

  @override
  Widget build(BuildContext context) {
    final koneksi = ref.watch(penyediaKoneksi);
    final printer = ref.watch(penyediaPrinter);
    final tertunda = ref.watch(penyediaJumlahTertunda).value ?? 0;
    final pengaturanSinkron = ref.watch(penyediaPengaturanSinkron).value ?? const <String, String>{};
    final kesiapan = SusunKesiapanPerangkat(
      koneksi: koneksi,
      tertunda: tertunda,
      printer: printer,
      selisihJamDetik: int.tryParse(pengaturanSinkron[KunciPengaturan.selisihJamDetik] ?? ''),
      dataDiperbarui: DateTime.tryParse(pengaturanSinkron[KunciPengaturan.dataAwalPada] ?? '')?.toLocal(),
      sekarang: ref.watch(penyediaJam)(),
      responsServerMs: ref.watch(penyediaWaktuResponsServer),
    );
    final perluCek = kesiapan.any((b) => b.nada == NadaStatus.Peringatan);
    final identitas = ref.watch(penyediaIdentitas).value;

    final lencana = <KategoriPengaturan, LencanaKategori>{
      KategoriPengaturan.perangkat: perluCek
          ? (teks: 'Cek', nada: NadaStatus.Peringatan)
          : (teks: 'Siap', nada: NadaStatus.Sukses),
      KategoriPengaturan.printer: switch (printer.keadaan) {
        KeadaanPrinter.BelumDiatur => (teks: 'Atur', nada: NadaStatus.Peringatan),
        KeadaanPrinter.Gagal => (teks: 'Cek', nada: NadaStatus.Peringatan),
        _ => (teks: 'Siap', nada: NadaStatus.Sukses),
      },
      KategoriPengaturan.data: tertunda == 0
          ? (teks: 'Terkirim', nada: NadaStatus.Sukses)
          : (teks: '$tertunda tertunda', nada: NadaStatus.Peringatan),
      if (ref.watch(penyediaModeLatihan)) KategoriPengaturan.keamanan: (teks: 'Latihan', nada: NadaStatus.Info),
    };
    final ringkasan = <KategoriPengaturan, String>{
      if ((identitas?.perangkat ?? '').isNotEmpty)
        KategoriPengaturan.perangkat: '${identitas!.perangkat} | v${VersiAplikasi.versi}',
    };

    return LayoutBuilder(
      builder: (context, batas) {
        final duaKolom = batas.maxWidth >= LayarPengaturan.lebarMinimumDuaKolom;
        final aktif = _kategori ?? (duaKolom ? KategoriPengaturan.perangkat : null);
        final daftar = DaftarKategoriPengaturan(
          aktif: aktif,
          saatPilih: (k) => setState(() => _kategori = k),
          lencana: lencana,
          ringkasan: ringkasan,
          penuh: !duaKolom,
        );

        // HP: tanpa pilihan = daftar kategori penuh; dengan pilihan = isi satu kategori + tombol kembali.
        if (!duaKolom) {
          if (aktif == null) {
            return IsiAreaKerja(judul: 'Pengaturan', anak: [daftar]);
          }
          return IsiAreaKerja(
            judul: aktif.label,
            aksi: [
              TextButton.icon(
                key: const ValueKey('KembaliKeKategori'),
                onPressed: () => setState(() => _kategori = null),
                icon: const Icon(Icons.chevron_left),
                label: const Text('Semua pengaturan'),
              ),
            ],
            anak: [_IsiKategori(aktif)],
            panelSamping: const [],
          );
        }

        return IsiAreaKerja(
          judul: 'Pengaturan',
          lebarMaksimum: 1600,
          anak: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(width: 280, child: daftar),
                const SizedBox(width: TokenJarak.jarak12),
                Expanded(child: _IsiKategori(aktif!)),
              ],
            ),
          ],
          panelSamping: [
            PanelKesiapanPerangkat(
              butir: kesiapan,
              saatBantuan: () => setState(() => _kategori = KategoriPengaturan.bantuan),
            ),
          ],
        );
      },
    );
  }

  /// Isi satu kategori: kumpulan bagian bergaris dengan jarak 8dp.
  Widget _IsiKategori(KategoriPengaturan kategori) {
    final pengaturan = ref.watch(penyediaPengaturanPerangkat);
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final keterangan = teks.bodySmall?.copyWith(color: warna.teksSekunder);

    Widget Bagian(String judul, IconData ikon, String penjelasan, Widget kontrol) => Padding(
      padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
      child: BagianKerja(
        judul: judul,
        ikon: ikon,
        anak: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(penjelasan, style: keterangan),
            const SizedBox(height: TokenJarak.jarak8),
            kontrol,
          ],
        ),
      ),
    );

    Widget Sakelar(Key kunci, bool nilai, ValueChanged<bool> saatUbah) => Row(
      children: [
        Switch(key: kunci, value: nilai, onChanged: saatUbah),
        const SizedBox(width: TokenJarak.jarak8),
        Text(nilai ? 'Hidup' : 'Mati'),
      ],
    );

    return switch (kategori) {
      KategoriPengaturan.perangkat => const BagianPerangkat(),
      KategoriPengaturan.printer => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Bagian(
            'Printer perangkat ini',
            Icons.print_outlined,
            'Semua printer perangkat ini ada di satu daftar. Centang kegunaan tiap printer: struk kasir, tiket dapur, '
                'atau tiket bar. Sambungan: LAN/Wi-Fi, Bluetooth, USB, atau printer bawaan mesin kasir (Sunmi, iMin). '
                'Isi kepala & kaki struk diatur di back-office.',
            const BagianPrinter(),
          ),
          Bagian(
            'Uji perangkat',
            Icons.fact_check_outlined,
            'Periksa printer, pemotong kertas, laci, dan pemindai. Hasilnya membantu tim dukungan bila ada kendala.',
            const BagianUjiPerangkat(),
          ),
        ],
      ),
      KategoriPengaturan.tampilan => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Bagian(
            'Ukuran tampilan',
            Icons.format_size,
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
            Icons.view_sidebar_outlined,
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
            Icons.grid_view_outlined,
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
            Icons.vibration,
            'Bunyi pendek dan getar menandai pindaian berhasil atau gagal. Matikan bila mengganggu.',
            Sakelar(
              const ValueKey('UmpanBalikPindai'),
              pengaturan.umpanBalikPindai,
              (nilai) => _Simpan(pengaturan.copyWith(umpanBalikPindai: nilai)),
            ),
          ),
          if (ref.watch(penyediaPemindaiKamera).CekTersedia())
            Bagian(
              'Kamera pemindai barcode',
              Icons.qr_code_scanner,
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
            'Layar pelanggan',
            Icons.monitor_outlined,
            'Tampilkan item, total, dan kembalian ke pelanggan lewat layar kedua mesin kasir, monitor HDMI, atau layar VFD.',
            const BagianLayarPelanggan(),
          ),
        ],
      ),
      KategoriPengaturan.keamanan => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Bagian(
            'Kunci otomatis',
            Icons.lock_clock,
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
          if (ref.watch(penyediaSesi).kasir case final kasir?)
            Bagian(
              'PIN saya',
              Icons.pin_outlined,
              'Ganti PIN ${kasir.nama} tanpa ke back-office. Perlu tersambung ke internet dan PIN lama.',
              OutlinedButton(
                key: const ValueKey('UbahPin'),
                onPressed: () => unawaited(_UbahPin(context, kasir)),
                child: const Text('Ubah PIN'),
              ),
            ),
          Bagian(
            'Mode latihan',
            Icons.school_outlined,
            'Untuk melatih kasir baru: transaksi dihitung seperti biasa tetapi tidak disimpan, tidak dikirim ke server, '
                'tidak mengubah stok & kas, dan tidak dicetak. Menyalakannya butuh PIN supervisor. Mati lagi saat aplikasi '
                'dibuka ulang.',
            Sakelar(
              const ValueKey('ModeLatihan'),
              ref.watch(penyediaModeLatihan),
              (nilai) => unawaited(_AturModeLatihan(context, ref, nilai)),
            ),
          ),
        ],
      ),
      KategoriPengaturan.data => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Bagian(
            'Status sinkron',
            Icons.sync,
            'Data yang belum terkirim, daftar yang perlu tindakan, dan kirim sekarang ada di menu Sinkron.',
            const _RingkasanSinkron(),
          ),
        ],
      ),
      KategoriPengaturan.bantuan => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Bagian(
            'Bantuan',
            Icons.support_agent,
            'Hubungi tim Payoung langsung dari sini. Outlet, versi aplikasi, dan transaksi yang belum terkirim ikut tercatat otomatis. '
                'Perlu internet.',
            const BagianBantuan(),
          ),
          Bagian(
            'Log & laporan galat',
            Icons.description_outlined,
            'Galat aplikasi dicatat di perangkat tanpa data pribadi dan dikirim ke tim dukungan saat online.',
            const BagianLogPerangkat(),
          ),
        ],
      ),
    };
  }
}

/// Ringkasan sinkron di kategori Data & sinkron: sinkron terakhir dan jumlah data tertunda, dengan jalan ke menu Sinkron
/// lewat rel navigasi (tidak ada tombol ganda yang berpindah halaman).
class _RingkasanSinkron extends ConsumerWidget {
  const _RingkasanSinkron();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final tertunda = ref.watch(penyediaJumlahTertunda).value ?? 0;
    final pengaturan = ref.watch(penyediaPengaturanSinkron).value ?? const <String, String>{};
    final terakhir = DateTime.tryParse(pengaturan[KunciPengaturan.sinkronTerakhir] ?? '')?.toLocal();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          terakhir == null ? 'Belum pernah sinkron' : 'Sinkron terakhir ${FormatWaktu.FormatTanggalJam(terakhir)}',
          key: const ValueKey('SinkronTerakhirPengaturan'),
          style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w700),
        ),
        Text(
          tertunda == 0 ? 'Semua data sudah terkirim.' : '$tertunda data belum terkirim.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
      ],
    );
  }
}
