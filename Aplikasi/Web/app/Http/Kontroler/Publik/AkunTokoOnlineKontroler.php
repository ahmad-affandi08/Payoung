<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Aksi\DaftarkanPelangganOnline;
use App\Domain\Pelanggan\Aksi\MintaKodeMasukPelanggan;
use App\Domain\Pelanggan\Aksi\PerbaruiProfilPelangganOnline;
use App\Domain\Pelanggan\Aksi\VerifikasiKodeMasukPelanggan;
use App\Domain\Pelanggan\Kueri\ProfilPembeliOnline;
use App\Domain\Pelanggan\Layanan\SesiPembeliOnline;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Kueri\RiwayatBelanjaPembeliOnline;
use App\Domain\Penjualan\Layanan\PenentuAkunTokoOnline;
use App\Domain\Penjualan\Layanan\PenentuKonteksTokoOnline;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Permintaan\Publik\AkunTokoOnlinePermintaan;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * F-17 toko online bagian 3: akun pembeli **opsional** — masuk dengan kode WhatsApp tanpa kata sandi, identitasnya
 * `Pelanggan` toko yang sama dengan pelanggan kasir. Sesi disimpan di cookie HttpOnly ber-path toko itu
 * (`/{slugTenant}`), SameSite=Lax; endpoint yang mengubah data dijaga CSRF. Checkout tamu tidak berubah.
 */
