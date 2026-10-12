<?php

declare(strict_types=1);

namespace Tests\Pendukung\Dukungan;

use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

/**
 * Bantuan test akses dukungan (PGL-16, P-09): Owner memberi izin, petugas konsol memulai sesi, dan "peramban" petugas yang
 * sesinya bisa disimpan lalu dipulihkan, supaya satu test bisa berganti peran Owner ↔ petugas.
 */
final class BantuanAksesDukungan
{
    /** @return array{Tenant: Tenant, Pemilik: Pengguna} */
    public static function BuatTenant(string $nama = 'Kopi Nusantara'): array
    {
        return BantuanOrganisasi::BuatTenant($nama);
    }

    /**
     * @param  array{Tenant: Tenant, Pemilik: Pengguna}  $tenant
     * @param  array<string, mixed>  $isian
     * @return TestResponse<Response>
     */
    public static function OwnerBeriIzin(TestCase $tes, array $tenant, array $isian = []): TestResponse
    {
        self::LupakanLogin($tes);

        return BantuanOrganisasi::Masuk($tes, $tenant['Pemilik'], $tenant['Tenant']->Id)
            ->post('/kelola/pengaturan/akses-dukungan', [...[
                'DurasiJam' => 24,
                'Cakupan' => 'BacaSaja',
                'DataPribadiTerbuka' => false,
            ], ...$isian]);
    }

    /**
     * @param  array{Tenant: Tenant, Pemilik: Pengguna}  $tenant
     * @return TestResponse<Response>
     */
    public static function OwnerCabut(TestCase $tes, array $tenant, string $uuidAkses): TestResponse
    {
        self::LupakanLogin($tes);

        return BantuanOrganisasi::Masuk($tes, $tenant['Pemilik'], $tenant['Tenant']->Id)
            ->post("/kelola/pengaturan/akses-dukungan/{$uuidAkses}/cabut");
    }

    public static function BuatPetugas(PeranPengelolaBawaan $peran = PeranPengelolaBawaan::Dukungan): PenggunaPengelola
    {
        return BantuanPengelola::BuatAnggota($peran);
    }

    /**
     * Petugas konsol menekan "Masuk sebagai tenant": jawabannya alamat sekali pakai di domain tenant.
     *
     * @return TestResponse<Response>
     */
    public static function PetugasMinta(TestCase $tes, PenggunaPengelola $petugas, Tenant $tenant): TestResponse
    {
        self::LupakanLogin($tes);
        $tes->actingAs($petugas, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

        return $tes->postJson(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/akses-dukungan/masuk"));
    }

    /**
     * Petugas membuka alamat sekali pakai di peramban baru; mengembalikan isi sesi peramban itu (untuk dipulihkan nanti).
     *
     * @return array<string, mixed>
     */
    public static function BukaAlamat(TestCase $tes, string $url): array
    {
        self::LupakanLogin($tes);
        $tes->get((string) parse_url($url, PHP_URL_PATH));

        return self::SimpanPeramban();
    }

    /**
     * Petugas masuk penuh: minta alamat lalu buka.
     *
     * @return array<string, mixed>
     */
    public static function PetugasMasuk(TestCase $tes, PenggunaPengelola $petugas, Tenant $tenant): array
    {
        $respons = self::PetugasMinta($tes, $petugas, $tenant);
        $respons->assertOk();

        return self::BukaAlamat($tes, (string) $respons->json('Url'));
    }

    /** @return array<string, mixed> */
    public static function SimpanPeramban(): array
    {
        return app('session.store')->all();
    }

    /** @param  array<string, mixed>  $sesi */
    public static function PulihkanPeramban(TestCase $tes, array $sesi): TestCase
    {
        self::LupakanLogin($tes);

        return $tes->withSession($sesi);
    }

    /**
     * Peramban baru: sesi dan login dibuang, dan host kembali ke domain tenant (klien uji mengingat host permintaan
     * terakhir, misalnya `pengelola.` setelah petugas memakai konsol).
     */
    public static function LupakanLogin(TestCase $tes): void
    {
        $tes->flushSession();
        app('auth')->forgetGuards();
        app('url')->forceRootUrl(rtrim((string) config('app.url'), '/'));
    }

    public static function IzinAktif(int $idTenant): ?AksesDukungan
    {
        BantuanOrganisasi::AturKonteks($idTenant);

        return AksesDukungan::query()->orderByDesc('Id')->first();
    }
}
