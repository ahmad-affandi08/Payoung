<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Kueri\MenuPesanSendiri;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Penjualan\Aksi\BuatPesananMeja;
use App\Domain\Penjualan\Aksi\BuatPesananSendiri;
use App\Domain\Penjualan\Aksi\BuatTagihanQrisPesananOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberPesananOnline;
use App\Domain\Penjualan\Kueri\PesananSendiriTamu;
use App\Domain\Penjualan\Layanan\KesiapanQrisOnline;
use App\Domain\Penjualan\Layanan\PembuatQrTagihanQris;
use App\Domain\Penjualan\Layanan\PenentuKonteksPesanSendiri;
use App\Domain\Penjualan\Layanan\PenghitungPesanSendiri;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Permintaan\Publik\HitungPesanSendiriPermintaan;
use App\Http\Permintaan\Publik\KirimPesanSendiriPermintaan;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F-17 Self-Order QR Meja (X12, SLS-04), tanpa login: `/{slugTenant}/meja/{tokenMeja}` (halaman), `.../hitung` (harga
 * server + estimasi total PRD v2.06: `Subtotal`, `Diskon`, `BiayaLayanan`, `Pajak` [{Kode, Nama, Tarif, Jumlah}],
 * `PajakTermasukHarga`, `Pembulatan`, `Total`, `Catatan`), `.../pesan` (kirim, idempoten per Uuid), `.../pesanan/{uuid}` (polling status), `.../gambar/{produk}`.
 * Tenant dari slug dipasang sebagai konteks (seperti struk digital) sehingga semua kueri tetap lewat `MilikTenant`.
 * Rute JSON dikecualikan dari CSRF (tanpa sesi/kredensial yang bisa disalahgunakan; dijaga batas laju, token meja
 * rahasia, dan batas pesanan tertunda per meja); galatnya berformat `{"Galat": {...}}`.
 */
