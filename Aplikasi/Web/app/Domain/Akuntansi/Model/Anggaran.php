<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Anggaran satu akun pada satu bulan (F8, FIN-11): `IdOutlet` kosong = seluruh usaha. Rencana, bukan dokumen
 * transaksi, jadi nilainya boleh diubah (lewat `SimpanAnggaran`, diaudit). Akun pendapatan, HPP, dan beban saja.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $Tahun
 * @property int $Bulan
 * @property int $IdAkun
 * @property int|null $IdOutlet
 * @property string $Jumlah
 * @property Carbon $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class Anggaran extends ModelDasar
{
    use MilikTenant;

    protected $table = 'Anggaran';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Tahun' => 'integer', 'Bulan' => 'integer'];
    }
}
