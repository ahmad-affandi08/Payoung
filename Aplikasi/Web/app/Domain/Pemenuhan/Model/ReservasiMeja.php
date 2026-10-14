<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Enum\SumberReservasi;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Reservasi meja FnB dengan uang muka opsional (§9.1, D-145). Waktu mulai UTC; durasi menit; selesai = mulai + durasi.
 * Status hanya lewat `UbahStatus()` (`StatusReservasiMeja::BisaBerubahKe`), dicatat di `RiwayatStatusDokumen` oleh Aksi.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdOutlet
 * @property string $Nomor
 * @property int|null $IdMeja
 * @property int|null $IdAreaMeja
 * @property int|null $IdPelanggan
 * @property string $NamaPemesan
 * @property string $NoHp
 * @property int $JumlahTamu
 * @property Carbon $MulaiPada
 * @property int $DurasiMenit
 * @property StatusReservasiMeja $Status
 * @property SumberReservasi $Sumber
 * @property string|null $Catatan
 * @property string|null $AlasanBatal
 * @property string $JumlahDp
 * @property string $DpTerpakai
 * @property string $DpDikembalikan
 * @property string $DpHangus
 * @property Carbon|null $DpDicatatPada
 * @property int|null $IdJurnalDp
 * @property int|null $IdJurnalPenyelesaianDp
 * @property int|null $IdPesananTerbuka
 * @property int|null $IdPenjualan
 * @property Carbon|null $HadirPada
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 */
final class ReservasiMeja extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'ReservasiMeja';

    protected $table = 'ReservasiMeja';

    /** @var array<string, mixed> */
    protected $attributes = [
        'IdMeja' => null,
        'IdAreaMeja' => null,
        'IdPelanggan' => null,
        'DurasiMenit' => 120,
        'Catatan' => null,
        'AlasanBatal' => null,
        'JumlahDp' => '0.00',
        'DpTerpakai' => '0.00',
        'DpDikembalikan' => '0.00',
        'DpHangus' => '0.00',
        'DpDicatatPada' => null,
        'IdJurnalDp' => null,
        'IdJurnalPenyelesaianDp' => null,
        'IdPesananTerbuka' => null,
        'IdPenjualan' => null,
        'HadirPada' => null,
        'DibuatOleh' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'MulaiPada' => 'datetime',
            'Status' => StatusReservasiMeja::class,
            'Sumber' => SumberReservasi::class,
            'DpDicatatPada' => 'datetime',
            'HadirPada' => 'datetime',
            'JumlahTamu' => 'integer',
            'DurasiMenit' => 'integer',
        ];
    }

    public function UbahStatus(StatusReservasiMeja $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status reservasi meja {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    public function AmbilSelesaiPada(): Carbon
    {
        return $this->MulaiPada->copy()->addMinutes($this->DurasiMenit);
    }

    /** Sisa uang muka yang belum dipakai, dikembalikan, atau hangus. */
    public function AmbilSisaUangMuka(): Uang
    {
        return Uang::Dari($this->JumlahDp)
            ->Kurangi(Uang::Dari($this->DpTerpakai))
            ->Kurangi(Uang::Dari($this->DpDikembalikan))
            ->Kurangi(Uang::Dari($this->DpHangus));
    }
}
