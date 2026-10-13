<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Armada pengiriman (F-12 §9.7): kendaraan yang membawa surat jalan grosir. `NomorKendaraan` unik per tenant; armada
 * yang tidak dipakai lagi dinonaktifkan (bukan dihapus) supaya surat jalan lama tetap menunjuk armada yang benar.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nama
 * @property string $NomorKendaraan
 * @property string|null $NamaPengemudi
 * @property string|null $Keterangan
 * @property bool $Aktif
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class Armada extends ModelDasar
{
    use MilikTenant;

    protected $table = 'Armada';

    /** @var array<string, mixed> */
    protected $attributes = ['NamaPengemudi' => null, 'Keterangan' => null, 'Aktif' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Aktif' => 'boolean'];
    }
}
