<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;

/**
 * Kebutuhan staf minimal sebuah outlet pada rentang jam di hari tertentu dalam seminggu (F-18, D-251). Dibandingkan
 * dengan jadwal oleh `PenghitungKekuranganStaf`. Rentang tidak melewati tengah malam (`JamSelesai` > `JamMulai`).
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdOutlet
 * @property int $HariKe 1 = Senin … 7 = Minggu
 * @property string $JamMulai
 * @property string $JamSelesai
 * @property int $Minimal
 */
final class KebutuhanStaf extends ModelDasar
{
    use MilikTenant;

    protected $table = 'KebutuhanStaf';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['HariKe' => 'integer', 'Minimal' => 'integer'];
    }
}
