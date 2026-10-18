import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/Perangkat/PenjagaLayarMenyala.dart';
import '../../Domain/Persediaan/LayananGudang.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatWaktu.dart';
import '../LayarJual.dart';
import '../LayarKas.dart';
import '../LayarPengaturan.dart';
import '../LayarRiwayat.dart';
import '../LayarShift.dart';
import '../LayarStatusSinkron.dart';
import '../LembarBukaLaci.dart';
import '../LembarMutasiKas.dart';
import '../Meja/LayarMeja.dart';
import '../Penjualan/LembarAmbilPreOrder.dart';
import '../Penjualan/LembarPesananOnline.dart';
import '../Penjualan/LembarCucian.dart';
import '../Penjualan/LembarPerintahKerja.dart';
import '../Penjualan/LembarReservasi.dart';
import '../Penjualan/LembarReservasiMeja.dart';
import '../Penjualan/LembarRetur.dart';
import '../Penjualan/LembarVoid.dart';
import '../Persediaan/LayarStok.dart';
import '../Persediaan/LembarBahanTerbuang.dart';
import '../Persediaan/LembarGudang.dart';
import '../Salesman/LayarSalesman.dart';
import '../Salesman/LembarPesananSalesman.dart';
import '../Shift/KartuLaporanShift.dart';
import '../Shift/LembarTutupShift.dart';
import '../Struk/BagianCetakDokumen.dart';
import 'BilahAtasRuangKerja.dart';
import 'ItemNavigasi.dart';
import 'LayarKunci.dart';
import 'BannerModeLatihan.dart';
import 'BannerPengumuman.dart';
import 'PanelWajibPembaruan.dart';
import 'SidebarRuangKerja.dart';
import 'TemaNavigasiRuangKerja.dart';

/// Bingkai Ruang Kerja Kasir (PRD §17.2.7, D-16): bilah atas, rel navigasi (bilah bawah di HP), area kerja, dan bilah
/// status. Membungkus semua layar setelah shift terbuka; layar fitur hanya mengisi area kerja. Tugas rutin (kas
/// masuk/keluar/setoran) dibuka sebagai panel samping (≥ 1024dp) atau lembar bawah di atas area kerja.
///
/// Selama bingkai ini tampil (shift terbuka): layar dijaga tetap menyala, outbox dikirim tiap 30 detik, dan layar
/// terkunci otomatis setelah perangkat diam selama waktu di pengaturan perangkat. Saat terkunci, area kerja tetap
/// hidup di bawah layar kunci (keranjang tidak hilang).
///
/// Mode Pelayan (v2.00): [shift] null untuk perangkat berjenis `Pelayan` — rel berisi Meja (beranda), Pesanan,
/// Sinkron, Pengaturan; tanpa kas, shift, riwayat, dan pembayaran.
///
/// Mode Salesman (Modul Salesman bagian 2): [shift] null dan [salesman] true untuk perangkat berjenis `Salesman` atau
/// pengguna yang hanya berizin salesman — rel berisi Salesman (beranda), Stok (dengan izin), Sinkron, Pengaturan.
class RuangKerja extends ConsumerStatefulWidget {
  const RuangKerja({
    super.key,
    required this.shift,
    required this.kasir,
    this.kunci = KeadaanKunci.Bebas,
    this.salesman = false,
  });

  /// Lebar minimum untuk rel navigasi kiri; di bawahnya memakai bilah navigasi bawah.
  static const double lebarRel = 600;

  /// Lebar minimum untuk panel samping; di bawahnya panel tugas tampil sebagai lembar bawah.
  static const double lebarPanelSamping = 1024;

  static const Duration selangSinkron = Duration(seconds: 30);

  /// Mode meja: snapshot pesanan terbuka outlet ditarik tiap 7 detik (rentang 5–10 detik, Rincian F-07 mode meja).
  static const Duration selangPesananMeja = Duration(seconds: 7);

  /// F-17 BR-17.3 (v3.33): ringkasan pesanan toko online ditarik tiap 10 detik (rentang 5–10 detik); pesanan baru
  /// diumumkan di layar dan jumlah yang menunggu tampil di bilah status.
  static const Duration selangPesananOnline = Duration(seconds: 10);

  /// Null = mode Pelayan atau Salesman (tanpa shift).
  final BarisShift? shift;
  final StafLokal kasir;
  final KeadaanKunci kunci;

  /// Mode Salesman (tanpa shift; [shift] diabaikan).
  final bool salesman;

  @override
  ConsumerState<RuangKerja> createState() => _RuangKerjaState();
}

class _RuangKerjaState extends ConsumerState<RuangKerja> {
  late TujuanRuangKerja _tujuan = _beranda;

