<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Kasir;

use App\Domain\Organisasi\Kueri\DaftarOutletStruk;
use App\Domain\Organisasi\Kueri\OutletUtama;
use App\Domain\Tenant\Aksi\UbahPengaturanStruk;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Layanan\PenyimpanLogoTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Kasir\UbahPengaturanStrukPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman pengaturan struk (PLT-06, PRD v1.79), izin `outlet.kelola`: teks isian satu untuk semua outlet, saklar tampil & logo per outlet (D-76), dengan
 * pratinjau struk thermal. Data profil (nama usaha, NPWP, logo) hanya untuk pratinjau; diubah di profil usaha.
 */
final class PengaturanStrukKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(Request $permintaan, PengaturanStrukTenant $pengaturan, ProfilTenant $profilTenant, PemeriksaFiturTenant $fitur, OutletUtama $outletUtama, DaftarOutletStruk $daftarOutlet): Response
    {
        $profil = $profilTenant->Ambil($this->IdTenant());
        $semuaOutlet = $daftarOutlet->Ambil();
        // D-76: pilihan outlet hanya muncul bila tenant punya lebih dari satu outlet aktif.
        $outletTerpilih = count($semuaOutlet) > 1 ? self::CariOutletStruk($semuaOutlet, $permintaan->query('Outlet')) ?? $semuaOutlet[0] : null;
        // Pratinjau memakai outlet yang sedang diatur (D-76), bukan selalu outlet utama; alamat & telepon asli (D-177).
        $outletProfil = $outletUtama->CariAktifRingkas($outletTerpilih['Id'] ?? $outletUtama->AmbilId());
        $pathLogoOutlet = $outletTerpilih === null ? null : $pengaturan->AmbilPathLogoOutlet($this->IdTenant(), $outletTerpilih['Id']);
        $pathLogo = $pathLogoOutlet ?? $profil['PathLogo'];

        return Inertia::render('Kelola/Kasir/Struk', [
            'Pengaturan' => $pengaturan->Ambil(null, $outletTerpilih['Id'] ?? null)->KeLarik(),
            'Outlet' => $outletTerpilih === null ? [] : array_map(static fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $semuaOutlet),
            'UuidOutlet' => $outletTerpilih['Uuid'] ?? null,
            'LogoOutletKhusus' => $pathLogoOutlet !== null,
            'Profil' => [
                'NamaUsaha' => $profil['Nama'],
                'Npwp' => $profil['Npwp'],
                'NamaOutlet' => $outletProfil?->nama,
                'AlamatOutlet' => $outletProfil?->alamat,
                'TeleponOutlet' => $outletProfil?->telepon,
                'UuidOutletProfil' => $outletProfil?->uuid,
                'TautanLogo' => $pathLogo === null ? null : route('kelola.kasir.struk.logo', ['v' => substr(hash('sha256', $pathLogo), 0, 12), 'Outlet' => $outletTerpilih['Uuid'] ?? null]),
                'TandaAir' => ! $fitur->CekAktif($this->IdTenant(), 'struk.tanpa-watermark'),
            ],
        ]);
    }

    /** Logo yang dipakai outlet terpilih (logo khusus outlet, selain itu logo usaha), untuk pratinjau. */
    public function UnduhLogo(Request $permintaan, PengaturanStrukTenant $pengaturan, ProfilTenant $profilTenant, DaftarOutletStruk $daftarOutlet, PenyimpanLogoTenant $penyimpan): StreamedResponse
    {
        $outletTerpilih = self::CariOutletStruk($daftarOutlet->Ambil(), $permintaan->query('Outlet'));
        $path = ($outletTerpilih === null ? null : $pengaturan->AmbilPathLogoOutlet($this->IdTenant(), $outletTerpilih['Id'])) ?? $profilTenant->Ambil($this->IdTenant())['PathLogo'];
        abort_if($path === null, 404);

        return $penyimpan->Unduh($path);
    }

    public function Simpan(UbahPengaturanStrukPermintaan $permintaan, UbahPengaturanStruk $ubah, DaftarOutletStruk $daftarOutlet): RedirectResponse
    {
        $outletTerpilih = self::CariOutletStruk($daftarOutlet->Ambil(), $permintaan->input('UuidOutlet'));
        $ubah->Jalankan($permintaan->AmbilData(), $outletTerpilih['Id'] ?? null, $permintaan->file('Logo'), $permintaan->boolean('HapusLogo'));

        return to_route('kelola.kasir.struk', $outletTerpilih === null ? [] : ['Outlet' => $outletTerpilih['Uuid']])->with('Kilat', 'Pengaturan struk disimpan. Kasir memakainya setelah data diperbarui.');
    }

    /**
     * @param  list<array{Id: int, Uuid: string, Nama: string}>  $semua
     * @return array{Id: int, Uuid: string, Nama: string}|null
     */
    private static function CariOutletStruk(array $semua, mixed $uuid): ?array
    {
        foreach (is_string($uuid) ? $semua : [] as $outletTerpilih) {
            if ($outletTerpilih['Uuid'] === $uuid) {
                return $outletTerpilih;
            }
        }

        return null;
    }
}
