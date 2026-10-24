<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Penjualan\Layanan\PengirimStrukDigital;
use App\Domain\Penjualan\Model\PesanKeluar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * K3: kirim struk digital (`PesanKeluar`) lewat WhatsApp/email di antrean, setelah commit (efek non-kritis, aturan
 * #10). Membawa `IdTenant` dan `IdPesanKeluar` saja (tujuan pelanggan tidak ikut payload antrean). Kegagalan penyedia
 * dicoba ulang sampai `tries` kali dengan jeda `backoff`; percobaan terakhir menandai `Gagal`.
 */
final class KirimStrukDigitalTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPesanKeluar,
    ) {}

    public function handle(KonteksTenant $konteks, PengirimStrukDigital $pengirim): void
    {
        $this->DalamKonteks($konteks, function () use ($pengirim): void {
            if ($pengirim->Kirim($this->idPesanKeluar, $this->attempts() >= $this->tries)) {
                $this->release($this->backoff[min($this->attempts(), count($this->backoff)) - 1]);
            }
        });
    }

    public function failed(?Throwable $galat): void
    {
        $this->DalamKonteks(app(KonteksTenant::class), function (): void {
            $pesan = PesanKeluar::query()->find($this->idPesanKeluar);

            if ($pesan !== null) {
                app(PengirimStrukDigital::class)->TandaiGagal($pesan, 'Struk gagal dikirim karena masalah sistem.');
            }
        });
    }

    /**
     * @param  callable(): void  $kerja
     */
    private function DalamKonteks(KonteksTenant $konteks, callable $kerja): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $kerja();
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
