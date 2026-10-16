<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;

/**
 * Bagian pelunasan untuk satu piutang (F-12).
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdPembayaranPiutang
 * @property int $IdPiutang
 * @property string $Jumlah
 * @property string $PotonganPph
 */
final class PembayaranPiutangAlokasi extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PembayaranPiutangAlokasi';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Jumlah' => 'decimal:2', 'PotonganPph' => 'decimal:2'];
    }
}
