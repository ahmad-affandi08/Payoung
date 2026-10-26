<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\AlasanBahanTerbuang;
use App\Domain\Persediaan\Enum\StatusBahanTerbuang;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Catatan bahan/menu terbuang (F-05f). Tidak dihapus & isinya tidak diedit; hanya berubah Tercatat → Dibatalkan.
 * `Sumber` = `Pos` (outbox kasir) atau `BackOffice`. `Nilai` = HPP persediaan yang keluar.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int|null $IdOutlet
 * @property int $IdGudang
 * @property int|null $IdPerangkat
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string $Jumlah
 * @property AlasanBahanTerbuang $Alasan
 * @property string|null $Catatan
 * @property string $Sumber
 * @property int|null $IdPengguna
 * @property Carbon|null $DibuatOfflinePada
 * @property Carbon $TanggalBisnis
 * @property string $Nilai
 * @property StatusBahanTerbuang $Status
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property bool $PerluTinjauan
 * @property string|null $AlasanTinjauan
 * @property string|null $AlasanBatal
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property Carbon|null $DibuatPada
 */
final class BahanTerbuang extends ModelDasar
{
    use MilikTenant;

    public const SUMBER_POS = 'Pos';

    public const SUMBER_BACK_OFFICE = 'BackOffice';

    /** @var list<string> */
    private const KOLOM_ISI = ['IdOutlet', 'IdGudang', 'IdProduk', 'Jumlah', 'Alasan', 'Catatan', 'TanggalBisnis', 'Nilai', 'IdJurnal'];

    protected $table = 'BahanTerbuang';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Tercatat',
        'Catatan' => null,
        'Nilai' => '0.00',
        'PerluTinjauan' => false,
        'AlasanTinjauan' => null,
        'AlasanBatal' => null,
    ];

    protected static function booted(): void
    {
        self::updating(function (self $baris): void {
            if ($baris->getOriginal('IdJurnal') !== null && array_intersect(array_keys($baris->getDirty()), self::KOLOM_ISI) !== []) {
                throw new LogicException('Catatan bahan terbuang tidak bisa diubah isinya. Batalkan lalu catat ulang.');
            }
        });
        self::deleting(function (): void {
            throw new LogicException('Catatan bahan terbuang tidak pernah dihapus. Batalkan untuk koreksi.');
        });
    }

    public function UbahStatus(StatusBahanTerbuang $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status bahan terbuang {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Alasan' => AlasanBahanTerbuang::class,
            'Status' => StatusBahanTerbuang::class,
            'Jumlah' => 'string',
            'Nilai' => 'string',
            'TanggalBisnis' => 'date',
            'DibuatOfflinePada' => 'datetime',
            'DibatalkanPada' => 'datetime',
            'PerluTinjauan' => 'boolean',
        ];
    }
}
