import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/RepositoriKasir.dart';
import '../Domain/GalatKasir.dart';
import '../Domain/Sesi/StafLokal.dart';
import '../Domain/Sinkron/LayananSinkron.dart';
import 'Komponen/FormatWaktu.dart';
import 'Komponen/PilihanAlasan.dart';
import 'LembarMutasiKas.dart';
import 'RuangKerja/BagianKerja.dart';
import 'RuangKerja/IsiAreaKerja.dart';
import 'RuangKerja/KartuPembaruanAplikasi.dart';

/// Status sinkron (PRD §18): jumlah data belum terkirim dan daftar "Perlu Tindakan" (ditolak server) beserta
/// alasannya. K-17 (§18.3 butir 7 & 10): waktu sinkron terakhir, peringatan data tertunda > 2 jam, peringatan jam
/// perangkat berbeda > 10 menit dari server, dan penjualan yang diterima dengan tanda tinjauan back-office. Item bisa dikirim ulang setelah penyebabnya diperbaiki di back-office. Tampil di area ruang kerja
/// (dibuka dari rel navigasi atau dengan mengetuk bilah status). D-40: angka utama data belum terkirim, antrean kirim
/// per jenis, dan panel konteks berisi transaksi yang diperiksa back-office serta cara kerja sinkron.
class LayarStatusSinkron extends ConsumerStatefulWidget {
  const LayarStatusSinkron({super.key});

  /// §18.3 butir 10: data tertunda lebih lama dari ini diberi peringatan.
  static const Duration batasTertunda = Duration(hours: 2);

  /// Data produk, harga, dan pengaturan dari server yang lebih tua dari ini dianggap basi (perangkat lama offline).
  static const Duration batasDataLama = Duration(days: 1);

  /// §18.3 butir 7: selisih jam perangkat − server lebih dari ini diberi peringatan.
  static const int batasSelisihJamDetik = 600;

  /// Label jenis item outbox untuk kasir (audit kemudahan pakai #30: semua jenis berlabel bahasa sehari-hari; jenis
  /// yang belum dikenal tampil sebagai "Data lain" supaya kode teknis tidak muncul di layar).
  static String AmbilLabelJenis(String jenis) => switch (jenis) {
    'Shift.Buka' => 'Buka shift',
    'Shift.Tutup' => 'Tutup shift',
    'Shift.BukaUlang' => 'Buka ulang shift',
    'Penjualan.Buat' => 'Penjualan',
    'Penjualan.Void' => 'Pembatalan transaksi',
    'ReturPenjualan.Buat' => 'Retur penjualan',
    'ReturPenjualan.TanpaStruk' => 'Retur tanpa struk',
    'MutasiKas.Catat' => 'Kas masuk/keluar',
    'Laci.Buka' => 'Buka laci',
    'Absensi.Masuk' => 'Absen masuk',
    'Absensi.Keluar' => 'Absen pulang',
    'Pelanggan.Buat' => 'Pelanggan baru',
    'Deposit.Isi' => 'Isi saldo pelanggan',
    'Sesi.Pakai' => 'Pakai paket sesi',
    'PesananPenjualan.Buat' => 'Pesanan dengan uang muka',
    'PesananTerbuka.Buka' => 'Buka pesanan meja',
    'PesananTerbuka.Tambah' => 'Tambah item pesanan meja',
    'PesananTerbuka.Ubah' => 'Ubah pesanan meja',
    'PesananTerbuka.KirimDapur' => 'Kirim ke dapur',
    'PesananTerbuka.BatalkanBaris' => 'Batalkan item pesanan meja',
    'PesananTerbuka.PindahBaris' => 'Pindah/pisah pesanan meja',
    'PesananTerbuka.Batal' => 'Batalkan pesanan meja',
    'Meja.Bersih' => 'Meja selesai dibersihkan',
    'BahanTerbuang.Catat' => 'Bahan terbuang',
    'PesananGrosir.Buat' => 'Pesanan salesman',
    'Kunjungan.Catat' => 'Kunjungan salesman',
    _ => 'Data lain',
  };

  @override
  ConsumerState<LayarStatusSinkron> createState() => _LayarStatusSinkronState();
}

class _LayarStatusSinkronState extends ConsumerState<LayarStatusSinkron> {
  bool _sibuk = false;
  String? _pesan;

