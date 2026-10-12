import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/BasisData/BasisDataKasir.dart';
import '../Data/RepositoriPenjualan.dart';
import '../Domain/GalatKasir.dart';
import '../Domain/Penjualan/LayananVoidPenjualan.dart';
import 'Komponen/FormatAngka.dart';
import 'Komponen/FormatWaktu.dart';
import 'RuangKerja/BagianKerja.dart';
import 'RuangKerja/IsiAreaKerja.dart';
import 'Struk/TombolKirimStruk.dart';
import 'Struk/TombolStrukTamu.dart';

/// Pesan tetap bila riwayat gagal dimuat; detail galat hanya ke log (tidak menampilkan teks exception ke kasir).
const String pesanGagalMuat = 'Riwayat transaksi tidak bisa dimuat. Coba lagi.';

void CatatGalat(String konteks, Object galat, StackTrace? jejak) =>
    debugPrint('LayarRiwayat: gagal memuat $konteks: $galat${jejak == null ? '' : '\n$jejak'}');

/// Detail baris & pembayaran satu penjualan lokal.
final penyediaDetailPenjualan =
    FutureProvider.family<({List<BarisPenjualanDetail> detail, List<BarisPenjualanPembayaran> pembayaran}), String>((
      ref,
      uuid,
    ) async {
      final repo = ref.watch(penyediaRepositoriPenjualan);
      return (detail: await repo.AmbilDetail(uuid), pembayaran: await repo.AmbilPembayaran(uuid));
    });

/// Riwayat transaksi perangkat hari ini (Rincian F-07c) dengan status sinkron per transaksi: Terkirim, Belum terkirim
/// (masih di outbox), atau Perlu tindakan (ditolak server; kirim ulang dari layar Sinkron). Status selalu berteks.
/// F-09 fase 1: "Void transaksi" untuk transaksi `Lunas` shift yang masih terbuka ([saatVoid]), "Retur dari struk"
/// ([saatRetur]), dan daftar retur hari ini. Void & retur dibuka sebagai panel tugas oleh bingkai ruang kerja.
class LayarRiwayat extends ConsumerStatefulWidget {
  const LayarRiwayat({
    super.key,
    this.saatVoid,
    this.saatRetur,
    this.saatReturStruk,
    this.saatAmbilPreOrder,
    this.saatPesananOnline,
    this.saatReservasi,
    this.saatServis,
    this.saatCucian,
  });

  /// Buka lembar void untuk Uuid penjualan.
  final ValueChanged<String>? saatVoid;

  /// Buka lembar retur dari struk.
  final VoidCallback? saatRetur;

  /// Audit kemudahan pakai #10: buka lembar retur/tukar langsung untuk nomor struk baris riwayat (tanpa mengetik).
  final ValueChanged<String>? saatReturStruk;

  /// F-12 bagian 2: buka lembar cari & ambil pre-order.
  final VoidCallback? saatAmbilPreOrder;

  /// F-17: pesanan toko online yang menunggu ditagihkan.
  final VoidCallback? saatPesananOnline;

  /// F-07 mode service: buka lembar antrian reservasi hari ini.
  final VoidCallback? saatReservasi;

  /// Bengkel bagian 2: buka lembar perintah kerja siap tagih.
  final VoidCallback? saatServis;

  /// Laundry: buka lembar daftar cucian (null = laundry belum aktif).
  final VoidCallback? saatCucian;

  /// Label status dokumen penjualan selain `Lunas` (selalu berteks, bukan hanya warna).
  static String? AmbilLabelStatusDokumen(String status) => switch (status) {
    StatusPenjualanLokal.divoid => 'Void',
    StatusPenjualanLokal.direturSebagian => 'Diretur sebagian',
    StatusPenjualanLokal.diretur => 'Diretur',
    _ => null,
  };

  static ({IconData ikon, String teks, NadaStatus nada}) AmbilStatus(StatusSinkronPenjualan status) => switch (status) {
    StatusSinkronPenjualan.Terkirim => (ikon: Icons.cloud_done_outlined, teks: 'Terkirim', nada: NadaStatus.Sukses),
    StatusSinkronPenjualan.BelumTerkirim => (
      ikon: Icons.cloud_upload_outlined,
      teks: 'Belum terkirim',
      nada: NadaStatus.Peringatan,
    ),
    StatusSinkronPenjualan.PerluTindakan => (
      ikon: Icons.error_outline,
      teks: 'Perlu tindakan',
      nada: NadaStatus.Bahaya,
    ),
  };

