<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Pelanggan\Enum\JenisMutasiPoin;
use App\Domain\Pelanggan\Enum\SumberMutasiPoin;
use App\Domain\Pelanggan\Tugas\KirimPesanLoyaltiTugas;
use Illuminate\Support\Carbon;

/**
 * Buku poin pelanggan (F-16b, append-only kecuali `Sisa` baris positif yang dipakai FIFO). Saldo = Σ `Poin`.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdPelanggan
 * @property JenisMutasiPoin $Jenis
 * @property int $Poin
 * @property int|null $Sisa
 * @property SumberMutasiPoin $JenisSumber
 * @property int|null $IdSumber
 * @property int|null $IdSumberAsal
 * @property Carbon|null $KedaluwarsaPada
 * @property string|null $Keterangan
 * @property int|null $IdPengguna
 * @property Carbon|null $DibuatPada
 */
final class MutasiPoin extends ModelDasar
{
    use MilikTenant;

    protected $table = 'MutasiPoin';

    protected bool $pakaiUuid = false;

    protected static function booted(): void
    {
        // D-299: pesan WhatsApp otomatis poin bertambah/ditukar dari semua jalur; tugas baru jalan setelah commit.
        self::created(static function (MutasiPoin $mutasi): void {
            if (app(KetersediaanWhatsapp::class)->PlatformAktif() && in_array($mutasi->Jenis, [JenisMutasiPoin::Perolehan, JenisMutasiPoin::Penukaran], true)) {
                KirimPesanLoyaltiTugas::dispatch($mutasi->IdTenant, 'poin', $mutasi->Id)->afterCommit();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisMutasiPoin::class,
            'JenisSumber' => SumberMutasiPoin::class,
            'Poin' => 'integer',
            'Sisa' => 'integer',
            'KedaluwarsaPada' => 'date',
        ];
    }
}
