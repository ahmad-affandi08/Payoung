<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Umpan balik pelanggan atas satu transaksi (F7, CRM-08): nilai 1–5 dan komentar opsional dari struk digital. Satu per
 * penjualan; baris tidak diubah atau dihapus setelah dikirim (hanya ditulis `KirimUmpanBalikTransaksi`).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPenjualan
 * @property int $IdOutlet
 * @property int|null $IdPelanggan
 * @property int $Nilai
 * @property string|null $Komentar
 * @property Carbon $DibuatPada
 */
final class UmpanBalikTransaksi extends ModelDasar
{
    use MilikTenant;

    protected $table = 'UmpanBalikTransaksi';

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new \LogicException('Umpan balik yang sudah dikirim tidak boleh diubah.'));
        self::deleting(fn (): never => throw new \LogicException('Umpan balik yang sudah dikirim tidak boleh dihapus.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Nilai' => 'integer', 'DibuatPada' => 'datetime'];
    }
}