  /// Penjualan yang masih bisa diretur dari riwayat: bukan void dan belum diretur seluruhnya.
  static bool CekBisaDiretur(BarisPenjualan p) =>
      p.Status != StatusPenjualanLokal.divoid && p.Status != StatusPenjualanLokal.diretur;

  /// Audit kemudahan pakai #10: cocokkan kata cari dengan sebagian nomor struk (tanpa beda huruf besar/kecil) atau
  /// nominal total (angka saja, titik ribuan diabaikan, misal "45.000" atau "45000").
  static bool CekCocokCari(BarisPenjualan p, String kata) {
    final cari = kata.trim().toLowerCase();
    if (cari.isEmpty) {
      return true;
    }
    if (p.Nomor.toLowerCase().contains(cari)) {
      return true;
    }
    final angka = cari.replaceAll('.', '').replaceAll(' ', '');
    if (angka.isEmpty || !RegExp(r'^\d+$').hasMatch(angka)) {
      return false;
    }
    return Uang.Dari(p.TotalAkhir).FormatRupiah().replaceAll(RegExp(r'[^0-9,]'), '').split(',').first.contains(angka);
  }

  @override
  ConsumerState<LayarRiwayat> createState() => _LayarRiwayatState();
}

class _LayarRiwayatState extends ConsumerState<LayarRiwayat> {
  final _cari = TextEditingController();

  /// D-40: transaksi yang rinciannya tampil di panel kanan (layar lebar). Null = transaksi teratas.
  String? _uuidTerpilih;

  @override
  void dispose() {
    _cari.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final saatVoid = widget.saatVoid;
    final saatReturStruk = widget.saatReturStruk;
    final riwayat = ref.watch(penyediaRiwayatHariIni);
    final semua = riwayat.value ?? const <RiwayatPenjualan>[];
    final daftar = semua.where((r) => LayarRiwayat.CekCocokCari(r.penjualan, _cari.text)).toList();
    final dihitung = semua.where((r) => r.penjualan.Status != StatusPenjualanLokal.divoid).toList();
    final total = dihitung.fold(Uang.Nol(), (t, r) => t.Tambah(Uang.Dari(r.penjualan.TotalAkhir)));
    final jumlahVoid = semua.length - dihitung.length;
    final retur = ref.watch(penyediaReturHariIni).value ?? const <RiwayatRetur>[];
    final shiftAktif = ref.watch(penyediaShiftAktif).value;
    final konteks = ref.watch(penyediaKonteksPenjualan).value;
    final hariIni = konteks?.HitungTanggalBisnis(ref.read(penyediaJam)());
    final dipilih = ref.watch(penyediaTanggalRiwayat);
    final tanggal = dipilih ?? hariIni;
    final lampau = dipilih != null && dipilih != hariIni;

    VoidCallback? AmbilSaatVoid(RiwayatPenjualan r) =>
        saatVoid != null && LayananVoidPenjualan.CekBisaDivoid(r.penjualan, shiftAktif)
        ? () => saatVoid(r.penjualan.Uuid)
        : null;
    VoidCallback? AmbilSaatRetur(RiwayatPenjualan r) =>
        saatReturStruk != null && LayarRiwayat.CekBisaDiretur(r.penjualan)
        ? () => saatReturStruk(r.penjualan.Nomor)
        : null;

    return LayoutBuilder(
      builder: (context, batas) {
        // D-40: di area kerja lebar, daftar di kiri dan rincian transaksi terpilih di panel kanan (master-detail);
        // di layar sempit baris tetap dibuka-tutup di tempat.
        final berdampingan = batas.maxWidth >= IsiAreaKerja.lebarMinimumPanelSamping;
        final terpilih = daftar.where((r) => r.penjualan.Uuid == _uuidTerpilih).firstOrNull ?? daftar.firstOrNull;
        return _SusunHalaman(
          context,
          berdampingan: berdampingan,
          terpilih: terpilih,
          semua: semua,
          daftar: daftar,
          dihitung: dihitung,
          total: total,
          jumlahVoid: jumlahVoid,
          retur: retur,
          riwayat: riwayat,
          tanggal: tanggal,
          hariIni: hariIni,
          lampau: lampau,
          AmbilSaatVoid: AmbilSaatVoid,
          AmbilSaatRetur: AmbilSaatRetur,
        );
      },
    );
  }

