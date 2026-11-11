<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Harga\Enum\StatusUsulanHarga;
use App\Domain\Katalog\Harga\Model\UsulanHargaOtomatis;
use Illuminate\Support\Facades\DB;

/**
 * Pemilik menolak usulan harga (D-266): harga produk tetap seperti sekarang. Usulan baru untuk produk yang sama baru
 * muncul bila harga beli berubah lagi. Audit `harga-otomatis.usulan.tolak`.
 */
final class TolakUsulanHarga
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @throws PelanggaranAturanBisnis UsulanTidakDikenal, UsulanSudahDiputuskan
     */
    public function Jalankan(string $uuid, int $idPengguna): void
    {
        DB::transaction(function () use ($uuid, $idPengguna): void {
            $usulan = UsulanHargaOtomatis::query()->where('Uuid', $uuid)->lockForUpdate()->first();

            if ($usulan === null) {
                throw new PelanggaranAturanBisnis('UsulanTidakDikenal', 'Usulan tidak ditemukan. Muat ulang halaman.', 'Uuid');
            }

            if ($usulan->Status !== StatusUsulanHarga::Menunggu) {
                throw new PelanggaranAturanBisnis('UsulanSudahDiputuskan', 'Usulan ini sudah diputuskan atau digantikan. Muat ulang halaman.', 'Uuid');
            }

            $usulan->Status = StatusUsulanHarga::Ditolak;
            $usulan->DiputuskanOleh = $idPengguna;
            $usulan->DiputuskanPada = now();
            $usulan->save();
            $this->audit->Catat('harga-otomatis.usulan.tolak', $usulan, ['Status' => 'Menunggu'], ['Status' => 'Ditolak'], idPengguna: $idPengguna);
        });
    }
}
