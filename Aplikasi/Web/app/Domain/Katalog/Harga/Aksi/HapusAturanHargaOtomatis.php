<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Harga\Model\AturanHargaOtomatis;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus satu aturan harga otomatis (D-266). Harga yang sudah berubah tetap, hanya penerimaan barang berikutnya
 * yang tidak lagi mengikuti aturan ini. Audit `harga-otomatis.aturan.hapus`.
 */
final class HapusAturanHargaOtomatis
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @throws PelanggaranAturanBisnis AturanTidakDikenal
     */
    public function Jalankan(string $uuid, int $idPengguna): void
    {
        DB::transaction(function () use ($uuid, $idPengguna): void {
            $aturan = AturanHargaOtomatis::query()->where('Uuid', $uuid)->lockForUpdate()->first();

            if ($aturan === null) {
                throw new PelanggaranAturanBisnis('AturanTidakDikenal', 'Aturan tidak ditemukan. Muat ulang halaman.', 'Uuid');
            }

            $ringkas = ['Cakupan' => $aturan->Cakupan->value, 'IdKategori' => $aturan->IdKategori, 'IdProduk' => $aturan->IdProduk, 'PersenUntung' => (string) $aturan->PersenUntung];
            $aturan->delete();
            $this->audit->Catat('harga-otomatis.aturan.hapus', $aturan, $ringkas, null, idPengguna: $idPengguna);
        });
    }
}
