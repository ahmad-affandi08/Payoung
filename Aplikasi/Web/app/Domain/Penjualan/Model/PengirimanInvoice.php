<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\JenisPengirimanInvoice;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Satu pengiriman invoice (atau pengingatnya) ke pelanggan lewat WhatsApp (F-21), dikirim `KirimInvoiceTugas`. `Tujuan`
 * terenkripsi dan tidak diserialisasi. Status hanya lewat `UbahStatus()`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdInvoice
 * @property JenisPengirimanInvoice $Jenis
 * @property string|null $Tahap
 * @property string $Tujuan
 * @property StatusPengirimanInvoice $Status
 * @property string|null $Penyedia
 * @property string|null $IdPesanPenyedia
 * @property string|null $PesanGalat
 * @property int $Percobaan
 * @property Carbon|null $TerkirimPada
 * @property int|null $DikirimOleh
 * @property Carbon|null $DibuatPada
 */
final class PengirimanInvoice extends ModelDasar
{
    use MilikTenant;

    public const PANJANG_PESAN_GALAT = 300;

    protected $table = 'PengirimanInvoice';

    /** @var list<string> */
    protected $hidden = ['Tujuan'];

    /** @var array<string, mixed> */
    protected $attributes = ['Tahap' => null, 'Penyedia' => null, 'IdPesanPenyedia' => null, 'PesanGalat' => null, 'Percobaan' => 0, 'TerkirimPada' => null, 'DikirimOleh' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisPengirimanInvoice::class,
            'Status' => StatusPengirimanInvoice::class,
            'Tujuan' => 'encrypted',
            'Percobaan' => 'integer',
            'TerkirimPada' => 'datetime',
        ];
    }

    public function UbahStatus(StatusPengirimanInvoice $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status pengiriman invoice {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }
}