  Future<void> _Kirim() async {
    setState(() {
      _sibuk = true;
      _pesan = null;
    });
    // K-17: "Kirim sekarang" tidak menunggu jadwal coba ulang (mundur eksponensial) item yang tertunda.
    final hasil = await ref.read(penyediaSesi.notifier).SinkronkanSegera() ?? const RingkasanSinkron();
    if (mounted) {
      setState(() {
        _sibuk = false;
        _pesan = hasil.offline
            ? 'Belum tersambung ke server. Data aman di perangkat dan akan dikirim otomatis.'
            : '${hasil.terkirim} data terkirim${hasil.ditolak > 0 ? ', ${hasil.ditolak} perlu tindakan' : ''}.';
      });
      // Hasil kirim harus terlihat jelas: berhasil = notifikasi, gagal/ditolak = dialog dengan langkah berikutnya.
      if (hasil.offline) {
        unawaited(
          UmpanAksi.Gagal(
            context,
            judul: 'Belum tersambung ke server',
            pesan: 'Data aman di perangkat ini dan dikirim otomatis begitu internet kembali. Tidak perlu diulang.',
          ),
        );
      } else if (hasil.ditolak > 0) {
        unawaited(
          UmpanAksi.Gagal(
            context,
            judul: '${hasil.ditolak} data ditolak server',
            pesan: 'Lihat bagian Perlu tindakan di bawah untuk alasannya, lalu perbaiki dan kirim ulang.',
          ),
        );
      } else {
        UmpanAksi.Berhasil(
          context,
          hasil.terkirim == 0
              ? 'Tidak ada data yang menunggu. Semua sudah terkirim.'
              : '${hasil.terkirim} data terkirim ke server.',
        );
      }
    }
  }

  /// Semua item "Perlu tindakan" karena shift kembali ke antrean (terlama dulu) lalu dikirim.
  Future<void> _KirimUlangSemua() async {
    await ref.read(penyediaLayananSinkron).JadwalkanUlangPenolakanShift();
    await _Kirim();
  }

