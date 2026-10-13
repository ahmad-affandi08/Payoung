import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:inti/Inti.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Struk/BagianCetakDokumen.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';
import '../../Domain/Penjualan/Keranjang.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Penjualan/LayananReturPenjualan.dart';
import '../../Domain/Penjualan/PenghitungNilaiRetur.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatAngka.dart';
import '../Komponen/FormatWaktu.dart';
import '../Komponen/MasukanUang.dart';
import '../LembarMutasiKas.dart';
import 'LembarReturTanpaStruk.dart';
import '../Komponen/PilihanAlasan.dart';

/// Cara refund retur fase 1: tunai dari laci shift aktif, transfer manual, atau keduanya.
/// Cara refund retur. `Tukar` (K-11): nilai barang yang diretur membayar barang pengganti di layar Jual.
enum CaraRefund { Tunai, Transfer, Campuran, Tukar }

/// Formulir retur dari struk (Rincian F-09 fase 1) di dalam `PanelTugas` ruang kerja: masukkan/pindai nomor struk →
/// cari online → pilih barang, jumlah, & kondisi → alasan & cara refund → PIN penyetuju ber-izin `penjualan.retur` (K-22) →
/// simpan lokal + outbox. Pemindai barcode (keyboard wedge) mengetik nomor lalu Enter langsung mencari.
class LembarRetur extends ConsumerStatefulWidget {
  const LembarRetur({super.key, required this.kasir, required this.saatSelesai, this.saatTukar, this.nomorAwal});

  static const String judul = 'Retur dari struk';

  final StafLokal kasir;
  final VoidCallback saatSelesai;

  /// K-11: tukar barang disiapkan di keranjang; pemanggil menutup lembar dan membuka layar Jual. Null = opsi tukar
  /// barang tidak ditawarkan.
  final VoidCallback? saatTukar;

  /// Audit kemudahan pakai #10: nomor struk dari baris Riwayat; diisi dan langsung dicari saat lembar dibuka.
  final String? nomorAwal;

  @override
  ConsumerState<LembarRetur> createState() => _LembarReturState();
}

class _LembarReturState extends ConsumerState<LembarRetur> {
  final _nomor = TextEditingController();
  final _alasan = TextEditingController();
  final _tunai = TextEditingController();
  final Map<String, TextEditingController> _jumlah = {};
  final Map<String, String> _kondisi = {};
  HasilCariPenjualan? _hasil;
  CaraRefund _cara = CaraRefund.Tunai;
  String? _uuidMetodeTransfer;
  bool _mencari = false;
  bool _sibuk = false;
  String? _galat;
  ReturTersimpan? _selesai;
  int? _batasHari;

  /// Penjualan yang bisa dipilih (terbaru atau yang cocok dengan ketikan), supaya kasir tidak mengetik nomor utuh.
  List<KandidatReturPos> _kandidat = const [];
  bool _memuatKandidat = false;
  Timer? _jedaKandidat;
  int _urutanKandidat = 0;

  /// K28: formulir retur tanpa struk ditampilkan.
  bool _tanpaStruk = false;

  LayananReturPenjualan get _layanan => ref.read(penyediaLayananRetur);

  @override
  void initState() {
    super.initState();
    unawaited(_MuatBatas());
    final nomor = widget.nomorAwal;
    if (nomor != null) {
      _nomor.text = nomor;
      unawaited(Future<void>.microtask(_Cari));
    } else {
      // Setelah initState selesai: _MuatKandidat memanggil setState.
      unawaited(Future<void>.microtask(() => _MuatKandidat('')));
    }
  }

  /// Muat daftar pilihan untuk [kata] (kosong = terbaru). Jawaban yang datang terlambat dibuang bila kasir sudah
  /// mengetik lagi.
  Future<void> _MuatKandidat(String kata) async {
    final urutan = ++_urutanKandidat;
    if (mounted) {
      setState(() => _memuatKandidat = true);
    }
    final daftar = await _layanan.CariKandidat(kata);
    if (mounted && urutan == _urutanKandidat) {
      setState(() {
        _kandidat = daftar;
        _memuatKandidat = false;
      });
    }
  }

