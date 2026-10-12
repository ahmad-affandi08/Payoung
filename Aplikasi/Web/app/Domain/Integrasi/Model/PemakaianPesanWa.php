<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;

/**
 * Meteran pesan WhatsApp lewat nomor Payoung (D-108): satu baris per tenant per bulan kalender WIB.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property string $Periode `YYYY-MM`
 * @property int $Jumlah
 */
final class PemakaianPesanWa extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PemakaianPesanWa';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['Jumlah' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Jumlah' => 'integer'];
    }
}
