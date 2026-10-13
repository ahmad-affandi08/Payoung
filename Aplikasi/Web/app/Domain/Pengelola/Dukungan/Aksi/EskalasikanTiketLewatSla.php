<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Enum\TingkatEskalasiTiket;
use App\Domain\Dukungan\Layanan\PenghitungSlaTiket;
use App\Domain\Dukungan\Layanan\PenulisPesanTiket;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Pengelola\Dukungan\Layanan\PemberiTahuEskalasiTiket;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Eskalasi otomatis karena SLA (P-09, terjadwal tiap 10 menit): tiket terbuka tanpa respons pertama yang melewati batas
 * SLA naik L1 → L2; bila masih tanpa respons setelah `dukungan.JamEskalasiKeTeknis` jam lagi (jam kerja untuk tiket
 * jam kerja, jam kalender untuk tiket kalender) naik L2 → Teknis. Setiap tiket naik paling tinggi satu tingkat per
 * putaran. Putaran tanpa tiket yang perlu dieskalasi tidak membuka akses lintas tenant (tanpa baris audit).
 */
final class EskalasikanTiketLewatSla
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PenulisPesanTiket $penulisPesan,
        private readonly PenghitungSlaTiket $penghitungSla,
        private readonly PemberiTahuEskalasiTiket $pemberiTahu,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @return array{L2: int, Teknis: int}
     */
    public function Jalankan(): array
    {
        $hasil = ['L2' => 0, 'Teknis' => 0];
        $terbuka = array_map(fn (StatusTiketDukungan $s): string => $s->value, StatusTiketDukungan::AmbilTerbuka());

        $tenant = $this->konteks->IdTenantDenganPekerjaan(TiketDukungan::class, fn (Builder $kueri) => $kueri
            ->whereIn('Status', $terbuka)
            ->whereNull('ResponsPertamaPada')
            ->where('BatasSlaPada', '<', now())
            ->where('TingkatEskalasi', '!=', TingkatEskalasiTiket::Teknis->value));

        if ($tenant === []) {
            return $hasil;
        }

        return $this->konteks->JalankanLintasTenant('Eskalasi otomatis tiket dukungan yang melewati SLA', function () use ($terbuka, $tenant): array {
            $hasil = ['L2' => 0, 'Teknis' => 0];
            $kandidat = $this->konteks->KueriLintas(TiketDukungan::class)
                ->whereIn('IdTenant', $tenant)
                ->whereIn('Status', $terbuka)
                ->whereNull('ResponsPertamaPada')
                ->where('BatasSlaPada', '<', now())
                ->where('TingkatEskalasi', '!=', TingkatEskalasiTiket::Teknis->value)
                ->orderBy('Id')
                ->get(['Id']);

            foreach ($kandidat as $baris) {
                $naik = DB::transaction(function () use ($baris): ?array {
                    $tiket = $this->konteks->KueriLintas(TiketDukungan::class)->lockForUpdate()->whereKey($baris->Id)->first();

                    if ($tiket === null || ! $tiket->Status->CekTerbuka() || $tiket->ResponsPertamaPada !== null) {
                        return null;
                    }

                    $tujuan = $this->TentukanTujuan($tiket);

                    if ($tujuan === null) {
                        return null;
                    }

                    $asal = $tiket->TingkatEskalasi;
                    $alasan = $tujuan === TingkatEskalasiTiket::L2
                        ? 'Batas SLA respons pertama terlewati.'
                        : 'Belum ada respons '.config('dukungan.JamEskalasiKeTeknis').' jam setelah batas SLA terlewati.';
                    $tiket->TingkatEskalasi = $tujuan;
                    $tiket->DieskalasiPada = now();
                    $tiket->save();
                    $this->penulisPesan->TulisSistem($tiket, "Eskalasi otomatis dari {$asal->AmbilLabel()} ke {$tujuan->AmbilLabel()}: {$alasan}", catatanInternal: true);
                    $this->audit->Catat(
                        'dukungan.tiket.eskalasi-otomatis',
                        $tiket,
                        nilaiLama: ['TingkatEskalasi' => $asal->value],
                        nilaiBaru: ['TingkatEskalasi' => $tujuan->value],
                        alasan: $alasan,
                        idTenant: $tiket->IdTenant,
                    );

                    return ['Tiket' => $tiket, 'Tujuan' => $tujuan, 'Alasan' => $alasan];
                });

                if ($naik === null) {
                    continue;
                }

                $this->pemberiTahu->Beritahu($naik['Tiket'], $naik['Tujuan'], $naik['Alasan']);
                $hasil[$naik['Tujuan'] === TingkatEskalasiTiket::L2 ? 'L2' : 'Teknis']++;
            }

            return $hasil;
        });
    }

    /** Tingkat tujuan satu langkah di atas tingkat sekarang, atau null bila belum waktunya. */
    private function TentukanTujuan(TiketDukungan $tiket): ?TingkatEskalasiTiket
    {
        if ($tiket->BatasSlaPada->gte(now())) {
            return null;
        }

        if ($tiket->TingkatEskalasi === TingkatEskalasiTiket::L1) {
            return TingkatEskalasiTiket::L2;
        }

        $jam = (int) config('dukungan.JamEskalasiKeTeknis');
        $batasTeknis = $this->penghitungSla->HitungBatas($tiket->BatasSlaPada, $jam, $tiket->SlaJamKalender);

        return $batasTeknis->lt(now()) ? TingkatEskalasiTiket::Teknis : null;
    }
}
