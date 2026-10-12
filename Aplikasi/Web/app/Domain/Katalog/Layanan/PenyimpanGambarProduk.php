<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Tenant\Layanan\PenjagaPenyimpanan;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gambar produk di disk privat `config('katalog.DiskGambar')` (F-03 C.2): diunduh lewat rute terautentikasi
 * (back-office dan token perangkat POS), terisolasi per tenant.
 * - Terima jpg/png/webp ≤ `katalog.Gambar.UkuranMaksimalKb`, minimal 200×200 piksel, maksimal
 *   `katalog.Gambar.PikselMaksimal` piksel sebelum didekode (bom piksel) (`GambarTidakValid`).
 * - Diubah ukurannya dengan GD: sisi terpanjang ≤ `SisiBesar` (800) → `produk/{IdTenant}/{UuidProduk}-{ulid}.webp`,
 *   dan ≤ `SisiKecil` (256) → `…-kecil.webp`; kualitas `Kualitas`; JPEG bila GD tanpa WebP.
 * - Nama berkas berversi (ulid) sehingga POS boleh menyimpan cache selamanya.
 */
final class PenyimpanGambarProduk
{
    public const UKURAN = ['kecil', 'besar'];

    private const SISI_MINIMAL = 200;

    /** Simpan gambar baru; mengembalikan path gambar besar. Gambar lama tidak disentuh. */
    public function Simpan(Produk $produk, UploadedFile $berkas): string
    {
        $penjaga = app(PenjagaPenyimpanan::class);
        $penjaga->Pastikan($produk->IdTenant, (int) $berkas->getSize());
        $gambar = $this->Baca($berkas);
        $webp = function_exists('imagewebp');
        $ekstensi = $webp ? 'webp' : 'jpg';
        $path = 'produk/'.$produk->IdTenant.'/'.$produk->Uuid.'-'.Str::ulid().'.'.$ekstensi;

        $disk = self::Disk();
        $disk->put($path, $this->Kodekan($this->UbahUkuran($gambar, (int) config('katalog.Gambar.SisiBesar', 800)), $webp));
        $disk->put(self::PathKecil($path), $this->Kodekan($this->UbahUkuran($gambar, (int) config('katalog.Gambar.SisiKecil', 256)), $webp));
        $penjaga->Catat($produk->IdTenant, (int) $disk->size($path) + (int) $disk->size(self::PathKecil($path)));

        return $path;
    }

    public function Hapus(?string $pathBesar): void
    {
        if ($pathBesar === null || $pathBesar === '') {
            return;
        }

        self::Disk()->delete([$pathBesar, self::PathKecil($pathBesar)]);
    }

    /** Unduhan gambar; 404 bila produk tidak punya gambar atau berkas hilang. */
    public function Unduh(Produk $produk, string $ukuran): StreamedResponse
    {
        $path = $produk->PathGambar;
        abort_if($path === null || $path === '', 404);
        $path = $ukuran === 'kecil' ? self::PathKecil($path) : $path;
        $disk = self::Disk();
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Versi gambar = ulid pada nama berkas; null bila tanpa gambar. */
    public static function AmbilVersi(Produk $produk): ?string
    {
        return self::AmbilVersiDariPath($produk->PathGambar);
    }

    /** Versi (ulid) dari path gambar besar; dipakai juga log audit agar path penyimpanan tidak ikut tercatat. */
    public static function AmbilVersiDariPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $nama = pathinfo($path, PATHINFO_FILENAME);
        $posisi = strrpos($nama, '-');

        return $posisi === false ? null : substr($nama, $posisi + 1);
    }

    /** URL unduhan back-office (`/kelola/produk/{uuid}/gambar`) atau POS (`/api/pos/v1/katalog/gambar/{uuid}`). */
    public static function BuatUrl(Produk $produk, string $ukuran, string $dasar = '/kelola/produk/{uuid}/gambar'): ?string
    {
        $versi = self::AmbilVersi($produk);

        if ($versi === null) {
            return null;
        }

        return str_replace('{uuid}', $produk->Uuid, $dasar).'?ukuran='.$ukuran.'&versi='.rawurlencode($versi);
    }

    public static function PathKecil(string $pathBesar): string
    {
        $info = pathinfo($pathBesar);

        return ($info['dirname'] ?? '.').'/'.$info['filename'].'-kecil.'.($info['extension'] ?? 'webp');
    }

    private static function Disk(): FilesystemAdapter
    {
        $disk = Storage::disk((string) config('katalog.DiskGambar', 'local'));
        if (! $disk instanceof FilesystemAdapter) {
            throw new LogicException('Disk gambar produk harus FilesystemAdapter.');
        }

        return $disk;
    }

    private function Baca(UploadedFile $berkas): GdImage
    {
        $maksimalKb = (int) config('katalog.Gambar.UkuranMaksimalKb', 5120);
        $isi = $berkas->isValid() && $berkas->getSize() <= $maksimalKb * 1024 ? file_get_contents($berkas->getRealPath()) : false;
        $info = is_string($isi) && $isi !== '' ? getimagesizefromstring($isi) : false;

        if (! is_string($isi) || $info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new PelanggaranAturanBisnis('GambarTidakValid', "Unggah gambar JPG, PNG, atau WebP maksimal {$maksimalKb} KB.", 'Gambar');
        }

        if ($info[0] < self::SISI_MINIMAL || $info[1] < self::SISI_MINIMAL) {
            throw new PelanggaranAturanBisnis('GambarTidakValid', 'Gambar minimal 200×200 piksel agar tetap jelas di layar kasir.', 'Gambar');
        }

        // Bom piksel: berkas kecil berdimensi raksasa ditolak sebelum GD mengalokasikan lebar × tinggi di memori.
        if ((int) config('katalog.Gambar.PikselMaksimal', 40000000) < $info[0] * $info[1]) {
            throw new PelanggaranAturanBisnis('GambarTidakValid', 'Resolusi gambar terlalu besar. Perkecil gambar (misal 4000×3000 piksel) lalu unggah lagi.', 'Gambar');
        }

        $gambar = @imagecreatefromstring($isi);

        if (! $gambar instanceof GdImage) {
            throw new PelanggaranAturanBisnis('GambarTidakValid', 'Gambar rusak atau tidak bisa dibaca. Coba simpan ulang lalu unggah lagi.', 'Gambar');
        }

        return $gambar;
    }

    private function UbahUkuran(GdImage $gambar, int $sisiMaksimal): GdImage
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $terpanjang = max($lebar, $tinggi);
        $lebarBaru = $terpanjang <= $sisiMaksimal ? $lebar : max(1, intdiv($lebar * $sisiMaksimal, $terpanjang));
        $tinggiBaru = $terpanjang <= $sisiMaksimal ? $tinggi : max(1, intdiv($tinggi * $sisiMaksimal, $terpanjang));
        $hasil = imagecreatetruecolor($lebarBaru, $tinggiBaru);
        imagealphablending($hasil, false);
        imagesavealpha($hasil, true);
        imagefill($hasil, 0, 0, (int) imagecolorallocatealpha($hasil, 255, 255, 255, 127));
        imagecopyresampled($hasil, $gambar, 0, 0, 0, 0, $lebarBaru, $tinggiBaru, $lebar, $tinggi);

        return $hasil;
    }

    private function Kodekan(GdImage $gambar, bool $webp): string
    {
        $kualitas = (int) config('katalog.Gambar.Kualitas', 80);
        ob_start();
        $webp ? imagewebp($gambar, null, $kualitas) : imagejpeg($gambar, null, $kualitas);

        return (string) ob_get_clean();
    }
}