  /// Shift lama di server menahan shift baru perangkat ini: tampilkan, minta alasan & PIN supervisor, tutup paksa, lalu
  /// kirim ulang semua yang tertahan.
  Future<void> _TutupShiftLama() async {
    final layanan = ref.read(penyediaLayananSinkron);
    setState(() {
      _sibuk = true;
      _pesan = null;
    });
    try {
      final aktifLokal = (await ref.read(penyediaRepositori).AmbilShiftAktif())?.Uuid;
      final lama = [
        for (final s in await layanan.AmbilShiftTerbukaServer())
          if (s.uuid != aktifLokal) s,
      ];
      if (!mounted) {
        return;
      }
      if (lama.isEmpty) {
        // Tidak ada yang perlu ditutup (sudah ditutup dari back-office, atau server tidak terjangkau): coba kirim ulang.
        await _KirimUlangSemua();
        return;
      }
      setState(() => _sibuk = false);
      final alasan = await showDialog<String>(
        context: context,
        builder: (_) => _DialogShiftLama(shift: lama),
      );
      if (alasan == null || !mounted) {
        return;
      }
      final penyetuju = await showDialog<StafLokal>(
        context: context,
        builder: (_) => const DialogPinSupervisor(
          izin: IzinKasir.shiftSelisihSetujui,
          judulDialog: 'Persetujuan tutup shift lama',
          pesan: 'Shift lama di server akan ditutup paksa. Pilih supervisor yang menyetujui.',
          judul: 'Tutup shift lama',
        ),
      );
      if (penyetuju == null || !mounted) {
        return;
      }
      setState(() => _sibuk = true);
      for (final s in lama) {
        await layanan.TutupShiftLamaServer(uuidShift: s.uuid, uuidPenyetuju: penyetuju.uuid, alasan: alasan);
      }
      await _Kirim();
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() {
          _sibuk = false;
          _pesan = galat.pesan;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final tertunda = ref.watch(penyediaJumlahTertunda).value ?? 0;
    final perlu = ref.watch(penyediaPerluTindakan).value ?? const [];

    final koneksi = ref.watch(penyediaKoneksi);
    final pengaturan = ref.watch(penyediaPengaturanSinkron).value ?? const <String, String>{};
    final terakhir = DateTime.tryParse(pengaturan[KunciPengaturan.sinkronTerakhir] ?? '');
    final selisih = int.tryParse(pengaturan[KunciPengaturan.selisihJamDetik] ?? '');
    final dataDiperbarui = DateTime.tryParse(pengaturan[KunciPengaturan.dataAwalPada] ?? '')?.toLocal();
    final tertua = ref.watch(penyediaOutboxTertua).value;
    final sekarang = ref.watch(penyediaJam)();
    final ditinjau = ref.watch(penyediaPenjualanDitinjau).value ?? const <String>[];
    final antrean = ref.watch(penyediaRingkasanTertunda).value ?? const <({String jenis, int jumlah})>[];
    final peringatan = <String>[
      if (tertua != null && sekarang.difference(tertua) > LayarStatusSinkron.batasTertunda)
        'Ada data belum terkirim sejak ${FormatWaktu.FormatTanggalJam(tertua)} (lebih dari 2 jam). Pastikan perangkat '
            'tersambung internet, lalu ketuk Kirim sekarang.',
      if (dataDiperbarui != null && sekarang.difference(dataDiperbarui) > LayarStatusSinkron.batasDataLama)
        'Data produk, harga, dan pengaturan toko terakhir diperbarui ${FormatWaktu.FormatTanggalJam(dataDiperbarui)} '
            '(lebih dari 1 hari). Sambungkan perangkat ke internet agar harga dan promo terbaru dipakai.',
      if (selisih != null && selisih.abs() > LayarStatusSinkron.batasSelisihJamDetik)
        'Jam perangkat ${selisih > 0 ? 'lebih cepat' : 'lebih lambat'} ${(selisih.abs() / 60).round()} menit dari '
            'server. Perbaiki tanggal, jam, dan zona waktu perangkat agar waktu transaksi benar.',
    ];

    final teksKoneksi = switch (koneksi) {
      StatusKoneksi.Online => 'Online | tersambung ke server',
      StatusKoneksi.Offline => 'Offline | data aman di perangkat dan dikirim otomatis saat online',
      StatusKoneksi.BelumDiketahui => 'Koneksi belum diperiksa',
    };

    return IsiAreaKerja(
      judul: 'Status sinkron',
      aksi: [FilledButton(onPressed: _sibuk ? null : _Kirim, child: Text(_sibuk ? 'Mengirim…' : 'Kirim sekarang'))],
      anak: [
        SorotanAngka(
          label: 'Belum terkirim ke server',
          ikon: tertunda == 0 ? Icons.cloud_done_outlined : Icons.cloud_upload_outlined,
          nada: tertunda == 0 ? NadaStatus.Sukses : NadaStatus.Peringatan,
          nilai: Text(tertunda == 0 ? 'Semua data sudah terkirim.' : '$tertunda data belum terkirim.'),
          keterangan: teksKoneksi,
        ),
        for (final p in peringatan)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: KotakPanel(
              anak: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.warning_amber_outlined, size: TokenJarak.ikonSedang, color: warna.peringatan),
                  const SizedBox(width: TokenJarak.jarak12),
                  Expanded(child: Text(p)),
                ],
              ),
            ),
          ),
        if (_pesan != null)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: Text(_pesan!),
          ),
        // POS-23: pembaruan aplikasi yang tersedia (Windows memasang sendiri; platform lain tautan/toko).
        if (ref.watch(penyediaKonfigurasiAplikasi) case final konfigurasi? when konfigurasi.adaPembaruan)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: KartuPembaruanAplikasi(konfigurasi: konfigurasi),
          ),
        const SizedBox(height: TokenJarak.jarak12),
        DeretKartuAngka(
          lebarMinimum: 140,
          kartu: [
            KartuAngka(
              label: 'Koneksi',
              ikon: koneksi == StatusKoneksi.Online ? Icons.wifi : Icons.wifi_off,
              nada: koneksi == StatusKoneksi.Offline ? NadaStatus.Peringatan : NadaStatus.Netral,
              nilai: Text(switch (koneksi) {
                StatusKoneksi.Online => 'Online',
                StatusKoneksi.Offline => 'Offline',
                StatusKoneksi.BelumDiketahui => 'Belum diperiksa',
              }),
            ),
            KartuAngka(
              label: 'Sinkron terakhir',
              ikon: Icons.history,
              nilai: Text(
                terakhir == null ? 'Belum pernah' : FormatWaktu.FormatTanggalJam(terakhir),
                key: const ValueKey('SinkronTerakhir'),
              ),
            ),
            KartuAngka(
              label: 'Menunggu sejak',
              ikon: Icons.schedule,
              nilai: Text(tertua == null ? '—' : FormatWaktu.FormatTanggalJam(tertua)),
            ),
            KartuAngka(
              label: 'Perlu tindakan',
              ikon: Icons.error_outline,
              nada: perlu.isEmpty ? NadaStatus.Netral : NadaStatus.Bahaya,
              nilai: Text('${perlu.length}'),
              keterangan: 'Ditolak server',
            ),
          ],
        ),
        const SizedBox(height: TokenJarak.jarak12),
        BagianKerja(
          judul: 'Antrean kirim',
          ikon: Icons.outbox_outlined,
          anak: antrean.isEmpty
              ? const KeadaanKosong(
                  ringkas: true,
                  ilustrasi: IlustrasiKosong.Sinkron,
                  ikon: Icons.cloud_done_outlined,
                  judul: 'Antrean kosong',
                  keterangan: 'Semua penjualan, kas, dan shift dari perangkat ini sudah sampai di server.',
                )
              : Column(
                  children: [
                    for (final a in antrean)
                      BatangProporsi(
                        label: LayarStatusSinkron.AmbilLabelJenis(a.jenis),
                        nilai: Text('${a.jumlah}'),
                        rasio: a.jumlah / antrean.first.jumlah,
                      ),
                  ],
                ),
        ),
        const SizedBox(height: TokenJarak.jarak12),
        if (perlu.any((b) => b.KodeGalat == 'ShiftSudahTerbuka')) ...[
          KotakPanel(
            key: const ValueKey('BannerShiftLama'),
            anak: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.warning_amber_outlined, size: TokenJarak.ikonSedang, color: warna.peringatan),
                    const SizedBox(width: TokenJarak.jarak12),
                    const Expanded(
                      child: Text(
                        'Server masih menyimpan shift lama dari perangkat ini yang belum ditutup, jadi shift baru dan '
                        'transaksi di dalamnya ditolak. Tutup shift lama itu (perlu PIN supervisor), lalu semua data '
                        'yang tertahan dikirim ulang otomatis.',
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: TokenJarak.jarak8),
                Align(
                  alignment: Alignment.centerLeft,
                  child: FilledButton.icon(
                    key: const ValueKey('TutupShiftLama'),
                    onPressed: _sibuk ? null : () => unawaited(_TutupShiftLama()),
                    icon: const Icon(Icons.lock_clock_outlined),
                    label: const Text('Tutup shift lama'),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: TokenJarak.jarak12),
        ],
        BagianKerja(
          judul: 'Perlu tindakan',
          ikon: Icons.error_outline,
          ekor: perlu.length > 1
              ? TextButton(
                  key: const ValueKey('KirimUlangSemua'),
                  onPressed: _sibuk ? null : () => unawaited(_KirimUlangSemua()),
                  child: const Text('Kirim ulang semua'),
                )
              : null,
          anak: perlu.isEmpty
              ? const KeadaanKosong(
                  ringkas: true,
                  ilustrasi: IlustrasiKosong.Sinkron,
                  ikon: Icons.verified_outlined,
                  judul: 'Tidak ada data yang ditolak server.',
                  keterangan: 'Bila server menolak data (misal produk sudah dihapus), alasannya muncul di sini.',
                )
              : Column(
                  children: [
                    for (final b in perlu)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
                        child: Row(
                          children: [
                            Icon(Icons.error_outline, size: TokenJarak.ikonSedang, color: warna.bahaya),
                            const SizedBox(width: TokenJarak.jarak12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(LayarStatusSinkron.AmbilLabelJenis(b.Jenis)),
                                  Text(b.PesanGalat ?? 'Ditolak server.', style: TextStyle(color: warna.bahaya)),
                                ],
                              ),
                            ),
                            TextButton(
                              onPressed: () async {
                                await ref.read(penyediaRepositori).CobaLagi(b.Uuid, ref.read(penyediaJam)());
                                await _Kirim();
                              },
                              child: const Text('Kirim ulang'),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
        ),
      ],
      panelSamping: [
        JudulPanelSamping('Diperiksa back-office${ditinjau.isEmpty ? '' : ' (${ditinjau.length})'}'),
        if (ditinjau.isEmpty)
          const KeadaanKosong(
            ringkas: true,
            ilustrasi: IlustrasiKosong.Sinkron,
            ikon: Icons.flag_outlined,
            judul: 'Tidak ada transaksi yang ditandai untuk diperiksa.',
            keterangan: 'Transaksi yang diterima server tetapi perlu dicek pemilik (misal stok kurang) tampil di sini.',
          )
        else ...[
          Text(
            'Transaksi ini sudah diterima server, tetapi ditandai untuk diperiksa back-office (misal stok kurang atau '
            'pesanan sudah dibayar di perangkat lain). Tidak perlu diulang di kasir.',
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
          const SizedBox(height: TokenJarak.jarak8),
          for (final nomor in ditinjau)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
              child: Row(
                children: [
                  Icon(Icons.flag_outlined, size: TokenJarak.ikonKecil, color: warna.peringatan),
                  const SizedBox(width: TokenJarak.jarak8),
                  Expanded(child: Text(nomor)),
                ],
              ),
            ),
        ],
        const SizedBox(height: TokenJarak.jarak8),
        Divider(color: warna.garis, height: TokenJarak.jarak16),
        const JudulPanelSamping('Cara kerja sinkron'),
        const ButirPeriksa(
          nada: NadaStatus.Sukses,
          judul: 'Tersimpan di perangkat dulu',
          keterangan: 'Setiap transaksi langsung aman di kasir ini, ada internet atau tidak.',
        ),
        const ButirPeriksa(
          nada: NadaStatus.Sukses,
          judul: 'Dikirim otomatis',
          keterangan: 'Begitu online, data dikirim berurutan tanpa perlu ditekan.',
        ),
        const ButirPeriksa(
          nada: NadaStatus.Sukses,
          judul: 'Tidak terkirim dua kali',
          keterangan: 'Mengirim ulang data yang sama tidak membuat transaksi ganda.',
        ),
      ],
    );
  }
}

/// Konfirmasi tutup shift lama di server: daftar shift (kasir, waktu buka, kas awal) dan alasan tertulis.
class _DialogShiftLama extends StatefulWidget {
  const _DialogShiftLama({required this.shift});

  final List<ShiftTerbukaServerPos> shift;

  @override
  State<_DialogShiftLama> createState() => _DialogShiftLamaState();
}

class _DialogShiftLamaState extends State<_DialogShiftLama> {
  final _alasan = TextEditingController();
  String? _galat;

  @override
  void dispose() {
    _alasan.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return AlertDialog(
      title: const Text('Tutup shift lama di server'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Shift ini masih terbuka di server dan menahan shift baru. Setelah ditutup, kas dihitung dari data server '
              'dan shift ditandai untuk diperiksa pemilik.',
              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
            ),
            const SizedBox(height: TokenJarak.jarak8),
            for (final s in widget.shift)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
                child: Text(
                  '${s.namaKasir} | dibuka ${FormatWaktu.FormatTanggalJam(s.dibukaPada)} | kas awal '
                  '${Uang.Dari(s.kasAwal).FormatRupiah()}',
                  style: teks.bodyMedium,
                ),
              ),
            const SizedBox(height: TokenJarak.jarak8),
            PilihanAlasan(pengendali: _alasan, pilihan: PilihanAlasan.shiftLama),
            TextField(
              key: const ValueKey('AlasanShiftLama'),
              controller: _alasan,
              maxLength: 150,
              onChanged: (_) => setState(() => _galat = null),
              decoration: InputDecoration(
                labelText: 'Alasan menutup',
                errorText: _galat,
                border: const OutlineInputBorder(),
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
        FilledButton(
          key: const ValueKey('LanjutTutupShiftLama'),
          onPressed: () {
            final alasan = _alasan.text.trim();
            if (alasan.runes.length < 5) {
              setState(() => _galat = 'Tulis alasan minimal 5 huruf.');
              return;
            }
            Navigator.of(context).pop(alasan);
          },
          child: const Text('Lanjut minta PIN'),
        ),
      ],
    );
  }
}
