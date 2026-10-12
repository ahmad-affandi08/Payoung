<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Menghapus berkas satu tenant dari disk (UU PDP, P-07): kelompok `PRIBADI` saat penghapusan data dilaksanakan, dan
 * kelompok `PEMBUKUAN` setelah masa retensi pembukuan lewat. Berkas yang gagal dihapus dihitung dan dilaporkan,
 * tidak menggagalkan pelaksanaan; pelaksanaan boleh diulang karena penghapusan folder bersifat idempoten.
 */
final class PenghapusBerkasTenant
{
    /**
     * @return array{Folder: int, Berkas: int, Gagal: int}
     */
    public function HapusPribadi(int $idTenant): array
    {
        return $this->Hapus(DaftarFolderBerkasTenant::PRIBADI, $idTenant);
    }

    /**
     * @return array{Folder: int, Berkas: int, Gagal: int}
     */
    public function HapusPembukuan(int $idTenant): array
    {
        return $this->Hapus(DaftarFolderBerkasTenant::PEMBUKUAN, $idTenant);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $daftar
     * @return array{Folder: int, Berkas: int, Gagal: int}
     */
    private function Hapus(array $daftar, int $idTenant): array
    {
        $hasil = ['Folder' => 0, 'Berkas' => 0, 'Gagal' => 0];

        foreach ($daftar as [$kunciDisk, $pola]) {
            $disk = Storage::disk((string) config($kunciDisk, 'local'));

            try {
                foreach (DaftarFolderBerkasTenant::UraiFolder($disk, $pola, $idTenant) as $folder) {
                    $berkas = $disk->allFiles($folder);

                    if ($berkas === [] && ! $disk->exists($folder)) {
                        continue;
                    }

                    if ($disk->deleteDirectory($folder)) {
                        $hasil['Folder']++;
                        $hasil['Berkas'] += count($berkas);
                    } else {
                        $hasil['Gagal'] += max(1, count($berkas));
                    }
                }
            } catch (Throwable) {
                $hasil['Gagal']++;
            }
        }

        return $hasil;
    }
}
