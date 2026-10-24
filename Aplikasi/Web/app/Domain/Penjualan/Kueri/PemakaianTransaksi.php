<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;

/**
 * Jumlah dokumen penjualan tenant aktif pada satu bulan kalender atau satu hari bisnis, dihitung langsung dari
 * `Penjualan` (satu sumber kebenaran, tanpa penghitung terpisah yang bisa melenceng). Semua status ikut dihitung,
 * termasuk `Void`: membatalkan transaksi tidak mengembalikan kuota. Retur bukan penjualan baru dan tidak dihitung.
 *
 * - Bulan: kalender WIB menurut `TanggalBisnis` dokumen (indeks `IdTenant + TanggalBisnis`).
 * - Hari: tanggal bisnis **tiap outlet sendiri** (zona waktu dan jam tutup buku outlet, `TanggalBisnisOutlet`), jadi
 *   penjualan lewat tengah malam tetap masuk hari yang sama dengan jam bukanya dan kuota baru kembali penuh saat hari
 *   bisnis outlet berganti.
 */
final class PemakaianTransaksi
{
    /** Zona bulan kuota: sama dengan kuota WhatsApp dan tagihan Payoung (WIB). */
    public const ZONA = 'Asia/Jakarta';

    public function __construct(private readonly TanggalBisnisOutlet $tanggalBisnis) {}

    public function AmbilPeriodeBulan(?CarbonImmutable $pada = null): string
    {
        return ($pada ?? CarbonImmutable::now())->setTimezone(self::ZONA)->format('Y-m');
    }

    public function HitungBulan(string $periode): int
    {
        $awal = CarbonImmutable::parse($periode.'-01', self::ZONA)->startOfMonth();
        $akhir = $awal->endOfMonth();

        return Penjualan::query()
            ->whereBetween('TanggalBisnis', [$awal->toDateString(), $akhir->toDateString()])
            ->count();
    }

    /**
     * Penjualan pada hari bisnis berjalan, dijumlah per outlet menurut tanggal bisnis outletnya sendiri. Satu kueri
     * pengelompokan atas tiga hari kalender (zona waktu Indonesia berbeda paling banyak dua jam).
     */
    public function HitungHari(?CarbonImmutable $pada = null): int
    {
        $pada ??= CarbonImmutable::now();
        $tengah = $pada->setTimezone(self::ZONA)->startOfDay();
        $baris = Penjualan::query()->toBase()
            ->whereBetween('TanggalBisnis', [$tengah->subDay()->toDateString(), $tengah->addDay()->toDateString()])
            ->selectRaw('IdOutlet, TanggalBisnis, COUNT(*) as Jumlah')
            ->groupBy('IdOutlet', 'TanggalBisnis')
            ->get();
        $total = 0;

        foreach ($baris as $b) {
            if ($this->tanggalBisnis->Hitung((int) $b->IdOutlet, $pada)->toDateString() === substr((string) $b->TanggalBisnis, 0, 10)) {
                $total += (int) $b->Jumlah;
            }
        }

        return $total;
    }
}
