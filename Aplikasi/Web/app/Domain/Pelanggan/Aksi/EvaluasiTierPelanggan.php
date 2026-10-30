<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Kueri\PengaturanLoyaltiTenant;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\TierPelanggan;
use App\Domain\Penjualan\Kueri\BelanjaPelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Naik/turun tier otomatis (F-16b) untuk tenant aktif: tier aktif tertinggi yang `MinimalBelanja`-nya ≤ total belanja
 * pelanggan (tanpa void) sejak [hariIni] − `BulanEvaluasiTier` bulan. Pelanggan `TierTetap` dan yang diarsipkan
 * dilewati; belanja di bawah semua ambang = tanpa tier. Hanya berjalan bila loyalti berlaku dan ada tier aktif.
 * Audit `pelanggan.tier-otomatis` per perubahan. Hasil: jumlah pelanggan yang tiernya berubah.
 */
final class EvaluasiTierPelanggan
{
    public function __construct(
        private readonly PengaturanLoyaltiTenant $pengaturan,
        private readonly BelanjaPelanggan $belanja,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(CarbonImmutable $hariIni): int
    {
        $aturan = $this->pengaturan->Ambil();
        // Tier dengan ambang tertinggi lebih dulu.
        $tier = TierPelanggan::query()->where('Status', StatusPelanggan::Aktif->value)->get()
            ->sort(fn (TierPelanggan $a, TierPelanggan $b): int => Uang::Dari($b->MinimalBelanja)->Bandingkan(Uang::Dari($a->MinimalBelanja)))
            ->values();

        if (! $aturan->CekBerlaku() || $tier->isEmpty()) {
            return 0;
        }

        $total = $this->belanja->AmbilTotalSejak($hariIni->subMonthsNoOverflow($aturan->bulanEvaluasiTier)->toDateString());
        $berubah = 0;

        Pelanggan::query()
            ->where('Status', StatusPelanggan::Aktif->value)
            ->where('TierTetap', false)
            ->orderBy('Id')
            ->chunkById(200, function ($daftar) use ($tier, $total, &$berubah): void {
                DB::transaction(function () use ($daftar, $tier, $total, &$berubah): void {
                    // Hanya pelanggan yang tiernya berubah yang disimpan satu per satu (dengan kunci dan pemeriksaan ulang
                    // TierTetap, supaya tier yang baru diatur admin tidak tertimpa). Penanda waktu evaluasi untuk semua
                    // pelanggan di potongan ini ditulis dalam satu UPDATE, bukan satu UPDATE per pelanggan tiap malam.
                    $sekarang = now();

                    foreach ($daftar as $p) {
                        /** @var Pelanggan $p */
                        $belanja = Uang::Dari($total[$p->Id] ?? '0');
                        $cocok = $tier->first(fn (TierPelanggan $t): bool => Uang::Dari($t->MinimalBelanja)->Bandingkan($belanja) <= 0);
                        $idBaru = $cocok?->Id;

                        if ($idBaru === $p->IdTier) {
                            continue;
                        }

                        $terkunci = Pelanggan::query()->whereKey($p->Id)->where('TierTetap', false)->lockForUpdate()->first();

                        if ($terkunci === null) {
                            continue;
                        }

                        $lama = $terkunci->IdTier;
                        $terkunci->IdTier = $idBaru;
                        $terkunci->TierDievaluasiPada = $sekarang;
                        $terkunci->save();
                        $berubah++;
                        $this->audit->Catat('pelanggan.tier-otomatis', $terkunci, ['IdTier' => $lama], ['IdTier' => $idBaru, 'Belanja' => $belanja->KeString()]);
                    }

                    Pelanggan::query()->whereIn('Id', $daftar->pluck('Id')->all())->where('TierTetap', false)->update(['TierDievaluasiPada' => $sekarang]);
                });
            }, 'Id');

        return $berubah;
    }
}
