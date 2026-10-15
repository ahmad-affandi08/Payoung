<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Aksi\BuatTiketDukungan;
use App\Domain\Dukungan\Data\DataTiketBaru;
use App\Domain\Dukungan\Enum\KategoriTiketDukungan;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Integrasi\Billing\GerbangBillingPlatform;
use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use App\Domain\Integrasi\Snap\GerbangBillingSnap;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Tenant\Aksi\AjukanTurunPaket;
use App\Domain\Tenant\Aksi\BatalkanTagihanLangganan;
use App\Domain\Tenant\Aksi\BatalkanTurunPaket;
use App\Domain\Tenant\Aksi\BeliAddonLangganan;
use App\Domain\Tenant\Aksi\BuatTagihanLangganan;
use App\Domain\Tenant\Aksi\MulaiPembayaranGerbangLangganan;
use App\Domain\Tenant\Aksi\PindahKeGratis;
use App\Domain\Tenant\Aksi\SegarkanPembayaranGerbangLangganan;
use App\Domain\Tenant\Aksi\UbahPerpanjanganAddonLangganan;
use App\Domain\Tenant\Aksi\UnggahBuktiTransfer;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Kueri\AddonLanggananTenant;
use App\Domain\Tenant\Kueri\PenawaranFiturTenant;
use App\Domain\Tenant\Kueri\PratinjauTurunPaket;
use App\Domain\Tenant\Kueri\RekeningTujuanPlatform;
use App\Domain\Tenant\Kueri\TagihanLanggananTenant;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Http\Kontroler\Kontroler;
use App\Http\Permintaan\Kelola\Langganan\BatalkanTagihanLanggananPermintaan;
use App\Http\Permintaan\Kelola\Langganan\BuatTagihanLanggananPermintaan;
use App\Http\Permintaan\Kelola\Langganan\UnggahBuktiTransferPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Langganan & tagihan di back-office tenant: lihat paket & status, buat tagihan,
 * bayar online via gerbang billing. Rute dijaga `WajibIzinTenant` dengan izin `langganan.kelola`.
 */
final class LanggananKontroler extends Kontroler
{
    public function __construct(private readonly TagihanLanggananTenant $kueri) {}

    public function Tampilkan(AddonLanggananTenant $addon, PratinjauTurunPaket $turunPaket): Response
    {
        return Inertia::render('Kelola/Langganan/Indeks', [
            'TurunPaket' => $turunPaket->Ambil(),
            'Langganan' => $this->kueri->AmbilLangganan(),
            'Addon' => $addon->Ambil(app(KonteksTenant::class)->Wajib()),
            'PilihanPaket' => $this->kueri->AmbilPilihanPaket(),
            'Tagihan' => $this->kueri->DaftarTagihan(),
            'HariMasaTenggang' => (int) config('tagihan.HariMasaTenggang'),
        ]);
    }

    public function BuatTagihan(BuatTagihanLanggananPermintaan $permintaan, BuatTagihanLangganan $buat): RedirectResponse
    {
        $pengguna = $this->PenggunaMasuk();
        $tagihan = $buat->Jalankan(
            $pengguna->Id,
            $permintaan->string('KodePaket')->toString(),
            $permintaan->AmbilSiklus(),
            $permintaan->AmbilKodeKupon(),
        );

        // D-112: kupon 100% membuat tagihan Rp 0 yang langsung lunas, tidak ada yang dibayar.
        $pesan = $tagihan->Status === StatusTagihanLangganan::Lunas
            ? "Tagihan {$tagihan->Nomor} bernilai Rp 0 karena kupon, jadi langsung lunas. Paket {$tagihan->Paket->Nama} sudah aktif."
            : "Tagihan {$tagihan->Nomor} dibuat. Silakan selesaikan pembayaran online.";

        return redirect()->route('kelola.langganan.tagihan.tampil', ['tagihan' => $tagihan->Uuid])->with('Kilat', $pesan);
    }

