<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Layanan\EvaluatorFitur;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\Paket;
use Carbon\CarbonImmutable;

/**
 * D-23: fitur yang dimiliki tenant dan penawaran untuk fitur yang terkunci (menu tetap tampil; klik = dialog naik paket
 * atau add-on seperti Majoo). Untuk tiap fitur katalog yang tidak aktif: paket aktif termurah (urutan terendah) yang
 * memuatnya beserta harga bulanan berlakunya, dan add-on aktif yang membukanya. Paket berharga negosiasi (Enterprise)
 * ditawarkan tanpa harga.
 */
final class PenawaranFiturTenant
{
    public function __construct(
        private readonly SumberFiturTenant $sumber,
        private readonly EvaluatorFitur $evaluator,
        private readonly HargaPaketBerlaku $harga,
        private readonly AddonLanggananTenant $addonTenant,
    ) {}

    /**
     * @return array{
     *     NamaPaket: string|null,
     *     Terkunci: array<string, array{Nama: string, Paket: array{Kode: string, Nama: string, HargaBulanan: string|null}|null, Addon: array{Kode: string, Nama: string, HargaBulanan: string, BisaDibeli: bool, AlasanTidakBisa: string|null, HargaProrata: string|null}|null}>
     * }
     */
    public function Ambil(int $idTenant): array
    {
        $sumber = $this->sumber->Ambil($idTenant);
        $terkunci = [];
        $paket = null;
        $addon = null;
        $addonTenant = $this->addonTenant->Ambil($idTenant);
        $prorata = array_column($addonTenant['Tersedia'], 'HargaProrata', 'Kode');
        // Banyak fitur terkunci jatuh ke paket yang sama: harga tiap paket dicari sekali.
        $hargaPaket = [];

        foreach (Fitur::query()->orderBy('Kunci')->get(['Kunci', 'Nama']) as $fitur) {
            if ($this->evaluator->CekFiturAktif($sumber, $fitur->Kunci)) {
                continue;
            }

            $paket ??= Paket::query()->with('Fitur')->where('Status', StatusPaket::Aktif->value)->orderBy('Urutan')->get();
            $addon ??= Addon::query()->where('Status', StatusPaket::Aktif->value)->whereNotNull('KunciFitur')->orderBy('HargaBulanan')->get();
            $calon = $paket->first(fn (Paket $p): bool => in_array($fitur->Kunci, $p->AmbilKunciFitur(), true));
            $tambahan = $addon->first(fn (Addon $a): bool => $a->KunciFitur === $fitur->Kunci);

            $terkunci[$fitur->Kunci] = [
                'Nama' => $fitur->Nama,
                'Paket' => $calon === null ? null : [
                    'Kode' => $calon->Kode,
                    'Nama' => $calon->Nama,
                    'HargaBulanan' => $calon->HargaNegosiasi ? null : ($hargaPaket[$calon->Id] ??= [$this->harga->Cari($calon->Id, CarbonImmutable::now())?->HargaBulanan])[0],
                ],
                'Addon' => $tambahan === null ? null : [
                    'Kode' => $tambahan->Kode,
                    'Nama' => $tambahan->Nama,
                    'HargaBulanan' => (string) $tambahan->HargaBulanan,
                    // D-49: add-on bisa dibeli mandiri bila langganan berbayar aktif; selain itu alasan ditampilkan.
                    'BisaDibeli' => $addonTenant['Alasan'] === null,
                    'AlasanTidakBisa' => $addonTenant['Alasan'],
                    'HargaProrata' => $prorata[$tambahan->Kode] ?? null,
                ],
            ];
        }

        return ['NamaPaket' => $this->sumber->AmbilNamaPaket($idTenant), 'Terkunci' => $terkunci];
    }
}
