<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Carbon\CarbonInterface;

/**
 * Pemakaian penyimpanan berkas satu tenant (audit E2), dibandingkan dengan `BatasPenyimpananMb` paket.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $Byte
 * @property CarbonInterface|null $DiukurPada
 */
final class PemakaianPenyimpanan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PemakaianPenyimpanan';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['Byte' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Byte' => 'integer', 'DiukurPada' => 'datetime'];
    }
}
