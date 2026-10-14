<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Organisasi\Aksi\AturPesanSendiriOutlet;
use App\Domain\Organisasi\Aksi\BuatUlangTokenPesanSendiri;
use App\Domain\Organisasi\Aksi\SimpanAreaMeja;
use App\Domain\Organisasi\Aksi\SimpanDenahMeja;
use App\Domain\Organisasi\Aksi\SimpanMeja;
use App\Domain\Organisasi\Aksi\SimpanMejaMassal;
use App\Domain\Organisasi\Aksi\UbahStatusMeja;
use App\Domain\Organisasi\Enum\BentukMeja;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Kueri\MejaOutlet;
use App\Domain\Organisasi\Layanan\PembuatQrMeja;
use App\Domain\Organisasi\Layanan\PenyediaTokenPesanSendiri;
use App\Domain\Organisasi\Model\AreaMeja;
use App\Domain\Organisasi\Model\Meja;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Http\Permintaan\Kelola\AturPesanSendiriOutletPermintaan;
use App\Http\Permintaan\Kelola\SimpanAreaMejaPermintaan;
use App\Http\Permintaan\Kelola\SimpanDenahMejaPermintaan;
use App\Http\Permintaan\Kelola\SimpanMejaMassalPermintaan;
use App\Http\Permintaan\Kelola\SimpanMejaPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Area & meja per outlet (F-10a, mode meja). Dicari di dalam outlet pada URL; milik outlet lain → 404.
 * F-17: sakelar pesan sendiri outlet, QR pesan sendiri per meja (JSON untuk dialog), halaman cetak semua QR meja aktif,
 * dan buat ulang QR.
 */
final class MejaKontroler extends DasarKelolaKontroler
{
    public function SimpanArea(string $outlet, SimpanAreaMejaPermintaan $permintaan, SimpanAreaMeja $simpan): RedirectResponse
    {
        $area = $simpan->Jalankan($this->CariOutlet($outlet), null, $permintaan->AmbilData());

        return back()->with('Kilat', "Area {$area->Nama} ditambahkan.");
    }

    public function UbahArea(string $outlet, string $areaMeja, SimpanAreaMejaPermintaan $permintaan, SimpanAreaMeja $simpan): RedirectResponse
    {
        $barisOutlet = $this->CariOutlet($outlet);
        $area = $simpan->Jalankan($barisOutlet, $this->CariArea($barisOutlet, $areaMeja), $permintaan->AmbilData());

        return back()->with('Kilat', "Area {$area->Nama} disimpan.");
    }

    public function ArsipkanArea(string $outlet, string $areaMeja, UbahStatusMeja $ubah): RedirectResponse
    {
        $barisOutlet = $this->CariOutlet($outlet);
        $area = $ubah->JalankanArea($barisOutlet, $this->CariArea($barisOutlet, $areaMeja), StatusOrganisasi::Diarsipkan);

        return back()->with('Kilat', "Area {$area->Nama} diarsipkan.");
    }

    public function PulihkanArea(string $outlet, string $areaMeja, UbahStatusMeja $ubah): RedirectResponse
    {
        $barisOutlet = $this->CariOutlet($outlet);
        $area = $ubah->JalankanArea($barisOutlet, $this->CariArea($barisOutlet, $areaMeja), StatusOrganisasi::Aktif);

        return back()->with('Kilat', "Area {$area->Nama} aktif kembali.");
    }

    public function Simpan(string $outlet, SimpanMejaPermintaan $permintaan, SimpanMeja $simpan): RedirectResponse
    {
        $meja = $simpan->Jalankan($this->CariOutlet($outlet), null, $permintaan->AmbilData());

        return back()->with('Kilat', "Meja {$meja->Nama} ditambahkan.");
    }

    /** Buat banyak meja sekaligus, bernomor berurutan. */
    public function SimpanMassal(string $outlet, SimpanMejaMassalPermintaan $permintaan, SimpanMejaMassal $simpan): RedirectResponse
    {
        $dibuat = $simpan->Jalankan(
            $this->CariOutlet($outlet),
            $permintaan->string('Awalan')->toString(),
            $permintaan->integer('Mulai'),
            $permintaan->integer('Jumlah'),
            $permintaan->filled('Area') ? $permintaan->string('Area')->toString() : null,
            $permintaan->integer('Kapasitas'),
            BentukMeja::from($permintaan->string('Bentuk')->toString()),
        );

        $awal = collect($dibuat)->first()?->Nama;
        $akhir = collect($dibuat)->last()?->Nama;

        return back()->with('Kilat', count($dibuat).' meja ditambahkan: '.$awal.($awal === $akhir ? '' : ' sampai '.$akhir).'.');
    }

    public function Ubah(string $outlet, string $meja, SimpanMejaPermintaan $permintaan, SimpanMeja $simpan): RedirectResponse
    {
        $barisOutlet = $this->CariOutlet($outlet);
        $baris = $simpan->Jalankan($barisOutlet, $this->CariMeja($barisOutlet, $meja), $permintaan->AmbilData());

        return back()->with('Kilat', "Meja {$baris->Nama} disimpan.");
    }

