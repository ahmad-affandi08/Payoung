<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Resep dokter yang menyertai satu penjualan obat wajib resep (Sektor Apotek bagian 1, PRD §9.5, PMK 73/2016). Dibuat
 * bersama penjualan di transaksi penerimaan, append-only (bagian dokumen yang sudah dicatat), tidak pernah dihapus.
 *
 * Data pasien = data kesehatan pribadi: `NamaPasien` & `AlamatPasien` terenkripsi saat disimpan dan tidak boleh ditulis
 * ke log; tampil utuh hanya untuk pemegang izin `apotek.resep.lihat`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPenjualan
 * @property int $IdOutlet
 * @property string $NomorResep
 * @property Carbon $TanggalResep
 * @property string $NamaDokter
 * @property string|null $NoSipDokter
 * @property string $NamaPasien
 * @property string|null $UmurPasien
 * @property string|null $AlamatPasien
 * @property int|null $IdApoteker
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class ResepPenjualan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'ResepPenjualan';

    /** Data pasien tidak pernah ikut serialisasi model (log, audit, respons). */
    protected $hidden = ['NamaPasien', 'AlamatPasien'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'TanggalResep' => 'date',
            'NamaPasien' => 'encrypted',
            'AlamatPasien' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Resep penjualan yang sudah diterima tidak boleh diubah.');
        });

        self::deleting(static function (): void {
            throw new LogicException('Resep penjualan tidak boleh dihapus.');
        });
    }

    /**
     * @return BelongsTo<Penjualan, $this>
     */
    public function Penjualan(): BelongsTo
    {
        return $this->belongsTo(Penjualan::class, 'IdPenjualan', 'Id');
    }
}
