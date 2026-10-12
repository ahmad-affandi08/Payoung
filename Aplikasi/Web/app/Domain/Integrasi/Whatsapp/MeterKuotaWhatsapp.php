<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp;

use App\Domain\Integrasi\Model\PemakaianPesanWa;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Meteran kuota pesan WhatsApp lewat nomor Payoung (D-108). Kuota per bulan kalender WIB = `KuotaPesanWaBulanan`
 * efektif tenant (paket + add-on, ditimpa override); null = tak terbatas (tetap dihitung untuk tampilan), 0 = nomor
 * Payoung tidak boleh dipakai. `Pesan` memesan satu pesan secara atomik (baris bulan itu dikunci), `Lepas`
 * mengembalikannya bila penyedia gagal, sehingga hanya pesan yang benar-benar terkirim yang memakai kuota. Hanya
 * dipanggil dalam konteks tenant (scope `MilikTenant`).
 */
final class MeterKuotaWhatsapp
{
    public const KOLOM_BATAS = 'KuotaPesanWaBulanan';

    /** Zona bulan kuota: tagihan dan laporan Payoung memakai tanggal WIB. */
    public const ZONA = 'Asia/Jakarta';

    public function __construct(private readonly PemeriksaFiturTenant $fitur) {}

    public function AmbilPeriode(?CarbonInterface $pada = null): string
    {
        return ($pada ?? now())->setTimezone(self::ZONA)->format('Y-m');
    }

    /** True bila satu pesan boleh dikirim dan kuota sudah dikurangi satu. */
    public function Pesan(int $idTenant): bool
    {
        $batas = $this->fitur->AmbilBatasEfektif($idTenant, self::KOLOM_BATAS);
        $periode = $this->AmbilPeriode();

        return DB::transaction(function () use ($idTenant, $batas, $periode): bool {
            $baris = $this->AmbilBarisTerkunci($idTenant, $periode);

            if ($batas !== null && $baris->Jumlah >= $batas) {
                return false;
            }

            $baris->Jumlah++;
            $baris->save();

            return true;
        });
    }

    /** Mengembalikan satu pesan yang dipesan tetapi gagal terkirim. */
    public function Lepas(int $idTenant, ?string $periode = null): void
    {
        $periode ??= $this->AmbilPeriode();

        DB::transaction(function () use ($periode): void {
            $baris = PemakaianPesanWa::query()->where('Periode', $periode)->lockForUpdate()->first();

            if ($baris !== null && $baris->Jumlah > 0) {
                $baris->Jumlah--;
                $baris->save();
            }
        });
    }

    /**
     * Ringkasan untuk layar: pemakaian bulan ini, batas, dan sisa. Batas/sisa null = tak terbatas.
     *
     * @return array{Periode: string, Terpakai: int, Batas: int|null, Sisa: int|null}
     */
    public function AmbilRingkasan(int $idTenant): array
    {
        $periode = $this->AmbilPeriode();
        $batas = $this->fitur->AmbilBatasEfektif($idTenant, self::KOLOM_BATAS);
        $terpakai = (int) PemakaianPesanWa::query()->where('Periode', $periode)->value('Jumlah');

        return [
            'Periode' => $periode,
            'Terpakai' => $terpakai,
            'Batas' => $batas,
            'Sisa' => $batas === null ? null : max(0, $batas - $terpakai),
        ];
    }

    private function AmbilBarisTerkunci(int $idTenant, string $periode): PemakaianPesanWa
    {
        $baris = PemakaianPesanWa::query()->where('Periode', $periode)->lockForUpdate()->first();

        if ($baris !== null) {
            return $baris;
        }

        try {
            return PemakaianPesanWa::query()->create(['IdTenant' => $idTenant, 'Periode' => $periode, 'Jumlah' => 0]);
        } catch (UniqueConstraintViolationException) {
            return PemakaianPesanWa::query()->where('Periode', $periode)->lockForUpdate()->firstOrFail();
        }
    }
}