    /** POS-03: simpan letak meja pada denah outlet (seret-lepas). */
    public function SimpanDenah(string $outlet, SimpanDenahMejaPermintaan $permintaan, SimpanDenahMeja $simpan): RedirectResponse
    {
        $berubah = $simpan->Jalankan($this->CariOutlet($outlet), $permintaan->AmbilPosisi());

        return back()->with('Kilat', $berubah === 0 ? 'Denah meja tidak berubah.' : 'Denah meja disimpan.');
    }

    public function Arsipkan(string $outlet, string $meja, UbahStatusMeja $ubah): RedirectResponse
    {
        $barisOutlet = $this->CariOutlet($outlet);
        $baris = $ubah->JalankanMeja($barisOutlet, $this->CariMeja($barisOutlet, $meja), StatusOrganisasi::Diarsipkan);

        return back()->with('Kilat', "Meja {$baris->Nama} diarsipkan.");
    }

    public function Pulihkan(string $outlet, string $meja, UbahStatusMeja $ubah): RedirectResponse
    {
        $barisOutlet = $this->CariOutlet($outlet);
        $baris = $ubah->JalankanMeja($barisOutlet, $this->CariMeja($barisOutlet, $meja), StatusOrganisasi::Aktif);

        return back()->with('Kilat', "Meja {$baris->Nama} aktif kembali.");
    }

    public function AturPesanSendiri(string $outlet, AturPesanSendiriOutletPermintaan $permintaan, AturPesanSendiriOutlet $atur): RedirectResponse
    {
        $baris = $atur->Jalankan($this->CariOutlet($outlet), $permintaan->boolean('Aktif'));

        return back()->with('Kilat', $baris->PesanSendiriAktif
            ? "Pesan sendiri lewat QR meja aktif di {$baris->Nama}."
            : "Pesan sendiri lewat QR meja dimatikan di {$baris->Nama}.");
    }

    public function Qr(string $outlet, string $meja, PenyediaTokenPesanSendiri $token, PembuatQrMeja $qr, ProfilTenant $profil): JsonResponse
    {
        $baris = $this->CariMeja($this->CariOutlet($outlet), $meja);
        $url = $this->BuatUrlPesanSendiri($profil, $token->Pastikan($baris));

        return response()->json(['NamaMeja' => $baris->Nama, 'Url' => $url, 'QrSvg' => $qr->BuatSvg($url)]);
    }

    public function BuatUlangQr(string $outlet, string $meja, BuatUlangTokenPesanSendiri $buatUlang): RedirectResponse
    {
        $baris = $buatUlang->Jalankan($this->CariMeja($this->CariOutlet($outlet), $meja));

        return back()->with('Kilat', "QR meja {$baris->Nama} dibuat ulang. Cetak dan ganti QR lama di meja; QR lama tidak bisa dipakai lagi.");
    }

    public function CetakQr(string $outlet, MejaOutlet $mejaOutlet, PenyediaTokenPesanSendiri $token, PembuatQrMeja $qr, ProfilTenant $profil, PemeriksaFiturTenant $fitur): Response
    {
        $barisOutlet = $this->CariOutlet($outlet);
        $aktif = array_values(array_filter($mejaOutlet->Ambil($barisOutlet->Id)['Meja'], fn (array $m): bool => $m['Status'] === StatusOrganisasi::Aktif->value));
        $model = Meja::query()->where('IdOutlet', $barisOutlet->Id)->whereIn('Uuid', array_column($aktif, 'Uuid'))->get()->keyBy('Uuid');

        return Inertia::render('Kelola/Outlet/QrMeja', [
            'Outlet' => ['Uuid' => $barisOutlet->Uuid, 'Kode' => $barisOutlet->Kode, 'Nama' => $barisOutlet->Nama],
            'NamaUsaha' => $profil->Ambil($this->IdTenant())['Nama'],
            'PesanSendiriAktif' => $barisOutlet->PesanSendiriAktif && $fitur->CekAktif($barisOutlet->IdTenant, PemeriksaFiturTenant::KUNCI_PESAN_SENDIRI),
            'Meja' => array_values(array_filter(array_map(function (array $m) use ($model, $token, $qr, $profil): ?array {
                $baris = $model->get($m['Uuid']);

                if (! $baris instanceof Meja) {
                    return null;
                }

                $url = $this->BuatUrlPesanSendiri($profil, $token->Pastikan($baris));

                return ['Uuid' => $m['Uuid'], 'Nama' => $m['Nama'], 'NamaArea' => $m['NamaArea'], 'Url' => $url, 'QrSvg' => $qr->BuatSvg($url)];
            }, $aktif))),
        ]);
    }

    private function BuatUrlPesanSendiri(ProfilTenant $profil, string $token): string
    {
        return url('/'.$profil->AmbilSlug($this->IdTenant()).'/meja/'.$token);
    }

    private function CariArea(Outlet $outlet, string $uuid): AreaMeja
    {
        return AreaMeja::query()->where('IdOutlet', $outlet->Id)->where('Uuid', $uuid)->firstOrFail();
    }

    private function CariMeja(Outlet $outlet, string $uuid): Meja
    {
        return Meja::query()->where('IdOutlet', $outlet->Id)->where('Uuid', $uuid)->firstOrFail();
    }
}
