import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/AvatarKasir.dart';
import 'JamRuangKerja.dart';

/// Bilah atas ruang kerja (PRD §17.2.7, D-16): logo merek outlet di kiri dengan nama outlet **dan** baris status
/// (koneksi, tertunda, printer, shift) di bawahnya, logo Payoung di tengah, lalu identitas kasir, jam, dan tombol
/// Kunci di kanan.
///
/// Latarnya memakai warna merek gelap, bukan permukaan putih: bingkai ruang kerja adalah satu-satunya bagian layar
/// yang tidak berganti sepanjang shift, jadi ia yang memberi aplikasi ini identitas. Yang dipakai `brandGelap`
/// (#22383a), bukan `brand` (#3b5b5d), karena teks putih di atasnya berkontras ±10:1 dibanding ±4,9:1 — nama outlet
/// dan jam harus terbaca dari jarak berdiri kasir, bukan hanya dari dekat.
class BilahAtasRuangKerja extends ConsumerWidget {
  const BilahAtasRuangKerja({
    super.key,
    required this.kasir,
    required this.saatGantiKasir,
    required this.saatKunci,
    required this.status,
  });

  static const double tinggi = 60;

  /// Di bawah lebar ini (HP) bilahnya diringkas: ikon merek saja, kasir tanpa nama (nama ada di petunjuknya dan di
  /// layar Shift), dan tanpa jam — jam sistem sudah berada persis di atasnya. Tanpa peringkasan ini tidak ada ruang
  /// untuk menaruh logo di tengah tanpa memotong nama outlet.
  static const double lebarLega = 600;

  /// Di bawah lebar ini baris status belum muat utuh di bawah nama outlet; `RuangKerja` menaruhnya di bilah status
  /// tersendiri seperti sebelumnya.
  static const double lebarStatus = 960;

  /// Batas lebar tombol kasir di layar lega; lebih dari ini nama dipendekkan dengan elipsis.
  static const double lebarMaksKasir = 240;

  /// Tinggi logo merek di kiri bilah; sama dengan tinggi logo Payoung di tengah.
  static const double tinggiLogo = 30;

  final StafLokal kasir;
  final VoidCallback saatGantiKasir;
  final VoidCallback saatKunci;

  /// Status ruang kerja (koneksi, tertunda sinkron, printer, shift). Di layar lega tampil di bawah nama outlet.
  final List<ItemBilahStatus> status;

