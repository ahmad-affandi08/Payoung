<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use Illuminate\Support\Carbon;

/**
 * Jenis notifikasi yang dibisukan satu pengguna (F31, PLT-08). Baris ada = notifikasi jenis itu tidak dibuat untuknya.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPengguna
 * @property JenisNotifikasiPengguna $Jenis
 * @property Carbon|null $DibuatPada
 */
final class NotifikasiDibisukan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'NotifikasiDibisukan';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['Jenis' => JenisNotifikasiPengguna::class];
    }
}