  /// Pencarian dinamis: tiap ketikan menyaring pilihan struk (jeda singkat agar tidak menembak server per huruf), dan
  /// kolom yang dikosongkan kembali menampilkan transaksi terbaru.
  void _SaatNomorBerubah(String kata) {
    _jedaKandidat?.cancel();
    setState(() => _galat = null);
    _jedaKandidat = Timer(const Duration(milliseconds: 250), () => unawaited(_MuatKandidat(kata)));
  }

  /// Pindai QR di struk (tautan struk digital) dengan kamera lalu langsung cari.
  Future<void> _PindaiKamera() async {
    final hasil = await ref
        .read(penyediaPemindaiQr)
        .Pindai(context, judul: 'Pindai QR struk', petunjuk: 'Arahkan kamera ke kode QR di struk pembeli.');
    if (!mounted || hasil == null || hasil.trim().isEmpty) {
      return;
    }
    _nomor.text = hasil.trim();
    await _Cari();
  }

  void _PilihKandidat(KandidatReturPos kandidat) {
    _nomor.text = kandidat.nomor;
    unawaited(_Cari());
  }

  Future<void> _MuatBatas() async {
    final batas = await _layanan.AmbilBatasHariRetur();
    if (mounted) {
      setState(() => _batasHari = batas);
    }
  }

  @override
  void dispose() {
    _jedaKandidat?.cancel();
    _nomor.dispose();
    _alasan.dispose();
    _tunai.dispose();
    for (final p in _jumlah.values) {
      p.dispose();
    }
    super.dispose();
  }

  TextEditingController _PengendaliJumlah(String uuid) => _jumlah.putIfAbsent(uuid, TextEditingController.new);

  Future<void> _Cari() async {
    setState(() {
      _mencari = true;
      _galat = null;
      _hasil = null;
    });
    try {
      final hasil = await _layanan.Cari(_nomor.text);
      if (mounted) {
        setState(() {
          for (final p in _jumlah.values) {
            p.clear();
          }
          _kondisi.clear();
          _hasil = hasil;
        });
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
        if (galat.kode == 'StrukBanyak') {
          unawaited(_MuatKandidat(_nomor.text));
        }
      }
    } finally {
      if (mounted) {
        setState(() => _mencari = false);
      }
    }
  }

  /// Jumlah retur yang diketik untuk [baris]; null bila tidak valid (bukan angka / lebih dari 4 desimal).
  Kuantitas? _AmbilJumlah(BarisPenjualanCariPos baris) {
    final teks = _jumlah[baris.uuid]?.text ?? '';
    if (teks.trim().isEmpty) {
      return Kuantitas.Nol();
    }
    final nilai = FormatAngka.UraiDesimal(teks);
    if (nilai == null || nilai.scale > Kuantitas.skala) {
      return null;
    }
    return Kuantitas.DariDesimal(nilai);
  }

  /// Galat isian satu baris untuk ditampilkan di bawah kolom jumlah.
  String? _GalatBaris(BarisPenjualanCariPos baris, bool bolehDesimal) {
    final jumlah = _AmbilJumlah(baris);
    if (jumlah == null || jumlah.BernilaiNegatif()) {
      return 'Isi angka yang benar.';
    }
    final d = jumlah.KeDesimal();
    if (!bolehDesimal && d != d.truncate()) {
      return 'Harus bilangan bulat.';
    }
    if (jumlah.Bandingkan(Kuantitas.Dari(baris.jumlahBisaDiretur)) > 0) {
      return 'Maksimal ${FormatAngka.FormatJumlah(Kuantitas.Dari(baris.jumlahBisaDiretur))}.';
    }
    return null;
  }

  List<PilihanReturBaris> _AmbilPilihan() {
    final hasil = _hasil;
    if (hasil == null) {
      return const [];
    }
    return [
      for (final b in hasil.baris)
        if (_AmbilJumlah(b) case final jumlah? when !jumlah.BernilaiNol())
          PilihanReturBaris(baris: b, jumlah: jumlah, kondisi: _kondisi[b.uuid] ?? KondisiRetur.layakJual),
    ];
  }

