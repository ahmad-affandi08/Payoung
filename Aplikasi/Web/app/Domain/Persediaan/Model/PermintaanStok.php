<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Permintaan stok dari lokasi yang butuh barang (tujuan) ke lokasi pengirim (asal) (F-05b, D-105). Bukan peristiwa stok
 * atau akuntansi; hanya mencatat apa yang diminta dan berapa yang sudah dikirim lewat transfer. Isi dokumen tidak
 * berubah setelah diajukan; yang berubah hanya status dan jumlah dikirim per baris. Tanpa hapus.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdGudangAsal
 * @property int|null $IdOutletAsal
 * @property int $IdGudangTujuan
 * @property int|null $IdOutletTujuan
 * @property Carbon $Tanggal
 * @property StatusPermintaanStok $Status
 * @property string|null $Catatan
 * @property int $JumlahBaris
 * @property string|null $Alasan
 * @property int|null $DibuatOleh
 * @property int|null $DitutupOleh
 * @property Carbon|null $DitutupPada
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, PermintaanStokDetail> $Detail
 */
final class PermintaanStok extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'PermintaanStok';

    protected $table = 'PermintaanStok';

    /** @var list<string> */
    private const KOLOM_ISI = ['IdGudangAsal', 'IdOutletAsal', 'IdGudangTujuan', 'IdOutletTujuan', 'Tanggal', 'Catatan', 'JumlahBaris', 'Nomor'];

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Diajukan', 'Catatan' => null, 'JumlahBaris' => 0, 'Alasan' => null];

    /**
     * @return HasMany<PermintaanStokDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(PermintaanStokDetail::class, 'IdPermintaanStok', 'Id');
    }

    protected static function booted(): void
    {
        self::updating(function (self $permintaan): void {
            if (array_intersect(array_keys($permintaan->getDirty()), self::KOLOM_ISI) !== []) {
                throw new LogicException('Isi permintaan stok tidak bisa diubah setelah diajukan.');
            }
        });
        self::deleting(function (): void {
            throw new LogicException('Permintaan stok tidak pernah dihapus; batalkan atau tutup.');
        });
    }

    public function UbahStatus(StatusPermintaanStok $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status permintaan stok {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
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
            'Status' => StatusPermintaanStok::class,
            'JumlahBaris' => 'integer',
            'DitutupPada' => 'datetime',
        ];
    }
}
