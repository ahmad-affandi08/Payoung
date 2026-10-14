<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Katalog\Kueri\ProdukMinimumCharge;
use App\Domain\Katalog\Kueri\StasiunDapurProduk;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Kueri\DaftarStasiunDapur;
use App\Domain\Organisasi\Kueri\MejaOutlet;
use App\Domain\Organisasi\Layanan\PenjagaModeMeja;
use App\Domain\Organisasi\Model\Outlet;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/pos/v1/meja` (F-07 mode meja fase 1): data meja outlet perangkat untuk kerja offline: apakah mode meja
 * aktif, area & meja aktif, stasiun dapur aktif, dan stasiun bawaan. Cetak struk bagian 4c (aditif): `KategoriStasiun`
 * = kategori yang diatur langsung ke stasiun aktif, agar perangkat merutekan tiket dapur tercetak secara offline.
 */
final class MejaKontroler extends Kontroler
{
    public function Ambil(Request $permintaan, MejaOutlet $meja, DaftarStasiunDapur $stasiun, PenjagaModeMeja $modeMeja, StasiunDapurProduk $stasiunProduk): JsonResponse
    {
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $outlet = Outlet::query()->findOrFail($perangkat->IdOutlet);
        $data = $meja->Ambil($outlet->Id);
        $semuaStasiun = $stasiun->AmbilSemua();
        $aktif = array_values(array_filter($semuaStasiun, fn (array $s): bool => $s['Status'] === StatusOrganisasi::Aktif->value));
        usort($aktif, fn (array $a, array $b): int => [$a['Urutan'], $a['Nama']] <=> [$b['Urutan'], $b['Nama']]);

        return response()->json([
            'ModeMejaAktif' => $modeMeja->CekAktif($outlet),
            'Area' => array_values(array_map(
                fn (array $a): array => ['Uuid' => $a['Uuid'], 'Nama' => $a['Nama'], 'Urutan' => $a['Urutan']],
                array_filter($data['Area'], fn (array $a): bool => $a['Status'] === StatusOrganisasi::Aktif->value),
            )),
            'Meja' => array_values(array_map(
                fn (array $m): array => ['Uuid' => $m['Uuid'], 'Nama' => $m['Nama'], 'UuidArea' => $m['UuidArea'], 'Kapasitas' => $m['Kapasitas'], 'Bentuk' => $m['Bentuk'], 'Urutan' => $m['Urutan'], 'PosisiX' => $m['PosisiX'], 'PosisiY' => $m['PosisiY'], 'MinimumCharge' => $m['MinimumChargeEfektif']],
                array_filter($data['Meja'], fn (array $m): bool => $m['Status'] === StatusOrganisasi::Aktif->value),
            )),
            'StasiunDapur' => array_map(fn (array $s): array => ['Uuid' => $s['Uuid'], 'Nama' => $s['Nama']], $aktif),
            'UuidStasiunBawaan' => $aktif[0]['Uuid'] ?? null,
            // D-144: produk penampung selisih minimum charge (null bila belum ada meja/area ber-minimum).
            'UuidProdukMinimumCharge' => app(ProdukMinimumCharge::class)->Ambil()['Uuid'] ?? null,
            'KategoriStasiun' => $this->SusunKategoriStasiun($stasiun, $stasiunProduk),
        ]);
    }

    /**
     * @return list<array{UuidKategori: string, UuidStasiun: string}>
     */
    private function SusunKategoriStasiun(DaftarStasiunDapur $stasiun, StasiunDapurProduk $stasiunProduk): array
    {
        $uuidStasiun = array_flip($stasiun->AmbilIdAktifPerUuid());
        $hasil = [];

        foreach ($stasiunProduk->AmbilPerKategori() as $uuidKategori => $idStasiun) {
            if (isset($uuidStasiun[$idStasiun])) {
                $hasil[] = ['UuidKategori' => (string) $uuidKategori, 'UuidStasiun' => $uuidStasiun[$idStasiun]];
            }
        }

        return $hasil;
    }
}
