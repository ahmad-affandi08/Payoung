<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Aksi;

use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\Tenant\Layanan\PengumpulFiturTenant;
use App\Domain\Pengelola\Tenant\Model\AktivitasTenantBulanan;
use App\Domain\Pengelola\Tenant\Model\AnalitikTenant;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * P-07 PGL-24: memperbarui bahan analitik platform (funnel, adopsi fitur, retensi kohort) dari data tenant, dibaca lintas
 * tenant lewat `KonteksPengelola::JalankanLintasTenant` (satu baris audit per proses) dengan kueri teragregasi per
 * `UKURAN_KELOMPOK` tenant; hanya angka agregat dan tanggal tonggak yang disimpan.
 *
 * - **Cakupan**: semua tenant tanpa penanda Uji/Demo/Internal (dikecualikan dari metrik bisnis), termasuk yang sudah
 *   berhenti atau ditutup, supaya penyebut kohort tidak menyusut.
 * - **`AnalitikTenant`**: sektor (template outlet pertama), tonggak funnel (panduan awal selesai, transaksi pertama, bayar
 *   pertama; sekali tercapai tidak berubah), dan fitur yang dipakai 30 hari terakhir (`Penjualan` + `PengumpulFiturTenant`).
 * - **`AktivitasTenantBulanan`**: transaksi tidak void dan hari aktif per bulan, untuk bulan lalu dan bulan ini bila tanpa
 *   argumen; `$sejak` mengisi ulang riwayat dari bulan itu (pertama kali dijalankan di server).
 */
