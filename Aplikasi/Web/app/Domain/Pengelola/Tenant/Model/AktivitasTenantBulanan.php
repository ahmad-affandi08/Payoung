<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Aktivitas transaksi satu tenant dalam satu bulan kalender (P-07 PGL-24, dasar retensi kohort): `Bulan` = tanggal 1,
 * `JumlahTransaksi` penjualan tidak void, `HariAktif` jumlah tanggal bisnis yang punya transaksi.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property Carbon $Bulan
 * @property int $JumlahTransaksi
 * @property int $HariAktif
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class AktivitasTenantBulanan extends ModelDasar
{
    protected $table = 'AktivitasTenantBulanan';

    protected bool $pakaiUuid = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Bulan' => 'date', 'JumlahTransaksi' => 'integer', 'HariAktif' => 'integer'];
    }
}
