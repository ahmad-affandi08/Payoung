<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Tenant\Model\PemakaianPenyimpanan;

/**
 * Penegakan `BatasPenyimpananMb` paket (audit E2, BR-P04.3) untuk unggahan yang boleh ditolak: gambar produk dan
 * lampiran pembelian/kas & bank. Foto operasional kasir (bukti kas, swafoto absensi, bukti pengiriman) tidak pernah
 * ditolak karena menahan kerja toko; ukurannya tetap dihitung. Pemakaian dibaca dari `PemakaianPenyimpanan` (diukur
 * ulang tiap malam, bertambah saat `Catat` dipanggil), bukan menelusuri disk di tiap unggahan.
 */
final class PenjagaPenyimpanan
{
    public const KOLOM_BATAS = 'BatasPenyimpananMb';

    private const BYTE_PER_MB = 1048576;

    public function __construct(private readonly PemeriksaFiturTenant $fitur) {}

    /**
     * @throws PelanggaranAturanBisnis bila unggahan baru membuat pemakaian melewati batas paket
     */
    public function Pastikan(int $idTenant, int $ukuranBaru): void
    {
        $batasMb = $this->fitur->AmbilBatasEfektif($idTenant, self::KOLOM_BATAS);

        if ($batasMb === null) {
            return;
        }

        $terpakai = $this->AmbilByte($idTenant);

        if ($terpakai + $ukuranBaru <= $batasMb * self::BYTE_PER_MB) {
            return;
        }

        throw new PelanggaranAturanBisnis(
            'PenyimpananPenuh',
            "Penyimpanan berkas paket Anda ({$batasMb} MB) sudah penuh. Hapus berkas yang tidak dipakai, atau tingkatkan paket atau tambah add-on di menu Langganan.",
        );
    }

    /** Menambah pemakaian setelah unggahan berhasil disimpan. */
    public function Catat(int $idTenant, int $ukuran): void
    {
        $baris = PemakaianPenyimpanan::query()->firstOrCreate(['IdTenant' => $idTenant], ['Byte' => 0]);
        $baris->Byte += max(0, $ukuran);
        $baris->save();
    }

    public function AmbilByte(int $idTenant): int
    {
        return (int) (PemakaianPenyimpanan::query()->where('IdTenant', $idTenant)->value('Byte') ?? 0);
    }

    /**
     * @return array{Batas: int|null, Terpakai: int} dalam MB; Batas null = tak terbatas
     */
    public function AmbilRingkasanMb(int $idTenant): array
    {
        return [
            'Batas' => $this->fitur->AmbilBatasEfektif($idTenant, self::KOLOM_BATAS),
            'Terpakai' => (int) ceil($this->AmbilByte($idTenant) / self::BYTE_PER_MB),
        ];
    }
}
