import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart' show KunciFlagFitur;
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import 'Komponen/FormatAngka.dart';
import 'Komponen/MasukanUang.dart';
import '../Aplikasi/Penyedia.dart';
import '../Data/PesananMeja.dart';
import '../Data/RepositoriKasir.dart';
import '../Domain/GalatKasir.dart';
import '../Domain/Katalog/BarcodeTimbangan.dart';
import '../Domain/Katalog/KatalogLokal.dart';
import '../Domain/Katalog/LayananKatalog.dart';
import '../Domain/Meja/KonteksPesananMeja.dart';
import '../Domain/Meja/LayananPesananMeja.dart';
import '../Domain/Penjualan/Keranjang.dart';
import '../Domain/Penjualan/KonteksPenjualan.dart';
import '../Domain/Penjualan/LayananPenjualan.dart';
import '../Domain/Penjualan/LayananPreOrder.dart';
import '../Domain/Penjualan/PengaliJumlah.dart';
import '../Domain/Perangkat/LayananLayarPelanggan.dart';
import '../Domain/Perangkat/PemindaiKamera.dart';
import '../Domain/Perangkat/PengaturanPerangkat.dart';
import '../Domain/Sesi/StafLokal.dart';
import 'Jual/DialogHargaTerbuka.dart';
import 'Jual/LencanaObat.dart';
import 'Jual/PanelBayar.dart';
import 'Jual/PanelCekHarga.dart';
import 'Jual/PanelDiskon.dart';
import 'Jual/PanelItem.dart';
import 'Jual/PanelKeranjang.dart';
import 'Jual/PanelLaundry.dart';
import 'Jual/PanelRacikan.dart';
import 'Jual/PanelPelanggan.dart';
import 'Jual/PanelPesananGrosir.dart';
import 'Jual/PanelPreOrder.dart';
import 'Jual/PanelTertahan.dart';
import 'Jual/JalurKameraPindai.dart';
import 'Jual/PanelVarian.dart';
import 'Jual/PengenalPemindai.dart';
import 'Jual/UmpanBalikPindai.dart';
import 'Meja/DialogPesananMeja.dart';

enum _JenisPanel {
  Keranjang,
  Item,
  Varian,
  CekHarga,
  DiskonPesanan,
  Bayar,
  Selesai,
  Tertahan,
  Pelanggan,
  PreOrder,
  PreOrderSelesai,
  PesananGrosir,
  PesananGrosirSelesai,
  Laundry,
  Racikan,
}

/// Beranda ruang kerja: layar Jual (F-07 mode retail, Rincian F-07c, PRD §17.2.3 & §17.2.7).
/// - Katalog: cari nama/SKU/barcode, kategori, ubin produk seragam; keranjang di sisi yang diatur (kiri/kanan) mulai
///   600dp, di HP keranjang dibuka sebagai lembar dengan bilah Bayar menempel di bawah.
/// - Tugas (pilihan item, diskon, bayar, pesanan tertahan) dibuka sebagai panel samping (≥ 1024dp) atau lembar di atas
///   area kerja, sehingga keranjang tidak hilang.
/// - Pemindai barcode tanpa fokus: rangkaian karakter cepat diakhiri Enter di mana pun di layar Jual (bukan saat mengetik
///   di kolom isian). Pintasan desktop (tabel §17.2.3): F1 cari, F4 cek harga (K-10), F8 bayar, F9 uang pas (tunai
///   uang pas langsung disimpan), Esc tutup panel atau hapus item terakhir keranjang, F2 pilih pelanggan (F-16a).
///   Batalkan transaksi hanya lewat tombol di keranjang (dengan konfirmasi).
/// - Katalog diperbarui berkala 60 detik saat online dan keranjang kosong (perubahan tidak mengejutkan di tengah
///   transaksi, §17.2.7).
///
/// Mode meja (F-07 mode meja fase 1): saat pesanan meja dibuka dari layar Meja, keranjang menampilkan baris tersimpan
/// pesanan (dengan status dapur; ketuk = batalkan item) dan item baru. "Kirim ke dapur" menggantikan "Tahan"; Bayar
/// menyimpan item baru ke pesanan, mengambil kunci bayar online, lalu membayar seluruh pesanan.
///
/// Mode Pelayan (v2.00, perangkat berjenis `Pelayan`): hanya mencatat pesanan meja. Tanpa Diskon, Tahan, Bayar,
/// pre-order, dan pintasan F8/F9; aksi utama "Kirim ke dapur" (lalu kembali ke Meja) atau "Pilih meja" bila belum ada
/// pesanan yang dibuka.
class LayarJual extends ConsumerStatefulWidget {
  const LayarJual({super.key, required this.kasir, this.aktif = true, this.saatKeMeja, this.modePelayan = false});

  /// Lebar area kerja minimum untuk katalog + keranjang berdampingan.
  static const double lebarDuaPanel = 600;
  static const Duration selangKatalog = Duration(seconds: 60);

  /// F-17 BR-17.2: daftar produk habis lebih ringan daripada katalog, jadi disegarkan lebih sering supaya produk yang
  /// baru ditandai habis di perangkat lain (atau back-office) cepat terlihat di kasir ini.
  static const Duration selangProdukHabis = Duration(seconds: 20);

  final StafLokal kasir;

  /// Layar Jual sedang tampil dan tidak tertutup layar kunci/panel bingkai (pemindai & pintasan aktif).
  final bool aktif;

  /// Kembali ke layar Meja (mode meja aktif); null = mode meja tidak aktif.
  final VoidCallback? saatKeMeja;

  /// v2.00: perangkat Pelayan — tanpa pembayaran.
  final bool modePelayan;

  /// Selang perpanjangan kunci bayar pesanan meja selama panel Bayar terbuka (kunci server berlaku 2 menit).
  static const Duration selangKunciBayar = Duration(seconds: 60);

  /// K-16: penanda "kategori" Terlaris di bar kategori (bukan Uuid kategori sungguhan).
  static const String kategoriTerlaris = 'Terlaris';

  /// K-16: penanda "kategori" Favorit (produk pilihan pemilik) di bar kategori.
  static const String kategoriFavorit = 'Favorit';

  /// K-15: lama baris keranjang disorot setelah pindaian (transisinya 150 ms, §17.2.7 prinsip 4).
  static const Duration lamaSorot = Duration(milliseconds: 900);

  /// Lebar maksimum isi halaman penuh (langkah bayar); di layar lebar isinya tetap terbaca, tidak melebar
  /// sampai ujung. Dinamai tanpa kata uang supaya tidak tertangkap penjaga "double untuk uang".
  static const double lebarIsiHalaman = 720;

  @override
  ConsumerState<LayarJual> createState() => _LayarJualState();
}

class _LayarJualState extends ConsumerState<LayarJual> {
  final _cari = TextEditingController();
  final _fokusCari = FocusNode(debugLabel: 'Cari produk');
  final _fokusAkar = FocusNode(debugLabel: 'Layar Jual');
  final _pemindai = PengenalPemindai();

  /// Pemindai kamera yang terus terbuka (strip di atas katalog); hanya di perangkat yang punya kamera (Android/iOS).
  bool _kameraAktif = false;
  HasilPindaiKamera? _hasilKamera;
  int _nomorHasilKamera = 0;

  /// Hasil `_JalankanPindai` terakhir: berhasil atau tidak, dan nama baris yang bertambah. Dibaca strip kamera.
  ({bool berhasil, String? nama, String? satuan})? _hasilPindaiTerakhir;
  final _kunciBayar = GlobalKey<PanelBayarState>();
  final _kunciCekHarga = GlobalKey<PanelCekHargaState>();
  Timer? _pewaktuKatalog;
  Timer? _pewaktuProdukHabis;
  Timer? _pewaktuKunciBayar;

  /// Pesanan meja yang kunci bayarnya sedang dipegang perangkat ini.
  String? _uuidKunciBayar;

  /// K-15: baris keranjang yang baru bertambah lewat pindaian (disorot sebentar).
  String? _uuidSorot;
  Timer? _pewaktuSorot;

  /// K-13: kursus item baru pesanan meja (null = tanpa kursus). Utama/Penutup disimpan & ditahan, bukan dikirim.
  String? _kursusBaru;

  /// K-13: kursus yang ditahan, bukan langsung dikirim ke dapur.
  bool get _kursusDitahan => _kursusBaru == KursusPesanan.utama || _kursusBaru == KursusPesanan.penutup;

  /// Transaksi terakhir menutup pesanan meja (setelah selesai kembali ke layar Meja).
  bool _selesaiPesanan = false;
  bool _mengirimDapur = false;

  String? _uuidKategori;

  /// Langkah pembayaran punya **halaman sendiri**, bukan panel di atas keranjang: Jual → Bayar → Transaksi berhasil.
  /// Pre-order ikut karena dicapai dari halaman Bayar dan berakhir di halaman hasil yang bentuknya sama.
  ///
  /// Sisa panel (item, diskon, pelanggan, pesanan tertahan) tetap panel/lembar sesuai §17.2.7: semuanya tugas
  /// sambilan yang keranjangnya harus tetap terlihat. Pembayaran bukan tugas sambilan — begitu ditekan Bayar,
  /// katalog tidak lagi dibutuhkan dan kasir butuh angka sebesar mungkin.
  static const Set<_JenisPanel> _halamanPenuh = {
    _JenisPanel.Bayar,
    _JenisPanel.Selesai,
    _JenisPanel.PreOrder,
    _JenisPanel.PreOrderSelesai,
    _JenisPanel.PesananGrosir,
    _JenisPanel.PesananGrosirSelesai,
  };

  _JenisPanel? _panel;
  ProdukJual? _produkPanel;
  String? _uuidBarisPanel;
  PenjualanTersimpan? _selesai;

  /// Isi terakhir yang dikirim ke layar pelanggan (hindari kirim ulang tiap build).
  String? _sidikLayarPelanggan;

  /// F-12 bagian 2: pre-order yang baru tersimpan.
  PreOrderTersimpan? _preOrderSelesai;
  PesananGrosirTersimpan? _pesananGrosirSelesai;
  ({String teks, bool galat})? _pesan;
  bool _memperbarui = false;

  @override
  void initState() {
    super.initState();
    HardwareKeyboard.instance.addHandler(_SaatTombolPemindai);
    _pewaktuKatalog = Timer.periodic(LayarJual.selangKatalog, (_) => unawaited(_PerbaruiBerkala()));
    unawaited(ref.read(penyediaProdukHabis.notifier).Muat());
    unawaited(ref.read(penyediaSesi.notifier).SegarkanStokTersedia());
    _pewaktuProdukHabis = Timer.periodic(LayarJual.selangProdukHabis, (_) => unawaited(_SegarkanProdukHabis()));
    if (widget.aktif) {
      _FokusAkar();
    }
    unawaited(_MuatKategoriTerakhir());
    // v3.51: transaksi baru memakai jenis pesanan bawaan outlet (misal Makan di tempat di kafe).
    ref.listenManual(
      penyediaKonteksPenjualan.select((k) => k.value?.jenisPesananBawaan),
      (_, bawaan) => ref.read(penyediaKeranjang.notifier).AturKanalBawaan(bawaan),
      fireImmediately: true,
    );
    // Audit kemudahan pakai: pilihan kursus hanya berlaku untuk pesanan yang sedang dibuka. Ganti/tutup pesanan meja
    // mengembalikannya ke "tanpa kursus", supaya pesanan meja berikutnya tidak ikut tertahan dan tetap sampai ke dapur.
    ref.listenManual(penyediaKeranjang.select((d) => d.pesananMeja?.uuid), (lama, baru) {
      if (lama != baru && _kursusBaru != null && mounted) {
        setState(() => _kursusBaru = null);
      }
    });
  }

  @override
  void didUpdateWidget(LayarJual lama) {
    super.didUpdateWidget(lama);
    if (widget.aktif && !lama.aktif) {
      _FokusAkar();
    }
    if (!widget.aktif) {
      _pemindai.Reset();
      // Berpindah ke menu lain: kamera dimatikan (baterai & privasi); kasir membukanya lagi dari tombol kamera.
      _kameraAktif = false;
      _hasilKamera = null;
    }
  }

  @override
  void dispose() {
    HardwareKeyboard.instance.removeHandler(_SaatTombolPemindai);
    _pewaktuKatalog?.cancel();
    _pewaktuProdukHabis?.cancel();
    _pewaktuKunciBayar?.cancel();
    _pewaktuSorot?.cancel();
    final kunci = _uuidKunciBayar;
    if (kunci != null) {
      unawaited(ref.read(penyediaLayananPesananMeja).LepasKunciBayar(kunci));
    }
    _cari.dispose();
    _fokusCari.dispose();
    _fokusAkar.dispose();
    super.dispose();
  }

