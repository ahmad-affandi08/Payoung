<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Kueri\MenuPesanSendiri;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Penjualan\Aksi\BuatPesananKios;
use App\Domain\Penjualan\Aksi\BuatTagihanQrisPesananOnline;
use App\Domain\Penjualan\Data\DataKonteksPesanSendiri;
use App\Domain\Penjualan\Enum\JenisSantapKios;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberPesananOnline;
use App\Domain\Penjualan\Layanan\KesiapanQrisOnline;
use App\Domain\Penjualan\Layanan\PembuatQrTagihanQris;
use App\Domain\Penjualan\Layanan\PenentuKonteksKios;
use App\Domain\Penjualan\Layanan\PenghitungKios;
use App\Domain\Penjualan\Layanan\PenghitungPesanSendiri;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Permintaan\Publik\KiosPermintaan;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F-17 bagian 4: kios pesan sendiri di layar sentuh outlet, tanpa login dan tanpa data pribadi. Tautan rahasia per
 * outlet (`Outlet.TokenKios`); semua harga, pajak, promo, dan stok dihitung server. Pesanan masuk lewat jalur
 * `PesananOnline` (aplikasi Kasir menerima dan menagihnya). Layar antrian publik menampilkan nomor yang disiapkan
 * dan yang siap diambil.
 */
