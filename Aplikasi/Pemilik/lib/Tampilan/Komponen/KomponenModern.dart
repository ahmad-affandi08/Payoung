import 'package:flutter/material.dart';
import 'package:sistem_desain/SistemDesain.dart';

/// Komponen bahasa visual modern Aplikasi Owner (D-231): kartu besar membulat tanpa garis tepi, kapsul, kartu sorotan
/// berilustrasi, grafik lengkung, dan bilah navigasi mengambang. Semua warna dari token, tanpa bayangan dekoratif.

/// Campuran [warna] di atas permukaan putih: nada lembut untuk latar ikon, lencana, dan kartu sorotan. Dihitung dari
/// token (bukan warna lepas) sehingga ikut berubah bila palet berubah.
Color TentukanNadaLembut(BuildContext context, Color warna, [double kekuatan = 0.14]) =>
    Color.alphaBlend(warna.withValues(alpha: kekuatan), TokenWarna.AmbilDari(context).permukaan);

/// Kartu putih membulat (radius 24) tanpa garis tepi. Kontras datang dari latar halaman, bukan bayangan.
class KartuModern extends StatelessWidget {
  const KartuModern({
    super.key,
    required this.anak,
    this.padding = const EdgeInsets.all(TokenJarak.jarak16),
    this.warna,
    this.saatTekan,
  });

  final Widget anak;
  final EdgeInsetsGeometry padding;

  /// Latar lain dari putih (kartu sorotan). Null = permukaan.
  final Color? warna;
  final VoidCallback? saatTekan;

  @override
  Widget build(BuildContext context) {
    final token = TokenWarna.AmbilDari(context);
    return Material(
      color: warna ?? token.permukaan,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusKartu)),
      clipBehavior: Clip.antiAlias,
      child: saatTekan == null
          ? Padding(padding: padding, child: anak)
          : InkWell(
              onTap: saatTekan,
              child: Padding(padding: padding, child: anak),
            ),
    );
  }
}

/// Judul halaman besar (tebal) dengan aksi opsional di kanan, untuk tab tanpa bilah atas.
class KepalaHalaman extends StatelessWidget {
  const KepalaHalaman({super.key, required this.judul, this.aksi});

  final String judul;
  final Widget? aksi;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.only(top: TokenJarak.jarak8, bottom: TokenJarak.jarak4),
      child: Row(
        children: [
          Expanded(
            child: Semantics(
              header: true,
              child: Text(
                judul,
                style: teks.headlineMedium?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -0.6),
              ),
            ),
          ),
          if (aksi case final Widget a) a,
        ],
      ),
    );
  }
}

/// Pemilih segmen berbentuk kapsul di dalam wadah putih: satu segmen terisi merek.
class SegmenPil<T> extends StatelessWidget {
  const SegmenPil({super.key, required this.pilihan, required this.nilai, required this.saatPilih});

