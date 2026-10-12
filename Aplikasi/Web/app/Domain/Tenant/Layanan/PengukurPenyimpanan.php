<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Tenant\Model\PemakaianPenyimpanan;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mengukur penyimpanan berkas satu tenant (audit E2): menjumlah ukuran semua berkas di folder tenant pada tiap disk
 * yang dipakai Payoung. Folder per tenant mengikuti konvensi `{modul}/{...}/{IdTenant}`; daftar di bawah harus
 * bertambah bila ada penyimpan berkas baru. Berkas platform (bukti transfer langganan, situs pemasaran) tidak
 * dihitung karena bukan milik tenant. Hanya dipanggil dalam konteks tenant (scope `MilikTenant`).
 */
final class PengukurPenyimpanan
{
    /**
     * Pasangan kunci config disk dan pola folder; `{id}` = Id tenant, `*` = satu tingkat folder bebas.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const FOLDER = [
        ['katalog.DiskGambar', 'produk/{id}'],
        ['kasir.DiskBuktiKas', 'kasir/bukti-kas/{id}'],
        ['akuntansi.DiskLampiran', 'akuntansi/kas-bank/{id}'],
        ['akuntansi.DiskLampiran', 'pembelian/*/{id}'],
        ['karyawan.DiskSwafoto', 'karyawan/swafoto/{id}'],
        ['pemenuhan.DiskBukti', 'pemenuhan/bukti/{id}'],
        ['tenant.DiskLogo', 'tenant/{id}'],
        ['pembayaran.DiskGambarQris', 'metode-pembayaran/{id}'],
        ['dukungan.DiskLampiran', 'dukungan/{id}'],
    ];

    public function HitungByte(int $idTenant): int
    {
        $total = 0;

        foreach (self::FOLDER as [$kunciDisk, $pola]) {
            $disk = Storage::disk((string) config($kunciDisk));

            foreach (DaftarFolderBerkasTenant::UraiFolder($disk, $pola, $idTenant) as $folder) {
                foreach ($disk->allFiles($folder) as $berkas) {
                    try {
                        $total += (int) $disk->size($berkas);
                    } catch (Throwable) {
                        // Berkas hilang di tengah pengukuran: dilewati, pengukuran berikutnya akan benar.
                    }
                }
            }
        }

        return $total;
    }

    /** Mengukur lalu menyimpan hasilnya untuk tenant aktif. */
    public function Segarkan(int $idTenant): PemakaianPenyimpanan
    {
        $byte = $this->HitungByte($idTenant);

        return PemakaianPenyimpanan::query()->updateOrCreate(
            ['IdTenant' => $idTenant],
            ['Byte' => $byte, 'DiukurPada' => now()],
        );
    }
}
