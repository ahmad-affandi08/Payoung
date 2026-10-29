<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logo usaha (F-01 langkah 1). Disimpan di disk privat `config('tenant.DiskLogo')` dengan nama acak di folder
 * tenant (`tenant/{IdTenant}/logo-{ulid}.{ext}`), tidak bergantung pada `storage:link` dan tidak bisa di-hotlink.
 * Diunduh hanya lewat rute back-office yang sudah memeriksa izin. Validasi jenis & ukuran di Permintaan.
 */
final class PenyimpanLogoTenant
{
    public function Simpan(int $idTenant, UploadedFile $berkas): string
    {
        $ekstensi = Str::lower($berkas->guessExtension() ?? $berkas->getClientOriginalExtension());
        $path = $this->AmbilDisk()->putFileAs("tenant/{$idTenant}", $berkas, 'logo-'.Str::lower((string) Str::ulid()).".{$ekstensi}");

        if ($path === false) {
            throw new RuntimeException('Logo gagal disimpan.');
        }

        return $path;
    }

    public function Hapus(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->AmbilDisk()->delete($path);
        }
    }

    /** Isi mentah logo untuk kop laporan; null bila berkasnya tidak ada. */
    public function Baca(string $path): ?string
    {
        $disk = $this->AmbilDisk();

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public function Unduh(string $path): StreamedResponse
    {
        abort_unless($this->AmbilDisk()->exists($path), 404);

        return $this->AmbilDisk()->response($path, 'logo.'.pathinfo($path, PATHINFO_EXTENSION), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Logo untuk halaman publik toko online (kepala toko dan pratinjau tautan). Beda dengan [Unduh]: boleh disimpan
     * peramban dan CDN beberapa menit, karena logo usaha memang untuk dilihat pembeli. Jenis isi dikunci ke gambar
     * (`nosniff`) dan hanya berkas bernama logo tenant yang sah yang sampai ke sini (path dari pengaturan tenant).
     */
    public function UnduhPublik(string $path): StreamedResponse
    {
        abort_unless($this->AmbilDisk()->exists($path), 404);

        return $this->AmbilDisk()->response($path, 'logo.'.pathinfo($path, PATHINFO_EXTENSION), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=600',
        ], 'inline');
    }

    private function AmbilDisk(): FilesystemAdapter
    {
        $disk = Storage::disk((string) config('tenant.DiskLogo'));

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('Disk logo tenant tidak dikenal.');
        }

        return $disk;
    }
}
