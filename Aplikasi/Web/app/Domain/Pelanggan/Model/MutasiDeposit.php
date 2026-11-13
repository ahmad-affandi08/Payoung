<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Pelanggan\Enum\JenisMutasiDeposit;
use App\Domain\Pelanggan\Enum\SumberMutasiDeposit;
use App\Domain\Pelanggan\Tugas\KirimPesanLoyaltiTugas;
use Illuminate\Support\Carbon;

/**
 * Buku deposit pelanggan (F-16d bagian 1, §15 `MutasiDeposit`), append-only: saldo = Σ `Jumlah` (bertanda),
 * `SaldoSetelah` = saldo pelanggan tepat setelah baris ini (diisi di bawah kunci baris pelanggan).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPelanggan
 * @property JenisMutasiDeposit $Jenis
 * @property string $Jumlah
 * @property string $SaldoSetelah
 * @property SumberMutasiDeposit $JenisSumber
 * @property int|null $IdSumber
 * @property string|null $NomorSumber
 * @property Carbon $Tanggal
 * @property int|null $IdAkunKasBank
 * @property int|null $IdJurnal
 * @property string|null $Keterangan
 * @property int|null $IdPengguna
 * @property Carbon|null $DibuatPada
 */
final class MutasiDeposit extends ModelDasar
{
    use MilikTenant;

    protected $table = 'MutasiDeposit';

    protected static function booted(): void
    {
        // D-299: pesan WhatsApp otomatis deposit diisi/dipakai dari semua jalur; tugas baru jalan setelah commit.
        self::created(static function (MutasiDeposit $mutasi): void {
            if (app(KetersediaanWhatsapp::class)->PlatformAktif() && in_array($mutasi->Jenis, [JenisMutasiDeposit::Isi, JenisMutasiDeposit::Pemakaian], true)) {
                KirimPesanLoyaltiTugas::dispatch($mutasi->IdTenant, 'deposit', $mutasi->Id)->afterCommit();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisMutasiDeposit::class,
            'JenisSumber' => SumberMutasiDeposit::class,
            'Jumlah' => 'decimal:2',
            'SaldoSetelah' => 'decimal:2',
            'Tanggal' => 'date',
        ];
    }
}