final class AkunTokoOnlineKontroler extends Kontroler
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
        private readonly PenentuKonteksTokoOnline $penentu,
        private readonly PenentuAkunTokoOnline $akun,
        private readonly SesiPembeliOnline $sesi,
        private readonly ProfilPembeliOnline $profilPembeli,
        private readonly RiwayatBelanjaPembeliOnline $riwayat,
    ) {}

    /** Halaman "Akun saya": formulir masuk bila belum masuk, profil + poin + riwayat belanja bila sudah. */
    public function Tampilkan(string $slugTenant, Request $request): SymfonyResponse
    {
        $props = $this->DalamTenant($slugTenant, function (int $idTenant) use ($slugTenant, $request): ?array {
            if ($this->penentu->Cari() === null) {
                return null;
            }

            $pelanggan = $this->sesi->CariPelanggan($request->cookie(SesiPembeliOnline::NAMA_COOKIE));

            return [
                'Slug' => $slugTenant,
                'Toko' => [
                    'Nama' => app(NamaTampilUsaha::class)->UntukTenant($idTenant),
                    // D-241: logo yang sama dengan toko online; null = kepala memakai inisial toko.
                    'UrlLogo' => $this->UrlLogo($idTenant, $slugTenant),
                ],
                'AkunAktif' => $this->akun->CekAktif(),
                'Pelanggan' => $pelanggan === null ? null : $this->profilPembeli->Ambil($pelanggan),
                'Riwayat' => $pelanggan === null ? null : $this->riwayat->Ambil($idTenant, $pelanggan->Id, $slugTenant),
            ];
        }, fn (): null => null);

        return Inertia::render('Publik/AkunTokoOnline', $props ?? ['Slug' => $slugTenant, 'Toko' => null, 'AkunAktif' => false, 'Pelanggan' => null, 'Riwayat' => null])
            ->toResponse($request)->setStatusCode($props === null ? 404 : 200);
    }

    public function MintaKode(string $slugTenant, AkunTokoOnlinePermintaan $permintaan, MintaKodeMasukPelanggan $minta): JsonResponse
    {
        $hashIp = hash_hmac('sha256', (string) $permintaan->ip(), (string) config('app.key'));

        return $this->DalamTenant($slugTenant, function (int $idTenant) use ($permintaan, $minta, $hashIp): JsonResponse {
            $this->WajibAkunAktif();
            $hasil = $minta->Jalankan((string) $permintaan->validated('NoHp'), $hashIp, app(NamaTampilUsaha::class)->UntukTenant($idTenant));

            return response()->json([
                'KedaluwarsaPada' => $hasil['KedaluwarsaPada']->toIso8601String(),
                'KirimUlangPada' => $hasil['KirimUlangPada']->toIso8601String(),
            ], 202);
        });
    }

    public function Masuk(string $slugTenant, AkunTokoOnlinePermintaan $permintaan, VerifikasiKodeMasukPelanggan $verifikasi): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($slugTenant, $permintaan, $verifikasi): JsonResponse {
            $this->WajibAkunAktif();
            $hasil = $verifikasi->Jalankan((string) $permintaan->validated('NoHp'), trim((string) $permintaan->validated('Kode')));

            if ($hasil['Pelanggan'] === null) {
                return response()->json(['PerluDaftar' => true, 'TokenDaftar' => $hasil['TokenDaftar']]);
            }

            return $this->ResponsMasuk($slugTenant, $hasil['Pelanggan']);
        });
    }

    public function Daftar(string $slugTenant, AkunTokoOnlinePermintaan $permintaan, DaftarkanPelangganOnline $daftarkan): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($slugTenant, $permintaan, $daftarkan): JsonResponse {
            $this->WajibAkunAktif();
            $pelanggan = $daftarkan->Jalankan(
                (string) $permintaan->validated('TokenDaftar'),
                (string) $permintaan->validated('NoHp'),
                (string) $permintaan->validated('Nama'),
                $permintaan->AmbilEmail(),
                $permintaan->AmbilTanggalLahir(),
                (bool) $permintaan->validated('SetujuPemasaran', false),
            );

            return $this->ResponsMasuk($slugTenant, $pelanggan, 201);
        });
    }

    public function PerbaruiProfil(string $slugTenant, AkunTokoOnlinePermintaan $permintaan, PerbaruiProfilPelangganOnline $perbarui): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($permintaan, $perbarui): JsonResponse {
            $pelanggan = $this->WajibMasuk($permintaan);
            $baru = $perbarui->Jalankan(
                $pelanggan,
                (string) $permintaan->validated('Nama'),
                $permintaan->AmbilEmail(),
                $permintaan->AmbilTanggalLahir(),
                (bool) $permintaan->validated('SetujuPemasaran', false),
            );

            return response()->json(['Pelanggan' => $this->profilPembeli->Ambil($baru)]);
        });
    }

    /** Keluar dari perangkat ini; `Semua=true` mencabut semua sesi pelanggan ini (perangkat hilang/dipinjam). */
    public function Keluar(string $slugTenant, Request $request): JsonResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($slugTenant, $request): JsonResponse {
            $token = $request->cookie(SesiPembeliOnline::NAMA_COOKIE);

            if ($request->boolean('Semua')) {
                $pelanggan = $this->sesi->CariPelanggan($token);

                if ($pelanggan !== null) {
                    $this->sesi->TutupSemuaSesi($pelanggan->Id);
                }
            } else {
                $this->sesi->TutupSesi($token);
            }

            return response()->json(['Keluar' => true])->withCookie(self::BuatCookie($slugTenant, '', -1));
        });
    }

    private function ResponsMasuk(string $slugTenant, Pelanggan $pelanggan, int $status = 200): JsonResponse
    {
        $token = $this->sesi->BukaSesi($pelanggan);

        return response()->json([
            'PerluDaftar' => false,
            'Pelanggan' => $this->profilPembeli->Ambil($pelanggan),
            'AlamatTerakhir' => $this->riwayat->AmbilAlamatTerakhir($pelanggan->Id),
        ], $status)->withCookie(self::BuatCookie($slugTenant, $token, SesiPembeliOnline::HARI_BERLAKU * 24 * 60));
    }

    /** Cookie sesi pembeli: HttpOnly, SameSite=Lax, path toko ini saja (toko lain di domain yang sama tidak ikut). */
    public static function BuatCookie(string $slugTenant, string $nilai, int $menit): Cookie
    {
        return cookie(SesiPembeliOnline::NAMA_COOKIE, $nilai, $menit, "/{$slugTenant}", null, (bool) config('session.secure'), true, false, 'lax');
    }

    private function WajibAkunAktif(): void
    {
        $this->penentu->WajibAktif();

        if (! $this->akun->CekAktif()) {
            throw new PelanggaranAturanBisnis('MasukBelumTersedia', 'Masuk dengan WhatsApp sedang tidak tersedia. Anda tetap bisa memesan tanpa masuk.', 'Umum', 409);
        }
    }

    private function WajibMasuk(Request $request): Pelanggan
    {
        return $this->sesi->CariPelanggan($request->cookie(SesiPembeliOnline::NAMA_COOKIE))
            ?? throw new PelanggaranAturanBisnis('BelumMasuk', 'Sesi Anda sudah berakhir. Silakan masuk lagi.', 'Umum', 401);
    }

    /** Logo tenant lewat rute publik `/{slug}/logo` (D-238), outlet pertama yang punya logo; null = kepala memakai inisial. */
    private function UrlLogo(int $idTenant, string $slugTenant): ?string
    {
        $outlet = Outlet::query()->orderBy('Id')->first(['Id', 'Uuid']);

        if ($outlet === null || app(PengaturanStrukTenant::class)->AmbilPathLogoUsaha($idTenant, $outlet->Id) === null) {
            return null;
        }

        return url("/{$slugTenant}/logo").'?outlet='.$outlet->Uuid;
    }

    private function DalamTenant(string $slugTenant, Closure $kerja, ?Closure $tidakDikenal = null): mixed
    {
        $idTenant = $this->profil->CariIdDariSlug($slugTenant);
        if ($idTenant === null) {
            return $tidakDikenal !== null ? $tidakDikenal() : throw new PelanggaranAturanBisnis('TokoTidakDitemukan', 'Toko tidak ditemukan.', 'Umum', 404);
        }
        $this->konteks->Atur($idTenant);
        try {
            return $kerja($idTenant);
        } finally {
            $this->konteks->Kosongkan();
        }
    }
}
