<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Model;

use App\Domain\Akuntansi\Enum\JenisTransaksiKasBank;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Dokumen transaksi kas & bank back-office (F-13a, FIN-03), nomor `KB/{YYYY}/{MM}/{SEQ4}`. Jurnal dicatat saat
 * simpan (`JenisSumberJurnal::TransaksiKasBank`). Append-only (aturan #8): tidak pernah diubah atau dihapus; koreksi =
 * dokumen pembalik (`IdTransaksiDibalik`). `PathLampiran` tidak pernah dikirim ke browser.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property JenisTransaksiKasBank $Jenis
 * @property Carbon $Tanggal
 * @property int|null $IdOutlet
 * @property int $IdAkunSumber
 * @property int $IdAkunTujuan
 * @property string $Jumlah
 * @property string $Keterangan
 * @property string|null $PathLampiran
 * @property string|null $NamaLampiran
 * @property string|null $MimeLampiran
 * @property int|null $UkuranLampiran
 * @property int|null $IdTransaksiDibalik
 * @property int|null $IdJadwalKasBank D-23 D: transaksi dicatat otomatis oleh jadwal berulang ini.
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Akun $AkunSumber
 * @property-read Akun $AkunTujuan
 */
final class TransaksiKasBank extends ModelDasar
{
    use MilikTenant;

    public const PESAN_TIDAK_BISA_DIUBAH = 'Transaksi kas & bank yang sudah disimpan tidak bisa diubah/dihapus. Koreksi lewat dokumen pembalik';

    protected $table = 'TransaksiKasBank';

    /** @var list<string> */
    protected $hidden = ['PathLampiran'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'IdOutlet' => null,
        'PathLampiran' => null,
        'NamaLampiran' => null,
        'MimeLampiran' => null,
        'UkuranLampiran' => null,
        'IdTransaksiDibalik' => null,
        'IdJadwalKasBank' => null,
    ];

    /**
     * @return BelongsTo<Akun, $this>
     */
    public function AkunSumber(): BelongsTo
    {
        return $this->belongsTo(Akun::class, 'IdAkunSumber', 'Id');
    }

    /**
     * @return BelongsTo<Akun, $this>
     */
    public function AkunTujuan(): BelongsTo
    {
        return $this->belongsTo(Akun::class, 'IdAkunTujuan', 'Id');
    }

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
            'Jenis' => JenisTransaksiKasBank::class,
            'Tanggal' => 'date',
            'Jumlah' => 'string',
            'UkuranLampiran' => 'integer',
        ];
    }
}