final class KiosKontroler extends Kontroler
{
    /** Layar kios kembali ke awal bila tidak disentuh selama ini (detik). */
    public const DETIK_DIAM = 90;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
        private readonly PenentuKonteksKios $penentu,
    ) {}

    public function Tampilkan(string $slugTenant, string $tokenKios, MenuPesanSendiri $menu): SymfonyResponse
    {
        $props = $this->DalamTenant($slugTenant, function (int $idTenant) use ($slugTenant, $tokenKios, $menu): ?array {
            $k = $this->penentu->Cari($tokenKios);

            if ($k === null) {
                return null;
            }

            return [
                'Aktif' => $k->aktif,
                'Slug' => $slugTenant,
                'Token' => $tokenKios,
                'Toko' => ['Nama' => app(NamaTampilUsaha::class)->UntukOutlet($k->idOutlet, $idTenant)],
                'Menu' => $k->aktif ? $this->AmbilMenu($menu, $k, JenisSantapKios::MakanDiTempat, $slugTenant, $tokenKios) : ['Kategori' => [], 'Produk' => []],
                'Pembayaran' => ['BayarDiKasir' => true, 'Qris' => PengaturanTokoOnline::query()->value('QrisAktif') === true && app(KesiapanQrisOnline::class)->CekSiap()],
                'DetikDiam' => self::DETIK_DIAM,
            ];
        }, fn (): null => null);

        return Inertia::render('Publik/Kios', $props ?? [
            'Aktif' => false, 'Slug' => $slugTenant, 'Token' => $tokenKios, 'Toko' => null,
            'Menu' => ['Kategori' => [], 'Produk' => []], 'Pembayaran' => ['BayarDiKasir' => true, 'Qris' => false], 'DetikDiam' => self::DETIK_DIAM,
        ])->toResponse(request())->setStatusCode($props === null ? 404 : 200);
    }

    /** Harga menu bergantung pada pilihan makan di sini atau bawa pulang, jadi layar memuat ulang setelah pelanggan memilih. */
    public function Menu(string $slugTenant, string $tokenKios, Request $permintaan, MenuPesanSendiri $menu): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($slugTenant, $tokenKios, $permintaan, $menu): JsonResponse {
            $k = $this->penentu->WajibAktif($tokenKios);
            $santap = JenisSantapKios::tryFrom((string) $permintaan->query('santap')) ?? JenisSantapKios::MakanDiTempat;

            return response()->json($this->AmbilMenu($menu, $k, $santap, $slugTenant, $tokenKios));
        });
    }

    public function Hitung(string $slugTenant, string $tokenKios, KiosPermintaan $permintaan, PenghitungKios $penghitung): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($tokenKios, $permintaan, $penghitung): JsonResponse {
            $hasil = $penghitung->Hitung(
                $this->penentu->WajibAktif($tokenKios),
                $permintaan->AmbilBaris(),
                JenisSantapKios::from((string) $permintaan->validated('JenisSantap')),
            );

            return response()->json([
                'Baris' => array_map(fn (array $b): array => ['UuidProduk' => $b['UuidProduk'], 'NamaProduk' => $b['NamaProduk'], 'Jumlah' => $b['Jumlah']->KeString(), 'Total' => $b['Total']->KeString()], $hasil['Baris']),
                'Subtotal' => $hasil['Subtotal']->KeString(), ...PenghitungPesanSendiri::KeLarik($hasil['Perkiraan']),
                'Total' => $hasil['Total']->KeString(),
            ]);
        });
    }

    public function Pesan(string $slugTenant, string $tokenKios, KiosPermintaan $permintaan, BuatPesananKios $buat): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($tokenKios, $permintaan, $buat): JsonResponse {
            [$pesanan, $baru] = $buat->Jalankan($this->penentu->WajibAktif($tokenKios), $permintaan->validated());

            return response()->json($this->RingkasPesanan($pesanan), $baru ? 201 : 200);
        });
    }

    /** Dipantau layar "pesanan diterima": status terbaru dan nomor antrian pesanan ini. */
    public function Status(string $slugTenant, string $tokenKios, string $kodeAkses): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($tokenKios, $kodeAkses): JsonResponse {
            return response()->json($this->RingkasPesanan($this->WajibPesanan($tokenKios, $kodeAkses)));
        });
    }

    public function Bayar(string $slugTenant, string $tokenKios, string $kodeAkses, BuatTagihanQrisPesananOnline $buat, PembuatQrTagihanQris $qr): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($tokenKios, $kodeAkses, $buat, $qr): JsonResponse {
            [$tagihan, $baru] = $buat->Jalankan($this->WajibPesanan($tokenKios, $kodeAkses));
            $lunas = $tagihan->Status === StatusTagihanQris::Lunas;

            return response()->json([
                'Jumlah' => $tagihan->Jumlah,
                'Status' => $tagihan->Status->value,
                'SudahDibayar' => $lunas,
                'KedaluwarsaPada' => $tagihan->KedaluwarsaPada->toIso8601String(),
                'Qr' => $tagihan->HalamanBayar || $lunas ? null : $qr->BuatSvg($tagihan->IsiQr),
                'UrlBayar' => $tagihan->HalamanBayar ? $tagihan->IsiQr : null,
            ], $baru ? 201 : 200);
        });
    }

    public function StatusBayar(string $slugTenant, string $tokenKios, string $kodeAkses): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($tokenKios, $kodeAkses): JsonResponse {
            $pesanan = $this->WajibPesanan($tokenKios, $kodeAkses);
            $tagihan = TagihanQris::query()->where('IdPesananOnline', $pesanan->Id)->orderByDesc('Id')->first();

            return response()->json([
                ...$this->RingkasPesanan($pesanan),
                'StatusTagihan' => $tagihan?->Status->value,
                'KedaluwarsaPada' => $tagihan?->KedaluwarsaPada->toIso8601String(),
            ]);
        });
    }

    public function Gambar(string $slugTenant, string $tokenKios, string $produk, Request $permintaan, MenuPesanSendiri $menu, PenyimpanGambarProduk $penyimpan): StreamedResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($tokenKios, $produk, $permintaan, $menu, $penyimpan): StreamedResponse {
            $this->penentu->WajibAktif($tokenKios);
            $baris = $menu->CariProduk(strtoupper($produk));
            abort_if($baris === null, 404);

            return $penyimpan->Unduh($baris, $permintaan->query('ukuran') === 'besar' ? 'besar' : 'kecil');
        });
    }

    /** Layar antrian untuk monitor/TV di outlet: halaman penuh yang memuat ulang datanya tiap beberapa detik. */
    public function Antrian(string $slugTenant, string $tokenKios): SymfonyResponse
    {
        $props = $this->DalamTenant($slugTenant, function (int $idTenant) use ($slugTenant, $tokenKios): ?array {
            $k = $this->penentu->Cari($tokenKios);

            return $k === null ? null : [
                'Aktif' => $k->aktif, 'Slug' => $slugTenant, 'Token' => $tokenKios,
                'Toko' => ['Nama' => app(NamaTampilUsaha::class)->UntukOutlet($k->idOutlet, $idTenant)],
                'Antrian' => $k->aktif ? $this->AmbilAntrian($k) : ['Disiapkan' => [], 'Siap' => []],
            ];
        }, fn (): null => null);

        return Inertia::render('Publik/AntrianKios', $props ?? [
            'Aktif' => false, 'Slug' => $slugTenant, 'Token' => $tokenKios, 'Toko' => null, 'Antrian' => ['Disiapkan' => [], 'Siap' => []],
        ])->toResponse(request())->setStatusCode($props === null ? 404 : 200);
    }

    public function DataAntrian(string $slugTenant, string $tokenKios): JsonResponse
    {
        // Layar antrian dibiarkan menyala sepanjang hari dan semuanya menanyakan data yang sama: hasil dibagi 3 detik per
        // outlet, jadi beban basis data sama untuk satu layar maupun sepuluh layar di outlet yang sama.
        return $this->DalamTenant($slugTenant, function () use ($tokenKios): JsonResponse {
            $konteks = $this->penentu->WajibAktif($tokenKios);

            return response()->json(Cache::remember("kios:antrian:{$konteks->idOutlet}", 3, fn (): array => $this->AmbilAntrian($konteks)));
        });
    }

    /**
     * Pesanan kios hari ini (tanggal bisnis outlet): "Disiapkan" = sudah dibayar/diterima kasir tetapi belum siap,
     * "Siap" = bisa diambil. Yang belum dibayar tidak ditampilkan, supaya nomornya tidak dipanggil sebelum waktunya.
     *
     * @return array{Disiapkan: list<string>, Siap: list<string>}
     */
    private function AmbilAntrian(DataKonteksPesanSendiri $k): array
    {
        $awal = now($k->zonaWaktu)->startOfDay()->utc();
        $baris = PesananOnline::query()
            ->where('IdOutlet', $k->idOutlet)->where('Sumber', SumberPesananOnline::Kios->value)
            ->whereIn('Status', [StatusPesananOnline::Dikonfirmasi->value, StatusPesananOnline::Diproses->value, StatusPesananOnline::Siap->value])
            ->where('DibuatPada', '>=', $awal)->whereNotNull('NomorAntrian')
            ->orderBy('NomorAntrian')->get(['NomorAntrian', 'Status']);

        $label = static fn (PesananOnline $p): string => BuatPesananKios::LabelAntrian((int) $p->NomorAntrian);

        return [
            'Disiapkan' => array_values($baris->filter(fn (PesananOnline $p): bool => $p->Status !== StatusPesananOnline::Siap)->map($label)->all()),
            'Siap' => array_values($baris->filter(fn (PesananOnline $p): bool => $p->Status === StatusPesananOnline::Siap)->map($label)->all()),
        ];
    }

    /** @return array{Kategori: list<array{Uuid: string, Nama: string}>, Produk: list<array<string, mixed>>} */
    private function AmbilMenu(MenuPesanSendiri $menu, DataKonteksPesanSendiri $k, JenisSantapKios $santap, string $slug, string $token): array
    {
        return $menu->Ambil($k->idOutlet, url("/{$slug}/kios/{$token}/gambar/{uuid}"), PenghitungKios::KanalDari($santap), false);
    }

    /** @return array<string, mixed> */
    private function RingkasPesanan(PesananOnline $pesanan): array
    {
        return [
            'KodeAkses' => $pesanan->KodeAkses,
            'Nomor' => $pesanan->Nomor,
            'NomorAntrian' => $pesanan->NomorAntrian === null ? null : BuatPesananKios::LabelAntrian($pesanan->NomorAntrian),
            'JenisSantap' => $pesanan->JenisSantap?->AmbilLabel(),
            'Status' => $pesanan->Status->value,
            'LabelStatus' => self::LabelStatus($pesanan->Status),
            'PerluBayar' => $pesanan->Status === StatusPesananOnline::MenungguPembayaran,
            'SudahDibayar' => $pesanan->DibayarPada !== null,
            'BayarDiKasir' => ! $pesanan->MetodePembayaran->CekBayarDiMuka(),
            'Total' => $pesanan->Total,
        ];
    }

    /** Bahasa kios: pelanggan tidak perlu tahu istilah "konfirmasi". */
    private static function LabelStatus(StatusPesananOnline $status): string
    {
        return match ($status) {
            StatusPesananOnline::MenungguPembayaran => 'Menunggu pembayaran',
            StatusPesananOnline::MenungguKonfirmasi => 'Silakan bayar di kasir',
            StatusPesananOnline::Dikonfirmasi, StatusPesananOnline::Diproses => 'Sedang disiapkan',
            StatusPesananOnline::Siap => 'Siap diambil',
            StatusPesananOnline::Selesai => 'Selesai',
            StatusPesananOnline::Ditolak, StatusPesananOnline::Dibatalkan => 'Pesanan dibatalkan',
            StatusPesananOnline::Kedaluwarsa => 'Pesanan kedaluwarsa',
        };
    }

    /** Pesanan harus milik kios ini: kode akses pesanan toko online atau outlet lain tidak bisa dibaca lewat tautan kios. */
    private function WajibPesanan(string $tokenKios, string $kodeAkses): PesananOnline
    {
        $k = $this->penentu->WajibAktif($tokenKios);
        $pesanan = PesananOnline::query()->where('KodeAkses', strtoupper($kodeAkses))->first();

        if (! $pesanan instanceof PesananOnline || $pesanan->Sumber !== SumberPesananOnline::Kios || $pesanan->IdOutlet !== $k->idOutlet) {
            throw new PelanggaranAturanBisnis('PesananTidakDitemukan', 'Pesanan tidak ditemukan.', 'Umum', 404);
        }

        return $pesanan;
    }

    private function DalamTenant(string $slugTenant, Closure $kerja, ?Closure $tidakDikenal = null): mixed
    {
        $idTenant = $this->profil->CariIdDariSlug($slugTenant);

        if ($idTenant === null) {
            return $tidakDikenal !== null ? $tidakDikenal() : throw new PelanggaranAturanBisnis('KiosTidakDikenal', 'Tautan kios ini tidak berlaku.', 'Umum', 404);
        }

        $this->konteks->Atur($idTenant);

        try {
            return $kerja($idTenant);
        } finally {
            $this->konteks->Kosongkan();
        }
    }
}
