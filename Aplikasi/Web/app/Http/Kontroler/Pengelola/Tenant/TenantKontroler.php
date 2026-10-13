<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tenant;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pengelola\Katalog\Kueri\DaftarKatalog;
use App\Domain\Pengelola\Tenant\Aksi\BuatOverrideTenant;
use App\Domain\Pengelola\Tenant\Aksi\PerpanjangTrial;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Enum\KategoriPenangguhan;
use App\Domain\Pengelola\Tenant\Kueri\DaftarTenant;
use App\Domain\Pengelola\Tenant\Kueri\TampilanTenant;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daftar tenant & tampilan 360° dasar (P-07). Tindakan pada tenant ada di TindakanTenantKontroler.
 */
final class TenantKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(Request $permintaan, DaftarTenant $kueri): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarTenant::KOLOM_URUT, '-DibuatPada', DaftarTenant::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Pengelola/Tenant/Daftar', 'Tenant', fn (): array => $kueri->AmbilTabel($tabel), fn (): array => [
            'PilihanStatus' => array_map(fn (StatusLangganan $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], StatusLangganan::cases()),
            'PilihanKesehatan' => array_map(fn (KategoriKesehatan $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], KategoriKesehatan::cases()),
            'PilihanPenanda' => [
                ['Nilai' => DaftarTenant::SARING_TANPA_PENANDA, 'Label' => 'Tanpa penanda'],
                ...array_map(fn (PenandaTenant $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], PenandaTenant::cases()),
            ],
        ]);
    }

    public function Tampilkan(Tenant $tenant, TampilanTenant $kueri, DaftarKatalog $katalog): Response
    {
        $pelaku = $this->AmbilPelaku();

        return Inertia::render('Pengelola/Tenant/Tampil', [
            // Tab tagihan & tiket hanya untuk peran yang boleh melihatnya (§19.3); null = tab disembunyikan.
            'Tenant' => $kueri->Ambil($tenant, $pelaku->PunyaIzin(IzinPengelola::DukunganTiketLihat), $pelaku->PunyaIzin(IzinPengelola::TagihanLihat)),
            'Pilihan' => [
                'KategoriPenangguhan' => array_map(
                    fn (KategoriPenangguhan $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()],
                    KategoriPenangguhan::cases(),
                ),
                'Penanda' => array_map(fn (PenandaTenant $item) => ['Nilai' => $item->value, 'Label' => $item->AmbilLabel()], PenandaTenant::cases()),
                'KolomBatas' => Paket::KOLOM_BATAS,
                'Fitur' => array_map(fn (array $fitur) => ['Nilai' => $fitur['Kunci'], 'Label' => "{$fitur['Nama']} ({$fitur['Kunci']})"], $katalog->AmbilFitur()),
                // D-112: paket harga negosiasi yang boleh ditagih manual oleh Keuangan.
                'PaketNegosiasi' => array_map(
                    fn (Paket $paket) => ['Nilai' => $paket->Kode, 'Label' => $paket->Nama],
                    array_values(Paket::query()->where('HargaNegosiasi', true)->orderBy('Urutan')->get()->all()),
                ),
            ],
            'Aturan' => [
                'MaksHariTrial' => PerpanjangTrial::MAKS_HARI,
                'MaksKaliTrial' => PerpanjangTrial::MAKS_KALI,
                'MaksHariOverride' => BuatOverrideTenant::MAKS_HARI,
            ],
        ]);
    }
}