  /// Total refund dari pilihan yang valid (null bila ada isian yang belum valid).
  Uang? _HitungTotal() {
    final hasil = _hasil;
    if (hasil == null) {
      return null;
    }
    final katalog = ref.read(penyediaKatalog).value;
    for (final b in hasil.baris) {
      if (_GalatBaris(b, LayananReturPenjualan.CekBolehDesimal(b, katalog)) != null) {
        return null;
      }
    }
    return LayananReturPenjualan.HitungTotal(_AmbilPilihan());
  }

  /// F-12: bagian retur penjualan tempo yang memotong piutang (bukan uang keluar).
  Uang _HitungPotongPiutang(Uang total) {
    final hasil = _hasil;
    return hasil == null ? Uang.Nol() : LayananReturPenjualan.HitungPotongPiutang(hasil, total);
  }

  /// Bagian tunai dari yang dibayar kembali (total − potong piutang).
  Uang _HitungTunai(Uang total) {
    final dibayarKembali = total.Kurangi(_HitungPotongPiutang(total));
    return switch (_cara) {
      CaraRefund.Tunai => dibayarKembali,
      CaraRefund.Transfer => Uang.Nol(),
      CaraRefund.Campuran => MasukanUang.AmbilNilai(_tunai) ?? Uang.Nol(),
      CaraRefund.Tukar => Uang.Nol(),
    };
  }