  Widget _SusunHalaman(
    BuildContext context, {
    required bool berdampingan,
    required RiwayatPenjualan? terpilih,
    required List<RiwayatPenjualan> semua,
    required List<RiwayatPenjualan> daftar,
    required List<RiwayatPenjualan> dihitung,
    required Uang total,
    required int jumlahVoid,
    required List<RiwayatRetur> retur,
    required AsyncValue<List<RiwayatPenjualan>> riwayat,
    required String? tanggal,
    required String? hariIni,
    required bool lampau,
    required VoidCallback? Function(RiwayatPenjualan) AmbilSaatVoid,
    required VoidCallback? Function(RiwayatPenjualan) AmbilSaatRetur,
  }) {
    final saatRetur = widget.saatRetur;
    final saatAmbilPreOrder = widget.saatAmbilPreOrder;
    final saatPesananOnline = widget.saatPesananOnline;
    final saatReservasi = widget.saatReservasi;
    final saatServis = widget.saatServis;
    final saatCucian = widget.saatCucian;
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return IsiAreaKerja(
      judul: lampau ? 'Riwayat transaksi' : 'Riwayat transaksi hari ini',
      aksi: [
        if (saatRetur != null)
          OutlinedButton.icon(
            onPressed: saatRetur,
            icon: const Icon(Icons.assignment_return_outlined),
            label: const Text('Retur dari struk'),
          ),
        if (saatAmbilPreOrder != null)
          OutlinedButton.icon(
            onPressed: saatAmbilPreOrder,
            icon: const Icon(Icons.event_available_outlined),
            label: const Text('Ambil pre-order'),
          ),
        if (saatPesananOnline != null)
          OutlinedButton.icon(
            onPressed: saatPesananOnline,
            icon: const Icon(Icons.shopping_bag_outlined),
            label: const Text('Pesanan toko online'),
          ),
        if (saatReservasi != null)
          OutlinedButton.icon(
            onPressed: saatReservasi,
            icon: const Icon(Icons.event_note_outlined),
            label: const Text('Reservasi hari ini'),
          ),
        if (saatServis != null)
          OutlinedButton.icon(
            onPressed: saatServis,
            icon: const Icon(Icons.build_outlined),
            label: const Text('Servis siap tagih'),
          ),
        if (saatCucian != null)
          OutlinedButton.icon(
            onPressed: saatCucian,
            icon: const Icon(Icons.local_laundry_service_outlined),
            label: const Text('Cucian'),
          ),
      ],
      anak: [
        // K-24: riwayat perangkat per tanggal (data lokal, offline) & ringkasan akhir hari outlet (server).
        Wrap(
          spacing: TokenJarak.jarak16,
          runSpacing: TokenJarak.jarak4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (tanggal != null && hariIni != null) _NavigasiTanggal(tanggal: tanggal, hariIni: hariIni),
            Text(
              semua.isEmpty
                  ? (lampau
                        ? 'Tidak ada transaksi pada tanggal ini di perangkat ini.'
                        : 'Belum ada transaksi hari ini di perangkat ini.')
                  : '${dihitung.length} transaksi | ${total.FormatRupiah()}'
                        '${jumlahVoid == 0 ? '' : ' | $jumlahVoid void'}',
              style: teks.titleSmall,
            ),
          ],
        ),
        if (riwayat.isLoading && riwayat.value == null) const LinearProgressIndicator(),
        if (riwayat.hasError)
          Builder(
            builder: (_) {
              CatatGalat('riwayat hari ini', riwayat.error!, riwayat.stackTrace);
              return Text(pesanGagalMuat, style: TextStyle(color: warna.bahaya));
            },
          ),
        if (semua.isNotEmpty) ...[
          const SizedBox(height: TokenJarak.jarak8),
          TextField(
            controller: _cari,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(
              labelText: 'Cari nomor struk atau nominal',
              prefixIcon: const Icon(Icons.search),
              border: const OutlineInputBorder(),
              isDense: true,
              suffixIcon: _cari.text.isEmpty
                  ? null
                  : IconButton(
                      tooltip: 'Hapus pencarian',
                      onPressed: () => setState(_cari.clear),
                      icon: const Icon(Icons.close),
                    ),
            ),
          ),
          if (daftar.isEmpty)
            KeadaanKosong(
              ikon: Icons.search_off,
              ilustrasi: IlustrasiKosong.Cari,
              ringkas: true,
              judul: 'Tidak ada transaksi yang cocok',
              keterangan: 'Tidak ada hasil untuk "${_cari.text.trim()}". Periksa nomor struk atau nominalnya.',
            ),
        ],
        const SizedBox(height: TokenJarak.jarak8),
        if (semua.isEmpty && riwayat.hasValue)
          KotakPanel(
            anak: KeadaanKosong(
              ilustrasi: IlustrasiKosong.Penjualan,
              ikon: Icons.receipt_long_outlined,
              judul: lampau ? 'Tidak ada struk di tanggal ini' : 'Struk pertama hari ini akan muncul di sini',
              keterangan:
                  'Setiap transaksi yang selesai di kasir ini tampil lengkap dengan isi, cara bayar, dan status '
                  'kirimnya. Dari sini Anda bisa cetak ulang, kirim struk, retur, atau membatalkan transaksi.',
            ),
          ),
        if (daftar.isNotEmpty)
          Material(
            color: warna.permukaan,
            shape: RoundedRectangleBorder(
              side: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
              borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                for (var i = 0; i < daftar.length; i++) ...[
                  if (i > 0) Divider(height: TokenJarak.tebalGaris, color: warna.garis),
                  _BarisRiwayat(
                    riwayat: daftar[i],
                    saatVoid: AmbilSaatVoid(daftar[i]),
                    saatRetur: AmbilSaatRetur(daftar[i]),
                    berdampingan: berdampingan,
                    terpilih: berdampingan && daftar[i].penjualan.Uuid == terpilih?.penjualan.Uuid,
                    saatPilih: () => setState(() => _uuidTerpilih = daftar[i].penjualan.Uuid),
                  ),
                ],
              ],
            ),
          ),
        if (retur.isNotEmpty) ...[
          const SizedBox(height: TokenJarak.jarak16),
          Text(lampau ? 'Retur' : 'Retur hari ini', style: teks.titleSmall),
          const SizedBox(height: TokenJarak.jarak4),
          Material(
            color: warna.permukaan,
            shape: RoundedRectangleBorder(
              side: BorderSide(color: warna.garis, width: TokenJarak.tebalGaris),
              borderRadius: BorderRadius.circular(TokenJarak.radiusPanel),
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                for (var i = 0; i < retur.length; i++) ...[
                  if (i > 0) Divider(height: TokenJarak.tebalGaris, color: warna.garis),
                  _BarisRetur(riwayat: retur[i]),
                ],
              ],
            ),
          ),
        ],
      ],
      panelSamping: !berdampingan
          ? const []
          : [
              const JudulPanelSamping('Rincian transaksi'),
              if (terpilih == null)
                const KeadaanKosong(
                  ringkas: true,
                  ilustrasi: IlustrasiKosong.Penjualan,
                  ikon: Icons.receipt_long_outlined,
                  judul: 'Belum ada transaksi dipilih',
                  keterangan:
                      'Ketuk transaksi di daftar untuk melihat isi struk, cetak ulang, kirim, retur, atau batal.',
                )
              else
                _PanelRincian(
                  key: ValueKey(terpilih.penjualan.Uuid),
                  riwayat: terpilih,
                  saatVoid: AmbilSaatVoid(terpilih),
                  saatRetur: AmbilSaatRetur(terpilih),
                ),
            ],
    );
  }
}