    public function TampilkanTagihan(string $tagihan, GerbangBillingPlatform $gerbang, GerbangBillingSnap $gerbangSnap, SegarkanPembayaranGerbangLangganan $segarkan, RekeningTujuanPlatform $rekening): Response
    {
        $data = $this->kueri->CariTagihan($tagihan);
        abort_if($data === null, 404);

        // v5.34: kembali dari halaman bayar DOKU = tanyakan status ke DOKU sekarang, jangan menunggu webhook.
        $berubah = false;

        foreach ($data->Pembayaran as $baris) {
            $berubah = $segarkan->Jalankan($baris) || $berubah;
        }

        if ($berubah) {
            $data = $this->kueri->CariTagihan($tagihan);
            abort_if($data === null, 404);
        }

        $pembayaran = $data->Pembayaran->sortByDesc('Id')->values();
        // Pembatalan tetap diblokir pembayaran `Menunggu` apa pun, termasuk transaksi gerbang yang sedang berjalan:
        // kalau tidak, tagihan bisa dibatalkan tepat saat uangnya sedang masuk.
        $adaPembayaranMenunggu = $pembayaran->contains(fn (PembayaranLangganan $baris): bool => $baris->Status === StatusPembayaranLangganan::Menunggu);
        $terbuka = $data->Status->CekTerbuka();

        return Inertia::render('Kelola/Langganan/Tagihan', [
            'Tagihan' => TagihanLanggananTenant::PetakanTagihan($data),
            'Pembayaran' => array_values($pembayaran->map(fn (PembayaranLangganan $baris): array => TagihanLanggananTenant::PetakanPembayaran($baris))->all()),
            // D-112: tagihan manual (paket harga negosiasi) dibayar dengan transfer; tagihan biasa hanya lewat gerbang (D-82).
            'BolehBayarOnline' => $terbuka && ! $data->Manual && ($gerbang->CekAktif() || $gerbangSnap->CekAktif()),
            // D-159: cara bayar SNAP (halaman bayar Payoung) dan apakah DOKU Checkout (kartu & lainnya) juga tersedia.
            'PilihanBayar' => $terbuka && ! $data->Manual ? $gerbangSnap->AmbilPilihan() : [],
            'BolehCheckout' => $gerbang->CekAktif(),
            'BolehUnggahBukti' => $terbuka && $data->Manual && ! $adaPembayaranMenunggu && $rekening->Ambil() !== [],
            'RekeningTujuan' => $data->Manual ? $rekening->Ambil() : [],
            'BolehBatalkan' => $terbuka && ! $adaPembayaranMenunggu,
        ]);
    }

    /**
     * Membuat transaksi DOKU Checkout untuk tagihan ini (BR-P08.11). Menjawab JSON berisi `UrlBayar`; halaman
     * mengarahkan peramban ke sana. Tagihan tidak pernah lunas dari sini: pelunasan hanya dari notifikasi webhook
     * bertanda tangan (atau rekonsiliasi status).
     */
    public function BayarOnline(string $tagihan, Request $permintaan, MulaiPembayaranGerbangLangganan $mulai): JsonResponse
    {
        $pengguna = $this->PenggunaMasuk();
        $urlKembali = route('kelola.langganan.tagihan.tampil', ['tagihan' => $tagihan]);
        $valid = $permintaan->validate(
            ['Metode' => ['nullable', 'string', 'in:'.implode(',', array_column(MetodeBayarSnap::cases(), 'value'))], 'Kanal' => ['nullable', 'string', 'max:40']],
            attributes: ['Metode' => 'cara bayar', 'Kanal' => 'bank'],
        );

        // D-159: dengan `Metode` = halaman bayar Payoung (SNAP); tanpa itu tetap DOKU Checkout (kartu & metode lain).
        if (isset($valid['Metode']) && $valid['Metode'] !== '') {
            $pembayaran = $mulai->JalankanSnap(
                $tagihan,
                $pengguna->Id,
                $pengguna->Nama,
                (string) $pengguna->Email,
                $urlKembali,
                MetodeBayarSnap::from((string) $valid['Metode']),
                isset($valid['Kanal']) && $valid['Kanal'] !== '' ? (string) $valid['Kanal'] : null,
                $permintaan->ip(),
            );

            return response()->json(['Pembayaran' => TagihanLanggananTenant::PetakanPembayaran($pembayaran)]);
        }

        $hasil = $mulai->Jalankan($tagihan, $pengguna->Id, $pengguna->Nama, (string) $pengguna->Email, $urlKembali);

        return response()->json(['UrlBayar' => $hasil->urlBayar]);
    }

    /**
     * D-159: ditanyakan berkala oleh halaman bayar Payoung selagi QR/nomor VA ditampilkan. Setiap pembayaran yang masih
     * menunggu ditanyakan ke DOKU (dibatasi `SegarkanPembayaranGerbangLangganan::JEDA_DETIK`); jawabannya hanya status
     * menurut server, tidak pernah dipercayakan ke peramban.
     */
    public function StatusBayar(string $tagihan, SegarkanPembayaranGerbangLangganan $segarkan): JsonResponse
    {
        $data = $this->kueri->CariTagihan($tagihan);
        abort_if($data === null, 404);

        foreach ($data->Pembayaran as $baris) {
            if ($segarkan->Jalankan($baris)) {
                $data = $this->kueri->CariTagihan($tagihan) ?? $data;
            }
        }

        $menunggu = $data->Pembayaran->contains(fn (PembayaranLangganan $baris): bool => $baris->Status === StatusPembayaranLangganan::Menunggu);

        return response()->json([
            'StatusTagihan' => $data->Status->value,
            'Lunas' => $data->Status === StatusTagihanLangganan::Lunas,
            'MasihMenunggu' => $menunggu,
        ]);
    }

