<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pelanggan\Enum\StatusPengingatPiutang;
use App\Domain\Pelanggan\Layanan\PengirimPengingatPiutang;
use App\Domain\Pelanggan\Model\PengingatPiutang;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * D-23 D bagian 4b: kirim `PengingatPiutang` lewat WhatsApp/email di antrean, setelah commit (efek non-kritis, aturan
 * #10). Payload hanya `IdTenant` & `IdPengingat` (tujuan pelanggan tidak ikut). Dicoba ulang sampai `tries` kali.
 */
final class KirimPengingatPiutangTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPengingat,
    ) {}

    public function handle(KonteksTenant $konteks, PengirimPengingatPiutang $pengirim): void
    {
        $this->DalamKonteks($konteks, function () use ($pengirim): void {
            if ($pengirim->Kirim($this->idPengingat, $this->attempts() >= $this->tries)) {
                $this->release($this->backoff[min($this->attempts(), count($this->backoff)) - 1]);
            }
        });
    }

    public function failed(?Throwable $galat): void
    {
        $this->DalamKonteks(app(KonteksTenant::class), function (): void {
            $pengingat = PengingatPiutang::query()->find($this->idPengingat);

            if ($pengingat !== null) {
                app(PengirimPengingatPiutang::class)->Akhiri($pengingat, StatusPengingatPiutang::Gagal, 'Pengingat gagal dikirim karena masalah sistem.');
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