  /// Kembalikan fokus ke akar layar Jual (setelah panel/dialog ditutup) agar pintasan & pemindai tetap bekerja.
  void _FokusAkar() => WidgetsBinding.instance.addPostFrameCallback((_) {
    if (mounted && widget.aktif && !_fokusAkar.hasPrimaryFocus && !_CekFokusDiIsian()) {
      _fokusAkar.requestFocus();
    }
  });

  void _TampilPesan(String teks, {bool galat = true}) => setState(() => _pesan = (teks: teks, galat: galat));

  // Pemindai & pintasan ------------------------------------------------------------------------------------------------

  static bool _CekFokusDiIsian() {
    final konteks = FocusManager.instance.primaryFocus?.context;
    return konteks != null &&
        (konteks.widget is EditableText || konteks.findAncestorWidgetOfExactType<EditableText>() != null);
  }

  bool _SaatTombolPemindai(KeyEvent event) {
    if (!mounted || !widget.aktif || event is! KeyDownEvent) {
      return false;
    }
    // Pintasan saat fokus hilang dari layar Jual (misal setelah panel ditutup): tetap ditangani selama tidak ada
    // dialog di atasnya. Bila fokus ada di dalam layar Jual, pintasan ditangani `Focus.onKeyEvent` (panel lebih dulu).
    if (!_fokusAkar.hasFocus && (ModalRoute.of(context)?.isCurrent ?? true)) {
      if (_SaatTombolPintasan(_fokusAkar, event) == KeyEventResult.handled) {
        return true;
      }
    }
    if (_CekFokusDiIsian() || _panel == _JenisPanel.Bayar) {
      _pemindai.Reset();
      return false;
    }
    if (event.logicalKey == LogicalKeyboardKey.enter || event.logicalKey == LogicalKeyboardKey.numpadEnter) {
      final kode = _pemindai.Selesai(event.timeStamp);
      if (kode == null) {
        return false;
      }
      _TanganiKode(kode);
      return true;
    }
    final karakter = event.character;
    if (karakter != null && karakter.length == 1 && karakter.codeUnitAt(0) >= 0x20) {
      _pemindai.Terima(karakter, event.timeStamp);
    }
    return false;
  }