final class PesanSendiriKontroler extends Kontroler
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
        private readonly PenentuKonteksPesanSendiri $penentu,
    ) {}

    public function Tampilkan(string $slugTenant, string $tokenMeja, MenuPesanSendiri $menu): SymfonyResponse
    {
        $props = $this->JalankanDalamTenant($slugTenant, function (int $idTenant) use ($slugTenant, $tokenMeja, $menu): ?array {
            $konteks = $this->penentu->Cari($tokenMeja);

            if ($konteks === null) {
                return null;
            }

            return [
                'Aktif' => $konteks->aktif,
                'Toko' => ['Nama' => app(NamaTampilUsaha::class)->UntukOutlet($konteks->idOutlet, $idTenant), 'NamaOutlet' => $konteks->namaOutlet],
                'Meja' => ['Nama' => $konteks->namaMeja],
                'Token' => $tokenMeja,
                'Slug' => $slugTenant,
                'Pembayaran' => ['Qris' => $konteks->aktif && PengaturanTokoOnline::query()->value('QrisAktif') === true && app(KesiapanQrisOnline::class)->CekSiap()],
                'Menu' => $konteks->aktif
                    ? $menu->Ambil($konteks->idOutlet, url("/{$slugTenant}/meja/{$tokenMeja}/gambar/{uuid}"))
                    : ['Kategori' => [], 'Produk' => []],
            ];
        }, fn (): null => null);

        return Inertia::render('Publik/PesanSendiri', $props ?? [
            'Aktif' => false,
            'Toko' => null,
            'Meja' => null,
            'Token' => $tokenMeja,
            'Slug' => $slugTenant,
            'Pembayaran' => ['Qris' => false],
            'Menu' => ['Kategori' => [], 'Produk' => []],
        ])->toResponse(request())->setStatusCode($props === null ? 404 : 200);
    }

    public function Hitung(string $slugTenant, string $tokenMeja, HitungPesanSendiriPermintaan $permintaan, PenghitungPesanSendiri $penghitung): JsonResponse
    {
        return $this->JalankanDalamTenant($slugTenant, function () use ($tokenMeja, $permintaan, $penghitung): JsonResponse {
            $konteks = $this->penentu->WajibAktif($tokenMeja);
            $hasil = $penghitung->Hitung($konteks, $permintaan->AmbilBaris());

            return response()->json([
                'Baris' => array_map(fn (array $b): array => [
                    'UuidProduk' => $b['UuidProduk'],
                    'NamaProduk' => $b['NamaProduk'],
                    'Jumlah' => $b['Jumlah']->KeString(),
                    'HargaSatuan' => $b['HargaSatuan']->KeString(),
                    'HargaPilihan' => $b['HargaPilihan']->KeString(),
                    'Total' => $b['Total']->KeString(),
                    ...($b['UuidProdukInduk'] === null ? [] : ['UuidProdukInduk' => $b['UuidProdukInduk'], 'NamaVarian' => $b['NamaVarian']]),
                ], $hasil['Baris']),
                'Subtotal' => $hasil['Subtotal']->KeString(),
                ...PenghitungPesanSendiri::KeLarik($hasil['Perkiraan']),
                'Catatan' => PenghitungPesanSendiri::CATATAN,
            ]);
        });
    }

    public function Pesan(string $slugTenant, string $tokenMeja, KirimPesanSendiriPermintaan $permintaan, BuatPesananSendiri $buat): JsonResponse
    {
        $hashIp = hash_hmac('sha256', (string) $permintaan->ip(), (string) config('app.key'));

        return $this->JalankanDalamTenant($slugTenant, function () use ($tokenMeja, $permintaan, $buat, $hashIp): JsonResponse {
            [$pesanan, $baru] = $buat->Jalankan($this->penentu->WajibAktif($tokenMeja), $permintaan->AmbilData(), $hashIp);

            return response()->json(['Uuid' => $pesanan->Uuid, 'Nomor' => $pesanan->Nomor, 'Status' => $pesanan->Status->value], $baru ? 201 : 200);
        });
    }

    /** BR-17.2: menu disegarkan peramban tiap 15–30 detik supaya produk yang ditandai habis ("86") langsung tampak. */
    public function Menu(string $slugTenant, string $tokenMeja, MenuPesanSendiri $menu): JsonResponse
    {
        return $this->JalankanDalamTenant($slugTenant, function () use ($slugTenant, $tokenMeja, $menu): JsonResponse {
            $konteks = $this->penentu->WajibAktif($tokenMeja);

            return response()->json($menu->Ambil($konteks->idOutlet, url("/{$slugTenant}/meja/{$tokenMeja}/gambar/{uuid}")));
        });
    }

    /** SLS-04: pesanan yang dibayar QRIS dari HP tamu (jalur `PesananOnline`, `Sumber` Meja); bayar di kasir tetap lewat `Pesan`. */
    public function PesanQris(string $slugTenant, string $tokenMeja, KirimPesanSendiriPermintaan $permintaan, BuatPesananMeja $buat): JsonResponse
    {
        $hashIp = hash_hmac('sha256', (string) $permintaan->ip(), (string) config('app.key'));

        return $this->JalankanDalamTenant($slugTenant, function () use ($tokenMeja, $permintaan, $buat, $hashIp): JsonResponse {
            [$pesanan, $baru] = $buat->Jalankan($this->penentu->WajibAktif($tokenMeja), $permintaan->AmbilData(), $hashIp);

            return response()->json($this->RingkasPesananQris($pesanan), $baru ? 201 : 200);
        });
    }

    public function BayarQris(string $slugTenant, string $tokenMeja, string $kodeAkses, BuatTagihanQrisPesananOnline $buat, PembuatQrTagihanQris $qr): JsonResponse
    {
        return $this->JalankanDalamTenant($slugTenant, function () use ($tokenMeja, $kodeAkses, $buat, $qr): JsonResponse {
            [$tagihan, $baru] = $buat->Jalankan($this->WajibPesananQris($tokenMeja, $kodeAkses));
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

    public function StatusBayarQris(string $slugTenant, string $tokenMeja, string $kodeAkses): JsonResponse
    {
        return $this->JalankanDalamTenant($slugTenant, function () use ($tokenMeja, $kodeAkses): JsonResponse {
            $pesanan = $this->WajibPesananQris($tokenMeja, $kodeAkses);
            $tagihan = TagihanQris::query()->where('IdPesananOnline', $pesanan->Id)->orderByDesc('Id')->first();

            return response()->json([
                ...$this->RingkasPesananQris($pesanan),
                'StatusTagihan' => $tagihan?->Status->value,
                'KedaluwarsaPada' => $tagihan?->KedaluwarsaPada->toIso8601String(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function RingkasPesananQris(PesananOnline $pesanan): array
    {
        return [
            'KodeAkses' => $pesanan->KodeAkses,
            'Nomor' => $pesanan->Nomor,
            'Status' => $pesanan->Status->value,
            'LabelStatus' => match ($pesanan->Status) {
                StatusPesananOnline::MenungguPembayaran => 'Menunggu pembayaran',
                StatusPesananOnline::MenungguKonfirmasi => 'Sudah dibayar, menunggu kasir menerima pesanan',
                StatusPesananOnline::Dikonfirmasi, StatusPesananOnline::Diproses => 'Sedang disiapkan',
                StatusPesananOnline::Siap => 'Siap diantar ke meja',
                StatusPesananOnline::Selesai => 'Selesai',
                StatusPesananOnline::Ditolak, StatusPesananOnline::Dibatalkan => 'Pesanan dibatalkan',
                StatusPesananOnline::Kedaluwarsa => 'Pesanan kedaluwarsa',
            },
            'PerluBayar' => $pesanan->Status === StatusPesananOnline::MenungguPembayaran,
            'SudahDibayar' => $pesanan->DibayarPada !== null,
            'Total' => $pesanan->Total,
        ];
    }

    /** Pesanan harus pesanan QRIS meja ini: kode akses toko online, kios, atau meja lain tidak bisa dibaca lewat tautan meja. */
    private function WajibPesananQris(string $tokenMeja, string $kodeAkses): PesananOnline
    {
        $konteks = $this->penentu->WajibAktif($tokenMeja);
        $pesanan = PesananOnline::query()->where('KodeAkses', strtoupper($kodeAkses))->first();

        if (! $pesanan instanceof PesananOnline || $pesanan->Sumber !== SumberPesananOnline::Meja || $pesanan->IdMeja !== $konteks->idMeja) {
            throw new PelanggaranAturanBisnis('PesananTidakDitemukan', 'Pesanan tidak ditemukan untuk meja ini.', 'Umum', 404);
        }

        return $pesanan;
    }

    public function Status(string $slugTenant, string $tokenMeja, string $uuid, PesananSendiriTamu $kueri): JsonResponse
    {
        return $this->JalankanDalamTenant($slugTenant, function () use ($tokenMeja, $uuid, $kueri): JsonResponse {
            $pesanan = $kueri->Ambil($this->penentu->Wajib($tokenMeja)->idMeja, strtoupper($uuid));

            if ($pesanan === null) {
                throw new PelanggaranAturanBisnis('PesananTidakDitemukan', 'Pesanan tidak ditemukan untuk meja ini.', 'Uuid', 404);
            }

            return response()->json($pesanan);
        });
    }

    public function Gambar(string $slugTenant, string $tokenMeja, string $produk, Request $permintaan, MenuPesanSendiri $menu, PenyimpanGambarProduk $penyimpan): StreamedResponse
    {
        return $this->JalankanDalamTenant($slugTenant, function () use ($tokenMeja, $produk, $permintaan, $menu, $penyimpan): StreamedResponse {
            $this->penentu->Wajib($tokenMeja);
            $baris = $menu->CariProduk(strtoupper($produk));
            abort_if($baris === null, 404);

            return $penyimpan->Unduh($baris, $permintaan->query('ukuran') === 'besar' ? 'besar' : 'kecil');
        });
    }

    /**
     * Jalankan di konteks tenant pemilik slug; slug tak dikenal → `$tidakDikenal()` atau 404 `MejaTidakDitemukan`.
     *
     * @template T
     *
     * @param  Closure(int): T  $kerja
     * @param  (Closure(): T)|null  $tidakDikenal
     * @return T
     */
    private function JalankanDalamTenant(string $slugTenant, Closure $kerja, ?Closure $tidakDikenal = null): mixed
    {
        $idTenant = $this->profil->CariIdDariSlug($slugTenant);

        if ($idTenant === null) {
            return $tidakDikenal !== null
                ? $tidakDikenal()
                : throw new PelanggaranAturanBisnis('MejaTidakDitemukan', 'QR meja ini tidak dikenal atau sudah diganti. Minta QR terbaru ke staf.', 'Token', 404);
        }

        $this->konteks->Atur($idTenant);

        try {
            return $kerja($idTenant);
        } finally {
            $this->konteks->Kosongkan();
        }
    }
}
