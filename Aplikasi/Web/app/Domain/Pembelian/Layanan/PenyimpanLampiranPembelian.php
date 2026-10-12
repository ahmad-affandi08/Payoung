<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Layanan;

use App\Domain\Tenant\Layanan\PenjagaPenyimpanan;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lampiran dokumen pembelian (surat jalan, faktur pemasok, bukti transfer; F-04 fase 1). Disimpan di disk privat
 * `config('akuntansi.DiskLampiran')` dengan nama acak di folder per tenant & jenis dokumen; nama asli tidak pernah
 * menjadi path. Diunduh hanya lewat rute yang sudah memeriksa izin & batas outlet. Validasi jenis & ukuran di
 * Permintaan (`config('akuntansi.EkstensiLampiran')`, `UkuranMaksimalLampiranKb`).
 */
final class PenyimpanLampiranPembelian
{
    /**
     * @return array{PathLampiran: string, NamaLampiran: string, MimeLampiran: string, UkuranLampiran: int}
     */
    public function Simpan(int $idTenant, string $jenis, UploadedFile $berkas): array
    {
        $penjaga = app(PenjagaPenyimpanan::class);
        $penjaga->Pastikan($idTenant, (int) $berkas->getSize());
        $ekstensi = Str::lower($berkas->guessExtension() ?? $berkas->getClientOriginalExtension());
        $path = $this->AmbilDisk()->putFileAs("pembelian/{$jenis}/{$idTenant}", $berkas, Str::ulid().'.'.$ekstensi);

        if ($path === false) {
            throw new RuntimeException('Lampiran gagal disimpan.');
        }

        $penjaga->Catat($idTenant, (int) $berkas->getSize());

        return [
            'PathLampiran' => $path,
            'NamaLampiran' => Str::limit(basename($berkas->getClientOriginalName()), 150, ''),
            'MimeLampiran' => (string) $berkas->getMimeType(),
            'UkuranLampiran' => (int) $berkas->getSize(),
        ];
    }

    /** Membersihkan berkas bila transaksi yang menyimpannya gagal. */
    public function Hapus(string $path): void
    {
        $this->AmbilDisk()->delete($path);
    }

    public function Unduh(?string $path, ?string $nama, ?string $mime): StreamedResponse
    {
        abort_if($path === null || ! $this->AmbilDisk()->exists($path), 404);

        return $this->AmbilDisk()->download($path, $nama ?? basename($path), [
            'Content-Type' => $mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function AmbilDisk(): FilesystemAdapter
    {
        $disk = Storage::disk((string) config('akuntansi.DiskLampiran'));

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('Disk lampiran tidak dikenal.');
        }

        return $disk;
    }
}