final class PerbaruiAnalitikTenant
{
    public const UKURAN_KELOMPOK = 500;

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PengumpulFiturTenant $fitur,
    ) {}

    /**
     * @return array{Tenant: int, Bulan: int}
     */
    public function Jalankan(?CarbonImmutable $sejak = null): array
    {
        $sekarang = CarbonImmutable::now();
        $bulanIni = $sekarang->startOfMonth();
        $awal = $sejak === null ? $bulanIni->subMonth() : $sejak->startOfMonth();
        $awal = $awal->greaterThan($bulanIni) ? $bulanIni : $awal;
        $bulan = [];

        for ($b = $awal; $b->lessThanOrEqualTo($bulanIni); $b = $b->addMonth()) {
            $bulan[] = $b;
        }

        $jumlah = 0;

        $this->konteks->JalankanLintasTenant('PGL-24: memperbarui bahan analitik platform', function (KonteksPengelola $k) use (&$jumlah, $sekarang, $bulan): void {
            Tenant::query()
                ->whereNull('Penanda')
                ->chunkById(self::UKURAN_KELOMPOK, function ($kelompok) use (&$jumlah, $k, $sekarang, $bulan): void {
                    $this->ProsesKelompok($k, array_map(fn (Tenant $t): int => $t->Id, array_values($kelompok->all())), $sekarang, $bulan);
                    $jumlah += $kelompok->count();
                });
        });

        return ['Tenant' => $jumlah, 'Bulan' => count($bulan)];
    }

    /**
     * @param  list<int>  $idTenant
     * @param  list<CarbonImmutable>  $bulan
     */
    private function ProsesKelompok(KonteksPengelola $k, array $idTenant, CarbonImmutable $sekarang, array $bulan): void
    {
        $sektor = [];

        foreach ($k->KueriLintas(Outlet::class)->whereIn('IdTenant', $idTenant)->orderBy('Id')->get(['IdTenant', 'TemplateSektor']) as $o) {
            if ($o->TemplateSektor !== null && ! isset($sektor[$o->IdTenant])) {
                $sektor[$o->IdTenant] = $o->TemplateSektor;
            }
        }

        $panduan = $this->TanggalPertama($k->KueriLintas(ProgresPanduanAwal::class)->whereIn('IdTenant', $idTenant)->whereNotNull('SelesaiPada'), 'SelesaiPada');
        $ada = [];

        foreach (AnalitikTenant::query()->whereIn('IdTenant', $idTenant)->get() as $a) {
            $ada[$a->IdTenant] = $a;
        }

        $belumTransaksi = array_values(array_filter($idTenant, fn (int $id): bool => ! isset($ada[$id]) || $ada[$id]->TransaksiPertamaPada === null));
        $transaksi = $belumTransaksi === [] ? [] : $this->TanggalPertama($k->KueriLintas(Penjualan::class)->whereIn('IdTenant', $belumTransaksi)->where('Status', '<>', StatusPenjualan::Void->value), 'DibuatOfflinePada');
        $belumBayar = array_values(array_filter($idTenant, fn (int $id): bool => ! isset($ada[$id]) || $ada[$id]->BayarPertamaPada === null));
        $bayar = $belumBayar === [] ? [] : $this->TanggalPertama($k->KueriDataPlatform(TagihanLangganan::class)->whereIn('IdTenant', $belumBayar)->whereNotNull('DibayarPada')->where('Total', '>', 0), 'DibayarPada');
        $fitur = $this->fitur->Kumpulkan($k, $idTenant, $sekarang);
        $menjual = array_flip(array_map('intval', $k->KueriLintas(Penjualan::class)
            ->whereIn('IdTenant', $idTenant)
            ->where('Status', '<>', StatusPenjualan::Void->value)
            ->where('TanggalBisnis', '>=', $sekarang->subDays(PengumpulFiturTenant::HARI_TERBARU)->toDateString())
            ->distinct()
            ->pluck('IdTenant')
            ->all()));

        foreach ($idTenant as $id) {
            $lama = $ada[$id] ?? null;
            AnalitikTenant::query()->updateOrCreate(['IdTenant' => $id], [
                'KodeSektor' => $sektor[$id] ?? null,
                // Tonggak yang sudah tercapai tidak berubah lagi.
                'PanduanSelesaiPada' => $lama->PanduanSelesaiPada ?? $panduan[$id] ?? null,
                'TransaksiPertamaPada' => $lama->TransaksiPertamaPada ?? $transaksi[$id] ?? null,
                'BayarPertamaPada' => $lama->BayarPertamaPada ?? $bayar[$id] ?? null,
                'FiturDipakai' => [...(isset($menjual[$id]) ? ['Penjualan'] : []), ...($fitur[$id] ?? [])],
            ]);
        }

        foreach ($bulan as $b) {
            $this->PerbaruiBulan($k, $idTenant, $b);
        }
    }

    /**
     * @param  list<int>  $idTenant
     */
    private function PerbaruiBulan(KonteksPengelola $k, array $idTenant, CarbonImmutable $bulan): void
    {
        $baris = $k->KueriLintas(Penjualan::class)
            ->whereIn('IdTenant', $idTenant)
            ->where('Status', '<>', StatusPenjualan::Void->value)
            ->whereBetween('TanggalBisnis', [$bulan->toDateString(), $bulan->endOfMonth()->toDateString()])
            ->groupBy('IdTenant')
            ->get(['IdTenant', DB::raw('COUNT(*) AS Jumlah'), DB::raw('COUNT(DISTINCT TanggalBisnis) AS Hari')]);

        foreach ($baris as $b) {
            AktivitasTenantBulanan::query()->updateOrCreate(
                ['IdTenant' => (int) $b->getAttribute('IdTenant'), 'Bulan' => $bulan->toDateString()],
                ['JumlahTransaksi' => (int) $b->getAttribute('Jumlah'), 'HariAktif' => (int) $b->getAttribute('Hari')],
            );
        }

        // Penjualan yang kemudian di-void bisa membuat bulan itu kosong: baris lama tidak boleh tertinggal.
        AktivitasTenantBulanan::query()
            ->where('Bulan', $bulan->toDateString())
            ->whereIn('IdTenant', $idTenant)
            ->whereNotIn('IdTenant', $baris->pluck('IdTenant')->all())
            ->delete();
    }

    /**
     * Tanggal paling awal per tenant dari kueri yang sudah dibatasi ke kelompok tenant.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $kueri
     * @return array<int, CarbonImmutable>
     */
    private function TanggalPertama($kueri, string $kolom): array
    {
        $hasil = [];

        // Ekspresi literal per kolom yang dikenal (bukan rakitan string) supaya tidak ada celah injeksi.
        $ekspresi = match ($kolom) {
            'SelesaiPada' => 'MIN(`SelesaiPada`) AS Pertama',
            'DibuatOfflinePada' => 'MIN(`DibuatOfflinePada`) AS Pertama',
            'DibayarPada' => 'MIN(`DibayarPada`) AS Pertama',
            default => throw new \InvalidArgumentException("Kolom tonggak tidak dikenal: {$kolom}"),
        };

        foreach ($kueri->groupBy('IdTenant')->get(['IdTenant', DB::raw($ekspresi)]) as $b) {
            $hasil[(int) $b->getAttribute('IdTenant')] = CarbonImmutable::parse((string) $b->getAttribute('Pertama'), 'UTC');
        }

        return $hasil;
    }
}
