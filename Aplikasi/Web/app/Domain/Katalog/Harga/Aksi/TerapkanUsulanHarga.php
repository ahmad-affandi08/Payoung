<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Enum\StatusUsulanHarga;
use App\Domain\Katalog\Harga\Layanan\PenerapHargaOtomatis;
use App\Domain\Katalog\Harga\Model\UsulanHargaOtomatis;
use App\Domain\Katalog\Model\ProdukHarga;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Pemilik menyetujui usulan harga yang ditahan karena melewati batas aman (D-266). Harga baru hanya diterapkan bila
 * harga produk sekarang masih sama dengan harga lama saat usulan dibuat. Kalau sudah berubah (diubah manual atau oleh
 * hitungan lain), usulan ditutup karena sudah tidak relevan (hasil `false`) dan pemilik diminta melihat harganya lagi. Memakai jalur
 * yang sama dengan harga otomatis (riwayat harga sumber Otomatis, audit `produk.harga.ubah`) ditambah audit
 * `harga-otomatis.usulan.terapkan`.
 */
final class TerapkanUsulanHarga
{
    public function __construct(
        private readonly PenerapHargaOtomatis $penerap,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return bool true bila harga diterapkan, false bila usulan ditutup karena harga produk sudah berubah
     *
     * @throws PelanggaranAturanBisnis UsulanTidakDikenal, UsulanSudahDiputuskan
     */
    public function Jalankan(string $uuid, int $idPengguna): bool
    {
        return DB::transaction(function () use ($uuid, $idPengguna): bool {
            $usulan = UsulanHargaOtomatis::query()->where('Uuid', $uuid)->lockForUpdate()->with('Produk')->first();

            if ($usulan === null) {
                throw new PelanggaranAturanBisnis('UsulanTidakDikenal', 'Usulan tidak ditemukan. Muat ulang halaman.', 'Uuid');
            }

            if ($usulan->Status !== StatusUsulanHarga::Menunggu) {
                throw new PelanggaranAturanBisnis('UsulanSudahDiputuskan', 'Usulan ini sudah diputuskan atau digantikan. Muat ulang halaman.', 'Uuid');
            }

            $sekarang = ProdukHarga::query()
                ->where('IdProdukSatuan', $usulan->IdProdukSatuan)
                ->whereNull('IdDaftarHarga')
                ->get()
                ->first(fn (ProdukHarga $h): bool => BigDecimal::of($h->JumlahMinimum)->isEqualTo(1));

            if ($sekarang === null || ! Uang::Dari($sekarang->Harga)->SamaDengan(Uang::Dari($usulan->HargaLama))) {
                $usulan->Status = StatusUsulanHarga::Digantikan;
                $usulan->DiputuskanOleh = $idPengguna;
                $usulan->DiputuskanPada = now();
                $usulan->save();

                return false;
            }

            $this->penerap->TerapkanLangsung($usulan->Produk, Uang::Dari($usulan->HargaBaru), $idPengguna);
            $usulan->Status = StatusUsulanHarga::Diterapkan;
            $usulan->DiputuskanOleh = $idPengguna;
            $usulan->DiputuskanPada = now();
            $usulan->save();
            $this->audit->Catat('harga-otomatis.usulan.terapkan', $usulan, ['Status' => 'Menunggu'], ['Status' => 'Diterapkan', 'HargaLama' => (string) $usulan->HargaLama, 'HargaBaru' => (string) $usulan->HargaBaru], idPengguna: $idPengguna);

            return true;
        });
    }
}