  final List<(T, String)> pilihan;
  final T nilai;
  final ValueChanged<T> saatPilih;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return DecoratedBox(
      decoration: BoxDecoration(color: warna.permukaan, borderRadius: BorderRadius.circular(TokenJarak.radiusTombol)),
      child: Padding(
        padding: const EdgeInsets.all(TokenJarak.jarak4),
        child: Row(
          children: [
            for (final (kunci, label) in pilihan)
              Expanded(
                child: Semantics(
                  button: true,
                  selected: kunci == nilai,
                  child: InkWell(
                    borderRadius: BorderRadius.circular(TokenJarak.radiusTombol - 4),
                    onTap: () => saatPilih(kunci),
                    child: Container(
                      constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh),
                      alignment: Alignment.center,
                      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak8),
                      decoration: BoxDecoration(
                        color: kunci == nilai ? warna.brand : null,
                        borderRadius: BorderRadius.circular(TokenJarak.radiusTombol - 4),
                      ),
                      child: Text(
                        label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: teks.labelLarge?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: kunci == nilai ? warna.permukaan : warna.teksSekunder,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Ikon dalam kotak membulat berlatar nada lembut dari [warna].
class LingkarIkon extends StatelessWidget {
  const LingkarIkon({super.key, required this.ikon, required this.warna, this.ukuran = 44});

  final IconData ikon;
  final Color warna;
  final double ukuran;

  @override
  Widget build(BuildContext context) => Container(
    width: ukuran,
    height: ukuran,
    decoration: BoxDecoration(
      color: TentukanNadaLembut(context, warna, 0.13),
      borderRadius: BorderRadius.circular(ukuran * 0.34),
    ),
    child: Icon(ikon, size: ukuran * 0.48, color: warna),
  );
}

/// Lencana angka bulat: merah untuk hal yang menunggu keputusan, apricot di atas navigasi.
class LencanaAngka extends StatelessWidget {
  const LencanaAngka({super.key, required this.jumlah, this.aksen = false, this.label});

  final int jumlah;

  /// Apricot berteks gelap (di navigasi), bukan merah.
  final bool aksen;
  final String? label;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Semantics(
      label: label ?? '$jumlah',
      excludeSemantics: true,
      child: Container(
        constraints: const BoxConstraints(minWidth: 22, minHeight: 22),
        padding: const EdgeInsets.symmetric(horizontal: 7),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: aksen ? warna.aksen : warna.bahaya,
          borderRadius: BorderRadius.circular(TokenJarak.radiusKapsul),
        ),
        child: Text(
          '$jumlah',
          style: teks.labelMedium?.copyWith(
            fontWeight: FontWeight.w800,
            color: aksen ? warna.brandGelap : warna.permukaan,
          ),
        ),
      ),
    );
  }
}

/// Wajah pemilik berilustrasi di lingkaran mint, bercincin putih.
class AvatarPemilik extends StatelessWidget {
  const AvatarPemilik({super.key, this.ukuran = 48});

  final double ukuran;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    return Container(
      width: ukuran,
      height: ukuran,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: warna.permukaan, width: 2),
      ),
      child: ClipOval(child: IlustrasiMerek(IlustrasiOwner.Avatar, lebar: ukuran - 4)),
    );
  }
}

/// Satu baris menu: ikon berlatar lembut, judul, keterangan, dan penunjuk. Dipakai di daftar Lainnya dan kartu
/// "Perlu tindakan".
class BarisMenu extends StatelessWidget {
  const BarisMenu({
    super.key,
    required this.ikon,
    required this.warnaIkon,
    required this.judul,
    this.keterangan,
    this.saatTekan,
    this.akhir,
  });

  final IconData ikon;
  final Color warnaIkon;
  final String judul;
  final String? keterangan;
  final VoidCallback? saatTekan;

  /// Pengganti panah di kanan (lencana, nilai). Null = panah bila [saatTekan] ada.
  final Widget? akhir;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final isi = ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 64),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16, vertical: TokenJarak.jarak12),
        child: Row(
          children: [
            LingkarIkon(ikon: ikon, warna: warnaIkon),
            const SizedBox(width: TokenJarak.jarak12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(judul, style: teks.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                  if (keterangan case final String k)
                    Text(k, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
                ],
              ),
            ),
            if (akhir case final Widget a)
              a
            else if (saatTekan != null)
              Icon(Icons.chevron_right, color: warna.garisInput),
          ],
        ),
      ),
    );
    return saatTekan == null ? isi : InkWell(onTap: saatTekan, child: isi);
  }
}

/// Daftar [BarisMenu] dalam satu kartu, dipisah garis tipis.
class DaftarMenu extends StatelessWidget {
  const DaftarMenu({super.key, required this.baris});

  final List<Widget> baris;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    return KartuModern(
      padding: EdgeInsets.zero,
      anak: Column(
        children: [
          for (final (i, b) in baris.indexed) ...[
            if (i > 0)
              Divider(height: 1, indent: TokenJarak.jarak16, endIndent: TokenJarak.jarak16, color: warna.brandTint),
            b,
          ],
        ],
      ),
    );
  }
}

/// Kartu sorotan: judul + keterangan di kiri, ilustrasi di kanan (menyembul dari tepi), dan tautan opsional.
class KartuSorotan extends StatelessWidget {
  const KartuSorotan({
    super.key,
    required this.latar,
    required this.judul,
    required this.keterangan,
    required this.ilustrasi,
    this.lebarIlustrasi = 128,
    this.tautan,
    this.saatTekan,
    this.gelap = false,
  });

  final Color latar;
  final String judul;
  final String keterangan;
  final IlustrasiOwner ilustrasi;
  final double lebarIlustrasi;
  final String? tautan;
  final VoidCallback? saatTekan;