  bool get _pelayan => widget.shift == null && !widget.salesman;

  bool get _salesman => widget.salesman;

  /// Beranda: Salesman untuk mode Salesman; Meja untuk pelayan dan untuk outlet bermode kasir Meja (K-8, restoran
  /// layan meja) selama mode meja aktif; Jual untuk lainnya.
  TujuanRuangKerja get _beranda => _salesman
      ? TujuanRuangKerja.Salesman
      : _pelayan || (ref.read(penyediaModeKasir).value == 'Meja' && ref.read(penyediaModeMeja).value == true)
      ? TujuanRuangKerja.Meja
      : TujuanRuangKerja.Jual;

  /// Kasir sudah berpindah tujuan sendiri; beranda yang baru terbaca tidak lagi memindahkannya.
  bool _tujuanDipilih = false;

  /// Mode kasir & mode meja dibaca dari basis data lokal setelah layar pertama tampil; begitu terbaca, ruang kerja yang
  /// belum disentuh kasir pindah ke berandanya.
  void _SesuaikanBeranda() {
    if (!_tujuanDipilih && mounted && _tujuan != _beranda) {
      setState(() => _tujuan = _beranda);
    }
  }

  /// D-66: rel navigasi tertutup (ikon saja) sampai kasir melebarkannya, supaya area kerja lega.
  late bool _relDiciutkan = ref.read(penyediaRelAwalDiciutkan);

  /// Jenis mutasi kas yang formulirnya sedang terbuka di panel tugas (null = tertutup).
  String? _jenisKas;

  /// Panel shift yang sedang terbuka (F-11: tutup shift atau laporan X); null = tertutup.
  _PanelShift? _panelShift;

  /// Panel void/retur yang sedang terbuka (F-09); null = tertutup.
  _PanelPenjualan? _panelPenjualan;

  /// Panel catat bahan terbuang (F-05f bagian 2) sedang terbuka.
  bool _panelTerbuang = false;

  /// Panel modul Gudang (POS-25) yang sedang terbuka; null = tertutup.
  JenisGudang? _panelGudang;

  /// Modul Salesman: Uuid pelanggan yang panel ambil pesanannya sedang terbuka; null = tertutup.
  String? _panelPesananSalesman;

  bool get _adaPanel =>
      _jenisKas != null ||
      _panelShift != null ||
      _panelPenjualan != null ||
      _panelTerbuang ||
      _panelGudang != null ||
      _panelPesananSalesman != null;

  Timer? _pewaktuSinkron;
  Timer? _pewaktuPesanan;
  Timer? _pewaktuDiam;
  Timer? _pewaktuPesananOnline;
  bool _menarikPesanan = false;
  bool _menarikPesananOnline = false;
  RingkasPesananOnlinePos? _ringkasOnline;
  late final PenjagaLayarMenyala _penjagaLayar;

  bool get _terkunci => widget.kunci != KeadaanKunci.Bebas;

  KonfigurasiAplikasi? get _konfigurasi => ref.watch(penyediaKonfigurasiAplikasi);

  @override
  void initState() {
    super.initState();
    _penjagaLayar = ref.read(penyediaPenjagaLayar);
    unawaited(_penjagaLayar.Aktifkan());
    _pewaktuSinkron = Timer.periodic(RuangKerja.selangSinkron, (_) => unawaited(_Sinkronkan()));
    unawaited(Future<void>.microtask(_Sinkronkan));
    _pewaktuPesanan = Timer.periodic(RuangKerja.selangPesananMeja, (_) => unawaited(_TarikPesanan()));
    _pewaktuPesananOnline = Timer.periodic(RuangKerja.selangPesananOnline, (_) => unawaited(_TarikPesananOnline()));
    HardwareKeyboard.instance.addHandler(_SaatTombol);
    _MulaiHitungDiam();
    ref.listenManual(penyediaModeKasir, (_, _) => _SesuaikanBeranda());
    ref.listenManual(penyediaModeMeja, (_, _) => _SesuaikanBeranda());
  }

