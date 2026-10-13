<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Ringkasan analitik satu tenant (P-07 PGL-24): sektor, tonggak funnel yang sudah tercapai (tanggal pertama kali), dan
 * fitur utama yang dipakai 30 hari terakhir. Catatan platform, diperbarui harian oleh `PerbaruiAnalitikTenant`.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property string|null $KodeSektor
 * @property Carbon|null $PanduanSelesaiPada
 * @property Carbon|null $TransaksiPertamaPada
 * @property Carbon|null $BayarPertamaPada
 * @property list<string> $FiturDipakai
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class AnalitikTenant extends ModelDasar
{
    protected $table = 'AnalitikTenant';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['KodeSektor' => null, 'PanduanSelesaiPada' => null, 'TransaksiPertamaPada' => null, 'BayarPertamaPada' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['PanduanSelesaiPada' => 'datetime', 'TransaksiPertamaPada' => 'datetime', 'BayarPertamaPada' => 'datetime', 'FiturDipakai' => 'array'];
    }
}
