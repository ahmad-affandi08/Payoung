<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;

/**
 * Penerima laporan otomatis ke karyawan per outlet (D-299 tahap 3), maksimal 6 per outlet.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdOutlet
 * @property string $Nama
 * @property string $NomorTelepon
 */
final class PenerimaLaporanWhatsapp extends ModelDasar
{
    use MilikTenant;

    public const MAKS_PER_OUTLET = 6;

    protected $table = 'PenerimaLaporanWhatsapp';
}