  /// Latar gelap: teks putih.
  final bool gelap;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final utama = gelap ? warna.permukaan : warna.teksUtama;
    final kedua = gelap ? warna.permukaan.withValues(alpha: 0.78) : warna.teksSekunder;
    return Material(
      color: latar,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(TokenJarak.radiusKartuUtama - 2)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: saatTekan,
        child: Padding(
          padding: const EdgeInsets.only(
            left: TokenJarak.jarak16 + 2,
            top: TokenJarak.jarak16,
            bottom: TokenJarak.jarak8,
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(bottom: TokenJarak.jarak8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        judul,
                        style: teks.titleMedium?.copyWith(fontWeight: FontWeight.w800, color: utama),
                      ),
                      const SizedBox(height: TokenJarak.jarak4),
                      Text(keterangan, style: teks.bodyMedium?.copyWith(color: kedua)),
                      if (tautan case final String t) ...[
                        const SizedBox(height: TokenJarak.jarak8),
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(
                              t,
                              style: teks.labelLarge?.copyWith(
                                fontWeight: FontWeight.w800,
                                color: gelap ? warna.aksen : warna.brand,
                              ),
                            ),
                            Icon(
                              Icons.chevron_right,
                              size: TokenJarak.ikonSedang,
                              color: gelap ? warna.aksen : warna.brand,
                            ),
                          ],
                        ),
                      ],
                    ],
                  ),
                ),
              ),
              Transform.translate(
                offset: const Offset(10, 6),
                child: IlustrasiMerek(ilustrasi, lebar: lebarIlustrasi),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Grafik lengkung mulus untuk kartu hero: garis apricot dengan isi tipis dan titik di ujung kanan. [titik] sudah
/// dinormalkan 0 sampai 1 (nol di dasar). Hiasan; angkanya selalu tertulis di dekatnya (§17.6.11).
class GrafikLengkung extends StatelessWidget {
  const GrafikLengkung({super.key, required this.titik, this.tinggi = 72, this.label});

  final List<double> titik;
  final double tinggi;
  final String? label;

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    return Semantics(
      label: label,
      image: true,
      child: CustomPaint(
        size: Size(double.infinity, tinggi),
        painter: _PelukisLengkung(
          titik: titik,
          garis: warna.aksen,
          isi: warna.permukaan.withValues(alpha: 0.08),
          cincin: warna.brandGelap,
        ),
      ),
    );
  }
}

class _PelukisLengkung extends CustomPainter {
  _PelukisLengkung({required this.titik, required this.garis, required this.isi, required this.cincin});

  final List<double> titik;
  final Color garis;
  final Color isi;
  final Color cincin;

  @override
  void paint(Canvas kanvas, Size ukuran) {
    if (titik.length < 2) {
      return;
    }
    const tepi = 6.0;
    final tinggiPakai = ukuran.height - tepi * 2;
    final langkah = (ukuran.width - tepi * 2) / (titik.length - 1);
    final p = <Offset>[
      for (final (i, v) in titik.indexed) Offset(tepi + i * langkah, tepi + tinggiPakai * (1 - v.clamp(0.0, 1.0))),
    ];
    final jalur = Path()..moveTo(p.first.dx, p.first.dy);
    for (var i = 0; i < p.length - 1; i++) {
      final p0 = i > 0 ? p[i - 1] : p[i];
      final p1 = p[i];
      final p2 = p[i + 1];
      final p3 = i + 2 < p.length ? p[i + 2] : p[i + 1];
      final c1 = Offset(p1.dx + (p2.dx - p0.dx) / 6, p1.dy + (p2.dy - p0.dy) / 6);
      final c2 = Offset(p2.dx - (p3.dx - p1.dx) / 6, p2.dy - (p3.dy - p1.dy) / 6);
      jalur.cubicTo(c1.dx, c1.dy, c2.dx, c2.dy, p2.dx, p2.dy);
    }
    final area = Path.from(jalur)
      ..lineTo(p.last.dx, ukuran.height)
      ..lineTo(p.first.dx, ukuran.height)
      ..close();
    kanvas
      ..drawPath(area, Paint()..color = isi)
      ..drawPath(
        jalur,
        Paint()
          ..color = garis
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3
          ..strokeCap = StrokeCap.round
          ..strokeJoin = StrokeJoin.round,
      )
      ..drawCircle(p.last, 7, Paint()..color = cincin)
      ..drawCircle(p.last, 5, Paint()..color = garis);
  }

