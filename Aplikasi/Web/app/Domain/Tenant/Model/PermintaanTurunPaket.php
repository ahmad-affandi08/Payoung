<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Permintaan turun paket (BR-P04.4, audit E5). Satu permintaan `Menunggu` per langganan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdLangganan
 * @property int $IdPaketAsal
 * @property int $IdPaketTujuan
 * @property StatusPermintaanTurunPaket $Status
 * @property Carbon $BerlakuPada
 * @property list<string>|null $UuidOutletTetap
 * @property list<string>|null $UuidAnggotaTetap
 * @property array<string, mixed>|null $Kelebihan
 * @property int|null $IdPenggunaPengaju
 * @property Carbon|null $DiterapkanPada
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property-read Paket $PaketTujuan
 * @property-read Paket $PaketAsal
 */
final class PermintaanTurunPaket extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PermintaanTurunPaket';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Menunggu',
        'UuidOutletTetap' => null,
        'UuidAnggotaTetap' => null,
        'Kelebihan' => null,
        'DiterapkanPada' => null,
        'DibatalkanPada' => null,
        'AlasanBatal' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusPermintaanTurunPaket::class,
            'BerlakuPada' => 'datetime',
            'UuidOutletTetap' => 'array',
            'UuidAnggotaTetap' => 'array',
            'Kelebihan' => 'array',
            'DiterapkanPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Paket, $this>
     */
    public function PaketTujuan(): BelongsTo
    {
        return $this->belongsTo(Paket::class, 'IdPaketTujuan', 'Id');
    }

    /**
     * @return BelongsTo<Paket, $this>
     */
    public function PaketAsal(): BelongsTo
    {
        return $this->belongsTo(Paket::class, 'IdPaketAsal', 'Id');
    }
}