    /** D-112: Owner mengunggah bukti transfer untuk tagihan manual; diverifikasi Keuangan di antrean P-08. */
    public function UnggahBukti(string $tagihan, UnggahBuktiTransferPermintaan $permintaan, UnggahBuktiTransfer $unggah): RedirectResponse
    {
        $data = $this->kueri->CariTagihan($tagihan);
        abort_if($data === null, 404);

        if (! $data->Manual) {
            throw new PelanggaranAturanBisnis('BukanTagihanManual', 'Tagihan ini dibayar lewat pembayaran online, bukan transfer.');
        }

        $pengguna = $this->PenggunaMasuk();
        $unggah->Jalankan($tagihan, $permintaan->AmbilData(), $pengguna->Id, $pengguna->Nama, (string) $pengguna->Email);

        return redirect()->route('kelola.langganan.tagihan.tampil', ['tagihan' => $tagihan])
            ->with('Kilat', 'Bukti transfer terkirim. Tim keuangan akan memeriksanya; Anda diberi tahu lewat email.');
    }

    public function Batalkan(string $tagihan, BatalkanTagihanLanggananPermintaan $permintaan, BatalkanTagihanLangganan $batalkan): RedirectResponse
    {
        $hasil = $batalkan->Jalankan($tagihan, $permintaan->AmbilAlasan());

        return redirect()->route('kelola.langganan.tampil')->with('Kilat', "Tagihan {$hasil->Nomor} dibatalkan.");
    }

    /**
     * D-49: beli add-on mandiri. Menerbitkan tagihan prorata sampai akhir periode; add-on aktif setelah tagihan lunas.
     */
    public function BeliAddon(Request $permintaan, BeliAddonLangganan $beli): RedirectResponse
    {
        $valid = $permintaan->validate(
            ['KodeAddon' => ['required', 'string', 'max:30'], 'Jumlah' => ['nullable', 'integer', 'min:1', 'max:'.BeliAddonLangganan::JUMLAH_MAKS]],
            attributes: ['KodeAddon' => 'add-on', 'Jumlah' => 'jumlah'],
        );
        $tagihan = $beli->Jalankan($this->PenggunaMasuk()->Id, (string) $valid['KodeAddon'], (int) ($valid['Jumlah'] ?? 1));

        return redirect()->route('kelola.langganan.tagihan.tampil', ['tagihan' => $tagihan->Uuid])
            ->with('Kilat', "Tagihan {$tagihan->Nomor} dibuat. Add-on aktif begitu pembayaran selesai.");
    }

    /** BR-P04.4: minta turun paket (berlaku pada perpanjangan berikutnya atau saat periode berakhir untuk Gratis). */
    public function AjukanTurunPaket(Request $permintaan, AjukanTurunPaket $ajukan): RedirectResponse
    {
        $valid = $permintaan->validate([
            'KodePaket' => ['required', 'string', 'max:30'],
            'UuidOutletTetap' => ['nullable', 'array', 'max:200'],
            'UuidOutletTetap.*' => ['string', 'max:36'],
            'UuidAnggotaTetap' => ['nullable', 'array', 'max:500'],
            'UuidAnggotaTetap.*' => ['string', 'max:36'],
        ], attributes: ['KodePaket' => 'paket']);
        $turun = $ajukan->Jalankan(
            $this->PenggunaMasuk()->Id,
            (string) $valid['KodePaket'],
            array_values(array_map('strval', $valid['UuidOutletTetap'] ?? [])),
            array_values(array_map('strval', $valid['UuidAnggotaTetap'] ?? [])),
        );
        $gratis = $turun->PaketTujuan->Kode === (string) config('tenant.KodePaketGratis');
        $tanggal = $turun->BerlakuPada->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y');

        return back()->with('Kilat', $gratis
            ? "Langganan akan turun ke paket Gratis pada {$tanggal}. Sampai saat itu semua fitur paket Anda tetap bisa dipakai."
            : "Paket {$turun->PaketTujuan->Nama} berlaku mulai perpanjangan berikutnya (setelah {$tanggal}). Sampai saat itu paket Anda tidak berubah.");
    }