class _BarisRetur extends StatelessWidget {
  const _BarisRetur({required this.riwayat});

  final RiwayatRetur riwayat;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final r = riwayat.retur;
    final status = LayarRiwayat.AmbilStatus(riwayat.status);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: TokenJarak.jarak8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: TeksKode(r.Nomor, gaya: teks.labelLarge)),
              TeksUang(Uang.Nol().Kurangi(Uang.Dari(r.TotalRefund)), gaya: teks.labelLarge),
            ],
          ),
          Row(
            children: [
              Expanded(
                child: Text(
                  '${FormatWaktu.FormatJam(r.DibuatPada)} | ${r.NamaKasir} | ${r.NomorPenjualanAsal.isEmpty ? 'tanpa struk' : 'dari ${r.NomorPenjualanAsal}'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: teks.bodySmall,
                ),
              ),
              Icon(status.ikon, size: TokenJarak.ikonKecil, color: BilahStatus.AmbilWarnaNada(warna, status.nada)),
              const SizedBox(width: TokenJarak.jarak4),
              Text(status.teks, style: teks.bodySmall?.copyWith(color: warna.teksUtama)),
            ],
          ),
          if (riwayat.status == StatusSinkronPenjualan.PerluTindakan && riwayat.pesanGalat != null)
            Text(riwayat.pesanGalat!, style: teks.bodySmall?.copyWith(color: warna.bahaya)),
        ],
      ),
    );
  }
}

