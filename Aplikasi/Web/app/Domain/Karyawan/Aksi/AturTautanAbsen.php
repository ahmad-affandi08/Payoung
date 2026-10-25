<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use Illuminate\Support\Str;

/**
 * F-18 bagian 4 (D-37, D-198): membuat (ulang) atau mencabut tautan absen pribadi karyawan `/{slug}/absen/{token}`.
 * Token 40 karakter acak; yang lama langsung tidak berlaku. Tokennya sendiri tidak masuk log audit. Membuat tautan
 * adalah keputusan bahwa karyawan itu absen, jadi penanda "ikut absensi"-nya ikut dinyalakan (tercatat di audit).
 */
final class AturTautanAbsen
{
    public const PANJANG_TOKEN = 40;

    public function __construct(private readonly PencatatAudit $audit) {}

    /** @return string|null token baru, atau null bila dicabut */
    public function Jalankan(Karyawan $karyawan, bool $buat, int $idPengguna): ?string
    {
        if ($buat && $karyawan->Status !== StatusKaryawan::Aktif) {
            throw new PelanggaranAturanBisnis('KaryawanNonaktif', 'Karyawan nonaktif tidak bisa diberi tautan absen.', 'Karyawan');
        }

        $token = $buat ? Str::random(self::PANJANG_TOKEN) : null;
        $menyalakan = $buat && ! $karyawan->IkutAbsensi;
        $karyawan->forceFill([
            'TokenAbsen' => $token,
            'HashTokenAbsen' => $token === null ? null : self::Hash($token),
            'TokenAbsenDibuatPada' => $token === null ? null : now(),
            'IkutAbsensi' => $menyalakan ? true : $karyawan->IkutAbsensi,
        ])->save();
        $this->audit->Catat(
            $buat ? 'karyawan.tautan-absen.buat' : 'karyawan.tautan-absen.cabut',
            $karyawan,
            null,
            ['Nama' => $karyawan->Nama, ...($menyalakan ? ['IkutAbsensi' => true] : [])],
            idPengguna: $idPengguna,
        );

        return $token;
    }

    public static function Hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
