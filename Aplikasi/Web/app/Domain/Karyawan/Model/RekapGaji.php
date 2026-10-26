<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Karyawan\Enum\SiklusGaji;
use App\Domain\Karyawan\Enum\StatusRekapGaji;
use Illuminate\Support\Carbon;

/**
 * Rekap gaji satu periode (F-18 bagian 3, EMP-06; D-219): `Bulanan` = bulan kalender (`Periode` `YYYY-MM`), `Mingguan` =
 * tujuh hari sejak `TanggalMulai` (`Periode` = tanggal mulai `YYYY-MM-DD`). Total = Σ baris; dihitung ulang setiap baris
 * berubah.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Periode
 * @property SiklusGaji $Siklus
 * @property Carbon $TanggalMulai
 * @property Carbon $TanggalSelesai
 * @property StatusRekapGaji $Status
 * @property string $TotalKotor
 * @property string $TotalPotongan
 * @property string $TotalBersih
 * @property Carbon|null $TanggalBayar
 * @property int|null $IdAkunKasBank
 * @property int|null $IdAkunBeban
 * @property int|null $IdJurnal
 * @property int|null $DibuatOleh
 * @property int|null $DibayarOleh
 * @property Carbon|null $DibayarPada
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class RekapGaji extends ModelDasar
{
    use MilikTenant;

    protected $table = 'RekapGaji';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Siklus' => 'Bulanan',
        'TotalKotor' => '0.00',
        'TotalPotongan' => '0.00',
        'TotalBersih' => '0.00',
        'TanggalBayar' => null,
        'IdAkunKasBank' => null,
        'IdAkunBeban' => null,
        'IdJurnal' => null,
        'DibuatOleh' => null,
        'DibayarOleh' => null,
        'DibayarPada' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Status' => StatusRekapGaji::class, 'Siklus' => SiklusGaji::class, 'TanggalMulai' => 'date', 'TanggalSelesai' => 'date', 'TanggalBayar' => 'date', 'DibayarPada' => 'datetime'];
    }

    /** Label untuk manusia: bulanan "September 2026", mingguan "19–25 Oktober 2026". */
    public function AmbilLabel(): string
    {
        return $this->Siklus === SiklusGaji::Mingguan
            ? PenjagaKunciPeriode::FormatRentang($this->TanggalMulai->toDateString(), $this->TanggalSelesai->toDateString())
            : PenjagaKunciPeriode::FormatPeriode($this->Periode);
    }

    /** Jumlah hari dalam rentang rekap (batas hari masuk upah harian). */
    public function HitungJumlahHari(): int
    {
        return (int) $this->TanggalMulai->diffInDays($this->TanggalSelesai) + 1;
    }
}
