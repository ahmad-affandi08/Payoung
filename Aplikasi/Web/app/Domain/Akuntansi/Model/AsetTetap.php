<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Model;

use App\Domain\Akuntansi\Enum\KelompokAsetTetap;
use App\Domain\Akuntansi\Enum\StatusAsetTetap;
use App\Domain\Akuntansi\Enum\SumberDanaAsetTetap;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Aset tetap (FIN-10). Data perolehan tidak diubah setelah dicatat (dokumen tercatat, aturan #8): salah catat
 * dibatalkan selama belum disusutkan, lalu dicatat ulang. Akumulasi penyusutan = `AkumulasiAwal` + Σ `PenyusutanAset`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property string $Nama
 * @property KelompokAsetTetap $Kelompok
 * @property int|null $IdOutlet
 * @property Carbon $TanggalPerolehan
 * @property string $HargaPerolehan
 * @property string $NilaiSisa
 * @property int $UmurBulan
 * @property string $AkumulasiAwal
 * @property string $PeriodeMulai
 * @property SumberDanaAsetTetap $SumberDana
 * @property int|null $IdAkunSumber
 * @property StatusAsetTetap $Status
 * @property int|null $IdJurnal
 * @property Carbon|null $TanggalPelepasan
 * @property string|null $NilaiPelepasan
 * @property int|null $IdAkunPelepasan
 * @property int|null $IdJurnalPelepasan
 * @property string|null $Catatan
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class AsetTetap extends ModelDasar
{
    use MilikTenant;

    protected $table = 'AsetTetap';

    /** @var array<string, mixed> */
    protected $attributes = ['NilaiSisa' => '0.00', 'AkumulasiAwal' => '0.00', 'IdOutlet' => null, 'IdAkunSumber' => null, 'IdJurnal' => null, 'Catatan' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Kelompok' => KelompokAsetTetap::class,
            'Status' => StatusAsetTetap::class,
            'SumberDana' => SumberDanaAsetTetap::class,
            'TanggalPerolehan' => 'date',
            'TanggalPelepasan' => 'date',
            'DibatalkanPada' => 'datetime',
            'UmurBulan' => 'integer',
        ];
    }
}
