<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Dokumen penyesuaian stok per lokasi stok (F-05b). Tanpa hapus; isi hanya berubah selama Draf; Diposting &
 * Dibatalkan final. `NilaiPerkiraan` = Σ |nilai| baris (keluar dinilai HPP rata-rata saat diajukan) untuk batas
 * persetujuan `BatasPersetujuanPenyesuaian`; `TotalNilaiMasuk`/`TotalNilaiKeluar` = nilai sebenarnya saat dicatat.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string|null $Nomor
 * @property int $IdGudang
 * @property int|null $IdOutlet
 * @property Carbon $Tanggal
 * @property AlasanPenyesuaian $KodeAlasan
 * @property string|null $Keterangan
 * @property StatusPenyesuaianStok $Status
 * @property int $JumlahBaris
 * @property string $NilaiPerkiraan
 * @property string $TotalNilaiMasuk
 * @property string $TotalNilaiKeluar
 * @property bool $PerluPersetujuan
 * @property string|null $AlasanTolak
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DiajukanOleh
 * @property int|null $DisetujuiOleh
 * @property int|null $DipostingOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DiajukanPada
 * @property Carbon|null $DisetujuiPada
 * @property Carbon|null $DipostingPada
 * @property Carbon|null $DibatalkanPada
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, PenyesuaianStokDetail> $Detail
 */
final class PenyesuaianStok extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'PenyesuaianStok';

    protected $table = 'PenyesuaianStok';

    /** @var list<string> */
    private const KOLOM_ISI = ['IdGudang', 'IdOutlet', 'Tanggal', 'KodeAlasan', 'Keterangan', 'JumlahBaris'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Nomor' => null,
        'Status' => 'Draf',
        'Keterangan' => null,
        'JumlahBaris' => 0,
        'NilaiPerkiraan' => '0.00',
        'TotalNilaiMasuk' => '0.00',
        'TotalNilaiKeluar' => '0.00',
        'PerluPersetujuan' => false,
        'AlasanTolak' => null,
    ];

    /**
     * @return HasMany<PenyesuaianStokDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(PenyesuaianStokDetail::class, 'IdPenyesuaianStok', 'Id');
    }

    protected static function booted(): void
    {
        self::updating(function (self $dokumen): void {
            $asal = $dokumen->getOriginal('Status');
            $asal = $asal instanceof StatusPenyesuaianStok ? $asal : StatusPenyesuaianStok::tryFrom((string) $asal);

            if ($asal === StatusPenyesuaianStok::Draf) {
                return;
            }

            if ($asal === StatusPenyesuaianStok::Diposting || $asal === StatusPenyesuaianStok::Dibatalkan
                || array_intersect(array_keys($dokumen->getDirty()), self::KOLOM_ISI) !== []) {
                throw new LogicException("Penyesuaian stok berstatus {$asal?->value} tidak bisa diubah isinya.");
            }
        });
        self::deleting(function (): void {
            throw new LogicException('Dokumen penyesuaian stok tidak pernah dihapus; draf dibatalkan.');
        });
    }

    public function UbahStatus(StatusPenyesuaianStok $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status penyesuaian stok {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'KodeAlasan' => AlasanPenyesuaian::class,
            'Status' => StatusPenyesuaianStok::class,
            'JumlahBaris' => 'integer',
            'NilaiPerkiraan' => 'string',
            'TotalNilaiMasuk' => 'string',
            'TotalNilaiKeluar' => 'string',
            'PerluPersetujuan' => 'boolean',
            'DiajukanPada' => 'datetime',
            'DisetujuiPada' => 'datetime',
            'DipostingPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
