<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use Illuminate\Support\Carbon;

/**
 * Permintaan penghapusan data dan penutupan akun (UU PDP, P-07). Satu `Menunggu` per tenant. Setelah `Dilaksanakan`
 * baris ini menjadi bukti (tanpa data pribadi).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int|null $IdPenggunaPeminta
 * @property StatusPermintaanPenghapusanData $Status
 * @property string|null $Alasan
 * @property Carbon $BerlakuPada
 * @property Carbon|null $DibatalkanPada
 * @property int|null $IdPenggunaPengelolaPemutus
 * @property Carbon|null $DiputuskanPada
 * @property string|null $CatatanPemutus
 * @property Carbon|null $DilaksanakanPada
 * @property array<string, mixed>|null $Ringkasan
 * @property Carbon|null $BuktiDikirimPada
 * @property Carbon|null $BerkasPembukuanDihapusPada
 * @property Carbon|null $DibuatPada
 */
final class PermintaanPenghapusanData extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PermintaanPenghapusanData';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Menunggu',
        'Alasan' => null,
        'DibatalkanPada' => null,
        'IdPenggunaPengelolaPemutus' => null,
        'DiputuskanPada' => null,
        'CatatanPemutus' => null,
        'DilaksanakanPada' => null,
        'Ringkasan' => null,
        'BuktiDikirimPada' => null,
        'BerkasPembukuanDihapusPada' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusPermintaanPenghapusanData::class,
            'BerlakuPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
            'DiputuskanPada' => 'datetime',
            'DilaksanakanPada' => 'datetime',
            'Ringkasan' => 'array',
            'BuktiDikirimPada' => 'datetime',
            'BerkasPembukuanDihapusPada' => 'datetime',
        ];
    }
}
