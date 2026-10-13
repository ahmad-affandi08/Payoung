<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Permintaan pembelian `PP/{OUTLET}/{YYMM}/{SEQ4}` (F-04 PR): outlet meminta barang dibelikan sebelum ada PO. Bebas
 * diubah selama Draf; setelah itu hanya kolom perpindahan status. Tanpa dampak stok maupun jurnal.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdGudang
 * @property int|null $IdOutlet
 * @property int|null $IdPemasok
 * @property Carbon $Tanggal
 * @property Carbon|null $DibutuhkanPada
 * @property StatusPermintaanPembelian $Status
 * @property string|null $Catatan
 * @property string|null $AlasanDitolak
 * @property string|null $AlasanBatal
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DiajukanOleh
 * @property Carbon|null $DiajukanPada
 * @property int|null $DiputuskanOleh
 * @property Carbon|null $DiputuskanPada
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property int|null $IdPesananPembelian
 * @property int|null $DijadikanPoOleh
 * @property Carbon|null $DijadikanPoPada
 * @property-read Collection<int, PermintaanPembelianDetail> $Detail
 */
final class PermintaanPembelian extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'PermintaanPembelian';

    private const KOLOM_STATUS = [
        'Status', 'AlasanDitolak', 'AlasanBatal', 'DiajukanOleh', 'DiajukanPada', 'DiputuskanOleh', 'DiputuskanPada',
        'DibatalkanOleh', 'DibatalkanPada', 'IdPesananPembelian', 'DijadikanPoOleh', 'DijadikanPoPada', 'DiubahOleh',
    ];

    protected $table = 'PermintaanPembelian';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Draf', 'IdPemasok' => null, 'DibutuhkanPada' => null, 'Catatan' => null, 'AlasanDitolak' => null, 'AlasanBatal' => null, 'IdPesananPembelian' => null];

    protected static function booted(): void
    {
        self::updating(static function (self $permintaan): void {
            $asal = $permintaan->getOriginal('Status');
            $asal = $asal instanceof StatusPermintaanPembelian ? $asal : StatusPermintaanPembelian::tryFrom((string) $asal);

            if ($asal !== StatusPermintaanPembelian::Draf && array_diff(array_keys($permintaan->getDirty()), [...self::KOLOM_STATUS, 'DiubahPada']) !== []) {
                throw new LogicException('Permintaan pembelian di luar Draf hanya boleh berubah statusnya.');
            }
        });
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusPermintaanPembelian $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status permintaan pembelian {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return HasMany<PermintaanPembelianDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(PermintaanPembelianDetail::class, 'IdPermintaanPembelian', 'Id')->orderBy('Urutan');
    }

    /**
     * @return BelongsTo<Pemasok, $this>
     */
    public function Pemasok(): BelongsTo
    {
        return $this->belongsTo(Pemasok::class, 'IdPemasok', 'Id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'DibutuhkanPada' => 'date',
            'Status' => StatusPermintaanPembelian::class,
            'DiajukanPada' => 'datetime',
            'DiputuskanPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
            'DijadikanPoPada' => 'datetime',
        ];
    }
}