class _BarisRiwayat extends StatelessWidget {
  const _BarisRiwayat({
    required this.riwayat,
    this.saatVoid,
    this.saatRetur,
    this.berdampingan = false,
    this.terpilih = false,
    this.saatPilih,
  });

  final RiwayatPenjualan riwayat;

  /// Null = transaksi ini tidak bisa diretur (void/diretur penuh) atau retur tidak tersedia.
  final VoidCallback? saatRetur;

  /// Null = transaksi ini tidak bisa di-void di perangkat ini sekarang.
  final VoidCallback? saatVoid;

  /// D-40: rincian tampil di panel kanan; baris hanya dipilih (tidak dibuka-tutup di tempat).
  final bool berdampingan;
  final bool terpilih;
  final VoidCallback? saatPilih;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final p = riwayat.penjualan;
    final status = LayarRiwayat.AmbilStatus(riwayat.status);
    final labelDokumen = LayarRiwayat.AmbilLabelStatusDokumen(p.Status);
    final divoid = p.Status == StatusPenjualanLokal.divoid;
    final judul = Row(
      children: [
        Expanded(child: TeksKode(p.Nomor, gaya: teks.labelLarge)),
        if (labelDokumen != null) ...[
          Icon(
            divoid ? Icons.block : Icons.assignment_return_outlined,
            size: TokenJarak.ikonKecil,
            color: divoid ? warna.bahaya : warna.teksSekunder,
          ),
          const SizedBox(width: TokenJarak.jarak4),
          Text(labelDokumen, style: teks.labelMedium?.copyWith(color: divoid ? warna.bahaya : warna.teksUtama)),
          const SizedBox(width: TokenJarak.jarak8),
        ],
        TeksUang(
          Uang.Dari(p.TotalAkhir),
          gaya: teks.labelLarge?.copyWith(decoration: divoid ? TextDecoration.lineThrough : null),
        ),
      ],
    );
    final subjudul = Row(
      children: [
        Expanded(
          child: Text(
            '${FormatWaktu.FormatJam(p.DibuatPada)} | ${p.NamaKasir}'
            '${riwayat.metode.isEmpty ? '' : ' | ${riwayat.metode.join(' + ')}'}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: teks.bodySmall,
          ),
        ),
        Icon(status.ikon, size: TokenJarak.ikonKecil, color: BilahStatus.AmbilWarnaNada(warna, status.nada)),
        const SizedBox(width: TokenJarak.jarak4),
        Text(status.teks, style: teks.bodySmall?.copyWith(color: warna.teksUtama)),
      ],
    );

    if (berdampingan) {
      return Semantics(
        selected: terpilih,
        button: true,
        child: InkWell(
          onTap: saatPilih,
          child: Container(
            constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh + TokenJarak.jarak16),
            padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12, vertical: TokenJarak.jarak8),
            decoration: BoxDecoration(
              color: terpilih ? warna.brand.withValues(alpha: 0.06) : null,
              border: Border(left: BorderSide(color: terpilih ? warna.brand : Colors.transparent, width: 3)),
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [judul, const SizedBox(height: 2), subjudul],
            ),
          ),
        ),
      );
    }

    return Theme(
      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        key: ValueKey(p.Uuid),
        dense: true,
        tilePadding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12),
        childrenPadding: const EdgeInsets.fromLTRB(TokenJarak.jarak12, 0, TokenJarak.jarak12, TokenJarak.jarak12),
        title: judul,
        subtitle: subjudul,
        children: [_IsiRincian(riwayat: riwayat, saatVoid: saatVoid, saatRetur: saatRetur)],
      ),
    );
  }
}

/// Isi rincian satu penjualan: baris barang, pembayaran, kembalian, dan tombol cetak ulang/kirim/retur/batal. Dipakai
/// di baris yang dibuka (layar sempit) dan di panel kanan (layar lebar, [panel] = tombol bertumpuk selebar panel).
class _IsiRincian extends ConsumerWidget {
  const _IsiRincian({required this.riwayat, this.saatVoid, this.saatRetur, this.panel = false});

