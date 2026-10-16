<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use App\Domain\Penjualan\Layanan\PengirimPenawaran;
use App\Domain\Penjualan\Model\PengirimanPenawaran;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Kirim `PengirimanPenawaran` lewat WhatsApp di antrean, setelah commit (efek non-kritis, aturan #10). Payload hanya
 * `IdTenant` & `IdPengiriman` (nomor tujuan tidak ikut). Dicoba ulang sampai `tries` kali.
 */
final class KirimPenawaranTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPengiriman,
    ) {}

    public function handle(KonteksTenant $konteks, PengirimPenawaran $pengirim): void
    {
        $this->DalamKonteks($konteks, function () use ($pengirim): void {
            if ($pengirim->Kirim($this->idPengiriman, $this->attempts() >= $this->tries)) {
                $this->release($this->backoff[min($this->attempts(), count($this->backoff)) - 1]);
            }
        });
    }

    public function failed(?Throwable $galat): void
    {
        $this->DalamKonteks(app(KonteksTenant::class), function (): void {
            $pengiriman = PengirimanPenawaran::query()->find($this->idPengiriman);

            if ($pengiriman !== null) {
                app(PengirimPenawaran::class)->Akhiri($pengiriman, StatusPengirimanInvoice::Gagal, 'Pengiriman gagal karena galat sistem.');
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