  @override
  bool shouldRepaint(_PelukisLengkung lama) => !_Sama(lama.titik, titik) || lama.garis != garis || lama.isi != isi;

  static bool _Sama(List<double> a, List<double> b) {
    if (a.length != b.length) {
      return false;
    }
    for (var i = 0; i < a.length; i++) {
      if (a[i] != b[i]) {
        return false;
      }
    }
    return true;
  }
}

/// Satu tujuan di bilah navigasi mengambang.
class ButirNavigasi {
  const ButirNavigasi({required this.ikon, required this.ikonAktif, required this.label, this.lencana = 0});

  final IconData ikon;
  final IconData ikonAktif;
  final String label;

  /// Jumlah yang menunggu (lencana apricot). Nol = tanpa lencana.
  final int lencana;
}

/// Bilah navigasi kapsul yang mengambang di atas latar halaman: ikon aktif terisi merek, label selalu tampil.
class BilahNavigasiMengambang extends StatelessWidget {
  const BilahNavigasiMengambang({super.key, required this.butir, required this.indeks, required this.saatPilih});

  final List<ButirNavigasi> butir;
  final int indeks;
  final ValueChanged<int> saatPilih;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return ColoredBox(
      color: warna.latar,
      child: SafeArea(
        top: false,
        minimum: const EdgeInsets.only(bottom: TokenJarak.jarak8),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            TokenJarak.jarak16 - 2,
            TokenJarak.jarak4,
            TokenJarak.jarak16 - 2,
            TokenJarak.jarak4,
          ),
          child: Material(
            color: warna.permukaan,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(34)),
            child: SizedBox(
              height: 72,
              child: Row(
                children: [
                  for (final (i, b) in butir.indexed)
                    Expanded(
                      child: Semantics(
                        button: true,
                        selected: i == indeks,
                        label: b.lencana > 0 ? '${b.label}, ${b.lencana} menunggu' : b.label,
                        excludeSemantics: true,
                        child: InkWell(
                          customBorder: const StadiumBorder(),
                          onTap: () => saatPilih(i),
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Stack(
                                clipBehavior: Clip.none,
                                alignment: Alignment.center,
                                children: [
                                  AnimatedContainer(
                                    duration: const Duration(milliseconds: 180),
                                    width: 52,
                                    height: 32,
                                    decoration: BoxDecoration(
                                      color: i == indeks ? warna.brand : null,
                                      borderRadius: BorderRadius.circular(16),
                                    ),
                                    child: Icon(
                                      i == indeks ? b.ikonAktif : b.ikon,
                                      size: 22,
                                      color: i == indeks ? warna.permukaan : warna.teksSekunder,
                                    ),
                                  ),
                                  if (b.lencana > 0)
                                    Positioned(
                                      top: -6,
                                      right: -2,
                                      child: DecoratedBox(
                                        decoration: BoxDecoration(
                                          shape: BoxShape.circle,
                                          border: Border.all(color: warna.permukaan, width: 2),
                                        ),
                                        child: LencanaAngka(jumlah: b.lencana, aksen: true),
                                      ),
                                    ),
                                ],
                              ),
                              const SizedBox(height: 2),
                              Text(
                                b.label,
                                style: teks.labelSmall?.copyWith(
                                  fontWeight: i == indeks ? FontWeight.w800 : FontWeight.w700,
                                  color: i == indeks ? warna.brand : warna.teksSekunder,
                                ),
                              ),
                            ],
                          ),
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

/// Membuka halaman turunan (Shift, Perangkat, Karyawan, Insight) dengan bilah atas modern dan tombol kembali.
Future<void> BukaHalaman(BuildContext context, {required String judul, required Widget isi}) => Navigator.of(context)
    .push(
      MaterialPageRoute<void>(
        builder: (_) => Scaffold(
          appBar: AppBar(title: Text(judul)),
          body: SafeArea(top: false, child: isi),
        ),
      ),
    );

/// Jarak vertikal standar antar bagian halaman.
const SizedBox JarakBagian = SizedBox(height: TokenJarak.jarak16);