    /** Langganan ditangguhkan karena tunggakan: pindah ke paket Gratis seketika (kasir terbuka lagi dalam batas Gratis). */
    public function PindahKeGratis(Request $permintaan, PindahKeGratis $pindah): RedirectResponse
    {
        $valid = $permintaan->validate([
            'UuidOutletTetap' => ['nullable', 'array', 'max:200'],
            'UuidOutletTetap.*' => ['string', 'max:36'],
            'UuidAnggotaTetap' => ['nullable', 'array', 'max:500'],
            'UuidAnggotaTetap.*' => ['string', 'max:36'],
        ]);
        $pindah->Jalankan(
            $this->PenggunaMasuk()->Id,
            array_values(array_map('strval', $valid['UuidOutletTetap'] ?? [])),
            array_values(array_map('strval', $valid['UuidAnggotaTetap'] ?? [])),
        );

        return back()->with('Kilat', 'Usaha Anda sekarang memakai paket Gratis. Kasir terbuka lagi dalam batas paket Gratis; data tidak dihapus.');
    }

    public function BatalkanTurunPaket(string $turunPaket, BatalkanTurunPaket $batalkan): RedirectResponse
    {
        $batalkan->Jalankan($turunPaket, $this->PenggunaMasuk()->Id);

        return back()->with('Kilat', 'Permintaan turun paket dibatalkan. Paket Anda tidak berubah.');
    }

    /** D-49: berhenti berlangganan add-on (aktif sampai akhir periode, tidak ditagih lagi). */
    public function HentikanAddon(string $addon, UbahPerpanjanganAddonLangganan $ubah): RedirectResponse
    {
        $milik = $ubah->Jalankan($addon, false);

        return back()->with('Kilat', "Add-on {$milik->Addon->Nama} tidak akan diperpanjang. Tetap aktif sampai ".$milik->SelesaiPada->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y').'.');
    }

    /** D-49: melanjutkan add-on yang tadinya dihentikan, selama masih aktif. */
    public function LanjutkanAddon(string $addon, UbahPerpanjanganAddonLangganan $ubah): RedirectResponse
    {
        $milik = $ubah->Jalankan($addon, true);

        return back()->with('Kilat', "Add-on {$milik->Addon->Nama} akan diperpanjang otomatis bersama paket Anda.");
    }

    /**
     * D-23: minta add-on lewat tiket dukungan. Sejak D-49 add-on dibeli mandiri; tiket tetap menjadi jalur cadangan untuk
     * kasus yang tidak bisa dibeli mandiri (paket berharga khusus, langganan belum aktif). Tim platform mengaktifkannya.
     */
    public function MintaAddon(Request $permintaan, PenawaranFiturTenant $penawaran, BuatTiketDukungan $buatTiket): RedirectResponse
    {
        $kunci = (string) $permintaan->validate(['KunciFitur' => ['required', 'string', 'max:60']], attributes: ['KunciFitur' => 'fitur'])['KunciFitur'];
        $pengguna = $this->PenggunaMasuk();
        $fitur = $penawaran->Ambil(app(KonteksTenant::class)->Wajib())['Terkunci'][$kunci] ?? null;
        $addon = $fitur['Addon'] ?? null;

        if ($fitur === null || $addon === null) {
            return back()->withErrors(['Umum' => 'Add-on untuk fitur ini tidak tersedia atau fitur sudah aktif.']);
        }

        $harga = Uang::Dari($addon['HargaBulanan'])->FormatRupiah();
        $tiket = $buatTiket->Jalankan($pengguna->Id, $pengguna->Nama, new DataTiketBaru(
            KategoriTiketDukungan::AkunLangganan,
            PrioritasTiketDukungan::Normal,
            "Permintaan add-on {$addon['Nama']}",
            "Mohon aktifkan add-on {$addon['Nama']} ({$harga}/bulan) untuk membuka fitur \"{$fitur['Nama']}\". Tagihan dikirim ke usaha ini.",
            konteks: ['KodeAddon' => $addon['Kode'], 'KunciFitur' => $kunci],
        ));

        return redirect()->route('kelola.bantuan.tampil', ['tiketDukungan' => $tiket->Uuid])
            ->with('Kilat', "Permintaan add-on {$addon['Nama']} terkirim. Tim kami akan mengaktifkannya dan mengirim tagihan.");
    }

    private function PenggunaMasuk(): Pengguna
    {
        $pengguna = Auth::guard('web')->user();
        abort_unless($pengguna instanceof Pengguna, 403);

        return $pengguna;
    }
}