  Future<void> _Simpan(KonteksPenjualan k, List<BarisMetodePembayaran> transfer) async {
    final hasil = _hasil;
    if (hasil == null) {
      return;
    }
    final katalog = ref.read(penyediaKatalog).value;
    final total = _HitungTotal();
    if (total == null) {
      setState(() => _galat = 'Periksa lagi jumlah retur yang ditandai.');
      return;
    }
    List<PilihanReturBaris> pilihan;
    try {
      pilihan = LayananReturPenjualan.ValidasiPilihan(hasil, _AmbilPilihan(), katalog: katalog);
    } on GalatKasir catch (galat) {
      setState(() => _galat = galat.pesan);
      return;
    }
    if (_alasan.text.trim().runes.length < LayananReturPenjualan.panjangAlasanMinimal) {
      setState(() => _galat = 'Tulis alasan retur minimal 5 huruf.');
      return;
    }
    final tunai = _HitungTunai(total);
    final dibayarKembali = total.Kurangi(_HitungPotongPiutang(total));
    if (tunai.Bandingkan(dibayarKembali) > 0) {
      setState(() => _galat = 'Refund tunai tidak boleh lebih dari ${dibayarKembali.FormatRupiah()}.');
      return;
    }
    final metodeTransfer = transfer.where((m) => m.Uuid == _uuidMetodeTransfer).firstOrNull ?? transfer.firstOrNull;

    // Tukar barang: PIN diminta nanti, saat pembayaran barang pengganti diselesaikan (nilai transaksi sudah pasti).
    if (_cara == CaraRefund.Tukar) {
      _MulaiTukar(k, hasil, pilihan, total.Kurangi(_HitungPotongPiutang(total)), katalog, total);
      return;
    }

    StafLokal? penyetuju;
    if (LayananReturPenjualan.AmbilPenyetujuEfektif(widget.kasir, null) == null) {
      penyetuju = await showDialog<StafLokal>(
        context: context,
        builder: (_) => DialogPinSupervisor(
          izin: IzinKasir.penjualanRetur,
          pesan: 'Retur ${total.FormatRupiah()} wajib disetujui. Pilih supervisor yang menyetujui.',
          judul: 'Retur penjualan',
          nilai: total,
        ),
      );
      if (penyetuju == null || !mounted) {
        return;
      }
    }

    setState(() {
      _sibuk = true;
      _galat = null;
    });
    try {
      final tersimpan = await _layanan.Simpan(
        hasil: hasil,
        pilihan: pilihan,
        alasan: _alasan.text,
        refundTunai: tunai,
        metodeTransfer: metodeTransfer,
        kasir: widget.kasir,
        k: k,
        penyetuju: penyetuju,
        katalog: katalog,
      );
      if (mounted) {
        setState(() => _selesai = tersimpan);
      }
      unawaited(ref.read(penyediaSesi.notifier).Sinkronkan());
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() => _galat = galat.pesan);
        unawaited(UmpanAksi.Gagal(context, judul: 'Retur belum tersimpan', pesan: galat.pesan));
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = false);
      }
    }
  }

  void _MulaiTukar(
    KonteksPenjualan k,
    HasilCariPenjualan hasil,
    List<PilihanReturBaris> pilihan,
    Uang nilai,
    KatalogLokal? katalog,
    Uang totalRetur,
  ) {
    final metode = k.metodeTukar;
    final saatTukar = widget.saatTukar;
    if (metode == null || saatTukar == null) {
      setState(() => _galat = 'Tukar barang belum tersedia. Perbarui data kasir.');
      return;
    }
    if (!ref.read(penyediaKeranjang).CekBebas) {
      setState(() => _galat = 'Keranjang masih berisi. Selesaikan atau tahan transaksi itu dulu sebelum tukar barang.');
      return;
    }
    final alasan = _alasan.text;
    final kasir = widget.kasir;
    final layanan = _layanan;
    final uuidRetur = ref.read(penyediaLayananPenjualan).BuatUuid();
    final penyetuju = PenyetujuTukar(
      izin: IzinKasir.penjualanRetur,
      pesan: 'Retur tukar barang ${totalRetur.FormatRupiah()} wajib disetujui. Pilih supervisor yang menyetujui.',
      bolehSendiri: true,
    );
    ref
        .read(penyediaKeranjang.notifier)
        .Ganti(
          Keranjang.kosong.Salin(
            tukar: () => TukarKeranjang(
              uuidRetur: uuidRetur,
              penyetuju: penyetuju,
              nomorPenjualanAsal: hasil.penjualan.nomor,
              nilai: nilai,
              uuidMetode: metode.Uuid,
              namaMetode: metode.Nama,
              simpanRetur: ({required tukar, required tunai}) async => (await layanan.Simpan(
                hasil: hasil,
                pilihan: pilihan,
                alasan: alasan,
                refundTunai: tunai,
                refundTukar: tukar,
                metodeTukar: metode,
                uuidRetur: uuidRetur,
                kasir: penyetuju.kasir ?? kasir,
                k: k,
                penyetuju: penyetuju.staf,
                katalog: katalog,
              )).nomor,
            ),
          ),
        );
    saatTukar();
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final selesai = _selesai;
    final hasil = _hasil;

    if (_tanpaStruk) {
      return LembarReturTanpaStruk(
        kasir: widget.kasir,
        saatSelesai: widget.saatSelesai,
        saatTukar: widget.saatTukar,
        saatKembali: () => setState(() => _tanpaStruk = false),
      );
    }

    Widget Bingkai(List<Widget> anak) => Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: anak),
    );
    final galat = _galat == null
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak8),
            child: Text(_galat!, style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
          );

    if (selesai != null) {
      return Bingkai([
        Row(
          children: [
            Icon(Icons.check_circle_outline, color: warna.sukses, size: TokenJarak.ikonBesar),
            const SizedBox(width: TokenJarak.jarak8),
            Expanded(child: Text('Retur tersimpan.', style: teks.titleMedium)),
          ],
        ),
        const SizedBox(height: TokenJarak.jarak8),
        TeksKode(selesai.nomor, gaya: teks.titleSmall),
        const SizedBox(height: TokenJarak.jarak12),
        KotakPanel(
          anak: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _BarisNilai(label: 'Total refund', nilai: selesai.totalRefund),
              if (!selesai.potongPiutang.BernilaiNol())
                _BarisNilai(label: 'Potong piutang pelanggan', nilai: selesai.potongPiutang),
              if (!selesai.refundTunai.BernilaiNol())
                _BarisNilai(label: 'Kembalikan tunai dari laci', nilai: selesai.refundTunai, tebal: true),
              if (!selesai.refundTransfer.BernilaiNol())
                _BarisNilai(
                  label: 'Transfer manual ke pelanggan (${selesai.namaMetodeTransfer ?? 'transfer'})',
                  nilai: selesai.refundTransfer,
                  tebal: true,
                ),
            ],
          ),
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          'Stok barang kembali & jurnal dicatat server setelah data terkirim.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
        const SizedBox(height: TokenJarak.jarak12),
        // Cetak struk bagian 3b: nota retur (laci terbuka bila ada refund tunai, hanya pada cetak otomatis pertama).
        BagianCetakDokumen(
          kunci: 'Retur:${selesai.uuid}',
          namaDokumen: 'nota retur',
          cetak: (l, ulang, otomatis) => l.CetakRetur(selesai.uuid, cetakUlang: ulang, bukaLaci: otomatis),
        ),
        const SizedBox(height: TokenJarak.jarak16),
        SizedBox(
          height: 56,
          child: FilledButton(onPressed: widget.saatSelesai, child: const Text('Selesai')),
        ),
      ]);
    }

    final adaPemindaiKamera = ref.watch(penyediaPemindaiQr).CekTersedia();
    final cari = [
      TextField(
        controller: _nomor,
        autofocus: hasil == null,
        textInputAction: TextInputAction.search,
        onChanged: _SaatNomorBerubah,
        onSubmitted: (_) => unawaited(_Cari()),
        decoration: InputDecoration(
          labelText: 'Nomor struk',
          hintText: 'Ketik 4 angka terakhir nomor struk, atau pindai QR di struk',
          border: const OutlineInputBorder(),
          suffixIcon: adaPemindaiKamera
              ? IconButton(
                  key: const ValueKey('PindaiStrukRetur'),
                  tooltip: 'Pindai QR di struk',
                  onPressed: _mencari ? null : () => unawaited(_PindaiKamera()),
                  icon: const Icon(Icons.qr_code_scanner),
                )
              : null,
        ),
      ),
      const SizedBox(height: TokenJarak.jarak8),
      SizedBox(
        height: TokenJarak.targetSentuh,
        child: OutlinedButton(
          onPressed: _mencari ? null : () => unawaited(_Cari()),
          child: Text(_mencari ? 'Mencari struk…' : 'Cari struk'),
        ),
      ),
      if (_mencari) const LinearProgressIndicator(),
      if (hasil == null) ...[
        galat,
        if (_kandidat.isNotEmpty) ...[
          const SizedBox(height: TokenJarak.jarak12),
          Text(_nomor.text.trim().isEmpty ? 'Transaksi terbaru' : 'Pilih struk', style: teks.titleSmall),
          const SizedBox(height: TokenJarak.jarak4),
          for (final k in _kandidat)
            ListTile(
              key: ValueKey('KandidatRetur:${k.uuid}'),
              dense: true,
              contentPadding: EdgeInsets.zero,
              onTap: _mencari ? null : () => _PilihKandidat(k),
              title: TeksKode(k.nomor, gaya: teks.bodyMedium),
              subtitle: Text(
                '${FormatWaktu.FormatTanggalJam(DateTime.parse(k.dibuatPada))} | ${k.labelStatus}',
                style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
              ),
              trailing: TeksUang(Uang.Dari(k.totalAkhir), gaya: teks.bodyMedium),
            ),
        ] else if (!_memuatKandidat && _nomor.text.trim().length >= 2) ...[
          const SizedBox(height: TokenJarak.jarak12),
          Text(
            'Belum ada struk yang cocok dengan "${_nomor.text.trim()}". Struk yang sudah di-void, lewat batas retur, '
            'atau dari outlet lain tidak muncul.',
            key: const ValueKey('KandidatReturKosong'),
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
        ],
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          'Retur butuh internet untuk mencari struk. Batas retur ${_batasHari ?? DataAwal.batasHariReturBawaan} hari '
          'sejak tanggal transaksi. Transaksi di shift ini yang belum ditutup cukup di-void dari Riwayat.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
        const SizedBox(height: TokenJarak.jarak12),
        // K28: pembeli tanpa struk — wajib PIN pemilik/berhak, tukar barang atau deposit saja. Kill switch
        // `kasir.retur-tanpa-struk` (PGL-18) menyembunyikan jalur ini.
        if (CekFlagKasir(ref, KunciFlagFitur.kasirReturTanpaStruk))
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: OutlinedButton.icon(
              key: const ValueKey('BukaReturTanpaStruk'),
              onPressed: () => setState(() {
                _tanpaStruk = true;
                _galat = null;
              }),
              icon: const Icon(Icons.receipt_long_outlined),
              label: const Text('Pembeli tidak membawa struk'),
            ),
          ),
      ],
    ];
    if (hasil == null) {
      return Bingkai(cari);
    }

    final p = hasil.penjualan;
    final tidakBisa = LayananReturPenjualan.AmbilPesanTidakBisaDiretur(p);
    final katalog = ref.watch(penyediaKatalog).value;
    final k = ref.watch(penyediaKonteksPenjualan).value;
    final metode = k?.metodePembayaran ?? const <BarisMetodePembayaran>[];
    // F-16d: penjualan berpelanggan boleh direfund ke saldo deposit (dipilih seperti rekening transfer).
    final transfer = metode
        .where(
          (m) =>
              m.Jenis == JenisMetodeBayar.transfer ||
              (m.Jenis == JenisMetodeBayar.deposit && p.bisaRefundDeposit && (k?.deposit.berlaku ?? false)),
        )
        .toList();
    final metodeNonTunai = transfer.where((m) => m.Uuid == _uuidMetodeTransfer).firstOrNull ?? transfer.firstOrNull;
    final labelNonTunai = metodeNonTunai?.Jenis == JenisMetodeBayar.deposit
        ? 'Ke deposit pelanggan'
        : 'Transfer manual';
    final total = _HitungTotal();
    final tunai = total == null ? null : _HitungTunai(total);

    return Bingkai([
      ...cari,
      const SizedBox(height: TokenJarak.jarak12),
      KotakPanel(
        anak: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TeksKode(p.nomor, gaya: teks.titleSmall),
            const SizedBox(height: TokenJarak.jarak4),
            Text('${_FormatTanggal(p.tanggalBisnis)} | ${p.namaKasir} | ${p.labelStatus}', style: teks.bodySmall),
            _BarisNilai(label: 'Total transaksi', nilai: Uang.Dari(p.totalAkhir)),
            for (final r in hasil.retur)
              Text(
                'Sudah diretur: ${r.nomor} | ${Uang.Dari(r.totalRefund).FormatRupiah()}',
                style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
              ),
          ],
        ),
      ),
      if (tidakBisa != null) ...[
        const SizedBox(height: TokenJarak.jarak12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.block, color: warna.bahaya, size: TokenJarak.ikonSedang),
            const SizedBox(width: TokenJarak.jarak8),
            Expanded(
              child: Text(tidakBisa, style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
            ),
          ],
        ),
      ] else ...[
        const SizedBox(height: TokenJarak.jarak16),
        Text('Barang yang diretur', style: teks.titleSmall),
        if (hasil.baris.any((b) => LayananReturPenjualan.CekPaketSesi(b, katalog)))
          Text(
            'Paket sesi tidak bisa diretur di kasir. Kembalikan sisa sesinya dari back-office.',
            style: teks.bodySmall,
          ),
        if (hasil.baris.any((b) => b.racikan)) Text('Obat racikan tidak bisa diretur.', style: teks.bodySmall),
        for (final b in hasil.baris.where((b) => !b.racikan && !LayananReturPenjualan.CekPaketSesi(b, katalog)))
          _BarisRetur(
            baris: b,
            pengendali: _PengendaliJumlah(b.uuid),
            kondisi: _kondisi[b.uuid] ?? KondisiRetur.layakJual,
            bolehDesimal: LayananReturPenjualan.CekBolehDesimal(b, katalog),
            galat: _GalatBaris(b, LayananReturPenjualan.CekBolehDesimal(b, katalog)),
            nilai: switch (_AmbilJumlah(b)) {
              final j? when !j.BernilaiNol() && _GalatBaris(b, true) == null => PenghitungNilaiRetur.Hitung(b, j),
              _ => null,
            },
            saatBerubah: () => setState(() => _galat = null),
            saatKondisi: (kondisi) => setState(() => _kondisi[b.uuid] = kondisi),
          ),
        const SizedBox(height: TokenJarak.jarak12),
        PilihanAlasan(pengendali: _alasan, pilihan: PilihanAlasan.retur),
        TextField(
          controller: _alasan,
          maxLength: 255,
          decoration: const InputDecoration(
            labelText: 'Alasan retur',
            hintText: 'Contoh: kemasan bocor',
            border: OutlineInputBorder(),
          ),
        ),
        Text('Cara refund', style: teks.titleSmall),
        const SizedBox(height: TokenJarak.jarak8),
        SegmentedButton<CaraRefund>(
          showSelectedIcon: false,
          segments: [
            const ButtonSegment(value: CaraRefund.Tunai, label: Text('Tunai')),
            ButtonSegment(
              value: CaraRefund.Transfer,
              label: Text(transfer.any((m) => m.Jenis == JenisMetodeBayar.deposit) ? 'Non-tunai' : 'Transfer'),
              enabled: transfer.isNotEmpty,
            ),
            ButtonSegment(value: CaraRefund.Campuran, label: const Text('Keduanya'), enabled: transfer.isNotEmpty),
            if (k?.metodeTukar != null && widget.saatTukar != null)
              const ButtonSegment(value: CaraRefund.Tukar, label: Text('Tukar barang')),
          ],
          selected: {_cara},
          onSelectionChanged: (pilih) => setState(() => _cara = pilih.first),
        ),
        if (transfer.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak4),
            child: Text(
              'Metode transfer belum aktif di outlet ini, jadi refund hanya tunai.',
              style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
            ),
          ),
        if ((_cara == CaraRefund.Transfer || _cara == CaraRefund.Campuran) && transfer.length > 1)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: DropdownButtonFormField<String>(
              initialValue: _uuidMetodeTransfer ?? transfer.first.Uuid,
              decoration: const InputDecoration(
                labelText: 'Rekening transfer atau deposit',
                border: OutlineInputBorder(),
              ),
              items: [for (final m in transfer) DropdownMenuItem(value: m.Uuid, child: Text(m.Nama))],
              onChanged: (uuid) => setState(() => _uuidMetodeTransfer = uuid),
            ),
          ),
        if (_cara == CaraRefund.Campuran)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: MasukanUang(
              pengendali: _tunai,
              label: 'Bagian tunai dari laci',
              saatBerubah: (_) => setState(() {}),
            ),
          ),
        const SizedBox(height: TokenJarak.jarak12),
        KotakPanel(
          anak: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: total == null
                ? [Text('Periksa jumlah retur yang ditandai merah.', style: TextStyle(color: warna.bahaya))]
                : [
                    _BarisNilai(label: 'Total refund', nilai: total, tebal: true),
                    if (!_HitungPotongPiutang(total).BernilaiNol())
                      _BarisNilai(label: 'Potong piutang pelanggan', nilai: _HitungPotongPiutang(total)),
                    if (!tunai!.BernilaiNol()) _BarisNilai(label: 'Tunai dari laci', nilai: tunai),
                    if (!total.Kurangi(_HitungPotongPiutang(total)).Kurangi(tunai).BernilaiNol())
                      _BarisNilai(
                        label: labelNonTunai,
                        nilai: total.Kurangi(_HitungPotongPiutang(total)).Kurangi(tunai),
                      ),
                  ],
          ),
        ),
        galat,
        const SizedBox(height: TokenJarak.jarak8),
        SizedBox(
          height: 56,
          child: FilledButton(
            onPressed: _sibuk || k == null ? null : () => unawaited(_Simpan(k, transfer)),
            child: Text(
              _sibuk
                  ? 'Menyimpan…'
                  : _cara == CaraRefund.Tukar
                  ? 'Pilih barang pengganti'
                  : 'Simpan retur',
            ),
          ),
        ),
        const SizedBox(height: TokenJarak.jarak8),
        Text(
          LayananReturPenjualan.AmbilPenyetujuEfektif(widget.kasir, null) == null
              ? (_cara == CaraRefund.Tukar
                    ? 'PIN supervisor diminta nanti, saat pembayaran barang pengganti diselesaikan.'
                    : 'Retur wajib disetujui supervisor dengan PIN.')
              : 'Anda berwenang menyetujui retur ini.',
          style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
        ),
      ],
    ]);
  }

  static String _FormatTanggal(String tanggal) => tanggal.length == 10
      ? '${tanggal.substring(8, 10)}/${tanggal.substring(5, 7)}/${tanggal.substring(0, 4)}'
      : tanggal;
}