  final RiwayatPenjualan riwayat;
  final VoidCallback? saatVoid;
  final VoidCallback? saatRetur;
  final bool panel;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final p = riwayat.penjualan;
    final tombol = <Widget>[
      _TombolCetakUlang(uuidPenjualan: p.Uuid),
      TombolStrukTamu(uuidPenjualan: p.Uuid, cetakUlang: true),
      TombolKirimStruk(uuidPenjualan: p.Uuid),
      if (saatRetur != null)
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: OutlinedButton.icon(
            onPressed: saatRetur,
            icon: const Icon(Icons.assignment_return_outlined),
            label: const Text('Retur / tukar'),
          ),
        ),
      if (saatVoid != null)
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: OutlinedButton.icon(
            onPressed: saatVoid,
            icon: const Icon(Icons.block),
            label: const Text('Batalkan transaksi'),
          ),
        ),
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (riwayat.status == StatusSinkronPenjualan.PerluTindakan && riwayat.pesanGalat != null)
          Padding(
            padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
            child: Text(riwayat.pesanGalat!, style: TextStyle(color: warna.bahaya)),
          ),
        ref
            .watch(penyediaDetailPenjualan(p.Uuid))
            .when(
              loading: () => const LinearProgressIndicator(),
              error: (galat, jejak) {
                CatatGalat('detail penjualan ${p.Uuid}', galat, jejak);
                return Text(pesanGagalMuat, style: TextStyle(color: warna.bahaya));
              },
              data: (isi) => Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final d in isi.detail)
                    Padding(
                      padding: EdgeInsets.symmetric(vertical: panel ? 3 : 0),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Text(
                              '${FormatAngka.FormatJumlah(Kuantitas.Dari(d.Jumlah))}× ${d.NamaProduk}',
                              style: teks.bodyMedium,
                            ),
                          ),
                          const SizedBox(width: TokenJarak.jarak8),
                          TeksUang(Uang.Dari(d.TotalBaris)),
                        ],
                      ),
                    ),
                  if (panel) ...[
                    Divider(color: warna.garis, height: TokenJarak.jarak24),
                    Row(
                      children: [
                        Expanded(child: Text('Total dibayar', style: teks.titleSmall)),
                        TeksUang(Uang.Dari(p.TotalAkhir), gaya: teks.titleMedium),
                      ],
                    ),
                    const SizedBox(height: TokenJarak.jarak4),
                  ] else
                    const SizedBox(height: TokenJarak.jarak8),
                  for (final b in isi.pembayaran)
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${b.NamaMetode}${b.Referensi == null ? '' : ' | ${b.Referensi}'}',
                            style: teks.bodySmall,
                          ),
                        ),
                        TeksUang(Uang.Dari(b.Jumlah), gaya: teks.bodySmall),
                      ],
                    ),
                  if (!Uang.Dari(p.Kembalian).BernilaiNol())
                    Row(
                      children: [
                        Expanded(child: Text('Kembalian', style: teks.bodySmall)),
                        TeksUang(Uang.Dari(p.Kembalian), gaya: teks.bodySmall),
                      ],
                    ),
                  SizedBox(height: panel ? TokenJarak.jarak16 : TokenJarak.jarak12),
                  if (panel)
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final (i, t) in tombol.indexed) ...[
                          if (i > 0) const SizedBox(height: TokenJarak.jarak8),
                          t,
                        ],
                      ],
                    )
                  else
                    Wrap(spacing: TokenJarak.jarak8, runSpacing: TokenJarak.jarak8, children: tombol),
                ],
              ),
            ),
      ],
    );
  }
}

/// D-40: rincian transaksi terpilih di panel kanan. Teks nomor & status sengaja berbeda bentuk dengan baris daftar
/// ("No. …", "Belum sampai di server") supaya satu informasi tidak tampil dua kali dengan kata yang sama.
class _PanelRincian extends StatelessWidget {
  const _PanelRincian({super.key, required this.riwayat, this.saatVoid, this.saatRetur});

