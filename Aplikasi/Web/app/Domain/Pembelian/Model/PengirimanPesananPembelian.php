<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pembelian\Enum\StatusPengirimanPesananPembelian;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Satu pengiriman PO ke pemasok lewat WhatsApp (F-04), dikirim `KirimPesananPembelianTugas`. `Tujuan` terenkripsi dan
 * tidak diserialisasi. Status hanya lewat `UbahStatus()`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPesananPembelian
 * @property string $Tujuan
 * @property StatusPengirimanPesananPembelian $Status
 * @property string|null $Penyedia
 * @property string|null $IdPesanPenyedia
 * @property string|null $PesanGalat
 * @property int $Percobaan
 * @property Carbon|null $TerkirimPada
 * @property int|null $DikirimOleh
 * @property Carbon|null $DibuatPada
 */
final class PengirimanPesananPembelian extends ModelDasar
{
    use MilikTenant;

    public const PANJANG_PESAN_GALAT = 300;

    protected $table = 'PengirimanPesananPembelian';

    /** @var list<string> */
    protected $hidden = ['Tujuan'];

    /** @var array<string, mixed> */
    protected $attributes = ['Penyedia' => null, 'IdPesanPenyedia' => null, 'PesanGalat' => null, 'Percobaan' => 0, 'TerkirimPada' => null, 'DikirimOleh' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusPengirimanPesananPembelian::class,
            'Tujuan' => 'encrypted',
            'Percobaan' => 'integer',
            'TerkirimPada' => 'datetime',
        ];
    }

    public function UbahStatus(StatusPengirimanPesananPembelian $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status pengiriman PO {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }
}
