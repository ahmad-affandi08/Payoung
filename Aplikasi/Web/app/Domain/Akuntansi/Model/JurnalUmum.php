<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Model;

use App\Domain\Akuntansi\Enum\JenisJurnalUmum;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Dokumen jurnal umum manual `JM/{YYYY}/{MM}/{SEQ4}` (FIN-05, D-131). Jurnalnya dicatat saat disimpan
 * (`JenisSumberJurnal::JurnalUmum`, baris ada di `JurnalDetail`). Append-only (aturan #8): tidak pernah diubah atau
 * dihapus; koreksi = dokumen pembalik (`IdJurnalUmumDibalik`). `PathLampiran` tidak pernah dikirim ke browser.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property JenisJurnalUmum $Jenis
 * @property Carbon $Tanggal
 * @property string|null $Referensi
 * @property string $Keterangan
 * @property string $UuidKlien
 * @property string $TotalNilai
 * @property string|null $PathLampiran
 * @property string|null $NamaLampiran
 * @property string|null $MimeLampiran
 * @property int|null $UkuranLampiran
 * @property int|null $IdJurnalUmumDibalik
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class JurnalUmum extends ModelDasar
{
    use MilikTenant;

    public const PESAN_TIDAK_BISA_DIUBAH = 'Jurnal umum yang sudah disimpan tidak bisa diubah/dihapus; koreksi lewat dokumen pembalik';

    protected $table = 'JurnalUmum';

    /** @var list<string> */
    protected $hidden = ['PathLampiran'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Referensi' => null,
        'PathLampiran' => null,
        'NamaLampiran' => null,
        'MimeLampiran' => null,
        'UkuranLampiran' => null,
        'IdJurnalUmumDibalik' => null,
    ];

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new LogicException(self::PESAN_TIDAK_BISA_DIUBAH);
        });
        self::deleting(function (): void {
            throw new LogicException(self::PESAN_TIDAK_BISA_DIUBAH);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisJurnalUmum::class,
            'Tanggal' => 'date',
            'TotalNilai' => 'string',
            'UkuranLampiran' => 'integer',
        ];
    }
}
