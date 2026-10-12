<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Katalog\Kueri\PemakaianSku;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Kueri\PemakaianPerangkat;
use App\Domain\Tenant\Data\SumberFitur;
use App\Domain\Tenant\Kueri\SumberFiturTenant;
use App\Domain\Tenant\Model\Paket;

/**
 * Membandingkan pemakaian tenant dengan batas paket tujuan sebelum turun paket (BR-P04.4, audit E5). Batas tujuan =
 * batas paket tujuan + tambahan add-on yang masih berjalan, ditimpa override pengelola, sama seperti batas efektif
 * hari ini. Murni baca: hasilnya dipakai pratinjau di halaman Langganan dan penerapan saat paket berganti.
 *
 * @phpstan-type Kelebihan array{Batas: array{Outlet: int|null, Pengguna: int|null, Sku: int|null, PerangkatPerOutlet: int|null}, Pakai: array{Outlet: int, Pengguna: int, Sku: int, PerangkatMaksimal: int}, Lebih: array{Outlet: int, Pengguna: int, Sku: int, Perangkat: int}, Ada: bool}
 */
final class PenilaiKapasitasPaket
{
    public function __construct(
        private readonly SumberFiturTenant $sumberFitur,
        private readonly EvaluatorFitur $evaluator,
        private readonly PemakaianBatasOrganisasi $pemakaianOrganisasi,
        private readonly PemakaianPerangkat $pemakaianPerangkat,
        private readonly PemakaianSku $pemakaianSku,
    ) {}

    /**
     * @param  list<int>  $idOutletAktif  untuk menghitung perangkat terbanyak per outlet
     * @return Kelebihan
     */
    public function Nilai(int $idTenant, Paket $tujuan, array $idOutletAktif): array
    {
        $sumber = $this->sumberFitur->Ambil($idTenant);
        $batas = $this->evaluator->HitungBatasEfektif(new SumberFitur(
            fiturPaket: $sumber->fiturPaket,
            batasPaket: $tujuan->AmbilBatas(),
            addon: $sumber->addon,
            overrideFitur: $sumber->overrideFitur,
            overrideBatas: $sumber->overrideBatas,
            flagFitur: $sumber->flagFitur,
        ));
        $pakaiOutlet = $this->pemakaianOrganisasi->HitungOutlet();
        $pakaiPengguna = $this->pemakaianOrganisasi->HitungPengguna($idTenant);
        $pakaiSku = $this->pemakaianSku->Hitung();
        $perangkatMaks = 0;

        foreach ($idOutletAktif as $idOutlet) {
            $perangkatMaks = max($perangkatMaks, $this->pemakaianPerangkat->HitungAktifDiOutlet($idOutlet));
        }

        $lebih = fn (?int $batasKolom, int $pakai): int => $batasKolom === null ? 0 : max(0, $pakai - $batasKolom);
        $hasil = [
            'Batas' => [
                'Outlet' => $batas['BatasOutlet'] ?? null,
                'Pengguna' => $batas['BatasPengguna'] ?? null,
                'Sku' => $batas['BatasSku'] ?? null,
                'PerangkatPerOutlet' => $batas['BatasPerangkatPerOutlet'] ?? null,
            ],
            'Pakai' => ['Outlet' => $pakaiOutlet, 'Pengguna' => $pakaiPengguna, 'Sku' => $pakaiSku, 'PerangkatMaksimal' => $perangkatMaks],
            'Lebih' => [
                'Outlet' => $lebih($batas['BatasOutlet'] ?? null, $pakaiOutlet),
                'Pengguna' => $lebih($batas['BatasPengguna'] ?? null, $pakaiPengguna),
                'Sku' => $lebih($batas['BatasSku'] ?? null, $pakaiSku),
                'Perangkat' => $lebih($batas['BatasPerangkatPerOutlet'] ?? null, $perangkatMaks),
            ],
        ];
        $hasil['Ada'] = array_sum($hasil['Lebih']) > 0;

        return $hasil;
    }
}