  final RiwayatPenjualan riwayat;
  final VoidCallback? saatVoid;
  final VoidCallback? saatRetur;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final p = riwayat.penjualan;
    final status = LayarRiwayat.AmbilStatus(riwayat.status);
    final teksKirim = switch (riwayat.status) {
      StatusSinkronPenjualan.Terkirim => 'Sudah di server',
      StatusSinkronPenjualan.BelumTerkirim => 'Menunggu dikirim',
      StatusSinkronPenjualan.PerluTindakan => 'Ditolak server',
    };
    final teksDokumen = switch (p.Status) {
      StatusPenjualanLokal.divoid => 'Dibatalkan (void)',
      StatusPenjualanLokal.direturSebagian => 'Sebagian barang diretur',
      StatusPenjualanLokal.diretur => 'Seluruh barang diretur',
      _ => 'Lunas',
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text.rich(
          TextSpan(
            children: [
              const TextSpan(text: 'No. '),
              TextSpan(
                text: p.Nomor,
                style: const TextStyle(fontFamily: fontMono, package: paketFont),
              ),
            ],
          ),
          style: teks.titleSmall,
        ),
        const SizedBox(height: TokenJarak.jarak4),
        BarisInfo(label: 'Waktu', nilai: Text(FormatWaktu.FormatTanggalJam(p.DibuatPada))),
        BarisInfo(label: 'Dilayani', nilai: Text(p.NamaKasir)),
        BarisInfo(label: 'Dokumen', nilai: Text(teksDokumen)),
        BarisInfo(
          label: 'Pengiriman data',
          nilai: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(status.ikon, size: TokenJarak.ikonKecil, color: BilahStatus.AmbilWarnaNada(warna, status.nada)),
              const SizedBox(width: TokenJarak.jarak4),
              Flexible(child: Text(teksKirim)),
            ],
          ),
        ),
        Divider(color: warna.garis, height: TokenJarak.jarak24),
        _IsiRincian(riwayat: riwayat, saatVoid: saatVoid, saatRetur: saatRetur, panel: true),
      ],
    );
  }
}

/// Cetak ulang struk dari riwayat (bertanda "CETAK ULANG"). Hasil ditampilkan lewat snackbar; printer belum diatur
/// = tombol tetap ada dan menjelaskan cara mengaturnya.
class _TombolCetakUlang extends ConsumerStatefulWidget {
  const _TombolCetakUlang({required this.uuidPenjualan});

  final String uuidPenjualan;

  @override
  ConsumerState<_TombolCetakUlang> createState() => _TombolCetakUlangState();
}

class _TombolCetakUlangState extends ConsumerState<_TombolCetakUlang> {
  var _mencetak = false;

  Future<void> _Cetak() async {
    setState(() => _mencetak = true);
    final galat = await ref.read(penyediaPrinter.notifier).CetakPenjualan(widget.uuidPenjualan, cetakUlang: true);
    if (!mounted) {
      return;
    }
    setState(() => _mencetak = false);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(galat ?? 'Struk dicetak ulang.')));
  }

  @override
  Widget build(BuildContext context) => SizedBox(
    height: TokenJarak.targetSentuh,
    child: OutlinedButton.icon(
      onPressed: _mencetak ? null : _Cetak,
      icon: const Icon(Icons.print_outlined),
      label: Text(_mencetak ? 'Mencetak…' : 'Cetak ulang struk'),
    ),
  );
}

/// K-24: geser tanggal riwayat (sampai 31 hari ke belakang, tidak ke masa depan) dan buka ringkasan akhir hari outlet.
class _NavigasiTanggal extends ConsumerWidget {
  const _NavigasiTanggal({required this.tanggal, required this.hariIni});

  final String tanggal;
  final String hariIni;

  static const int batasHari = 31;
  static const List<String> _bulan = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
  ];

  static String Geser(String tanggal, int hari) {
    final t = DateTime.utc(
      int.parse(tanggal.substring(0, 4)),
      int.parse(tanggal.substring(5, 7)),
      int.parse(tanggal.substring(8, 10)),
    ).add(Duration(days: hari));
    return '${t.year}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';
  }

  static String Label(String tanggal) =>
      '${int.parse(tanggal.substring(8, 10))} ${_bulan[int.parse(tanggal.substring(5, 7)) - 1]} ${tanggal.substring(0, 4)}';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final teks = Theme.of(context).textTheme;
    final notifier = ref.read(penyediaTanggalRiwayat.notifier);
    final paling = Geser(hariIni, -batasHari);
    void Atur(String baru) => notifier.Atur(baru == hariIni ? null : baru);
    return Padding(
      padding: EdgeInsets.zero,
      child: Wrap(
        spacing: TokenJarak.jarak8,
        runSpacing: TokenJarak.jarak8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              IconButton(
                tooltip: 'Hari sebelumnya',
                onPressed: tanggal.compareTo(paling) <= 0 ? null : () => Atur(Geser(tanggal, -1)),
                icon: const Icon(Icons.chevron_left),
              ),
              Flexible(
                child: Text(
                  tanggal == hariIni ? '${Label(tanggal)} (hari ini)' : Label(tanggal),
                  key: const ValueKey('TanggalRiwayat'),
                  style: teks.titleSmall,
                ),
              ),
              IconButton(
                tooltip: 'Hari berikutnya',
                onPressed: tanggal.compareTo(hariIni) >= 0 ? null : () => Atur(Geser(tanggal, 1)),
                icon: const Icon(Icons.chevron_right),
              ),
            ],
          ),
          TextButton.icon(
            onPressed: () => showDialog<void>(
              context: context,
              builder: (_) => _DialogRingkasanHarian(tanggal: tanggal),
            ),
            icon: const Icon(Icons.summarize_outlined),
            label: const Text('Ringkasan outlet'),
          ),
        ],
      ),
    );
  }
}