  KeyEventResult _SaatTombolPintasan(FocusNode _, KeyEvent event) {
    if (!widget.aktif || event is! KeyDownEvent) {
      return KeyEventResult.ignored;
    }
    if (event.logicalKey == LogicalKeyboardKey.f1) {
      _fokusCari.requestFocus();
      return KeyEventResult.handled;
    }
    if (event.logicalKey == LogicalKeyboardKey.f2) {
      _BukaPelanggan();
      return KeyEventResult.handled;
    }
    if (event.logicalKey == LogicalKeyboardKey.f4) {
      _BukaCekHarga();
      return KeyEventResult.handled;
    }
    if (event.logicalKey == LogicalKeyboardKey.f8) {
      _BukaBayar();
      return KeyEventResult.handled;
    }
    if (event.logicalKey == LogicalKeyboardKey.f9) {
      _UangPas();
      return KeyEventResult.handled;
    }
    if (event.logicalKey == LogicalKeyboardKey.escape) {
      _Esc();
      return KeyEventResult.handled;
    }
    // K-16: `?` (di luar kolom isian) membuka daftar pintasan keyboard.
    if (event.character == '?' && !_CekFokusDiIsian()) {
      unawaited(_TampilPintasan());
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  /// K-16 (§17.2.7): daftar pintasan keyboard layar Jual.
  static const List<(String, String)> daftarPintasan = [
    ('F1', 'Cari produk'),
    ('F2', 'Pilih pelanggan'),
    ('F4', 'Cek harga tanpa menambah ke keranjang'),
    ('F8', 'Bayar'),
    ('F9', 'Bayar tunai uang pas'),
    ('Esc', 'Tutup panel, kosongkan pencarian, atau hapus item terakhir'),
    ('12*', 'Ketik di kolom cari lalu pindai: tambah 12 unit'),
    ('?', 'Tampilkan daftar ini'),
  ];

  Future<void> _TampilPintasan() async {
    await showDialog<void>(
      context: context,
      builder: (konteks) {
        final teks = Theme.of(konteks).textTheme;
        return AlertDialog(
          title: const Text('Pintasan keyboard'),
          content: SizedBox(
            width: 420,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                for (final (tombol, arti) in daftarPintasan)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SizedBox(width: 56, child: Text(tombol, style: teks.labelLarge)),
                        Expanded(child: Text(arti, style: teks.bodyMedium)),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          actions: [TextButton(onPressed: () => Navigator.of(konteks).pop(), child: const Text('Tutup'))],
        );
      },
    );
    _FokusAkar();
  }

  /// Esc: tutup panel yang terbuka; tanpa panel → hapus item terakhir keranjang. Saat mengetik di kolom cari, Esc
  /// mengosongkan pencarian lebih dulu (tidak menghapus item tanpa sengaja).
  void _Esc() {
    if (_panel == _JenisPanel.Selesai) {
      _TransaksiBaru();
    } else if (_panel != null) {
      _TutupPanel();
    } else if (_fokusCari.hasFocus && _cari.text.isNotEmpty) {
      setState(_cari.clear);
    } else {
      final keranjang = ref.read(penyediaKeranjang);
      if (keranjang.CekKosong) {
        return;
      }
      final terakhir = keranjang.baris.last;
      ref
          .read(penyediaKeranjang.notifier)
          .Ganti(ref.read(penyediaLayananPenjualan).HapusBaris(keranjang, terakhir.uuid));
      _TampilPesan('${terakhir.nama} dihapus dari keranjang.', galat: false);
      _FokusAkar();
    }
  }

  /// F9 uang pas: buka Bayar bila perlu, lalu bayar sisa dengan tunai uang pas dan simpan.
  void _UangPas() {
    if (_panel == _JenisPanel.Bayar) {
      unawaited(_kunciBayar.currentState?.BayarUangPas());
      return;
    }
    if (_panel == _JenisPanel.Selesai) {
      return;
    }
    _BukaBayar();
    if (_panel == _JenisPanel.Bayar) {
      WidgetsBinding.instance.addPostFrameCallback((_) => unawaited(_kunciBayar.currentState?.BayarUangPas()));
    }
  }

  // Aksi keranjang -----------------------------------------------------------------------------------------------------

  /// K-15 (§17.2.7 prinsip 4): pindaian berhasil = bunyi klik + getar ringan + sorot baris keranjang yang bertambah;
  /// gagal (kode tidak dikenal, produk tidak bisa dijual) = bunyi peringatan + getar kuat. Bisa dimatikan di Pengaturan.
  void _TanganiKode(String kode) {
    // K-10: pindaian saat panel Cek harga terbuka hanya menampilkan harga, tidak menambah ke keranjang.
    if (_panel == _JenisPanel.CekHarga) {
      _kunciCekHarga.currentState?.Tampilkan(kode);
      return;
    }
    _TutupLayarSelesai();
    _JalankanPindai(() => _ProsesKode(kode));
  }

  /// K-15: jalankan [proses] pindaian lalu beri umpan balik dari perubahan keranjang/panel.
  void _JalankanPindai(VoidCallback proses) {
    final sebelum = ref.read(penyediaKeranjang).baris;
    final panelSebelum = _panel;
    proses();
    final sesudah = ref.read(penyediaKeranjang).baris;
    final berubah = sesudah
        .where((b) => !sebelum.any((s) => s.uuid == b.uuid && s.jumlah.SamaDengan(b.jumlah)))
        .map((b) => b.uuid)
        .firstOrNull;
    final berhasil = berubah != null || (_panel != panelSebelum && _panel != null);
    final barisBerubah = berubah == null ? null : sesudah.where((b) => b.uuid == berubah).firstOrNull;
    _hasilPindaiTerakhir = (berhasil: berhasil, nama: barisBerubah?.nama, satuan: barisBerubah?.namaSatuan);
    if (ref.read(penyediaPengaturanPerangkat).umpanBalikPindai) {
      final umpanBalik = ref.read(penyediaUmpanBalikPindai);
      unawaited(berhasil ? umpanBalik.Berhasil() : umpanBalik.Gagal());
    }
    if (berubah != null) {
      _pewaktuSorot?.cancel();
      setState(() => _uuidSorot = berubah);
      _pewaktuSorot = Timer(LayarJual.lamaSorot, () {
        if (mounted) {
          setState(() => _uuidSorot = null);
        }
      });
    }
  }

  /// Kamera pemindai: kode yang terbaca masuk lewat jalur yang sama dengan pemindai USB/Bluetooth ([_TanganiKode]),
  /// lalu hasilnya ditulis di strip kamera karena keranjang bisa tertutup di layar HP.
  void _SaatKodeKamera(String kode) {
    // Panel lain (varian, item, pelanggan, ...) sedang meminta perhatian kasir: pindaian tidak boleh mengubah
    // keranjang di belakangnya. Cek harga memang menerima pindaian.
    if (!mounted || (_panel != null && _panel != _JenisPanel.CekHarga)) {
      return;
    }
    final untukCekHarga = _panel == _JenisPanel.CekHarga;
    _hasilPindaiTerakhir = null;
    _pesan = null;
    _TanganiKode(kode);
    final hasil = _hasilPindaiTerakhir;
    final HasilPindaiKamera tampil;
    if (untukCekHarga) {
      tampil = (nomor: ++_nomorHasilKamera, teks: 'Harga $kode ditampilkan di Cek harga.', galat: false);
    } else if (hasil != null && hasil.berhasil) {
      final nama = hasil.nama;
      tampil = (
        nomor: ++_nomorHasilKamera,
        teks: nama == null
            ? 'Lanjutkan di panel yang terbuka.'
            : 'Ditambahkan: $nama${hasil.satuan == null || hasil.satuan!.isEmpty ? '' : ' (${hasil.satuan})'}',
        galat: false,
      );
    } else {
      tampil = (nomor: ++_nomorHasilKamera, teks: _pesan?.teks ?? 'Kode $kode tidak dikenal.', galat: true);
    }
    setState(() => _hasilKamera = tampil);
  }

  void _UbahKamera() {
    setState(() {
      _kameraAktif = !_kameraAktif;
      _hasilKamera = null;
      if (_kameraAktif) {
        _fokusCari.unfocus();
      }
    });
    if (!_kameraAktif) {
      _FokusAkar();
    }
  }

  /// Ingat kamera yang terakhir dipakai kasir (depan/belakang) untuk pembukaan berikutnya.
  void _SimpanArahKamera(ArahKamera arah) {
    final pengaturan = ref.read(penyediaPengaturanPerangkat);
    if (pengaturan.kameraPindai != arah) {
      unawaited(ref.read(penyediaPengaturanPerangkat.notifier).Simpan(pengaturan.copyWith(kameraPindai: arah)));
    }
  }

  void _ProsesKode(String kode) {
    final katalog = ref.read(penyediaKatalog).value;
    final hasil = katalog?.CariKode(kode);
    if (hasil == null && _CobaBarcodeTimbangan(kode)) {
      return;
    }
    if (hasil == null) {
      _TampilPesan('Kode $kode tidak ditemukan di katalog. Perbarui katalog atau cari manual.');
      return;
    }
    // K-8: `12*` di kolom cari lalu pindai = 12 unit produk hasil pindaian.
    final pengali = PengaliJumlah.Urai(_cari.text);
    if (pengali.CekMenunggu) {
      setState(_cari.clear);
    }
    _TambahProduk(hasil.produk, satuan: hasil.satuan, jumlah: pengali.CekMenunggu ? pengali.jumlah : null);
  }

  /// Audit kemudahan pakai: memindai atau mengetuk produk saat layar "Pembayaran berhasil" tampil langsung memulai
  /// transaksi baru (tanpa ketukan "Transaksi baru"), kecuali selesai menagih pesanan meja (kembali ke denah meja).
  void _TutupLayarSelesai() {
    if ((_panel == _JenisPanel.Selesai ||
            _panel == _JenisPanel.PreOrderSelesai ||
            _panel == _JenisPanel.PesananGrosirSelesai) &&
        !_selesaiPesanan) {
      _TransaksiBaru();
    }
  }

  /// BR-03.2: produk diarsipkan tidak dijual lagi. Katalog lokal tetap menyimpannya (pesanan lama, retur, gudang), jadi
  /// jalur tambah ke keranjang (ubin, pindai, SKU, timbangan) menolaknya di sini; true bila ditolak.
  bool _TolakProdukDiarsipkan(ProdukJual produk) {
    if (produk.aktif) {
      return false;
    }
    _TampilPesan(
      '"${produk.nama}" sudah diarsipkan dan tidak dijual lagi. Pulihkan di back-office menu Produk bila masih dijual.',
    );
    return true;
  }

  void _TambahProduk(ProdukJual produk, {SatuanJual? satuan, Kuantitas? jumlah}) {
    _TutupLayarSelesai();
    if (_TolakProdukDiarsipkan(produk)) {
      return;
    }
    // K-9: induk varian membuka pemilih varian (ukuran × warna); induk tanpa varian aktif tetap ditolak dengan pesan.
    if (produk.indukVarian && (ref.read(penyediaKatalog).value?.AmbilVarian(produk.uuid).isNotEmpty ?? false)) {
      setState(() {
        _pesan = null;
        _panel = _JenisPanel.Varian;
        _produkPanel = produk;
        _uuidBarisPanel = null;
      });
      return;
    }
    final alasan = produk.AmbilAlasanTidakBisaDijual();
    if (alasan != null) {
      _TampilPesan(alasan.pesan);
      return;
    }
    // Produk berpilihan dan produk bernomor seri (F-05h) butuh panel: pilihan / nomor seri per unit.
    if (produk.kelompokPilihan.isNotEmpty || produk.bernomorSeri) {
      setState(() {
        _pesan = null;
        _panel = _JenisPanel.Item;
        _produkPanel = produk;
        _uuidBarisPanel = null;
      });
      return;
    }
    if (produk.hargaTerbuka) {
      unawaited(_TambahHargaTerbuka(produk, satuan: satuan, jumlah: jumlah));
      return;
    }
    _TambahBaris(produk, satuan: satuan, jumlah: jumlah);
  }

  /// K-25: produk harga terbuka meminta harga (harga daftar jadi isian awal) & keterangan sebelum masuk keranjang.
  Future<void> _TambahHargaTerbuka(ProdukJual produk, {SatuanJual? satuan, Kuantitas? jumlah}) async {
    final katalog = ref.read(penyediaKatalog).value;
    final k = ref.read(penyediaKonteksPenjualan).value;
    final satuanJual = satuan ?? produk.AmbilSatuanBawaan();
    if (katalog == null || k == null) {
      return;
    }
    final keranjang = ref.read(penyediaKeranjang);
    final saran = satuanJual == null
        ? null
        : ref
              .read(penyediaLayananPenjualan)
              .TentukanHarga(
                katalog,
                k,
                produk.uuid,
                satuanJual.uuid,
                jumlah ?? Kuantitas.DariBulat(1),
                kanal: LayananPenjualan.AmbilKanal(keranjang),
                tierPelanggan: keranjang.pelanggan?.kodeTier,
              );
    final hasil = await showDialog<HargaTerbukaDiketik>(
      context: context,
      builder: (_) => DialogHargaTerbuka(namaProduk: produk.nama, namaSatuan: satuanJual?.nama, saran: saran),
    );
    if (hasil == null || !mounted) {
      return;
    }
    _TambahBaris(produk, satuan: satuan, jumlah: jumlah, hargaManual: hasil.harga, catatan: hasil.keterangan);
  }

  void _TambahBaris(ProdukJual produk, {SatuanJual? satuan, Kuantitas? jumlah, Uang? hargaManual, String? catatan}) {
    final katalog = ref.read(penyediaKatalog).value;
    final k = ref.read(penyediaKonteksPenjualan).value;
    if (katalog == null || k == null) {
      return;
    }
    final layanan = ref.read(penyediaLayananPenjualan);
    try {
      final baris = layanan.BuatBaris(
        katalog,
        k,
        produk,
        satuan: satuan,
        jumlah: jumlah,
        kanal: LayananPenjualan.AmbilKanal(ref.read(penyediaKeranjang)),
        tierPelanggan: ref.read(penyediaKeranjang).pelanggan?.kodeTier,
        hargaManual: hargaManual,
        catatan: catatan,
      );
      ref.read(penyediaKeranjang.notifier).Ganti(layanan.TambahBaris(ref.read(penyediaKeranjang), baris, katalog, k));
      if (_pesan != null) {
        setState(() => _pesan = null);
      }
      if (produk.berBatch) {
        unawaited(_PeriksaBatch(produk));
      }
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
    }
  }

  /// K-19: peringatan bila batch yang akan terjual lebih dulu (FEFO server) sudah/hampir kedaluwarsa. Tidak memblokir
  /// penjualan; offline = diam.
  Future<void> _PeriksaBatch(ProdukJual produk) async {
    final peringatan = await ref.read(penyediaLayananInfoBatch).PeriksaSaatJual(produk.uuid, produk.nama);
    if (peringatan != null && mounted) {
      _TampilPesan(peringatan.teks, galat: peringatan.lewat);
    }
  }

  /// v3.55 (§9.3): barcode timbangan `AA PPPPP NNNNN C`. Produk dicari dari 7 digit pertama; jumlah = berat (kg) atau
  /// harga label ÷ harga satuan (3 desimal). True = kode ini barcode timbangan (sudah ditangani, termasuk pesan galat).
  bool _CobaBarcodeTimbangan(String kode, {bool umpanBalik = false}) {
    final katalog = ref.read(penyediaKatalog).value;
    final k = ref.read(penyediaKonteksPenjualan).value;
    if (katalog == null || k == null) {
      return false;
    }
    final urai = PenguraiBarcodeTimbangan.Urai(kode, k.barcodeTimbangan);
    if (urai == null) {
      return false;
    }
    if (umpanBalik) {
      _JalankanPindai(() => _CobaBarcodeTimbangan(kode));
      return true;
    }
    final cocok = katalog.CariKode(urai.kodeProduk);
    if (cocok == null) {
      _TampilPesan(
        'Barcode timbangan ${urai.kodeProduk} belum terdaftar. Isi barcode produknya dengan ${urai.kodeProduk} '
        'di back-office.',
      );
      return true;
    }
    final produk = cocok.produk;
    if (_TolakProdukDiarsipkan(produk)) {
      return true;
    }
    final satuan = cocok.satuan ?? produk.AmbilSatuanBawaan();
    if (satuan == null || !satuan.bolehDesimal) {
      _TampilPesan('Satuan ${produk.nama} tidak boleh desimal, jadi tidak bisa dijual per berat timbangan.');
      return true;
    }
    final keranjang = ref.read(penyediaKeranjang);
    final layanan = ref.read(penyediaLayananPenjualan);
    Kuantitas jumlah;
    if (urai.harga) {
      final harga = layanan.TentukanHarga(
        katalog,
        k,
        produk.uuid,
        satuan.uuid,
        Kuantitas.DariBulat(1),
        kanal: LayananPenjualan.AmbilKanal(keranjang),
        tierPelanggan: keranjang.pelanggan?.kodeTier,
      );
      if (harga == null || harga.BernilaiNol()) {
        _TampilPesan('Harga ${produk.nama} belum diatur, jadi berat dari label harga tidak bisa dihitung.');
        return true;
      }
      jumlah = Kuantitas.DariPembagian(Uang.Dari(urai.nilai).KeDesimal(), harga.KeDesimal());
    } else {
      jumlah = Kuantitas.Dari(urai.nilai);
    }
    try {
      final baris = layanan.BuatBaris(
        katalog,
        k,
        produk,
        satuan: satuan,
        jumlah: jumlah,
        kanal: LayananPenjualan.AmbilKanal(keranjang),
        tierPelanggan: keranjang.pelanggan?.kodeTier,
      );
      ref.read(penyediaKeranjang.notifier).Ganti(layanan.TambahBaris(keranjang, baris, katalog, k));
      if (_pesan != null) {
        setState(() => _pesan = null);
      }
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
    }
    return true;
  }

  /// Baris yang sudah tersimpan di pesanan meja (bukan item baru).
  bool _CekBarisTersimpan(String uuidBaris) =>
      ref.read(penyediaKeranjangEfektif).pesananMeja?.CekTersimpan(uuidBaris) ?? false;

  void _GeserJumlah(String uuidBaris, int arah) {
    if (_CekBarisTersimpan(uuidBaris)) {
      if (arah > 0) {
        _PesanLagi(uuidBaris);
        return;
      }
      _TampilPesan('Item yang sudah dipesan tidak bisa dikurangi di sini. Ketuk item lalu pilih "Batalkan item".');
      return;
    }
    final katalog = ref.read(penyediaKatalog).value ?? KatalogLokal.kosong;
    final k = ref.read(penyediaKonteksPenjualan).value;
    if (k == null) {
      return;
    }
    final keranjang = ref.read(penyediaKeranjang);
    final baris = keranjang.baris.firstWhere((b) => b.uuid == uuidBaris);
    try {
      ref
          .read(penyediaKeranjang.notifier)
          .Ganti(
            ref
                .read(penyediaLayananPenjualan)
                .UbahJumlah(keranjang, uuidBaris, baris.jumlah.Tambah(Kuantitas.DariBulat(arah)), katalog, k),
          );
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
    }
  }

  /// Audit kemudahan pakai #29: "+" pada item meja yang sudah dipesan menambah baris baru yang sama (pilihan, catatan,
  /// harga ikut) sebagai item baru yang akan dikirim ke dapur; item tersimpannya tidak diubah.
  void _PesanLagi(String uuidBaris) {
    final asal = ref.read(penyediaKeranjangEfektif).baris.where((b) => b.uuid == uuidBaris).firstOrNull;
    final k = ref.read(penyediaKonteksPenjualan).value;
    if (asal == null || k == null) {
      return;
    }
    final layanan = ref.read(penyediaLayananPenjualan);
    final baru = ItemKeranjang(
      uuid: layanan.BuatUuid(),
      uuidProduk: asal.uuidProduk,
      nama: asal.nama,
      uuidProdukSatuan: asal.uuidProdukSatuan,
      namaSatuan: asal.namaSatuan,
      bolehDesimal: asal.bolehDesimal,
      jumlah: Kuantitas.DariBulat(1),
      hargaSatuan: asal.hargaSatuan,
      pilihan: asal.pilihan,
      catatan: asal.catatan,
      hargaTermasukPajak: asal.hargaTermasukPajak,
      pajak: asal.pajak,
      hargaTerbuka: asal.hargaTerbuka,
      hargaAsli: asal.hargaAsli,
      alasanHarga: asal.alasanHarga,
      penyetujuHarga: asal.penyetujuHarga,
    );
    try {
      final katalog = ref.read(penyediaKatalog).value ?? KatalogLokal.kosong;
      ref.read(penyediaKeranjang.notifier).Ganti(layanan.TambahBaris(ref.read(penyediaKeranjang), baru, katalog, k));
      _TampilPesan('${asal.nama} ditambah 1 sebagai item baru.');
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
    }
  }

  void _UbahBaris(String uuidBaris) {
    if (_CekBarisTersimpan(uuidBaris)) {
      unawaited(_BukaRincianTersimpan(uuidBaris));
      return;
    }
    final baris = ref.read(penyediaKeranjang).baris.firstWhere((b) => b.uuid == uuidBaris);
    setState(() {
      _panel = _JenisPanel.Item;
      _uuidBarisPanel = uuidBaris;
      _produkPanel = ref.read(penyediaKatalog).value?.CariProduk(baris.uuidProduk);
    });
  }

  /// Audit kemudahan pakai #29: ketuk item meja tersimpan membuka rinciannya dulu (bukan langsung tawaran batal).
  Future<void> _BukaRincianTersimpan(String uuidBaris) async {
    final baris = ref.read(penyediaKeranjangEfektif).pesananMeja?.CariBaris(uuidBaris);
    final item = ref.read(penyediaKeranjangEfektif).baris.where((b) => b.uuid == uuidBaris).firstOrNull;
    if (baris == null || item == null) {
      return;
    }
    final pilihan = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (konteks) {
        final teks = Theme.of(konteks).textTheme;
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(TokenJarak.jarak16, 0, TokenJarak.jarak16, TokenJarak.jarak16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(item.nama, style: teks.titleMedium),
                const SizedBox(height: TokenJarak.jarak4),
                Text(
                  '${FormatAngka.FormatJumlah(item.jumlah)} × ${item.hargaSatuan.FormatRupiah()} | ${baris.AmbilLabelStatus()}',
                ),
                if ((item.catatan ?? '').isNotEmpty) Text('Catatan: ${item.catatan}'),
                const SizedBox(height: TokenJarak.jarak12),
                OutlinedButton.icon(
                  onPressed: () => Navigator.of(konteks).pop('Lagi'),
                  icon: const Icon(Icons.add),
                  label: const Text('Pesan 1 lagi'),
                ),
                const SizedBox(height: TokenJarak.jarak8),
                FilledButton.tonalIcon(
                  onPressed: () => Navigator.of(konteks).pop('Batal'),
                  icon: const Icon(Icons.remove_circle_outline),
                  label: const Text('Batalkan item'),
                ),
              ],
            ),
          ),
        );
      },
    );
    if (!mounted) {
      return;
    }
    if (pilihan == 'Lagi') {
      _PesanLagi(uuidBaris);
    } else if (pilihan == 'Batal') {
      await _BatalkanBarisTersimpan(uuidBaris);
    }
  }

  Future<void> _BatalkanBarisTersimpan(String uuidBaris) async {
    final konteks = ref.read(penyediaKeranjangEfektif).pesananMeja;
    final baris = konteks?.CariBaris(uuidBaris);
    if (konteks == null || baris == null) {
      return;
    }
    final pesan = await BatalkanBarisPesanan(
      context,
      ref,
      kasir: widget.kasir,
      uuidPesanan: konteks.uuid,
      baris: baris,
    );
    if (pesan != null && mounted) {
      _TampilPesan(pesan, galat: !pesan.endsWith('dibatalkan.'));
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
    }
    _FokusAkar();
  }

  /// Mode meja: simpan item baru ke pesanan dan kirim ke dapur (termasuk item tersimpan yang belum dikirim).
  /// Ketuk ganda diabaikan selama pengiriman sebelumnya belum selesai; tanpa penjaga ini dua tiket dapur tercetak.
  Future<void> _KirimDapur() async {
    if (_mengirimDapur) {
      return;
    }
    if (ref.read(penyediaModeLatihan)) {
      _TampilPesan('Mode latihan hidup, jadi pesanan tidak dikirim ke dapur. Matikan dulu di Pengaturan.', galat: true);
      return;
    }
    _mengirimDapur = true;
    try {
      await _KirimDapurSekali();
    } finally {
      _mengirimDapur = false;
    }
  }

  Future<void> _KirimDapurSekali() async {
    final draf = ref.read(penyediaKeranjang);
    final konteks = draf.pesananMeja;
    if (konteks == null) {
      return;
    }
    final pemberitahu = ScaffoldMessenger.maybeOf(context);
    try {
      final sebelum = await ref.read(penyediaRepositoriPesananMeja).CariPesanan(konteks.uuid);
      final kursus = _kursusBaru;
      if (_kursusDitahan) {
        // K-13: kursus Utama/Penutup disimpan & ditahan; dikirim nanti lewat tombol "Kirim Utama".
        await ref
            .read(penyediaLayananPesananMeja)
            .SimpanBaris(
              uuidPesanan: konteks.uuid,
              draf: draf.baris,
              kasir: widget.kasir,
              kirimDapur: false,
              kursus: kursus,
            );
        ref.read(penyediaKeranjang.notifier).Ganti(draf.Salin(baris: const []));
        setState(() => _kursusBaru = null);
        _TampilPesan('Item $kursus ${konteks.AmbilJudul()} disimpan & ditahan.', galat: false);
        unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
        _FokusAkar();
        return;
      }
      final dikirim = {
        ...draf.baris.map((b) => b.uuid),
        ...?sebelum?.AmbilBarisAktif()
            .where((b) => !b.dikirimKeDapur && (b.kursus == null || b.kursus == kursus))
            .map((b) => b.uuid),
      };
      final pesanan = await ref
          .read(penyediaLayananPesananMeja)
          .SimpanBaris(
            uuidPesanan: konteks.uuid,
            draf: draf.baris,
            kasir: widget.kasir,
            kirimDapur: true,
            kursus: kursus,
          );
      if (widget.modePelayan) {
        // Pelayan: pesanan selesai dicatat, kembali ke denah meja untuk tamu berikutnya (pesan tampil di atas denah).
        ref.read(penyediaKeranjang.notifier).Kosongkan();
        widget.saatKeMeja?.call();
        pemberitahu?.showSnackBar(SnackBar(content: Text('Pesanan ${konteks.AmbilJudul()} dikirim ke dapur.')));
      } else {
        ref.read(penyediaKeranjang.notifier).Ganti(draf.Salin(baris: const []));
        _TampilPesan('Pesanan ${konteks.AmbilJudul()} dikirim ke dapur.', galat: false);
      }
      if (mounted && _kursusBaru != null) {
        setState(() => _kursusBaru = null);
      }
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
      // Cetak struk bagian 4c: tiket per stasiun yang punya printer di perangkat ini; gagal cetak tidak membatalkan.
      final tiket = await ref
          .read(penyediaLayananTiketDapur)
          .Cetak(
            pesanan: pesanan,
            uuidBaris: dikirim,
            katalog: ref.read(penyediaKatalog).value ?? KatalogLokal.kosong,
            waktu: ref.read(penyediaJam)(),
            namaKasir: widget.kasir.nama,
          );
      final gagal = tiket.where((t) => t.galat != null).toList();
      if (gagal.isNotEmpty && mounted) {
        _TampilPesan(
          'Pesanan terkirim, tetapi tiket ${gagal.map((t) => t.stasiun.nama).join(', ')} gagal dicetak: '
          '${gagal.first.galat} Periksa printer dapur di Pengaturan.',
        );
      }
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
    }
    _FokusAkar();
  }

  /// K-13: kirim (fire) kursus yang ditahan ke dapur, lalu cetak tiketnya.
  Future<void> _KirimKursus(String kursus) async {
    final konteks = ref.read(penyediaKeranjang).pesananMeja;
    if (konteks == null) {
      return;
    }
    try {
      final hasil = await ref
          .read(penyediaLayananPesananMeja)
          .KirimKursus(uuidPesanan: konteks.uuid, kursus: kursus, kasir: widget.kasir);
      _TampilPesan('$kursus ${konteks.AmbilJudul()} dikirim ke dapur.', galat: false);
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
      final tiket = await ref
          .read(penyediaLayananTiketDapur)
          .Cetak(
            pesanan: hasil.pesanan,
            uuidBaris: hasil.uuidBaris,
            katalog: ref.read(penyediaKatalog).value ?? KatalogLokal.kosong,
            waktu: ref.read(penyediaJam)(),
            namaKasir: widget.kasir.nama,
          );
      final gagal = tiket.where((t) => t.galat != null).toList();
      if (gagal.isNotEmpty && mounted) {
        _TampilPesan(
          '$kursus terkirim, tetapi tiket ${gagal.map((t) => t.stasiun.nama).join(', ')} gagal dicetak: '
          '${gagal.first.galat} Periksa printer dapur di Pengaturan.',
        );
      }
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
    }
    _FokusAkar();
  }

  /// Mode meja: tutup pesanan dari layar Jual (pesanan tetap terbuka di meja). Item baru yang belum dikirim hilang,
  /// jadi dikonfirmasi dulu.
  Future<void> _TutupPesanan() async {
    final draf = ref.read(penyediaKeranjang);
    final konteks = draf.pesananMeja;
    if (konteks == null) {
      return;
    }
    if (!draf.CekKosong) {
      final ya = await showDialog<bool>(
        context: context,
        builder: (konteksDialog) => AlertDialog(
          title: Text('Tutup ${konteks.AmbilJudul()}?'),
          content: const Text(
            'Item baru yang belum dikirim ke dapur akan dihapus. Pesanan yang sudah tersimpan tetap ada.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(konteksDialog).pop(false), child: const Text('Kembali')),
            FilledButton(onPressed: () => Navigator.of(konteksDialog).pop(true), child: const Text('Hapus item baru')),
          ],
        ),
      );
      if (ya != true) {
        _FokusAkar();
        return;
      }
    }
    ref.read(penyediaKeranjang.notifier).Kosongkan();
    _TutupPanel();
    widget.saatKeMeja?.call();
  }

  /// K-10 (F4): cek harga tanpa menambah ke keranjang.
  /// Audit kemudahan pakai #10: cetak ulang struk transaksi terakhir perangkat ini tanpa membuka Riwayat.
  Future<void> _CetakUlangTerakhir() async {
    final terakhir = await ref.read(penyediaRepositoriPenjualan).AmbilPenjualanTerakhir();
    if (!mounted) {
      return;
    }
    if (terakhir == null) {
      _TampilPesan('Belum ada transaksi di perangkat ini.');
      return;
    }
    final galat = await ref.read(penyediaPrinter.notifier).CetakPenjualan(terakhir.Uuid, cetakUlang: true);
    if (mounted) {
      _TampilPesan(galat ?? 'Struk ${terakhir.Nomor} dicetak ulang.', galat: galat != null);
    }
  }

  void _BukaCekHarga() {
    if (_panel == _JenisPanel.Bayar || _panel == _JenisPanel.Selesai) {
      return;
    }
    setState(() {
      _panel = _JenisPanel.CekHarga;
      _pesan = null;
    });
  }

  void _BukaPelanggan() {
    if (widget.modePelayan || _panel == _JenisPanel.Bayar || _panel == _JenisPanel.Selesai) {
      return;
    }
    setState(() {
      _panel = _JenisPanel.Pelanggan;
      _pesan = null;
    });
  }

  List<KanalPenjualan> _AmbilPilihanKanal() {
    final k = ref.watch(penyediaKonteksPenjualan).value;
    final katalog = ref.watch(penyediaKatalog).value;
    return k == null || katalog == null ? const [] : LayananPenjualan.AmbilPilihanKanal(k, katalog);
  }

  /// X8: pilih kanal penjualan; harga semua baris ditentukan ulang menurut daftar harga kanal baru.
  Future<void> _PilihKanal() async {
    final pilihan = _AmbilPilihanKanal();
    final sekarang = LayananPenjualan.AmbilKanal(ref.read(penyediaKeranjang));
    final kanal = await showDialog<KanalPenjualan>(
      context: context,
      builder: (konteks) => SimpleDialog(
        title: const Text('Kanal penjualan'),
        children: [
          for (final p in pilihan)
            ListTile(
              key: ValueKey('Kanal-${p.name}'),
              leading: Icon(
                LayananPenjualan.kanalPlatform.contains(p) ? Icons.delivery_dining : Icons.storefront_outlined,
              ),
              title: Text(LayananPenjualan.AmbilLabelKanal(p)),
              subtitle: LayananPenjualan.kanalPlatform.contains(p)
                  ? const Text('Pesanan dari aplikasi; harga & pembayaran platform')
                  : null,
              trailing: p == sekarang ? const Icon(Icons.check) : null,
              onTap: () => Navigator.of(konteks).pop(p),
            ),
        ],
      ),
    );
    if (kanal == null || kanal == sekarang || !mounted) {
      return;
    }
    _GantiKanal(kanal);
  }

  /// Ganti kanal/jenis pesanan; harga baris ditentukan ulang menurut daftar harga kanal baru (X8, v3.51).
  void _GantiKanal(KanalPenjualan kanal) {
    final k = ref.read(penyediaKonteksPenjualan).value;
    final katalog = ref.read(penyediaKatalog).value;
    final keranjang = ref.read(penyediaKeranjang);
    if (k == null || katalog == null || kanal == LayananPenjualan.AmbilKanal(keranjang)) {
      return;
    }
    ref
        .read(penyediaKeranjang.notifier)
        .Ganti(ref.read(penyediaLayananPenjualan).GantiKanal(keranjang, kanal, katalog, k));
    if (!keranjang.CekKosong) {
      _TampilPesan('${LayananPenjualan.AmbilLabelKanal(kanal)}. Harga keranjang disesuaikan.', galat: false);
    }
  }

  /// v3.52 (§9.2): nama pemesan yang dipanggil saat pesanan bayar-dulu siap (dicetak di struk & tiket dapur).
  Future<void> _IsiNamaPemesan() async {
    final awal = ref.read(penyediaKeranjang).namaPemesan ?? '';
    final nama = await showDialog<String>(
      context: context,
      builder: (_) => _DialogNamaPemesan(awal: awal),
    );
    if (nama == null || !mounted) {
      _FokusAkar();
      return;
    }
    final rapi = nama.trim();
    ref
        .read(penyediaKeranjang.notifier)
        .Ganti(ref.read(penyediaKeranjang).Salin(namaPemesan: () => rapi.isEmpty ? null : rapi));
    _FokusAkar();
  }

  /// v3.29: isi ongkir kotor kanal Antar. Potongan gratis ongkir dihitung mesin promo dari promo yang berlaku.
  Future<void> _IsiOngkir() async {
    final awal = ref.read(penyediaKeranjang).biayaKirim;
    final teks = await showDialog<String>(
      context: context,
      builder: (_) => _DialogOngkir(awal: awal),
    );
    if (teks == null || !mounted) {
      return;
    }
    try {
      final ongkir = MasukanUang.UraiTeks(teks) ?? Uang.Nol();
      ref
          .read(penyediaKeranjang.notifier)
          .Ganti(ref.read(penyediaLayananPenjualan).AturOngkir(ref.read(penyediaKeranjang), ongkir));
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
    }
  }

  void _BukaTertahan() => setState(() {
    _panel = _JenisPanel.Tertahan;
    _pesan = null;
  });

  void _BukaBayar() {
    if (widget.modePelayan) {
      return;
    }
    if (ref.read(penyediaKeranjangEfektif).CekKosong) {
      _TampilPesan('Keranjang masih kosong. Tambahkan produk dulu.');
      return;
    }
    final konteks = ref.read(penyediaKeranjang).pesananMeja;
    if (konteks != null) {
      unawaited(_BukaBayarPesanan(konteks));
      return;
    }
    setState(() {
      _pesan = null;
      _panel = _JenisPanel.Bayar;
    });
  }

  /// Mode meja: item baru disimpan ke pesanan (tanpa dikirim ke dapur), lalu kunci bayar online diambil agar perangkat
  /// lain tidak membayar pesanan yang sama. Offline tetap bisa bayar.
  Future<void> _BukaBayarPesanan(KonteksPesananMeja konteks) async {
    final layanan = ref.read(penyediaLayananPesananMeja);
    final draf = ref.read(penyediaKeranjang);
    try {
      if (!draf.CekKosong) {
        await layanan.SimpanBaris(uuidPesanan: konteks.uuid, draf: draf.baris, kasir: widget.kasir, kirimDapur: false);
        ref.read(penyediaKeranjang.notifier).Ganti(draf.Salin(baris: const []));
      }
      await layanan.KunciBayar(konteks.uuid);
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
      return;
    }
    if (!mounted) {
      return;
    }
    _uuidKunciBayar = konteks.uuid;
    _pewaktuKunciBayar?.cancel();
    _pewaktuKunciBayar = Timer.periodic(LayarJual.selangKunciBayar, (_) {
      final uuid = _uuidKunciBayar;
      if (uuid != null) {
        unawaited(layanan.KunciBayar(uuid).catchError((Object _) {}));
      }
    });
    setState(() {
      _pesan = null;
      _panel = _JenisPanel.Bayar;
    });
  }

  /// Lepas kunci bayar pesanan meja (panel Bayar ditutup tanpa bayar). Setelah dibayar server melepasnya sendiri.
  void _LepasKunciBayar({bool keServer = true}) {
    _pewaktuKunciBayar?.cancel();
    _pewaktuKunciBayar = null;
    final uuid = _uuidKunciBayar;
    _uuidKunciBayar = null;
    if (uuid != null && keServer) {
      unawaited(ref.read(penyediaLayananPesananMeja).LepasKunciBayar(uuid));
    }
  }

  Future<void> _Tahan() async {
    final keranjang = ref.read(penyediaKeranjang);
    if (keranjang.tukar != null) {
      // Pesanan tertahan tidak membawa retur tukar barang; menahannya akan membuang retur & PIN penyetuju diam-diam.
      _TampilPesan('Tukar barang sedang berjalan. Selesaikan pembayarannya atau batalkan dulu; tidak bisa ditahan.');
      return;
    }
    final k = await ref.read(penyediaKonteksPenjualan.future);
    final layanan = ref.read(penyediaLayananPenjualan);
    try {
      await layanan.TahanPesanan(keranjang, widget.kasir, layanan.Hitung(keranjang, k).hasil.totalAkhir);
      ref.read(penyediaKeranjang.notifier).Kosongkan();
      _TutupPanel();
      _TampilPesan('Pesanan ditahan. Buka lagi lewat tombol Tertahan.', galat: false);
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
    }
  }

  Future<void> _KonfirmasiBatal() async {
    final tukar = ref.read(penyediaKeranjang).tukar != null;
    if (ref.read(penyediaKeranjang).CekKosong && !tukar) {
      return;
    }
    final warna = TokenWarna.AmbilDari(context);
    final ya = await showDialog<bool>(
      context: context,
      builder: (konteks) => AlertDialog(
        title: Text(tukar ? 'Batalkan tukar barang?' : 'Batalkan transaksi ini?'),
        content: Text(
          tukar
              ? 'Retur tukar barang yang belum disimpan dibatalkan dan keranjang dikosongkan. Barang yang dikembalikan '
                    'pelanggan tidak tercatat.'
              : 'Semua item di keranjang dihapus. Transaksi yang belum dibayar tidak disimpan.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Kembali')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: warna.bahaya),
            onPressed: () => Navigator.of(konteks).pop(true),
            child: const Text('Batalkan transaksi'),
          ),
        ],
      ),
    );
    if (ya == true) {
      // F-16c bagian 2: voucher yang sudah dipesan dilepas agar bisa dipakai transaksi lain.
      if (ref.read(penyediaKeranjang).voucher case final voucher?) {
        unawaited(ref.read(penyediaLayananVoucher).Lepas(voucher));
      }
      ref.read(penyediaKeranjang.notifier).Kosongkan();
      _TutupPanel();
    }
    _FokusAkar();
  }

  void _TutupPanel() {
    if (!mounted) {
      return;
    }
    if (_panel == _JenisPanel.Bayar) {
      _LepasKunciBayar();
    }
    setState(() {
      _panel = null;
      _produkPanel = null;
      _uuidBarisPanel = null;
    });
    _FokusAkar();
  }

  void _TransaksiBaru() {
    final keMeja = _selesaiPesanan;
    setState(() {
      _selesai = null;
      _pesan = null;
      _selesaiPesanan = false;
    });
    _TutupPanel();
    if (keMeja) {
      widget.saatKeMeja?.call();
    }
  }

  Future<void> _PerbaruiKatalog({bool manual = false}) async {
    if (_memperbarui) {
      return;
    }
    setState(() => _memperbarui = true);
    final hasil = await ref.read(penyediaSesi.notifier).PerbaruiKatalog();
    if (!mounted) {
      return;
    }
    // F-17 BR-17.2: keadaan "habis" ikut disegarkan bersama katalog (gagal/offline = keadaan terakhir tetap dipakai).
    unawaited(ref.read(penyediaProdukHabis.notifier).Muat());
    setState(() => _memperbarui = false);
    if (manual) {
      switch (hasil) {
        case HasilPerbaruiKatalog.Lengkap || HasilPerbaruiKatalog.Delta:
          _TampilPesan('Katalog sudah diperbarui.', galat: false);
        case HasilPerbaruiKatalog.Offline:
          _TampilPesan('Belum tersambung ke server. Katalog di perangkat tetap dipakai.');
        case HasilPerbaruiKatalog.Gagal:
          _TampilPesan('Katalog gagal diperbarui. Coba lagi beberapa saat lagi.');
      }
    }
  }

  /// F-17 BR-17.2: tandai [produk] habis atau tersedia lagi di outlet ini (wajib online; keadaannya dipakai bersama
  /// semua perangkat, menu self-order, dan toko online).
  Future<void> _UbahKetersediaan(ProdukJual produk, {required bool habis}) async {
    final lanjut = await showDialog<bool>(
      context: context,
      builder: (konteks) => AlertDialog(
        title: Text(habis ? 'Tandai ${produk.nama} habis?' : 'Tandai ${produk.nama} tersedia lagi?'),
        content: Text(
          habis
              ? 'Produk tidak bisa ditambahkan di kasir dan hilang dari menu self-order dan toko online outlet ini. '
                    'Stok tidak berubah.'
              : 'Produk bisa dijual lagi di kasir, menu self-order, dan toko online outlet ini.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(konteks).pop(false), child: const Text('Batal')),
          FilledButton(
            onPressed: () => Navigator.of(konteks).pop(true),
            child: Text(habis ? 'Tandai habis' : 'Tandai tersedia'),
          ),
        ],
      ),
    );
    if (lanjut != true || !mounted) {
      return;
    }
    try {
      await ref.read(penyediaProdukHabis.notifier).Ubah(produk.uuid, habis: habis, kasir: widget.kasir);
      if (mounted) {
        _TampilPesan(habis ? '${produk.nama} ditandai habis.' : '${produk.nama} tersedia lagi.', galat: false);
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        _TampilPesan(galat.pesan);
      }
    }
  }

  Future<void> _SegarkanProdukHabis() async {
    if (widget.aktif && ref.read(penyediaKoneksi) == StatusKoneksi.Online) {
      await ref.read(penyediaProdukHabis.notifier).Muat();
      if (!mounted) {
        return;
      }
      // BR-05.2: salinan sisa stok Toko ikut disegarkan (penjualan di perangkat lain, penerimaan barang, dll.).
      await ref.read(penyediaSesi.notifier).SegarkanStokTersedia();
    }
  }

  /// BR-05.2: ketuk produk yang sisa stoknya nol atau kurang. Pesannya sama dengan penolakan saat menambah (jumlah
  /// stok, atau "semuanya sudah ada di keranjang"), tanpa membuka panel pilihan/nomor seri dulu.
  void _TampilStokKosong(ProdukJual produk) {
    _TutupLayarSelesai();
    final katalog = ref.read(penyediaKatalog).value;
    if (katalog == null) {
      return;
    }
    try {
      ref
          .read(penyediaLayananPenjualan)
          .PastikanStokCukup(
            ref.read(penyediaKeranjang),
            katalog,
            uuidProduk: produk.uuid,
            nama: produk.nama,
            jumlahDasarBaru: Kuantitas.DariBulat(1),
          );
    } on GalatKasir catch (galat) {
      _TampilPesan(galat.pesan);
      return;
    }
    // Sisa berubah di antara tampilan dan ketukan (kini cukup): tambahkan seperti ketukan biasa.
    _TambahProduk(produk);
  }

  Future<void> _PerbaruiBerkala() async {
    final keranjang = ref.read(penyediaKeranjang);
    if (ref.read(penyediaKoneksi) == StatusKoneksi.Online &&
        keranjang.CekKosong &&
        keranjang.pesananMeja == null &&
        _panel == null) {
      await _PerbaruiKatalog();
      if (mounted) {
        // Audit kesegaran data (P1): data awal ikut disegarkan tiap 10 menit selama kasir bekerja.
        await ref.read(penyediaSesi.notifier).SegarkanDataAwalBerkala();
      }
    }
  }

  // Tampilan -----------------------------------------------------------------------------------------------------------

  /// K-16: produk yang tampil di katalog. Kategori Terlaris = produk terlaris yang masih tampil, urut terlaris dulu.
  List<ProdukJual>? _AmbilDaftarProduk(KatalogLokal? katalog, List<String> terlaris) {
    if (katalog == null) {
      return null;
    }
    final kata = PengaliJumlah.Urai(_cari.text).sisa;
    if (_uuidKategori == LayarJual.kategoriFavorit) {
      // Favorit dicabut pemilik selagi kategori ini terpilih: chip-nya hilang, jadi tampilkan semua produk.
      return katalog.adaFavorit ? katalog.AmbilFavorit(kata: kata) : katalog.AmbilTampil(kata: kata);
    }
    if (_uuidKategori != LayarJual.kategoriTerlaris) {
      return katalog.AmbilTampil(uuidKategori: _uuidKategori, kata: kata);
    }
    final tampil = {for (final p in katalog.AmbilTampil(kata: kata)) p.uuid: p};
    return [for (final uuid in terlaris) ?tampil[uuid]];
  }

  /// K-16: kategori terakhir diingat per perangkat; kategori yang sudah tidak ada kembali ke Semua.
  Future<void> _MuatKategoriTerakhir() async {
    final tersimpan = await ref.read(penyediaRepositori).AmbilPengaturan(KunciPengaturan.kategoriTerakhirJual);
    if (tersimpan == null || tersimpan.isEmpty || !mounted) {
      return;
    }
    final katalog = await ref.read(penyediaKatalog.future);
    if (!mounted || _uuidKategori != null) {
      return;
    }
    if (tersimpan == LayarJual.kategoriTerlaris ||
        (tersimpan == LayarJual.kategoriFavorit && katalog.adaFavorit) ||
        katalog.kategori.any((k) => k.Uuid == tersimpan)) {
      setState(() => _uuidKategori = tersimpan);
    }
  }

  void _PilihKategori(String? uuid) {
    setState(() => _uuidKategori = uuid);
    unawaited(ref.read(penyediaRepositori).SimpanPengaturan(KunciPengaturan.kategoriTerakhirJual, uuid ?? ''));
  }

  Widget _BangunKatalog(BuildContext context, KatalogLokal? katalog, KonteksPenjualan? k, bool sempit) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final tepi = sempit ? TokenJarak.jarak16 : TokenJarak.jarak24;
    final tertahan = ref.watch(penyediaPesananTertahan).value?.length ?? 0;
    final terlaris = ref.watch(penyediaProdukTerlaris).value ?? const <String>[];
    final daftar = _AmbilDaftarProduk(katalog, terlaris) ?? const <ProdukJual>[];
    final layanan = ref.read(penyediaLayananPenjualan);
    final kanal = LayananPenjualan.AmbilKanal(ref.watch(penyediaKeranjang));
    final tier = ref.watch(penyediaKeranjang.select((k) => k.pelanggan?.kodeTier));
    final habis = ref.watch(penyediaProdukHabis);
    // BR-05.2: tampilan "Habis" karena stok dihitung ulang saat salinan stok, penjualan lokal, atau keranjang berubah.
    ref.watch(penyediaVersiStokTersedia);
    final keranjangJual = ref.watch(penyediaKeranjang);
    final pesan = _pesan;
    // K-8: Retail & Grosir (atau pilihan perangkat) memakai daftar ringkas, mode lain ubin bergambar.
    final daftarRingkas =
        ref
            .watch(penyediaPengaturanPerangkat.select((p) => p.tampilanKatalog))
            .Tentukan(ref.watch(penyediaModeKasir).value) ==
        TampilanKatalog.Daftar;

    /// Harga, keadaan, dan aksi satu produk; sama untuk ubin & baris daftar.
    ({Uang? harga, bool nonaktif, String? keterangan, VoidCallback saatDiketuk, VoidCallback saatDitahan}) BangunItem(
      ProdukJual p,
    ) {
      final satuan = p.AmbilSatuanBawaan();
      final adaVarian = p.indukVarian && (katalog?.AmbilVarian(p.uuid).isNotEmpty ?? false);
      final alasan = adaVarian ? null : p.AmbilAlasanTidakBisaDijual();
      final tandaiHabis = habis.contains(p.uuid);
      final stokKosong =
          !tandaiHabis && !adaVarian && katalog != null && layanan.CekStokHabis(keranjangJual, katalog, p.uuid);
      return (
        harga: satuan == null || katalog == null || k == null
            ? null
            : layanan.TentukanHarga(
                katalog,
                k,
                p.uuid,
                satuan.uuid,
                Kuantitas.DariBulat(1),
                kanal: kanal,
                tierPelanggan: tier,
              ),
        nonaktif: alasan != null || tandaiHabis || stokKosong,
        keterangan: alasan != null
            ? 'Tidak bisa dijual'
            : tandaiHabis || stokKosong
            ? 'Habis'
            : adaVarian
            ? 'Pilih varian'
            : p.kelompokPilihan.isNotEmpty
            ? 'Ada pilihan'
            : null,
        saatDiketuk: tandaiHabis
            ? () => _TampilPesan('${p.nama} ditandai habis di outlet ini. Tahan untuk menandai tersedia lagi.')
            : stokKosong
            ? () => _TampilStokKosong(p)
            : () => _TambahProduk(p),
        saatDitahan: () => unawaited(_UbahKetersediaan(p, habis: !tandaiHabis)),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(tepi, TokenJarak.jarak16, tepi, TokenJarak.jarak8),
          child: LayoutBuilder(
            builder: (context, batasKepala) {
              final ringkas = batasKepala.maxWidth < 440;
              return Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _cari,
                      focusNode: _fokusCari,
                      textInputAction: TextInputAction.search,
                      onChanged: (_) => setState(() {}),
                      onSubmitted: (masukan) {
                        // K-8: `12*kode` atau `12x nama` = tambah 12 unit; `12*` saja menunggu pindaian berikutnya.
                        final pengali = PengaliJumlah.Urai(masukan);
                        if (pengali.CekMenunggu) {
                          _FokusAkar();
                          return;
                        }
                        final kata = pengali.sisa;
                        final hasil = katalog?.CariKode(kata);
                        // K-15: pemindai yang mengetik ke kolom cari (HP/tablet) juga mendapat umpan balik pindai.
                        if (hasil == null && pengali.jumlah == null && _CobaBarcodeTimbangan(kata, umpanBalik: true)) {
                          setState(_cari.clear);
                        } else if (hasil != null) {
                          _JalankanPindai(
                            () => _TambahProduk(hasil.produk, satuan: hasil.satuan, jumlah: pengali.jumlah),
                          );
                          setState(_cari.clear);
                        } else if (daftar.length == 1) {
                          _TambahProduk(daftar.single, jumlah: pengali.jumlah);
                          setState(_cari.clear);
                        }
                        _FokusAkar();
                      },
                      decoration: InputDecoration(
                        hintText: ringkas ? 'Cari produk atau barcode' : 'Cari nama, SKU, atau barcode (F1)',
                        prefixIcon: const Icon(Icons.search),
                        suffixIcon: _cari.text.isEmpty
                            ? (ref.watch(penyediaPemindaiKamera).CekTersedia() &&
                                      CekFlagKasir(ref, KunciFlagFitur.kasirPindaiKamera)
                                  ? IconButton(
                                      key: const ValueKey('TombolKameraPindai'),
                                      tooltip: _kameraAktif ? 'Tutup kamera pemindai' : 'Pindai dengan kamera',
                                      isSelected: _kameraAktif,
                                      onPressed: _UbahKamera,
                                      icon: const Icon(Icons.photo_camera_outlined),
                                      selectedIcon: const Icon(Icons.photo_camera),
                                    )
                                  : null)
                            : IconButton(
                                tooltip: 'Hapus pencarian',
                                onPressed: () => setState(_cari.clear),
                                icon: const Icon(Icons.clear),
                              ),
                        border: const OutlineInputBorder(),
                        isDense: true,
                      ),
                    ),
                  ),
                  const SizedBox(width: TokenJarak.jarak8),
                  if (widget.modePelayan)
                    const SizedBox.shrink()
                  else if (ringkas)
                    IconButton(
                      tooltip: 'Pesanan tertahan ($tertahan)',
                      onPressed: _BukaTertahan,
                      icon: Badge.count(
                        count: tertahan,
                        isLabelVisible: tertahan > 0,
                        child: const Icon(Icons.pause_circle_outline),
                      ),
                    )
                  else
                    SizedBox(
                      height: TokenJarak.targetSentuh,
                      child: OutlinedButton.icon(
                        onPressed: _BukaTertahan,
                        icon: const Icon(Icons.pause_circle_outline),
                        label: Text('Tertahan ($tertahan)'),
                      ),
                    ),
                  IconButton(
                    key: const ValueKey('TombolCekHarga'),
                    tooltip: 'Cek harga (F4)',
                    onPressed: _BukaCekHarga,
                    icon: const Icon(Icons.sell_outlined),
                  ),
                  // Layar sempit: kotak cari diutamakan; cetak ulang tetap ada di baris Riwayat.
                  if (!widget.modePelayan && !ringkas)
                    IconButton(
                      key: const ValueKey('TombolCetakUlangTerakhir'),
                      tooltip: 'Cetak ulang struk terakhir',
                      onPressed: () => unawaited(_CetakUlangTerakhir()),
                      icon: const Icon(Icons.receipt_long_outlined),
                    ),
                  IconButton(
                    tooltip: 'Perbarui katalog',
                    onPressed: _memperbarui ? null : () => _PerbaruiKatalog(manual: true),
                    icon: const Icon(Icons.sync),
                  ),
                ],
              );
            },
          ),
        ),
        if (_kameraAktif &&
            ref.watch(penyediaPemindaiKamera).CekTersedia() &&
            CekFlagKasir(ref, KunciFlagFitur.kasirPindaiKamera))
          Padding(
            padding: EdgeInsets.fromLTRB(tepi, 0, tepi, TokenJarak.jarak8),
            child: JalurKameraPindai(
              pabrik: ref.read(penyediaPemindaiKamera),
              arahAwal: ref.read(penyediaPengaturanPerangkat).kameraPindai,
              jam: ref.read(penyediaJam),
              saatKode: _SaatKodeKamera,
              saatArahBerubah: _SimpanArahKamera,
              saatTutup: _UbahKamera,
              hasil: _hasilKamera,
              tinggi: sempit ? 168 : 208,
            ),
          ),
        if (katalog != null && (katalog.kategori.isNotEmpty || katalog.adaFavorit))
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: EdgeInsets.symmetric(horizontal: tepi),
              children: [
                for (final (uuid, nama) in [
                  (null, 'Semua'),
                  // K-16: produk favorit pilihan pemilik (diatur di back-office).
                  if (katalog.adaFavorit) (LayarJual.kategoriFavorit, 'Favorit'),
                  // K-16: produk yang paling sering dijual di perangkat ini (30 hari).
                  if (terlaris.isNotEmpty) (LayarJual.kategoriTerlaris, 'Terlaris'),
                  for (final k in katalog.kategori) (k.Uuid, k.Nama),
                ])
                  Padding(
                    padding: const EdgeInsets.only(right: TokenJarak.jarak8),
                    child: ChoiceChip(
                      avatar: switch (uuid) {
                        LayarJual.kategoriTerlaris => const Icon(Icons.trending_up),
                        LayarJual.kategoriFavorit => const Icon(Icons.star_outline),
                        _ => null,
                      },
                      label: Text(nama),
                      // Favorit dicabut saat terpilih: daftar menampilkan semua produk, jadi "Semua" yang tersorot.
                      selected:
                          _uuidKategori == uuid ||
                          (uuid == null && _uuidKategori == LayarJual.kategoriFavorit && !katalog.adaFavorit),
                      onSelected: (_) => _PilihKategori(uuid),
                    ),
                  ),
              ],
            ),
          ),
        if (pesan != null)
          Padding(
            padding: EdgeInsets.fromLTRB(tepi, TokenJarak.jarak8, tepi, 0),
            child: Semantics(
              liveRegion: true,
              child: Row(
                children: [
                  Icon(
                    pesan.galat ? Icons.error_outline : Icons.info_outline,
                    size: TokenJarak.ikonSedang,
                    color: pesan.galat ? warna.bahaya : warna.info,
                  ),
                  const SizedBox(width: TokenJarak.jarak8),
                  Expanded(child: Text(pesan.teks, style: teks.bodyMedium)),
                  IconButton(
                    tooltip: 'Tutup pesan',
                    onPressed: () => setState(() => _pesan = null),
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
            ),
          ),
        if (_memperbarui) const LinearProgressIndicator(),
        Expanded(
          child: katalog == null || k == null
              ? const Center(child: CircularProgressIndicator())
              : katalog.CekKosong
              ? _BangunKosong(
                  context,
                  'Katalog belum ada di perangkat ini.',
                  'Sambungkan ke internet lalu ketuk Perbarui katalog.',
                  aksi: _memperbarui ? null : () => _PerbaruiKatalog(manual: true),
                )
              : daftar.isEmpty
              ? _BangunKosong(
                  context,
                  _cari.text.isEmpty ? 'Belum ada produk di kategori ini.' : 'Tidak ada produk yang cocok.',
                  _cari.text.isEmpty ? 'Pilih kategori lain.' : 'Periksa ejaan atau cari dengan SKU/barcode.',
                  ilustrasi: _cari.text.isEmpty ? IlustrasiKosong.Produk : IlustrasiKosong.Cari,
                )
              : daftarRingkas
              ? ListView.builder(
                  key: const ValueKey('KatalogDaftar'),
                  padding: EdgeInsets.fromLTRB(tepi, TokenJarak.jarak8, tepi, tepi),
                  itemCount: daftar.length,
                  itemBuilder: (context, i) {
                    final b = BangunItem(daftar[i]);
                    return BarisProduk(
                      key: ValueKey(daftar[i].uuid),
                      nama: daftar[i].nama,
                      lencana: LencanaObat.Buat(daftar[i]),
                      sku: daftar[i].sku,
                      harga: b.harga,
                      nonaktif: b.nonaktif,
                      keterangan: b.keterangan,
                      saatDiketuk: b.saatDiketuk,
                      saatDitahan: b.saatDitahan,
                    );
                  },
                )
              : LayoutBuilder(
                  builder: (context, batas) {
                    // Papan menu: jumlah kolom ditentukan lebar area, ubin membagi habis lebarnya sampai tepi.
                    final kolom = UbinProduk.HitungKolom(batas.maxWidth - tepi * 2);
                    final lebarUbin = (batas.maxWidth - tepi * 2 - (kolom - 1) * TokenJarak.jarak12) / kolom;
                    return GridView.builder(
                      padding: EdgeInsets.fromLTRB(tepi, TokenJarak.jarak8, tepi, tepi),
                      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: kolom,
                        childAspectRatio: UbinProduk.HitungRasio(lebarUbin),
                        crossAxisSpacing: TokenJarak.jarak12,
                        mainAxisSpacing: TokenJarak.jarak12,
                      ),
                      itemCount: daftar.length,
                      itemBuilder: (context, i) {
                        final p = daftar[i];
                        final b = BangunItem(p);
                        return UbinProduk(
                          key: ValueKey(p.uuid),
                          nama: p.nama,
                          lencana: LencanaObat.Buat(p),
                          gambar: p.urlGambarKecil == null ? null : _GambarProduk(nama: p.nama, url: p.urlGambarKecil!),
                          harga: b.harga,
                          nonaktif: b.nonaktif,
                          keterangan: b.keterangan,
                          saatDiketuk: b.saatDiketuk,
                          saatDitahan: b.saatDitahan,
                        );
                      },
                    );
                  },
                ),
        ),
      ],
    );
  }

  Widget _BangunKosong(
    BuildContext context,
    String judul,
    String isi, {
    VoidCallback? aksi,
    IlustrasiKosong ilustrasi = IlustrasiKosong.Produk,
  }) {
    return Center(
      child: SingleChildScrollView(
        child: KeadaanKosong(
          ikon: Icons.inventory_2_outlined,
          ilustrasi: ilustrasi,
          judul: judul,
          keterangan: isi,
          aksi: aksi == null ? null : OutlinedButton(onPressed: aksi, child: const Text('Perbarui katalog')),
        ),
      ),
    );
  }

  Widget _BangunKeranjang(HitunganKeranjang? hitungan, {bool tampilKepala = true}) {
    final keranjang = ref.watch(penyediaKeranjangEfektif);
    final pesanan = keranjang.pesananMeja;
    return PanelKeranjang(
      keranjang: keranjang,
      hitungan: hitungan,
      tampilKepala: tampilKepala,
      // K-11: tukar barang menandai keranjang; "Batalkan transaksi" membatalkan tukarnya (returnya belum tersimpan).
      judul:
          pesanan?.AmbilJudul() ??
          switch ((keranjang.tukar, keranjang.perintahKerja)) {
            (final tukar?, _) => 'Tukar barang | ${tukar.nilai.FormatRupiah()}',
            // Bengkel bagian 2: keranjang menagih perintah kerja → kendaraannya jadi judul.
            (null, final pk?) => 'Servis ${pk.pengenalUnit ?? pk.nomor}',
            _ => null,
          },
      lencanaProduk: {
        for (final b in keranjang.baris)
          b.uuidProduk: ?LencanaObat.Buat(ref.watch(penyediaKatalog).value?.CariProduk(b.uuidProduk)),
      },
      statusBaris: {for (final b in pesanan?.baris ?? const <BarisPesananMeja>[]) b.uuid: b.AmbilLabelStatus()},
      labelTahan: switch ((pesanan, widget.modePelayan)) {
        (null, true) => 'Pilih meja',
        (null, false) => 'Tahan',
        _ when _kursusDitahan => 'Tahan $_kursusBaru',
        _ => 'Kirim ke dapur',
      },
      labelKosongkan: pesanan == null ? 'Batalkan transaksi' : 'Tutup pesanan (kembali ke Meja)',
      saatUbahBaris: _UbahBaris,
      saatTambah: (uuid) => _GeserJumlah(uuid, 1),
      saatKurang: (uuid) => _GeserJumlah(uuid, -1),
      saatDiskonPesanan: () => setState(() => _panel = _JenisPanel.DiskonPesanan),
      saatTahan: () => pesanan == null && widget.modePelayan
          ? widget.saatKeMeja?.call()
          : unawaited(pesanan == null ? _Tahan() : _KirimDapur()),
      saatKosongkan: () => unawaited(pesanan == null ? _KonfirmasiBatal() : _TutupPesanan()),
      saatBayar: widget.modePelayan ? null : _BukaBayar,
      saatPelanggan: widget.modePelayan ? null : _BukaPelanggan,
      uuidSorot: _uuidSorot,
      // K-13: kursus item baru & kursus yang ditahan (hanya pesanan meja).
      kursus: pesanan == null ? const [] : KursusPesanan.semua,
      kursusDipilih: _kursusBaru,
      saatKursus: (k) => setState(() => _kursusBaru = k),
      kursusDitahan: pesanan == null ? const [] : LayananPesananMeja.AmbilKursusDitahan(pesanan.baris),
      saatKirimKursus: (k) => unawaited(_KirimKursus(k)),
      // X8: kanal (GoFood, GrabFood, …) untuk penjualan langsung; pesanan meja selalu makan di tempat.
      // v3.51: jenis pesanan outlet (FnB) sebagai tombol segmen; pesanan meja selalu makan di tempat.
      jenisPesanan:
          widget.modePelayan ||
              pesanan != null ||
              keranjang.praPesan != null ||
              keranjang.reservasi != null ||
              keranjang.perintahKerja != null
          ? const []
          : (ref.watch(penyediaKonteksPenjualan).value?.jenisPesanan ?? const []),
      saatJenisPesanan: _GantiKanal,
      // v3.52: nama pemesan untuk penjualan bayar-dulu di outlet FnB (pesanan meja sudah punya nama/meja).
      saatNamaPemesan:
          widget.modePelayan ||
              pesanan != null ||
              keranjang.praPesan != null ||
              keranjang.reservasi != null ||
              keranjang.perintahKerja != null ||
              (ref.watch(penyediaKonteksPenjualan).value?.jenisPesanan.isEmpty ?? true)
          ? null
          : () => unawaited(_IsiNamaPemesan()),
      saatKanal:
          widget.modePelayan ||
              pesanan != null ||
              keranjang.praPesan != null ||
              keranjang.reservasi != null ||
              keranjang.perintahKerja != null ||
              // Hanya bila ada kanal di luar tombol jenis pesanan (Antar, ojol).
              _AmbilPilihanKanal().every(
                (p) => ref.watch(penyediaKonteksPenjualan).value?.jenisPesanan.contains(p) ?? false,
              )
          ? null
          : () => unawaited(_PilihKanal()),
      // v3.29: ongkir penjualan yang diantar toko sendiri (kanal Antar); pesanan online membawa ongkirnya sendiri.
      saatOngkir:
          widget.modePelayan ||
              pesanan != null ||
              keranjang.praPesan != null ||
              LayananPenjualan.AmbilKanal(keranjang) != KanalPenjualan.Antar
          ? null
          : () => unawaited(_IsiOngkir()),
      // Laundry (§9.9): tiket laundry untuk penjualan langsung (bukan pesanan meja/pengambilan pre-order).
      saatLaundry:
          widget.modePelayan ||
              pesanan != null ||
              keranjang.praPesan != null ||
              ref.watch(penyediaKonteksPenjualan).value?.laundry.aktif != true
          ? null
          : () => setState(() => _panel = _JenisPanel.Laundry),
      // Apotek bagian 4: racikan obat untuk penjualan langsung (bukan pesanan meja/pre-order/perintah kerja).
      saatRacikan:
          widget.modePelayan ||
              pesanan != null ||
              keranjang.praPesan != null ||
              keranjang.perintahKerja != null ||
              !PanelRacikan.CekTersedia(ref.watch(penyediaKatalog).value)
          ? null
          : () => setState(() => _panel = _JenisPanel.Racikan),
    );
  }

  /// Bilah bawah HP: ringkasan keranjang (ketuk → lembar keranjang) dan tombol Bayar yang menempel.
  Widget _BangunBilahHp(BuildContext context, HitunganKeranjang? hitungan) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final keranjang = ref.watch(penyediaKeranjangEfektif);
    final judul =
        keranjang.pesananMeja?.AmbilJudul() ??
        switch ((keranjang.tukar, keranjang.perintahKerja)) {
          (null, final pk?) => 'Servis ${pk.pengenalUnit ?? pk.nomor}',
          (null, null) => 'Keranjang',
          _ => 'Tukar barang',
        };
    return Material(
      color: warna.permukaan,
      child: Container(
        padding: const EdgeInsets.fromLTRB(
          TokenJarak.jarak16,
          TokenJarak.jarak8,
          TokenJarak.jarak16,
          TokenJarak.jarak8,
        ),
        decoration: BoxDecoration(
          border: Border(
            top: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
          ),
        ),
        child: Row(
          children: [
            Expanded(
              child: InkWell(
                onTap: () => setState(() => _panel = _JenisPanel.Keranjang),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: 56),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        keranjang.CekKosong ? '$judul kosong' : '$judul | ${keranjang.baris.length} baris',
                        style: teks.bodySmall,
                      ),
                      TeksUang(hitungan?.hasil.totalAkhir ?? Uang.Nol(), rataKanan: false, gaya: teks.titleMedium),
                    ],
                  ),
                ),
              ),
            ),
            SizedBox(
              height: 56,
              child: widget.modePelayan
                  ? FilledButton(
                      onPressed: keranjang.pesananMeja == null
                          ? widget.saatKeMeja
                          : keranjang.CekKosong
                          ? null
                          : () => unawaited(_KirimDapur()),
                      child: Text(keranjang.pesananMeja == null ? 'Pilih meja' : 'Kirim ke dapur'),
                    )
                  : FilledButton(onPressed: keranjang.CekKosong ? null : _BukaBayar, child: const Text('Bayar')),
            ),
          ],
        ),
      ),
    );
  }

  ({String judul, Widget isi})? _AmbilIsiPanel(HitunganKeranjang? hitungan, double tinggiArea) {
    final keranjang = ref.read(penyediaKeranjang);
    return switch (_panel) {
      null => null,
      _JenisPanel.Keranjang => (
        judul: 'Keranjang',
        // Lembar maks. 92% area; dikurangi kepala panel (±57dp) agar tombol Bayar tetap terlihat.
        isi: SizedBox(
          height: (tinggiArea * 0.92 - 64).clamp(240, double.infinity),
          child: _BangunKeranjang(hitungan, tampilKepala: false),
        ),
      ),
      _JenisPanel.Item => (
        judul: _uuidBarisPanel == null
            ? (_produkPanel?.nama ?? 'Item')
            : keranjang.baris.where((b) => b.uuid == _uuidBarisPanel).firstOrNull?.nama ?? 'Item',
        isi: PanelItem(
          key: ValueKey('item-${_uuidBarisPanel ?? _produkPanel?.uuid}'),
          produk: _produkPanel,
          baris: keranjang.baris.where((b) => b.uuid == _uuidBarisPanel).firstOrNull,
          kasir: widget.kasir,
          saatSelesai: _TutupPanel,
        ),
      ),
      _JenisPanel.Varian => (
        judul: 'Pilih varian | ${_produkPanel?.nama ?? ''}',
        isi: Builder(
          builder: (context) {
            final katalog = ref.read(penyediaKatalog).value;
            final k = ref.read(penyediaKonteksPenjualan).value;
            final induk = _produkPanel;
            if (katalog == null || k == null || induk == null) {
              return const SizedBox.shrink();
            }
            final layanan = ref.read(penyediaLayananPenjualan);
            final kanal = LayananPenjualan.AmbilKanal(keranjang);
            return PanelVarian(
              key: ValueKey('varian-${induk.uuid}'),
              induk: induk,
              varian: katalog.AmbilVarian(induk.uuid),
              hargaDari: (v) => switch (v.AmbilSatuanBawaan()) {
                final satuan? => layanan.TentukanHarga(
                  katalog,
                  k,
                  v.uuid,
                  satuan.uuid,
                  Kuantitas.DariBulat(1),
                  kanal: kanal,
                  tierPelanggan: keranjang.pelanggan?.kodeTier,
                ),
                null => null,
              },
              saatPilih: (v) {
                _TutupPanel();
                _TambahProduk(v);
              },
            );
          },
        ),
      ),
      _JenisPanel.CekHarga => (
        judul: 'Cek harga',
        isi: PanelCekHarga(
          key: _kunciCekHarga,
          saatTambah: (p) {
            _TutupPanel();
            _TambahProduk(p);
          },
        ),
      ),
      _JenisPanel.DiskonPesanan => (
        judul: 'Voucher & diskon',
        isi: PanelDiskonPesanan(kasir: widget.kasir, saatSelesai: _TutupPanel),
      ),
      _JenisPanel.Bayar => (
        judul: 'Bayar',
        isi: PanelBayar(
          key: _kunciBayar,
          kasir: widget.kasir,
          // D-183: pre-order + uang muka hanya untuk bakery, florist, dan jasa umum.
          saatPreOrder:
              _uuidKunciBayar == null &&
                  (ref.watch(penyediaKonteksPenjualan).value?.CekSesuaiSektor(KonteksPenjualan.sektorPreOrder) ?? true)
              ? () => setState(() => _panel = _JenisPanel.PreOrder)
              : null,
          // POS-05: SO cepat hanya di outlet bermode kasir Grosir (kasir lain membuat pesanan grosir dari back-office).
          saatPesananGrosir: _uuidKunciBayar == null && ref.watch(penyediaModeKasir).value == 'Grosir'
              ? () => setState(() => _panel = _JenisPanel.PesananGrosir)
              : null,
          saatSelesai: (hasil) {
            final pesanan = _uuidKunciBayar != null;
            _LepasKunciBayar(keServer: false);
            setState(() {
              _selesai = hasil;
              _selesaiPesanan = pesanan;
              _panel = _JenisPanel.Selesai;
            });
          },
        ),
      ),
      _JenisPanel.Selesai => (
        judul: 'Transaksi selesai',
        isi: _selesai == null
            ? const SizedBox.shrink()
            : TampilanSelesai(hasil: _selesai!, saatTransaksiBaru: _TransaksiBaru),
      ),
      _JenisPanel.Tertahan => (judul: 'Pesanan tertahan', isi: PanelTertahan(saatDibuka: _TutupPanel)),
      _JenisPanel.PreOrder => (
        judul: 'Pre-order',
        isi: PanelPreOrder(
          kasir: widget.kasir,
          saatKembali: () => setState(() => _panel = _JenisPanel.Bayar),
          saatSelesai: (hasil) => setState(() {
            _preOrderSelesai = hasil;
            _panel = _JenisPanel.PreOrderSelesai;
          }),
        ),
      ),
      _JenisPanel.PreOrderSelesai => (
        judul: 'Pre-order tersimpan',
        isi: _preOrderSelesai == null
            ? const SizedBox.shrink()
            : TampilanPreOrderSelesai(hasil: _preOrderSelesai!, saatTransaksiBaru: _TransaksiBaru),
      ),
      _JenisPanel.PesananGrosir => (
        judul: 'Pesanan grosir',
        isi: PanelPesananGrosir(
          kasir: widget.kasir,
          saatKembali: () => setState(() => _panel = _JenisPanel.Bayar),
          saatSelesai: (hasil) => setState(() {
            _pesananGrosirSelesai = hasil;
            _panel = _JenisPanel.PesananGrosirSelesai;
          }),
        ),
      ),
      _JenisPanel.PesananGrosirSelesai => (
        judul: 'Pesanan grosir tersimpan',
        isi: _pesananGrosirSelesai == null
            ? const SizedBox.shrink()
            : TampilanPesananGrosirSelesai(hasil: _pesananGrosirSelesai!, saatTransaksiBaru: _TransaksiBaru),
      ),
      _JenisPanel.Pelanggan => (judul: 'Pelanggan', isi: PanelPelanggan(kasir: widget.kasir, saatSelesai: _TutupPanel)),
      _JenisPanel.Laundry => (judul: 'Tiket laundry', isi: PanelLaundry(saatSelesai: _TutupPanel)),
      _JenisPanel.Racikan => (judul: 'Racikan obat', isi: PanelRacikan(saatSelesai: _TutupPanel)),
    };
  }

  /// Halaman pembayaran & hasilnya: kepala dengan tombol kembali, lalu isinya memakai seluruh area kerja.
  /// Bingkai Ruang Kerja (bilah atas, rel, bilah status) tetap ada — yang hilang hanya katalog & keranjang.
  Widget _BangunHalamanPembayaran(BuildContext context, ({String judul, Widget isi}) halaman) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final selesai =
        _panel == _JenisPanel.Selesai ||
        _panel == _JenisPanel.PreOrderSelesai ||
        _panel == _JenisPanel.PesananGrosirSelesai;
    final kembali = switch (_panel) {
      // Pre-order dan pesanan grosir dicapai dari halaman Bayar, jadi kembalinya ke sana, bukan ke keranjang.
      _JenisPanel.PreOrder || _JenisPanel.PesananGrosir => () => setState(() => _panel = _JenisPanel.Bayar),
      _JenisPanel.Selesai || _JenisPanel.PreOrderSelesai || _JenisPanel.PesananGrosirSelesai => _TransaksiBaru,
      _ => _TutupPanel,
    };

    // D-66: layar selesai adalah halaman merek penuh dengan kepalanya sendiri (logo Payoung), bukan halaman kerja
    // berlatar terang. Isinya mengatur gulir, lebar, dan tinggi sendiri.
    if (_panel == _JenisPanel.Selesai) {
      return halaman.isi;
    }

    // `Material`, bukan `ColoredBox`: isi halaman bisa memuat `ListTile`/`SwitchListTile`, yang melukis latar &
    // riak sentuhnya di `Material` terdekat.
    return Material(
      color: warna.latar,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Material(
            color: warna.permukaan,
            child: Container(
              height: 56,
              padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak8),
              decoration: BoxDecoration(
                border: Border(
                  bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
                ),
              ),
              child: Row(
                children: [
                  IconButton(
                    tooltip: selesai ? 'Transaksi baru' : 'Kembali ke keranjang',
                    onPressed: kembali,
                    icon: Icon(selesai ? Icons.close : Icons.arrow_back),
                  ),
                  const SizedBox(width: TokenJarak.jarak4),
                  Expanded(child: Text(halaman.judul, style: teks.titleMedium)),
                ],
              ),
            ),
          ),
          Expanded(
            // Bayar mengatur gulir & bilah aksinya sendiri (tombol selesaikan selalu terlihat, dua kolom di layar
            // lebar). Halaman lain: rata atas di tengah horizontal, digulir bila lebih panjang dari layar; tidak
            // ditengahkan vertikal agar isi tidak melompat setiap kali tingginya berubah.
            child: halaman.isi is PanelBayar
                ? halaman.isi
                : SingleChildScrollView(
                    child: Align(
                      alignment: Alignment.topCenter,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: LayarJual.lebarIsiHalaman),
                        child: halaman.isi,
                      ),
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  /// v2.01 layar pelanggan: keranjang (item & total), "Silakan lakukan pembayaran" saat panel Bayar terbuka, kembalian
  /// setelah bayar, lalu layar siaga. Dikirim setelah frame dan hanya bila isinya berubah.
  void _SiarkanLayarPelanggan(Keranjang keranjang, HitunganKeranjang? hitungan) {
    final layar = ref.watch(penyediaLayarPelanggan);
    if (widget.modePelayan || !layar.aktif || !widget.aktif) {
      return;
    }
    final selesai = _selesai;
    final isi = _panel == _JenisPanel.Selesai && selesai != null
        ? PenyusunLayarPelanggan.Selesai(layar.namaToko, selesai)
        : PenyusunLayarPelanggan.DariKeranjang(
            layar.namaToko,
            keranjang,
            hitungan,
            bayar: _panel == _JenisPanel.Bayar,
            dataQr: ref.watch(penyediaQrisLayarPelanggan),
          );
    final sidik = isi.AmbilSidik();
    if (sidik == _sidikLayarPelanggan) {
      return;
    }
    _sidikLayarPelanggan = sidik;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        unawaited(ref.read(penyediaLayarPelanggan.notifier).Tampilkan(isi));
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final katalog = ref.watch(penyediaKatalog).value;
    final k = ref.watch(penyediaKonteksPenjualan).value;
    final keranjang = ref.watch(penyediaKeranjangEfektif);
    final posisi = ref.watch(penyediaPengaturanPerangkat.select((p) => p.posisiKeranjang));
    final warna = TokenWarna.AmbilDari(context);
    final lebarLayar = MediaQuery.sizeOf(context).width;
    HitunganKeranjang? hitungan;
    if (k != null && !keranjang.CekKosong) {
      try {
        hitungan = ref.read(penyediaLayananPenjualan).Hitung(keranjang, k);
      } on ArgumentError {
        hitungan = null;
      }
    }
    _SiarkanLayarPelanggan(keranjang, hitungan);

    return Focus(
      focusNode: _fokusAkar,
      onKeyEvent: _SaatTombolPintasan,
      child: LayoutBuilder(
        builder: (context, batas) {
          final duaPanel = batas.maxWidth >= LayarJual.lebarDuaPanel;
          // Diramping dari 400/320 (v2.67): 40dp itu bedanya satu kolom penuh di papan menu, sedangkan isi
          // keranjang (nama item, nominal) tetap muat.
          final lebarKeranjang = batas.maxWidth >= 960 ? 360.0 : 280.0;
          final Widget isi;
          if (duaPanel) {
            final keranjangSamping = SizedBox(width: lebarKeranjang, child: _BangunKeranjang(hitungan));
            final pemisah = VerticalDivider(
              width: TokenJarak.tebalGaris,
              thickness: TokenJarak.tebalGaris,
              color: warna.garis,
            );
            isi = Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: posisi == PosisiKeranjang.Kiri
                  ? [keranjangSamping, pemisah, Expanded(child: _BangunKatalog(context, katalog, k, false))]
                  : [Expanded(child: _BangunKatalog(context, katalog, k, false)), pemisah, keranjangSamping],
            );
          } else {
            isi = Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(child: _BangunKatalog(context, katalog, k, true)),
                _BangunBilahHp(context, hitungan),
              ],
            );
          }
          final panel = _AmbilIsiPanel(hitungan, batas.maxHeight);
          if (panel == null) {
            return isi;
          }
          final samping = lebarLayar >= 1024;
          final tutup = _panel == _JenisPanel.Selesai ? _TransaksiBaru : _TutupPanel;
          if (_halamanPenuh.contains(_panel)) {
            return _BangunHalamanPembayaran(context, panel);
          }
          return Stack(
            children: [
              Positioned.fill(child: isi),
              Positioned.fill(
                child: Semantics(
                  label: 'Tutup ${panel.judul}',
                  button: true,
                  child: GestureDetector(
                    onTap: tutup,
                    child: ColoredBox(color: warna.teksUtama.withValues(alpha: 0.24)),
                  ),
                ),
              ),
              if (samping)
                Positioned(
                  top: 0,
                  right: 0,
                  bottom: 0,
                  child: PanelTugas(judul: panel.judul, saatTutup: tutup, anak: panel.isi),
                )
              else
                Positioned(
                  left: 0,
                  right: 0,
                  bottom: 0,
                  child: ConstrainedBox(
                    constraints: BoxConstraints(maxHeight: batas.maxHeight * 0.92),
                    child: PanelTugas(
                      judul: panel.judul,
                      saatTutup: tutup,
                      tataLetak: TataLetakPanel.Lembar,
                      anak: panel.isi,
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _GambarProduk extends ConsumerWidget {
  const _GambarProduk({required this.nama, required this.url});

  final String nama;
  final String url;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final gambar = ref.watch(penyediaGambarProduk(url));
    final byte = gambar.value;

    if (byte != null && byte.isNotEmpty) {
      return Image.memory(
        byte,
        fit: BoxFit.cover,
        gaplessPlayback: true,
        errorBuilder: (_, _, _) => _InisialProduk(nama: nama),
      );
    }

    return Center(
      child: Text(
        UbinProduk.AmbilInisial(nama),
        style: teks.headlineSmall?.copyWith(color: warna.teksSekunder, fontFamily: fontMono),
      ),
    );
  }
}

class _InisialProduk extends StatelessWidget {
  const _InisialProduk({required this.nama});

  final String nama;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    return Center(
      child: Text(
        UbinProduk.AmbilInisial(nama),
        style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: warna.teksSekunder, fontFamily: fontMono),
      ),
    );
  }
}

/// v3.29: isian ongkir (rupiah bulat) untuk penjualan kanal Antar. Kosong = tanpa ongkir.
class _DialogNamaPemesan extends StatefulWidget {
  const _DialogNamaPemesan({required this.awal});

  final String awal;

  @override
  State<_DialogNamaPemesan> createState() => _DialogNamaPemesanState();
}

class _DialogNamaPemesanState extends State<_DialogNamaPemesan> {
  late final TextEditingController _nama = TextEditingController(text: widget.awal);

  @override
  void dispose() {
    _nama.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Nama pemesan'),
    content: TextField(
      key: const ValueKey('NamaPemesan'),
      controller: _nama,
      autofocus: true,
      textCapitalization: TextCapitalization.words,
      inputFormatters: [LengthLimitingTextInputFormatter(60)],
      decoration: const InputDecoration(
        labelText: 'Nama yang dipanggil saat pesanan siap',
        border: OutlineInputBorder(),
      ),
      onSubmitted: (_) => Navigator.of(context).pop(_nama.text),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
      FilledButton(onPressed: () => Navigator.of(context).pop(_nama.text), child: const Text('Simpan')),
    ],
  );
}

class _DialogOngkir extends StatefulWidget {
  const _DialogOngkir({required this.awal});

  final Uang awal;

  @override
  State<_DialogOngkir> createState() => _DialogOngkirState();
}

class _DialogOngkirState extends State<_DialogOngkir> {
  late final TextEditingController _ongkir = TextEditingController(
    text: widget.awal.BernilaiNol() ? '' : MasukanUang.FormatTeks(widget.awal),
  );

  @override
  void dispose() {
    _ongkir.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Ongkir'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('NilaiOngkir'),
          controller: _ongkir,
          autofocus: true,
          keyboardType: TextInputType.number,
          inputFormatters: [MasukanUang.pemformat],
          textAlign: TextAlign.right,
          decoration: const InputDecoration(labelText: 'Ongkir', prefixText: 'Rp ', border: OutlineInputBorder()),
          onSubmitted: (_) => Navigator.of(context).pop(_ongkir.text.trim()),
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          'Promo gratis ongkir yang berlaku dipotong otomatis di keranjang.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ],
    ),
    actions: [
      TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Batal')),
      FilledButton(onPressed: () => Navigator.of(context).pop(_ongkir.text.trim()), child: const Text('Simpan')),
    ],
  );
}
