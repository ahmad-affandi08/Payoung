<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Layanan;

use App\Domain\Akuntansi\Model\TransaksiKasBank;
use App\Domain\Tenant\Layanan\PenjagaPenyimpanan;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lampiran dokumen akuntansi (F-13a transaksi kas & bank; FIN-05 jurnal umum). Disimpan di disk privat `config('akuntansi.DiskLampiran')` dengan nama acak
 * di folder per tenant, sehingga nama asli dari pengguna tidak pernah menjadi path. Diunduh hanya lewat rute yang
 * sudah memeriksa izin & batas outlet. Validasi jenis & ukuran di Permintaan.
 */
final class PenyimpanLampiranKasBank
{
    /**
     * @return array{Path: string, NamaAsli: string, Mime: string, Ukuran: int}
     */
    public function Simpan(int $idTenant, UploadedFile $berkas, string $folder = 'kas-bank'): array
    {
        $penjaga = app(PenjagaPenyimpanan::class);
        $penjaga->Pastikan($idTenant, (int) $berkas->getSize());
        $ekstensi = Str::lower($berkas->guessExtension() ?? $berkas->getClientOriginalExtension());
        $path = $this->AmbilDisk()->putFileAs("akuntansi/{$folder}/{$idTenant}", $berkas, Str::ulid().'.'.$ekstensi);

        if ($path === false) {
            throw new RuntimeException('Lampiran gagal disimpan.');
        }

        $penjaga->Catat($idTenant, (int) $berkas->getSize());

        return [
            'Path' => $path,
            'NamaAsli' => Str::limit(basename($berkas->getClientOriginalName()), 150, ''),
            'Mime' => (string) $berkas->getMimeType(),
            'Ukuran' => (int) $berkas->getSize(),
        ];
    }

    /** Membersihkan berkas bila transaksi yang menyimpannya gagal. */
    public function Hapus(string $path): void
    {
        $this->AmbilDisk()->delete($path);
    }

    public function Unduh(TransaksiKasBank $transaksi): StreamedResponse
    {
        return $this->UnduhBerkas($transaksi->PathLampiran, $transaksi->NamaLampiran, $transaksi->MimeLampiran);
    }

    /** Unduh lampiran dokumen akuntansi mana pun (jurnal umum memakai ini juga): path dari kolom dokumennya, bukan dari pengguna. */
    public function UnduhBerkas(?string $path, ?string $nama, ?string $mime): StreamedResponse
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
            throw new RuntimeException('Disk lampiran akuntansi tidak dikenal.');
        }

        return $disk;
    }
}