  /// Pil tonal di atas latar merek gelap (D-66): putih 12% dengan sudut membulat penuh.
  static ButtonStyle _GayaPil(TokenWarna warna) => TextButton.styleFrom(
    foregroundColor: warna.permukaan,
    backgroundColor: warna.permukaan.withValues(alpha: 0.12),
    shape: const StadiumBorder(),
    padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12),
    minimumSize: const Size(0, 40),
  );

  /// Logo merek outlet di kiri bilah: bait gambar asli dari data awal (D-16), setinggi logo Payoung. Latar putih
  /// tipis di belakangnya supaya logo berwarna gelap tetap terbaca di atas bilah merek gelap; tanpa logo (atau
  /// gambarnya rusak) yang tampil ikon toko.
  static Widget _LogoOutlet(TokenWarna warna, Uint8List? logo) {
    if (logo == null) {
      return Container(
        width: 32,
        height: 32,
        decoration: BoxDecoration(
          color: warna.permukaan.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
        ),
        child: Icon(Icons.storefront_outlined, size: TokenJarak.ikonSedang, color: warna.permukaan),
      );
    }

    return Container(
      height: tinggiLogo + 4,
      constraints: const BoxConstraints(maxWidth: 132),
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak4, vertical: 2),
      decoration: BoxDecoration(color: warna.permukaan, borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol)),
      child: Image.memory(
        logo,
        height: tinggiLogo,
        fit: BoxFit.contain,
        cacheHeight: 60,
        gaplessPlayback: true,
        filterQuality: FilterQuality.medium,
        semanticLabel: 'Logo outlet',
        errorBuilder: (_, _, _) =>
            Icon(Icons.storefront_outlined, size: TokenJarak.ikonKecil, color: warna.teksSekunder),
      ),
    );
  }

  /// Avatar inisial kasir: apricot dengan huruf merek gelap, terbaca di atas bilah gelap.
  static Widget _Avatar(TokenWarna warna, String nama, double ukuran) => ExcludeSemantics(
    child: Container(
      width: ukuran,
      height: ukuran,
      alignment: Alignment.center,
      decoration: BoxDecoration(shape: BoxShape.circle, color: warna.aksen),
      child: Text(
        AvatarKasir.Inisial(nama),
        style: TextStyle(
          fontFamily: fontUtama,
          package: paketFont,
          fontSize: ukuran * 0.4,
          fontWeight: FontWeight.w700,
          color: warna.brandGelap,
        ),
      ),
    ),
  );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final identitas = ref.watch(penyediaIdentitas).value;
    final logo = ref.watch(penyediaLogoOutlet).value;
    final lebar = MediaQuery.sizeOf(context).width;
    final lega = lebar >= lebarLega;
    final lokasi = identitas == null
        ? ''
        : [identitas.outlet, identitas.perangkat].where((b) => b.isNotEmpty).join(' | ');
    final gayaBilah = teks.labelLarge?.copyWith(color: warna.permukaan, fontWeight: FontWeight.w600);
    final gayaPil = _GayaPil(warna);

    // Ikon status bar dibuat terang karena bilah ini gelap; `SafeArea` di dalam `Material` supaya warna merek ikut
    // mengisi area status bar, bukan menyisakan garis putih di puncak layar.
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light.copyWith(statusBarColor: warna.brandGelap),
      child: Material(
        color: warna.brandGelap,
        child: SafeArea(
          bottom: false,
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border(
                bottom: BorderSide(color: warna.permukaan.withValues(alpha: 0.08), width: TokenJarak.tebalGaris),
              ),
            ),
            child: SizedBox(
              height: tinggi,
              child: Row(
                children: [
                  // Kiri dan kanan sama-sama `Expanded` dengan flex sama, jadi logo di tengah tetap berada di tengah
                  // layar walau nama outlet panjang dan lebar tombol di kanan berubah-ubah.
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(left: TokenJarak.jarak16),
                      child: Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: Row(
                          children: [
                            _LogoOutlet(warna, logo),
                            const SizedBox(width: TokenJarak.jarak12),
                            Expanded(
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    lokasi,
                                    style: gayaBilah,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    softWrap: false,
                                  ),
                                  // D-16: isi bilah status pindah ke bawah nama outlet di layar lebar, sehingga
                                  // tidak perlu pita status tersendiri di bawah bilah.
                                  if (lebar >= lebarStatus) ...[
                                    const SizedBox(height: 2),
                                    BilahStatus(item: status, gelap: true, tanpaLatar: true),
                                  ],
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12),
                    child: lega ? const LogoMerek.lengkapPutih(tinggi: 30) : const LogoMerek.ikonPutih(tinggi: 30),
                  ),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(right: TokenJarak.jarak12),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
                          Flexible(
                            child: Tooltip(
                              message: 'Ganti kasir',
                              child: lega
                                  ? ConstrainedBox(
                                      constraints: const BoxConstraints(maxWidth: lebarMaksKasir),
                                      child: TextButton(
                                        onPressed: saatGantiKasir,
                                        style: gayaPil.copyWith(
                                          padding: const WidgetStatePropertyAll(
                                            EdgeInsets.only(left: 4, right: TokenJarak.jarak12),
                                          ),
                                        ),
                                        child: Row(
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            _Avatar(warna, kasir.nama, 32),
                                            const SizedBox(width: TokenJarak.jarak8),
                                            Flexible(
                                              child: Text(
                                                kasir.nama,
                                                maxLines: 1,
                                                overflow: TextOverflow.ellipsis,
                                                softWrap: false,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                    )
                                  : InkResponse(
                                      onTap: saatGantiKasir,
                                      radius: 24,
                                      child: Padding(
                                        padding: const EdgeInsets.all(TokenJarak.jarak8),
                                        child: _Avatar(warna, kasir.nama, 32),
                                      ),
                                    ),
                            ),
                          ),
                          if (lega) ...[
                            const SizedBox(width: TokenJarak.jarak12),
                            JamRuangKerja(gaya: gayaBilah),
                            const SizedBox(width: TokenJarak.jarak12),
                            TextButton.icon(
                              onPressed: saatKunci,
                              style: gayaPil,
                              icon: const Icon(Icons.lock_outline, size: TokenJarak.ikonSedang),
                              label: const Text('Kunci'),
                            ),
                          ] else
                            IconButton(
                              tooltip: 'Kunci',
                              onPressed: saatKunci,
                              color: warna.permukaan,
                              style: IconButton.styleFrom(backgroundColor: warna.permukaan.withValues(alpha: 0.12)),
                              icon: const Icon(Icons.lock_outline),
                            ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
