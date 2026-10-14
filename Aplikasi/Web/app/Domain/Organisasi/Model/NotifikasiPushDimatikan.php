<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use Illuminate\Support\Carbon;

/**
 * Jenis notifikasi yang tidak dikirim sebagai push ke Aplikasi Owner untuk satu pengguna (PLT-08), tetapi tetap tampil
 * di pusat notifikasi web. Jenis Persetujuan tidak bisa dimatikan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPengguna
 * @property JenisNotifikasiPengguna $Jenis
 * @property Carbon|null $DibuatPada
 */
final class NotifikasiPushDimatikan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'NotifikasiPushDimatikan';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['Jenis' => JenisNotifikasiPengguna::class];
    }
}