  @override
  void didUpdateWidget(RuangKerja lama) {
    super.didUpdateWidget(lama);
    // Ganti kasir: isian setengah jadi kasir sebelumnya (kas, tutup shift, void) tidak boleh terbawa dan tersimpan
    // atas nama kasir baru.
    if (widget.kasir.uuid != lama.kasir.uuid) {
      _TutupSemuaPanel();
    }
    if (widget.kunci == lama.kunci) {
      return;
    }
    if (_terkunci) {
      _pewaktuDiam?.cancel();
      // Tutup dialog/menu yang masih terbuka (mis. PIN supervisor) agar tidak ada yang tertinggal di atas layar kunci.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) {
          Navigator.of(context).popUntil((rute) => rute.isFirst);
        }
      });
    } else {
      _MulaiHitungDiam();
    }
  }

  @override
  void dispose() {
    _pewaktuSinkron?.cancel();
    _pewaktuPesanan?.cancel();
    _pewaktuDiam?.cancel();
    _pewaktuPesananOnline?.cancel();
    HardwareKeyboard.instance.removeHandler(_SaatTombol);
    unawaited(_penjagaLayar.Nonaktifkan());
    super.dispose();
  }

  Future<void> _Sinkronkan() async {
    if (mounted) {
      await ref.read(penyediaSesi.notifier).Sinkronkan();
    }
  }

  /// Tarik pesanan terbuka outlet (mode meja aktif, tidak terkunci, tidak sedang menarik). Galat diabaikan: data lokal
  /// tetap dipakai dan dicoba lagi pada putaran berikutnya.
  Future<void> _TarikPesanan() async {
    if (!mounted || _menarikPesanan || _terkunci || _salesman || ref.read(penyediaModeMeja).value != true) {
      return;
    }
    _menarikPesanan = true;
    try {
      await ref.read(penyediaLayananPesananMeja).Tarik();
    } on Object {
      // Offline/galat server: coba lagi nanti.
    }
    try {
      // F-17: pesanan QR meja yang menunggu konfirmasi.
      await ref.read(penyediaPesanSendiri.notifier).Tarik();
    } on Object {
      // Offline/galat server: daftar terakhir tetap tampil.
    } finally {
      _menarikPesanan = false;
    }
  }

  /// BR-17.3: ringkasan pesanan toko online (hanya bila toko online aktif & tidak terkunci). Pesanan baru sejak
  /// tarikan sebelumnya diumumkan dengan tombol "Lihat"; galat/offline diam dan dicoba lagi 10 detik kemudian.
  Future<void> _TarikPesananOnline() async {
    if (!mounted ||
        _menarikPesananOnline ||
        _terkunci ||
        _salesman ||
        ref.read(penyediaKonteksPenjualan).value?.tokoOnlineAktif != true) {
      return;
    }
    _menarikPesananOnline = true;
    try {
      final ringkas = await ref.read(penyediaLayananPesananOnline).AmbilRingkas(sejak: _ringkasOnline?.waktuServer);
      if (!mounted || ringkas == null) {
        return;
      }
      final pertama = _ringkasOnline == null;
      setState(() => _ringkasOnline = ringkas);
      if (!pertama && ringkas.baru > 0) {
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(
          SnackBar(
            content: Text(ringkas.baru == 1 ? 'Ada pesanan online baru.' : 'Ada ${ringkas.baru} pesanan online baru.'),
            action: SnackBarAction(
              label: 'Lihat',
              onPressed: () => _BukaPanelPenjualan(const _PanelPenjualan(pesananOnline: true)),
            ),
          ),
        );
      }
    } finally {
      _menarikPesananOnline = false;
    }
  }

  /// Hitung ulang waktu diam dari nol (setiap sentuhan, gulir, gerak tetikus, atau tombol). Audit kemudahan pakai
  /// #26: selama transaksi berjalan (keranjang berisi, termasuk menunggu pembayaran QRIS) layar tidak dikunci;
  /// hitungan diulang dan kunci baru jatuh setelah keranjang selesai/dikosongkan lalu diam lagi.
  void _MulaiHitungDiam() {
    _pewaktuDiam?.cancel();
    if (_terkunci) {
      return;
    }
    final batas = ref.read(penyediaPengaturanPerangkat).AmbilBatasDiam();
    _pewaktuDiam = Timer(batas, () {
      if (!mounted) {
        return;
      }
      if (!ref.read(penyediaKeranjang).CekBebas) {
        _MulaiHitungDiam();
        return;
      }
      ref.read(penyediaSesi.notifier).Kunci();
    });
  }

  void _CatatAktivitas(PointerEvent _) => _MulaiHitungDiam();

  bool _SaatTombol(KeyEvent _) {
    _MulaiHitungDiam();
    return false;
  }

  void _Buka(TujuanRuangKerja tujuan) => setState(() {
    _tujuanDipilih = true;
    _tujuan = tujuan;
  });

  void _BukaPanelKas(String jenis) => setState(() {
    _TutupSemuaPanel();
    _jenisKas = jenis;
  });

  void _BukaPanelShift(_PanelShift panel) => setState(() {
    _TutupSemuaPanel();
    _panelShift = panel;
  });

  /// D-48: fitur khusus sektor tampil bila sektor outlet berawalan [awalan] (atau sektor belum diketahui).
  bool _SektorCocok(List<String> awalan) => ref.watch(penyediaKonteksPenjualan).value?.CekSesuaiSektor(awalan) ?? true;

  /// Sektor outlet sudah diketahui dan memuat FnB (D-145).
  bool _SektorFnbDiketahui() {
    final k = ref.watch(penyediaKonteksPenjualan).value;
    return k != null && k.kodeSektor.isNotEmpty && k.CekSesuaiSektor(const ['FNB']);
  }

  void _BukaPanelPenjualan(_PanelPenjualan panel) => setState(() {
    _TutupSemuaPanel();
    _panelPenjualan = panel;
  });

  void _BukaPanelTerbuang() => setState(() {
    _TutupSemuaPanel();
    _panelTerbuang = true;
  });

  void _BukaPanelGudang(JenisGudang jenis) => setState(() {
    _TutupSemuaPanel();
    _panelGudang = jenis;
  });

  void _BukaPanelPesananSalesman(String uuidPelanggan) => setState(() {
    _TutupSemuaPanel();
    _panelPesananSalesman = uuidPelanggan;
  });

  void _TutupSemuaPanel() {
    _jenisKas = null;
    _panelShift = null;
    _panelPenjualan = null;
    _panelTerbuang = false;
    _panelGudang = null;
    _panelPesananSalesman = null;
  }

  void _TutupPanel() => setState(_TutupSemuaPanel);

  /// Tombol kembali (Android) selalu kembali ke area kerja: tutup panel, lalu ke beranda Jual. Tidak keluar aplikasi.
  void _SaatKembali(bool sudahKembali, Object? _) {
    if (sudahKembali || _terkunci) {
      return;
    }
    if (_adaPanel) {
      _TutupPanel();
    } else if (_tujuan != _beranda) {
      _Buka(_beranda);
    }
  }

  Widget _BangunLayar(TujuanRuangKerja tujuan) => switch (tujuan) {
    // P-10: di bawah versi minimal, layar jual & meja dikunci sampai aplikasi diperbarui (outbox tetap terkirim).
    TujuanRuangKerja.Jual ||
    TujuanRuangKerja.Meja when _konfigurasi?.wajibPembaruan == true => PanelWajibPembaruan(konfigurasi: _konfigurasi!),
    TujuanRuangKerja.Jual => LayarJual(
      kasir: widget.kasir,
      aktif: _tujuan == TujuanRuangKerja.Jual && !_terkunci && !_adaPanel,
      saatKeMeja: _pelayan || ref.watch(penyediaModeMeja).value == true ? () => _Buka(TujuanRuangKerja.Meja) : null,
      modePelayan: _pelayan,
    ),
    TujuanRuangKerja.Meja => LayarMeja(kasir: widget.kasir, saatBukaPesanan: () => _Buka(TujuanRuangKerja.Jual)),
    TujuanRuangKerja.Riwayat => LayarRiwayat(
      saatVoid: (uuid) => _BukaPanelPenjualan(_PanelPenjualan(uuidPenjualanVoid: uuid)),
      saatRetur: () => _BukaPanelPenjualan(const _PanelPenjualan()),
      saatReturStruk: (nomor) => _BukaPanelPenjualan(_PanelPenjualan(nomorRetur: nomor)),
      saatAmbilPreOrder: () => _BukaPanelPenjualan(const _PanelPenjualan(ambilPreOrder: true)),
      saatPesananOnline: ref.watch(penyediaKonteksPenjualan).value?.tokoOnlineAktif == true
          ? () => _BukaPanelPenjualan(const _PanelPenjualan(pesananOnline: true))
          : null,
      // D-48: reservasi hanya untuk usaha jasa (SVC), servis hanya untuk bengkel (SVC-WRK).
      saatReservasi: _SektorCocok(const ['SVC'])
          ? () => _BukaPanelPenjualan(const _PanelPenjualan(reservasi: true))
          : null,
      // D-145: reservasi meja + uang muka hanya untuk usaha FnB.
      // Hanya bila sektor outlet sudah diketahui FnB (sektor kosong tidak menampilkan tombol ini, supaya kepala Riwayat tidak
      // penuh oleh fitur yang mungkin tidak dipakai).
      saatReservasiMeja: _SektorFnbDiketahui()
          ? () => _BukaPanelPenjualan(const _PanelPenjualan(reservasiMeja: true))
          : null,
      saatServis: _SektorCocok(const ['SVC-WRK'])
          ? () => _BukaPanelPenjualan(const _PanelPenjualan(servis: true))
          : null,
      saatCucian: ref.watch(penyediaKonteksPenjualan).value?.laundry.aktif == true
          ? () => _BukaPanelPenjualan(const _PanelPenjualan(cucian: true))
          : null,
    ),
    TujuanRuangKerja.Stok => LayarStok(
      saatCatatTerbuang: widget.kasir.PunyaIzin(IzinKasir.persediaanTerbuangCatat) ? _BukaPanelTerbuang : null,
      jenisGudang: [
        for (final j in JenisGudang.values)
          if (LayananGudang.CekBoleh(widget.kasir, j)) j,
      ],
      saatGudang: _BukaPanelGudang,
    ),
    TujuanRuangKerja.Salesman => LayarSalesman(
      staf: widget.kasir,
      aktif: _tujuan == TujuanRuangKerja.Salesman && !_terkunci,
      saatAmbilPesanan: _BukaPanelPesananSalesman,
    ),
    TujuanRuangKerja.Kas => LayarKas(shift: widget.shift!, saatCatat: _BukaPanelKas),
    TujuanRuangKerja.Shift => LayarShift(
      shift: widget.shift!,
      kasir: widget.kasir,
      saatTutupShift: () => _BukaPanelShift(_PanelShift.Tutup),
      saatLaporanX: () => _BukaPanelShift(_PanelShift.LaporanX),
    ),
    TujuanRuangKerja.StatusSinkron => const LayarStatusSinkron(),
    TujuanRuangKerja.Pengaturan => const LayarPengaturan(),
  };

  List<ItemBilahStatus> _AmbilItemStatus() {
    final koneksi = ref.watch(penyediaKoneksi);
    final tertunda = ref.watch(penyediaJumlahTertunda).value ?? 0;
    final perluTindakan = ref.watch(penyediaPerluTindakan).value?.length ?? 0;
    return [
      switch (koneksi) {
        StatusKoneksi.Online => const ItemBilahStatus(ikon: Icons.wifi, teks: 'Online', nada: NadaStatus.Sukses),
        StatusKoneksi.Offline => const ItemBilahStatus(
          ikon: Icons.wifi_off,
          teks: 'Offline',
          nada: NadaStatus.Peringatan,
        ),
        StatusKoneksi.BelumDiketahui => const ItemBilahStatus(ikon: Icons.wifi_find, teks: 'Memeriksa koneksi'),
      },
      if (perluTindakan > 0)
        ItemBilahStatus(ikon: Icons.error_outline, teks: '$perluTindakan perlu tindakan', nada: NadaStatus.Bahaya)
      else if (tertunda > 0)
        ItemBilahStatus(
          ikon: Icons.cloud_upload_outlined,
          teks: '$tertunda belum terkirim',
          nada: NadaStatus.Peringatan,
        )
      else
        const ItemBilahStatus(ikon: Icons.cloud_done_outlined, teks: 'Tersinkron', nada: NadaStatus.Sukses),
      // BR-17.3: pesanan toko online yang menunggu staf (terima) atau siap ditagih.
      if (_ringkasOnline case final r? when r.menunggu > 0)
        ItemBilahStatus(
          ikon: Icons.shopping_bag_outlined,
          teks: '${r.menunggu} pesanan online menunggu',
          nada: NadaStatus.Peringatan,
        )
      else if (_ringkasOnline case final r? when r.perluDitagih > 0)
        ItemBilahStatus(ikon: Icons.shopping_bag_outlined, teks: '${r.perluDitagih} pesanan online siap ditagih'),
      if (_konfigurasi case final k? when k.wajibPembaruan)
        const ItemBilahStatus(ikon: Icons.system_update, teks: 'Wajib perbarui aplikasi', nada: NadaStatus.Bahaya)
      else if (_konfigurasi case final k? when k.adaPembaruan)
        ItemBilahStatus(ikon: Icons.system_update_outlined, teks: 'Versi ${k.versiTerbaru ?? 'baru'} tersedia'),
      switch (ref.watch(penyediaPrinter)) {
        StatusPrinter(keadaan: KeadaanPrinter.BelumDiatur) => const ItemBilahStatus(
          ikon: Icons.print_disabled_outlined,
          teks: 'Printer belum diatur',
        ),
        StatusPrinter(keadaan: KeadaanPrinter.Gagal) => const ItemBilahStatus(
          ikon: Icons.print_disabled_outlined,
          teks: 'Printer bermasalah',
          nada: NadaStatus.Bahaya,
        ),
        StatusPrinter(keadaan: KeadaanPrinter.Mencetak) => const ItemBilahStatus(
          ikon: Icons.print_outlined,
          teks: 'Mencetak…',
        ),
        StatusPrinter() => const ItemBilahStatus(
          ikon: Icons.print_outlined,
          teks: 'Printer siap',
          nada: NadaStatus.Sukses,
        ),
      },
      if (_salesman)
        const ItemBilahStatus(ikon: Icons.storefront_outlined, teks: 'Mode salesman')
      else if (widget.shift case final shift?)
        ItemBilahStatus(ikon: Icons.schedule, teks: 'Shift ${FormatWaktu.FormatJam(shift.DibukaPada)}')
      else
        const ItemBilahStatus(ikon: Icons.room_service_outlined, teks: 'Mode pelayan'),
    ];
  }

  Widget _BangunRel(List<ItemNavigasi> item, int indeks, {required bool lebarPenuh}) => SidebarRuangKerja(
    item: item,
    indeks: indeks,
    diciutkan: _relDiciutkan,
    lebarPenuh: lebarPenuh,
    saatPilih: _Buka,
    saatUbahLebar: () => setState(() => _relDiciutkan = !_relDiciutkan),
  );

  Widget _BangunAreaKerja(List<ItemNavigasi> item, int indeks, double lebar) {
    final warna = TokenWarna.AmbilDari(context);
    final jenisKas = _jenisKas;
    final panelShift = _panelShift;
    final panelPenjualan = _panelPenjualan;
    final area = IndexedStack(index: indeks, children: [for (final i in item) _BangunLayar(i.tujuan)]);
    final panelGudang = _panelGudang;
    final panelPesananSalesman = _panelPesananSalesman;
    if (!_adaPanel) {
      return area;
    }

    final (judul, formulir) = switch ((jenisKas, panelShift, panelPenjualan)) {
      _ when panelPesananSalesman != null => (
        LembarPesananSalesman.judul,
        LembarPesananSalesman(
          key: ValueKey('PesananSalesman-$panelPesananSalesman'),
          staf: widget.kasir,
          uuidPelanggan: panelPesananSalesman,
          saatTerkirim: _TutupPanel,
        ) as Widget,
      ),
      _ when panelGudang != null => (
        panelGudang.judul,
        LembarGudang(key: ValueKey('Gudang-${panelGudang.name}'), jenis: panelGudang, staf: widget.kasir) as Widget,
      ),
      _ when _panelTerbuang => (
        LembarBahanTerbuang.judul,
        LembarBahanTerbuang(key: const ValueKey('BahanTerbuang'), pencatat: widget.kasir, saatTersimpan: _TutupPanel)
            as Widget,
      ),
      (_, _, _PanelPenjualan(uuidPenjualanVoid: final String uuid)) => (
        LembarVoid.judul,
        LembarVoid(key: ValueKey('Void-$uuid'), uuidPenjualan: uuid, kasir: widget.kasir, saatSelesai: _TutupPanel)
            as Widget,
      ),
      (_, _, _PanelPenjualan(cucian: true)) => (
        LembarCucian.judul,
        LembarCucian(key: const ValueKey('Cucian'), kasir: widget.kasir) as Widget,
      ),
      (_, _, _PanelPenjualan(reservasi: true)) => (
        LembarReservasi.judul,
        LembarReservasi(
          key: const ValueKey('Reservasi'),
          kasir: widget.kasir,
          saatDimuat: () {
            _TutupPanel();
            _Buka(TujuanRuangKerja.Jual);
          },
        ) as Widget,
      ),
      (_, _, _PanelPenjualan(reservasiMeja: true)) => (
        LembarReservasiMeja.judul,
        LembarReservasiMeja(
          key: const ValueKey('ReservasiMeja'),
          kasir: widget.kasir,
          saatDimuat: () {
            _TutupPanel();
            _Buka(TujuanRuangKerja.Jual);
          },
        ) as Widget,
      ),
      (_, _, _PanelPenjualan(servis: true)) => (
        LembarPerintahKerja.judul,
        LembarPerintahKerja(
          key: const ValueKey('PerintahKerja'),
          kasir: widget.kasir,
          saatDimuat: () {
            _TutupPanel();
            _Buka(TujuanRuangKerja.Jual);
          },
        ) as Widget,
      ),
      (_, _, _PanelPenjualan(pesananOnline: true)) => (
        LembarPesananOnline.judul,
        LembarPesananOnline(
          key: const ValueKey('PesananOnline'),
          kasir: widget.kasir,
          saatDimuat: () {
            _TutupPanel();
            _Buka(TujuanRuangKerja.Jual);
          },
        ) as Widget,
      ),
      (_, _, _PanelPenjualan(ambilPreOrder: true)) => (
        LembarAmbilPreOrder.judul,
        LembarAmbilPreOrder(
          key: const ValueKey('AmbilPreOrder'),
          saatDimuat: () {
            _TutupPanel();
            _Buka(TujuanRuangKerja.Jual);
          },
        ) as Widget,
      ),
      (_, _, _PanelPenjualan(:final nomorRetur)) => (
        LembarRetur.judul,
        LembarRetur(
          key: ValueKey('Retur-${nomorRetur ?? ''}'),
          kasir: widget.kasir,
          nomorAwal: nomorRetur,
          saatSelesai: _TutupPanel,
          // K-11: tukar barang dilanjutkan di layar Jual dengan nilai retur sebagai pembayaran.
          saatTukar: () {
            _TutupPanel();
            _Buka(TujuanRuangKerja.Jual);
          },
        ),
      ),
      (LembarBukaLaci.kunciPanel, _, _) => (
        LembarBukaLaci.judul,
        LembarBukaLaci(
          key: const ValueKey(LembarBukaLaci.kunciPanel),
          shift: widget.shift!,
          pembuka: widget.kasir,
          saatSelesai: _TutupPanel,
        ) as Widget,
      ),
      (final String jenis, _, _) => (
        LembarMutasiKas.AmbilJudul(jenis),
        LembarMutasiKas(
          key: ValueKey(jenis),
          shift: widget.shift!,
          jenis: jenis,
          pencatat: widget.kasir,
          saatTersimpan: _TutupPanel,
        ) as Widget,
      ),
      (_, _PanelShift.Tutup, _) => (
        LembarTutupShift.judul,
        LembarTutupShift(key: const ValueKey('TutupShift'), shift: widget.shift!, penutup: widget.kasir),
      ),
      _ => ('Laporan sementara (X)', _IsiLaporanX(uuidShift: widget.shift!.Uuid)),
    };

    if (lebar >= RuangKerja.lebarPanelSamping) {
      return Stack(
        children: [
          Positioned.fill(child: area),
          Positioned(
            top: 0,
            right: 0,
            bottom: 0,
            child: PanelTugas(judul: judul, saatTutup: _TutupPanel, anak: formulir),
          ),
        ],
      );
    }

    return LayoutBuilder(
      builder: (context, batas) => Stack(
        children: [
          Positioned.fill(child: area),
          Positioned.fill(
            child: Semantics(
              label: 'Tutup $judul',
              button: true,
              child: GestureDetector(
                onTap: _TutupPanel,
                child: ColoredBox(color: warna.teksUtama.withValues(alpha: 0.24)),
              ),
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: ConstrainedBox(
              constraints: BoxConstraints(maxHeight: batas.maxHeight * 0.9),
              child: PanelTugas(judul: judul, saatTutup: _TutupPanel, tataLetak: TataLetakPanel.Lembar, anak: formulir),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(penyediaPengaturanPerangkat.select((p) => p.menitKunciOtomatis), (_, _) => _MulaiHitungDiam());

    final warna = TokenWarna.AmbilDari(context);
    final lebar = MediaQuery.sizeOf(context).width;
    final pakaiRel = lebar >= RuangKerja.lebarRel;
    final item = _salesman
        ? ItemNavigasi.Saring(widget.kasir, daftar: ItemNavigasi.salesman)
        : _pelayan
        ? ItemNavigasi.Saring(widget.kasir, daftar: ItemNavigasi.pelayan)
        : ItemNavigasi.Saring(
            widget.kasir,
            modulAktif: {if (ref.watch(penyediaModeMeja).value == true) ItemNavigasi.modulMeja},
          );
    final indeks = item.indexWhere((i) => i.tujuan == _tujuan).clamp(0, item.length - 1);

    final bingkai = Scaffold(
      // `top: false`: bilah atas yang menangani inset atas sendiri, supaya warna merek ikut mengisi area
      // status bar dan bingkai tidak terpotong garis putih di puncak layar.
      body: SafeArea(
        top: false,
        bottom: pakaiRel,
        child: Column(
          children: [
            BilahAtasRuangKerja(
              kasir: widget.kasir,
              saatGantiKasir: () => ref.read(penyediaSesi.notifier).Kunci(gantiKasir: true),
              saatKunci: ref.read(penyediaSesi.notifier).Kunci,
            ),
            // P-10 PGL-19: pengumuman & jadwal pemeliharaan dari pengelola platform.
            if (_konfigurasi case final k? when k.pengumuman.isNotEmpty) BannerPengumuman(pengumuman: k.pengumuman),
            // K-23: mode latihan selalu terlihat.
            if (ref.watch(penyediaModeLatihan)) const BannerModeLatihan(),
            Expanded(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (pakaiRel) ...[
                    _BangunRel(item, indeks, lebarPenuh: lebar >= RuangKerja.lebarPanelSamping),
                    VerticalDivider(width: TokenJarak.tebalGaris, thickness: TokenJarak.tebalGaris, color: warna.garis),
                  ],
                  // Batas lukis sendiri: perubahan keranjang tidak melukis ulang bilah atas, rel, dan bilah status.
                  Expanded(child: RepaintBoundary(child: _BangunAreaKerja(item, indeks, lebar))),
                ],
              ),
            ),
            BilahStatus(
              item: _AmbilItemStatus(),
              petunjuk: 'Buka status sinkron',
              saatDiketuk: () => _Buka(TujuanRuangKerja.StatusSinkron),
            ),
          ],
        ),
      ),
      bottomNavigationBar: pakaiRel
          ? null
          : NavigationBarTheme(
              data: TemaNavigasiRuangKerja.BuatTemaBilah(warna, Theme.of(context).textTheme),
              child: NavigationBar(
                selectedIndex: indeks,
                onDestinationSelected: (i) => _Buka(item[i].tujuan),
                labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
                destinations: [
                  for (final i in item)
                    NavigationDestination(
                      icon: Icon(i.ikon),
                      selectedIcon: Icon(i.ikonAktif),
                      label: i.labelRingkas ?? i.label,
                      tooltip: i.label,
                    ),
                ],
              ),
            ),
    );

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: _SaatKembali,
      child: Listener(
        behavior: HitTestBehavior.translucent,
        onPointerDown: _CatatAktivitas,
        onPointerHover: _CatatAktivitas,
        onPointerSignal: _CatatAktivitas,
        child: Stack(
          children: [
            Positioned.fill(
              child: Offstage(
                offstage: _terkunci,
                child: TickerMode(
                  enabled: !_terkunci,
                  child: ExcludeFocus(excluding: _terkunci, child: bingkai),
                ),
              ),
            ),
            if (_terkunci)
              Positioned.fill(
                child: LayarKunci(
                  key: ValueKey(widget.kunci),
                  kasir: widget.kasir,
                  gantiKasir: widget.kunci == KeadaanKunci.GantiKasir,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Panel shift di ruang kerja (F-11).
enum _PanelShift { Tutup, LaporanX }

/// Panel void (dengan Uuid penjualan) atau retur dari struk (tanpa Uuid) di ruang kerja (F-09).
class _PanelPenjualan {
  const _PanelPenjualan({
    this.uuidPenjualanVoid,
    this.nomorRetur,
    this.ambilPreOrder = false,
    this.pesananOnline = false,
    this.reservasi = false,
    this.reservasiMeja = false,
    this.servis = false,
    this.cucian = false,
  });

  final String? uuidPenjualanVoid;

  /// Audit kemudahan pakai #10: nomor struk dari baris Riwayat untuk lembar retur (null = kasir mengetik/memindai).
  final String? nomorRetur;

  /// F-12 bagian 2: cari & ambil pre-order.
  final bool ambilPreOrder;

  /// F-17: pesanan toko online yang menunggu ditagihkan.
  final bool pesananOnline;

  /// F-07 mode service: antrian reservasi hari ini.
  final bool reservasi;

  /// FnB reservasi meja + uang muka (D-145): antrian reservasi meja hari ini.
  final bool reservasiMeja;

  /// Bengkel bagian 2: perintah kerja siap tagih.
  final bool servis;

  /// Laundry: daftar cucian (siap diambil, ubah status, cetak nota).
  final bool cucian;
}

/// Laporan X: ringkasan shift berjalan dari data perangkat, bisa dibuka kapan saja dari layar Shift.
class _IsiLaporanX extends ConsumerWidget {
  const _IsiLaporanX({required this.uuidShift});

  final String uuidShift;

  @override
  Widget build(BuildContext context, WidgetRef ref) => Padding(
    padding: const EdgeInsets.all(TokenJarak.jarak24),
    child: ref
        .watch(penyediaLaporanShift(uuidShift))
        .when(
          loading: () => const LinearProgressIndicator(),
          error: (galat, _) => Text('Laporan tidak bisa dibaca: $galat'),
          data: (laporan) => Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              KartuLaporanShift(laporan: laporan),
              const SizedBox(height: TokenJarak.jarak12),
              // Cetak struk bagian 3b: laporan X hanya dicetak bila diminta (tanpa kas seharusnya saat tutup buta).
              BagianCetakDokumen(
                kunci: 'LaporanX:$uuidShift',
                namaDokumen: 'laporan X',
                otomatis: false,
                cetak: (layanan, _, _) => layanan.CetakLaporanShift(laporan),
              ),
            ],
          ),
        ),
  );
}