/// K-24: ringkasan akhir hari outlet (semua perangkat) dari server.
class _DialogRingkasanHarian extends ConsumerStatefulWidget {
  const _DialogRingkasanHarian({required this.tanggal});

  final String tanggal;

  @override
  ConsumerState<_DialogRingkasanHarian> createState() => _DialogRingkasanHarianState();
}

class _DialogRingkasanHarianState extends ConsumerState<_DialogRingkasanHarian> {
  late final Future<RingkasanHarianPos> _ringkasan = ref
      .read(penyediaLayananRingkasanHarian)
      .Ambil(tanggal: widget.tanggal);

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    Widget Baris(String label, Widget nilai, {TextStyle? gaya}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: gaya ?? teks.bodyMedium)),
          const SizedBox(width: TokenJarak.jarak8),
          Flexible(
            child: FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerRight, child: nilai),
          ),
        ],
      ),
    );
    return AlertDialog(
      title: Text('Ringkasan outlet | ${_NavigasiTanggal.Label(widget.tanggal)}'),
      content: SizedBox(
        width: 480,
        child: FutureBuilder<RingkasanHarianPos>(
          future: _ringkasan,
          builder: (context, hasil) {
            if (hasil.connectionState != ConnectionState.done) {
              return const LinearProgressIndicator();
            }
            final r = hasil.data;
            if (r == null) {
              final galat = hasil.error;
              return Text(
                galat is GalatKasir ? galat.pesan : 'Ringkasan belum bisa dimuat. Coba lagi.',
                style: TextStyle(color: warna.bahaya),
              );
            }
            return SingleChildScrollView(
              child: Column(
                key: const ValueKey('RingkasanOutlet'),
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Semua perangkat kasir di outlet ini, dihitung server dari transaksi yang sudah terkirim.',
                    style: teks.bodySmall,
                  ),
                  const SizedBox(height: TokenJarak.jarak8),
                  Baris('Transaksi', Text('${r.jumlahTransaksi}', style: teks.bodyMedium)),
                  Baris('Void', Text('${r.jumlahVoid}', style: teks.bodyMedium)),
                  Baris(
                    'Retur',
                    Text('${r.jumlahRetur} | ${Uang.Dari(r.retur).FormatRupiah()}', style: teks.bodyMedium),
                  ),
                  Baris('Penjualan kotor', TeksUang(Uang.Dari(r.kotor))),
                  Baris('Diskon', TeksUang(Uang.Dari(r.diskon))),
                  Baris('Pajak', TeksUang(Uang.Dari(r.pajak))),
                  Baris(
                    'Penjualan bersih',
                    TeksUang(Uang.Dari(r.bersih), gaya: teks.titleMedium),
                    gaya: teks.titleMedium,
                  ),
                  if (r.perMetodeBayar.isNotEmpty) ...[
                    const Divider(),
                    Text('Per metode bayar', style: teks.titleSmall),
                    for (final m in r.perMetodeBayar) Baris(m.nama, TeksUang(Uang.Dari(m.jumlah))),
                  ],
                  if (r.perKasir.isNotEmpty) ...[
                    const Divider(),
                    Text('Per kasir', style: teks.titleSmall),
                    for (final k in r.perKasir)
                      Baris('${k.nama} | ${k.jumlahTransaksi} transaksi', TeksUang(Uang.Dari(k.bersih))),
                  ],
                ],
              ),
            );
          },
        ),
      ),
      actions: [TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Tutup'))],
    );
  }
}