/// Satu baris penjualan asal: jumlah retur (maks. sisa) dan kondisi barang.
class _BarisRetur extends StatelessWidget {
  const _BarisRetur({
    required this.baris,
    required this.pengendali,
    required this.kondisi,
    required this.bolehDesimal,
    required this.galat,
    required this.nilai,
    required this.saatBerubah,
    required this.saatKondisi,
  });

  final BarisPenjualanCariPos baris;
  final TextEditingController pengendali;
  final String kondisi;
  final bool bolehDesimal;
  final String? galat;
  final Uang? nilai;
  final VoidCallback saatBerubah;
  final ValueChanged<String> saatKondisi;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final sisa = Kuantitas.Dari(baris.jumlahBisaDiretur);
    final satuan = baris.simbolSatuan.isEmpty ? '' : ' ${baris.simbolSatuan}';
    final habis = sisa.BernilaiNol();
    return Container(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak12),
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: Text(baris.namaProduk, style: teks.bodyLarge)),
              TeksUang(Uang.Dari(baris.totalBaris), gaya: teks.bodyMedium),
            ],
          ),
          if (baris.pilihan.isNotEmpty) Text(baris.pilihan.join(', '), style: teks.bodySmall),
          Text(
            'Terjual ${FormatAngka.FormatJumlah(Kuantitas.Dari(baris.jumlah))}$satuan | '
            '${habis ? 'sudah diretur semua' : 'bisa diretur ${FormatAngka.FormatJumlah(sisa)}$satuan'}',
            style: teks.bodySmall?.copyWith(color: warna.teksSekunder),
          ),
          if (!habis) ...[
            const SizedBox(height: TokenJarak.jarak8),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: TextField(
                    key: ValueKey('JumlahRetur-${baris.uuid}'),
                    controller: pengendali,
                    keyboardType: TextInputType.numberWithOptions(decimal: bolehDesimal),
                    textAlign: TextAlign.right,
                    onChanged: (_) => saatBerubah(),
                    style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()]),
                    decoration: InputDecoration(
                      labelText: 'Jumlah retur',
                      hintText: '0',
                      errorText: galat,
                      border: const OutlineInputBorder(),
                    ),
                  ),
                ),
                const SizedBox(width: TokenJarak.jarak8),
                SizedBox(
                  height: TokenJarak.targetSentuh + 8,
                  child: TextButton(
                    onPressed: () {
                      pengendali.text = FormatAngka.FormatJumlah(sisa);
                      saatBerubah();
                    },
                    child: const Text('Semua'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak8),
            SegmentedButton<String>(
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: KondisiRetur.layakJual, label: Text('Layak jual')),
                ButtonSegment(value: KondisiRetur.rusak, label: Text('Rusak')),
              ],
              selected: {kondisi},
              onSelectionChanged: (pilih) => saatKondisi(pilih.first),
            ),
            if (nilai != null)
              Padding(
                padding: const EdgeInsets.only(top: TokenJarak.jarak4),
                child: Row(
                  children: [
                    Expanded(child: Text('Nilai retur', style: teks.bodySmall)),
                    TeksUang(nilai!, gaya: teks.bodySmall),
                  ],
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _BarisNilai extends StatelessWidget {
  const _BarisNilai({required this.label, required this.nilai, this.tebal = false});

  final String label;
  final Uang nilai;
  final bool tebal;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final gaya = tebal ? teks.titleSmall : teks.bodyMedium;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: Text(label, style: gaya)),
          const SizedBox(width: TokenJarak.jarak12),
          TeksUang(nilai, gaya: gaya),
        ],
      ),
    );
  }
}
