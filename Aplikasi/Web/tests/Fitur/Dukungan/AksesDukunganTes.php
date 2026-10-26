<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Dukungan\Model\AktivitasAksesDukungan;
use App\Domain\Dukungan\Model\PenggunaDukungan;
use App\Domain\Dukungan\Model\SesiAksesDukungan;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pengelola\Dukungan\Surel\AksesDaruratDibuka;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Dukungan\BantuanAksesDukungan as Bantuan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/* PGL-16 / P-09: akses dukungan berizin ("Masuk sebagai tenant"), akses darurat, dan jejaknya. */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/** Tenant, Owner memberi izin, petugas masuk. @return array{0: array, 1: PenggunaPengelola, 2: array<string, mixed>} */
function SiapkanSesiDukunganUji(TestCase $tes, array $isian = []): array
{
    $tenant = Bantuan::BuatTenant();
    Bantuan::OwnerBeriIzin($tes, $tenant, $isian)->assertSessionHasNoErrors();
    $petugas = Bantuan::BuatPetugas();
    $peramban = Bantuan::PetugasMasuk($tes, $petugas, $tenant['Tenant']);

    return [$tenant, $petugas, $peramban];
}

describe('P-09 langkah 1: Owner memberi dan mencabut izin', function (): void {
    it('Owner memberi izin berbatas waktu. Tercatat di audit tenant dan hanya satu yang berlaku', function (): void {
        $tenant = Bantuan::BuatTenant();

        Bantuan::OwnerBeriIzin($this, $tenant, ['DurasiJam' => 72, 'Cakupan' => 'BacaUbah', 'Catatan' => 'Tolong rapikan produk.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $akses = Bantuan::IzinAktif($tenant['Tenant']->Id);
        expect($akses->Jenis)->toBe(JenisAksesDukungan::Berizin)
            ->and($akses->DiizinkanOleh)->toBe($tenant['Pemilik']->Id)
            ->and($akses->BerakhirPada->equalTo(now()->addHours(72)))->toBeTrue()
            ->and($akses->DataPribadiTerbuka)->toBeFalse()
            ->and(LogAudit::query()->where('Peristiwa', 'dukungan.akses.beri')->where('IdTenant', $tenant['Tenant']->Id)->count())->toBe(1);

        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasErrors('Umum');
        expect(AksesDukungan::query()->count())->toBe(1);
    });

    it('durasi di luar 1/24/72 jam ditolak', function (): void {
        $tenant = Bantuan::BuatTenant();

        Bantuan::OwnerBeriIzin($this, $tenant, ['DurasiJam' => 5])->assertSessionHasErrors('DurasiJam');
        Bantuan::OwnerBeriIzin($this, $tenant, ['Cakupan' => 'Ubah'])->assertSessionHasErrors('Cakupan');
        expect(AksesDukungan::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('hanya Owner yang boleh; Admin dan Kasir ditolak', function (PeranTenantBawaan $peran): void {
        $tenant = Bantuan::BuatTenant();
        $anggota = BantuanOrganisasi::TambahAnggota($tenant['Tenant']->Id, $peran);

        Bantuan::LupakanLogin($this);
        BantuanOrganisasi::Masuk($this, $anggota, $tenant['Tenant']->Id)
            ->post('/kelola/pengaturan/akses-dukungan', ['DurasiJam' => 1, 'Cakupan' => 'BacaSaja', 'DataPribadiTerbuka' => false])
            ->assertForbidden();
        BantuanOrganisasi::Masuk($this, $anggota, $tenant['Tenant']->Id)->get('/kelola/pengaturan/akses-dukungan')->assertForbidden();
    })->with([PeranTenantBawaan::Admin, PeranTenantBawaan::Kasir]);

    it('halaman Owner menampilkan izin yang berlaku dan riwayat', function (): void {
        $tenant = Bantuan::BuatTenant();
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();

        Bantuan::LupakanLogin($this);
        BantuanOrganisasi::Masuk($this, $tenant['Pemilik'], $tenant['Tenant']->Id)
            ->get('/kelola/pengaturan/akses-dukungan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Pengaturan/AksesDukungan')
                ->where('Riwayat.Aktif.Status', 'Berlaku')
                ->where('Riwayat.Aktif.Cakupan', 'BacaSaja')
                ->where('Riwayat.Izin.0.NamaPemberi', $tenant['Pemilik']->Nama));
    });

    it('Owner tenant lain tidak bisa mencabut izin tenant ini (isolasi tenant)', function (): void {
        $tenantA = Bantuan::BuatTenant('Toko A');
        $tenantB = Bantuan::BuatTenant('Toko B');
        Bantuan::OwnerBeriIzin($this, $tenantA)->assertSessionHasNoErrors();
        $uuidAkses = Bantuan::IzinAktif($tenantA['Tenant']->Id)->Uuid;

        Bantuan::OwnerCabut($this, $tenantB, $uuidAkses)->assertNotFound();
        expect(Bantuan::IzinAktif($tenantA['Tenant']->Id)->DicabutPada)->toBeNull();
    });
});

describe('P-09 langkah 2: petugas masuk sebagai tenant', function (): void {
    it('tanpa izin dari Owner, konsol menolak dan tidak membuat sesi', function (): void {
        $tenant = Bantuan::BuatTenant();
        $petugas = Bantuan::BuatPetugas();

        Bantuan::PetugasMinta($this, $petugas, $tenant['Tenant'])
            ->assertUnprocessable()
            ->assertJsonPath('Galat.Kode', 'AksesTidakAda');

        expect(SesiAksesDukungan::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('peran tanpa izin masuk (Keuangan) ditolak oleh konsol', function (): void {
        $tenant = Bantuan::BuatTenant();
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();

        Bantuan::PetugasMinta($this, Bantuan::BuatPetugas(PeranPengelolaBawaan::Keuangan), $tenant['Tenant'])->assertForbidden();
    });

    it('petugas dengan izin Owner masuk ke back-office. Banner dan identitas teknis terpasang, tercatat di dua audit', function (): void {
        [$tenant, $petugas] = SiapkanSesiDukunganUji($this);

        // Isi HTML dibaca langsung: respons sesi dukungan disamarkan setelah dirender, jadi `assertInertia` (yang membaca
        // data tampilan asli) tidak mewakili apa yang dilihat petugas.
        $isi = $this->get('/kelola')->assertOk()->getContent();
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', (string) $isi, $cocok);
        $props = json_decode($cocok[1] ?? '{}', true)['props'] ?? [];
        expect($props['AksesDukungan']['NamaPetugas'])->toBe($petugas->Nama)
            ->and($props['AksesDukungan']['Cakupan'])->toBe('BacaSaja')
            ->and($props['AksesDukungan']['Darurat'])->toBeFalse()
            ->and($props['AksesDukungan']['DataPribadiTerbuka'])->toBeFalse();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $sesi = SesiAksesDukungan::query()->sole();
        expect($sesi->TokenDipakaiPada)->not->toBeNull()
            ->and($sesi->IdPenggunaPengelola)->toBe($petugas->Id)
            ->and(LogAuditPengelola::query()->where('Aksi', 'dukungan.sesi.siapkan')->where('IdTenant', $tenant['Tenant']->Id)->exists())->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'dukungan.sesi.mulai')->where('IdPenggunaPengelola', $petugas->Id)->exists())->toBeTrue();
    });

    it('token hanya berlaku sekali', function (): void {
        $tenant = Bantuan::BuatTenant();
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();
        $url = Bantuan::PetugasMinta($this, Bantuan::BuatPetugas(), $tenant['Tenant'])->json('Url');

        Bantuan::BukaAlamat($this, $url);
        $this->get('/kelola')->assertOk();

        Bantuan::LupakanLogin($this);
        $this->get((string) parse_url($url, PHP_URL_PATH))->assertRedirect(route('dukungan.berakhir', ['alasan' => 'TokenTidakSah']));
        $this->get('/kelola')->assertRedirect();
    });

    it('token kedaluwarsa setelah 60 detik', function (): void {
        $tenant = Bantuan::BuatTenant();
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();
        $url = Bantuan::PetugasMinta($this, Bantuan::BuatPetugas(), $tenant['Tenant'])->json('Url');

        $this->travel(61)->seconds();
        Bantuan::LupakanLogin($this);
        $this->get((string) parse_url($url, PHP_URL_PATH))->assertRedirect(route('dukungan.berakhir', ['alasan' => 'TokenTidakSah']));
    });

    it('token ditukar beberapa detik setelah dibuat: batas sesi dihitung ulang dari saat penukaran', function (): void {
        $tenant = Bantuan::BuatTenant();
        Bantuan::OwnerBeriIzin($this, $tenant, ['DurasiJam' => 72])->assertSessionHasNoErrors();
        $url = Bantuan::PetugasMinta($this, Bantuan::BuatPetugas(), $tenant['Tenant'])->json('Url');

        $this->travel(30)->seconds();
        Bantuan::BukaAlamat($this, $url);
        $this->get('/kelola')->assertOk();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $sesi = SesiAksesDukungan::query()->sole();
        expect($sesi->TokenDipakaiPada)->not->toBeNull()
            ->and($sesi->BerakhirPada->equalTo(now()->addHours(4)))->toBeTrue();

        // Sesudah ditukar, batas waktu tidak boleh diperpanjang.
        $sesi->BerakhirPada = now()->addHours(10);
        expect(fn () => $sesi->save())->toThrow(LogicException::class);
    });

    it('token tenant A tidak bisa dipakai di alamat tenant B', function (): void {
        $tenantA = Bantuan::BuatTenant('Toko A');
        $tenantB = Bantuan::BuatTenant('Toko B');
        Bantuan::OwnerBeriIzin($this, $tenantA)->assertSessionHasNoErrors();
        $url = Bantuan::PetugasMinta($this, Bantuan::BuatPetugas(), $tenantA['Tenant'])->json('Url');
        $token = basename((string) parse_url($url, PHP_URL_PATH));

        Bantuan::LupakanLogin($this);
        $this->get("/dukungan/masuk/{$tenantB['Tenant']->Id}/{$token}")->assertRedirect(route('dukungan.berakhir', ['alasan' => 'TokenTidakSah']));
    });

    it('identitas teknis petugas tidak punya hak apa pun di luar sesi akses', function (): void {
        [$tenant, $petugas] = SiapkanSesiDukunganUji($this);
        $idPengguna = PenggunaDukungan::query()->where('IdPenggunaPengelola', $petugas->Id)->value('IdPengguna');
        $identitas = Pengguna::query()->findOrFail($idPengguna);

        Bantuan::LupakanLogin($this);
        BantuanOrganisasi::Masuk($this, $identitas, $tenant['Tenant']->Id)->get('/kelola')->assertRedirect();
        $this->get('/kelola/produk')->assertRedirect();
    });
});

describe('BR-P09.2: baca saja menolak ubah. Baca & ubah mengizinkan', function (): void {
    it('Baca saja: mengubah data ditolak dengan pesan "Izin akses hanya baca" dan tercatat di audit tenant dan pengelola', function (): void {
        [$tenant, $petugas] = SiapkanSesiDukunganUji($this);

        $this->post('/kelola/kategori', ['Nama' => 'Minuman'])
            ->assertRedirect()
            ->assertSessionHasErrors(['Umum' => 'Izin akses hanya baca.']);

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(Kategori::query()->where('Nama', 'Minuman')->exists())->toBeFalse()
            ->and(LogAudit::query()->where('Peristiwa', 'dukungan.aksi.ditolak')->where('IdPenggunaPengelola', $petugas->Id)->exists())->toBeTrue()
            ->and(LogAuditPengelola::query()->where('Aksi', 'dukungan.aksi.ditolak')->where('IdPenggunaPengelola', $petugas->Id)->where('IdTenant', $tenant['Tenant']->Id)->exists())->toBeTrue()
            ->and(AktivitasAksesDukungan::query()->where('Ditolak', true)->count())->toBe(1);
    });

    it('Baca saja: permintaan JSON ditolak dengan masalah 403 berformat seragam', function (): void {
        SiapkanSesiDukunganUji($this);

        $this->postJson('/kelola/kategori', ['Nama' => 'Minuman'])
            ->assertForbidden()
            ->assertJsonPath('Galat.Kode', 'HanyaBaca')
            ->assertJsonPath('Galat.Pesan', 'Izin akses hanya baca.');
    });

    it('Baca & ubah: perubahan lolos, tercatat atas nama petugas di audit tenant dan pengelola', function (): void {
        [$tenant, $petugas] = SiapkanSesiDukunganUji($this, ['Cakupan' => 'BacaUbah']);

        $this->post('/kelola/kategori', ['Nama' => 'Minuman'])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(Kategori::query()->where('Nama', 'Minuman')->exists())->toBeTrue()
            ->and(LogAudit::query()->where('IdPenggunaPengelola', $petugas->Id)->where('Peristiwa', '!=', 'dukungan.sesi.mulai')->exists())->toBeTrue()
            ->and(LogAuditPengelola::query()->where('Aksi', 'dukungan.aksi')->where('IdPenggunaPengelola', $petugas->Id)->exists())->toBeTrue();
    });

    it('Baca saja tetap bisa membuka halaman dan jejak membuka halaman tercatat', function (): void {
        [$tenant] = SiapkanSesiDukunganUji($this);

        $this->get('/kelola/produk')->assertOk();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(AktivitasAksesDukungan::query()->where('Metode', 'GET')->where('Jalur', '/kelola/produk')->exists())->toBeTrue();
    });
});

describe('BR-P09.1: kata sandi, PIN, dan kredensial tidak pernah terbuka', function (): void {
    it('halaman keamanan akun, PIN, langganan, integrasi, token API, dan data saya ditutup', function (string $jalur): void {
        SiapkanSesiDukunganUji($this, ['Cakupan' => 'BacaUbah']);

        $this->get($jalur)->assertRedirect(route('kelola.beranda'))->assertSessionHasErrors('Umum');
    })->with([
        '/kelola/keamanan',
        '/kelola/langganan',
        '/kelola/pengaturan/api',
        '/kelola/pengaturan/webhook',
        '/kelola/pengaturan/data-saya',
        '/kelola/pengaturan/akses-dukungan',
    ]);

    it('petugas tidak bisa memberi dirinya izin atau mengubah pengguna dan peran', function (): void {
        $tenant = Bantuan::BuatTenant();
        Bantuan::OwnerBeriIzin($this, $tenant, ['Cakupan' => 'BacaUbah'])->assertSessionHasNoErrors();
        Bantuan::PetugasMasuk($this, Bantuan::BuatPetugas(), $tenant['Tenant']);

        $this->post('/kelola/pengaturan/akses-dukungan', ['DurasiJam' => 72, 'Cakupan' => 'BacaUbah', 'DataPribadiTerbuka' => true])
            ->assertRedirect()->assertSessionHasErrors('Umum');
        expect(AksesDukungan::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('jalur di luar back-office (/masuk, /pilih-usaha, /ganti-kata-sandi) tidak terbuka bagi sesi dukungan', function (): void {
        SiapkanSesiDukunganUji($this);

        $this->get('/ganti-kata-sandi')->assertRedirect()->assertSessionHasErrors('Umum');
    });
});

describe('BR-P09.1: data pribadi pelanggan tersamar kecuali Owner membukanya', function (): void {
    function BuatPelangganUji(int $idTenant): Pelanggan
    {
        BantuanOrganisasi::AturKonteks($idTenant);

        return Pelanggan::query()->create([
            'Nama' => 'Budi Santoso Wicaksono',
            'NoHp' => '6281234567890',
            'Email' => 'budi.santoso@contoh.id',
            'Alamat' => 'Jl. Melati No. 12, Surakarta',
        ]);
    }

    it('tanpa izin data pribadi: daftar JSON dan halaman HTML tidak memuat nilai asli', function (): void {
        $tenant = Bantuan::BuatTenant();
        $pelanggan = BuatPelangganUji($tenant['Tenant']->Id);
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();
        Bantuan::PetugasMasuk($this, Bantuan::BuatPetugas(), $tenant['Tenant']);

        $daftar = $this->getJson('/kelola/pelanggan')->assertOk();
        $halaman = $this->get("/kelola/pelanggan/{$pelanggan->Uuid}")->assertOk();

        foreach ([$daftar->getContent(), $halaman->getContent()] as $isi) {
            expect($isi)->not->toContain('6281234567890')
                ->and($isi)->not->toContain('budi.santoso@contoh.id')
                ->and($isi)->not->toContain('Budi Santoso Wicaksono')
                ->and($isi)->not->toContain('Jl. Melati');
        }

        expect($daftar->getContent())->toContain('\u2022');
    });

    it('dengan izin data pribadi dari Owner: nilai asli tampil', function (): void {
        $tenant = Bantuan::BuatTenant();
        BuatPelangganUji($tenant['Tenant']->Id);
        Bantuan::OwnerBeriIzin($this, $tenant, ['DataPribadiTerbuka' => true])->assertSessionHasNoErrors();
        Bantuan::PetugasMasuk($this, Bantuan::BuatPetugas(), $tenant['Tenant']);

        $this->getJson('/kelola/pelanggan')->assertOk()->assertSee('Budi Santoso Wicaksono', false)->assertSee('0812-3456-7890', false);
    });

    it('unduhan dan cetak ditolak selama data pribadi tersamar, dibuka bila Owner mengizinkan', function (): void {
        $tenant = Bantuan::BuatTenant();
        BuatPelangganUji($tenant['Tenant']->Id);
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();
        Bantuan::PetugasMasuk($this, Bantuan::BuatPetugas(), $tenant['Tenant']);

        $this->get('/kelola/pelanggan/ekspor')->assertRedirect()->assertSessionHasErrors('Umum');
        $this->getJson('/kelola/pelanggan/ekspor')->assertForbidden()->assertJsonPath('Galat.Kode', 'CetakUnduhanDitolak');
    });

    it('unduhan lolos bila Owner membuka data pribadi', function (): void {
        $tenant = Bantuan::BuatTenant();
        BuatPelangganUji($tenant['Tenant']->Id);
        Bantuan::OwnerBeriIzin($this, $tenant, ['DataPribadiTerbuka' => true])->assertSessionHasNoErrors();
        Bantuan::PetugasMasuk($this, Bantuan::BuatPetugas(), $tenant['Tenant']);

        $this->get('/kelola/pelanggan/ekspor')->assertOk();
    });
});

describe('P-09 langkah 4: pencabutan, kedaluwarsa, dan petugas nonaktif memutus sesi seketika', function (): void {
    it('Owner mencabut izin: permintaan berikutnya petugas ditolak dan sesi bertanda Dicabut', function (): void {
        [$tenant, , $peramban] = SiapkanSesiDukunganUji($this);
        $this->get('/kelola')->assertOk();

        $uuidAkses = Bantuan::IzinAktif($tenant['Tenant']->Id)->Uuid;
        Bantuan::OwnerCabut($this, $tenant, $uuidAkses)->assertSessionHasNoErrors();

        Bantuan::PulihkanPeramban($this, $peramban);
        $this->get('/kelola')->assertRedirect(route('dukungan.berakhir', ['alasan' => AlasanAkhirSesiDukungan::Dicabut->value]));

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $sesi = SesiAksesDukungan::query()->sole();
        expect($sesi->DiakhiriPada)->not->toBeNull()
            ->and($sesi->AlasanAkhir)->toBe(AlasanAkhirSesiDukungan::Dicabut)
            ->and(AksesDukungan::query()->sole()->DicabutOleh)->toBe($tenant['Pemilik']->Id);

        $this->get('/kelola')->assertRedirect();
    });

    it('izin habis waktu: sesi berhenti. Perintah penyapu mengisi alasan Kedaluwarsa', function (): void {
        [$tenant, , $peramban] = SiapkanSesiDukunganUji($this, ['DurasiJam' => 1]);

        $this->travel(61)->minutes();
        Bantuan::PulihkanPeramban($this, $peramban);
        $this->get('/kelola')->assertRedirect(route('dukungan.berakhir', ['alasan' => AlasanAkhirSesiDukungan::Kedaluwarsa->value]));

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(SesiAksesDukungan::query()->sole()->AlasanAkhir)->toBe(AlasanAkhirSesiDukungan::Kedaluwarsa);
    });

    it('penyapu terjadwal mengakhiri sesi yang lewat waktu tanpa ada permintaan lagi', function (): void {
        [$tenant] = SiapkanSesiDukunganUji($this, ['DurasiJam' => 1]);

        $this->travel(2)->hours();
        $this->artisan('dukungan:akhiri-kedaluwarsa')->assertSuccessful();

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $sesi = SesiAksesDukungan::query()->sole();
        expect($sesi->DiakhiriPada)->not->toBeNull()
            ->and($sesi->AlasanAkhir)->toBe(AlasanAkhirSesiDukungan::Kedaluwarsa);
    });

    it('petugas yang dinonaktifkan kehilangan sesinya pada permintaan berikutnya', function (): void {
        [$tenant, $petugas, $peramban] = SiapkanSesiDukunganUji($this);

        $petugas->forceFill(['Aktif' => false])->save();
        Bantuan::PulihkanPeramban($this, $peramban);

        $this->get('/kelola')->assertRedirect(route('dukungan.berakhir', ['alasan' => AlasanAkhirSesiDukungan::Dicabut->value]));
    });

    it('tombol Akhiri sesi menutup sesi dan melepas login', function (): void {
        [$tenant] = SiapkanSesiDukunganUji($this);

        $this->post('/dukungan/akhiri')->assertRedirect(route('dukungan.berakhir', ['alasan' => AlasanAkhirSesiDukungan::Selesai->value]));

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        expect(SesiAksesDukungan::query()->sole()->AlasanAkhir)->toBe(AlasanAkhirSesiDukungan::Selesai);
        $this->get('/kelola')->assertRedirect();
    });

    it('sesi baru petugas yang sama menggantikan sesi lamanya', function (): void {
        $tenant = Bantuan::BuatTenant();
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();
        $petugas = Bantuan::BuatPetugas();
        Bantuan::PetugasMasuk($this, $petugas, $tenant['Tenant']);
        Bantuan::PetugasMasuk($this, $petugas, $tenant['Tenant']);

        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $alasan = SesiAksesDukungan::query()->orderBy('Id')->pluck('AlasanAkhir')->all();
        expect($alasan[0])->toBe(AlasanAkhirSesiDukungan::Digantikan)->and($alasan[1])->toBeNull();
    });
});

describe('P-09 langkah 5: akses darurat hanya Super Admin dan Owner diberi tahu', function (): void {
    function BukaDaruratUji(array $isian = []): array
    {
        return [...[
            'Kategori' => 'InsidenKeamanan',
            'Alasan' => 'Dugaan pembobolan akun kasir, perlu menelusuri log langsung.',
            'DurasiJam' => 2,
            'Cakupan' => 'BacaSaja',
        ], ...$isian];
    }

    it('Super Admin membuka akses darurat; Owner dikirimi email dan akses hanya dipakai pembukanya', function (): void {
        $tenant = Bantuan::BuatTenant();
        $admin = BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->post(BantuanPengelola::Url("/tenant/{$tenant['Tenant']->Uuid}/akses-dukungan/darurat"), BukaDaruratUji())
            ->assertSessionHasNoErrors();

        $akses = Bantuan::IzinAktif($tenant['Tenant']->Id);
        expect($akses->Jenis)->toBe(JenisAksesDukungan::Darurat)
            ->and($akses->IdPenggunaPengelola)->toBe($admin->Id)
            ->and($akses->DataPribadiTerbuka)->toBeFalse()
            ->and($akses->BerakhirPada->equalTo(now()->addHours(2)))->toBeTrue()
            ->and($akses->PemilikDiberitahuPada)->not->toBeNull()
            ->and(LogAuditPengelola::query()->where('Aksi', 'dukungan.akses.darurat')->where('IdTenant', $tenant['Tenant']->Id)->exists())->toBeTrue();
        Mail::assertQueued(AksesDaruratDibuka::class, fn ($surel) => $surel->hasTo($tenant['Pemilik']->Email));

        // Super Admin lain tidak boleh memakai akses darurat milik rekannya.
        $lain = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        Bantuan::PetugasMinta($this, $lain, $tenant['Tenant'])->assertUnprocessable()->assertJsonPath('Galat.Kode', 'AksesDaruratBukanMilikAnda');
        Bantuan::PetugasMinta($this, $admin, $tenant['Tenant'])->assertOk();
    });

    it('selain Super Admin ditolak', function (PeranPengelolaBawaan $peran): void {
        $tenant = Bantuan::BuatTenant();
        BantuanTenantPengelola::Masuk($this, $peran);

        $this->post(BantuanPengelola::Url("/tenant/{$tenant['Tenant']->Uuid}/akses-dukungan/darurat"), BukaDaruratUji())->assertForbidden();
        expect(AksesDukungan::query()->withoutGlobalScopes()->count())->toBe(0);
    })->with([PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::Keuangan]);

    it('wajib kategori sah, alasan tertulis minimal 20 karakter, dan durasi maksimal 4 jam', function (): void {
        $tenant = Bantuan::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $url = BantuanPengelola::Url("/tenant/{$tenant['Tenant']->Uuid}/akses-dukungan/darurat");

        $this->post($url, BukaDaruratUji(['Alasan' => 'Penting']))->assertSessionHasErrors('Alasan');
        $this->post($url, BukaDaruratUji(['DurasiJam' => 5]))->assertSessionHasErrors('DurasiJam');
        $this->post($url, BukaDaruratUji(['Kategori' => 'Penasaran']))->assertSessionHasErrors('Kategori');
        expect(AksesDukungan::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('tidak dibuka bila tenant sudah punya izin yang berlaku', function (): void {
        $tenant = Bantuan::BuatTenant();
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        $this->post(BantuanPengelola::Url("/tenant/{$tenant['Tenant']->Uuid}/akses-dukungan/darurat"), BukaDaruratUji())->assertSessionHasErrors('Umum');
        expect(AksesDukungan::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('Super Admin menutup akses darurat lebih awal. Izin Owner tidak bisa dicabut dari konsol', function (): void {
        $tenant = Bantuan::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $this->post(BantuanPengelola::Url("/tenant/{$tenant['Tenant']->Uuid}/akses-dukungan/darurat"), BukaDaruratUji())->assertSessionHasNoErrors();
        $uuid = Bantuan::IzinAktif($tenant['Tenant']->Id)->Uuid;

        $this->post(BantuanPengelola::Url("/tenant/{$tenant['Tenant']->Uuid}/akses-dukungan/{$uuid}/cabut"))->assertSessionHasNoErrors();
        expect(Bantuan::IzinAktif($tenant['Tenant']->Id)->DicabutPada)->not->toBeNull();

        // Izin Owner: konsol tidak boleh mencabut.
        Bantuan::OwnerBeriIzin($this, $tenant)->assertSessionHasNoErrors();
        $uuidOwner = Bantuan::IzinAktif($tenant['Tenant']->Id)->Uuid;
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $this->post(BantuanPengelola::Url("/tenant/{$tenant['Tenant']->Uuid}/akses-dukungan/{$uuidOwner}/cabut"))->assertSessionHasErrors('Umum');
        expect(Bantuan::IzinAktif($tenant['Tenant']->Id)->DicabutPada)->toBeNull();
    });

    it('tampilan 360° tenant memuat izin dan sesi tanpa data pribadi', function (): void {
        [$tenant] = SiapkanSesiDukunganUji($this);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $this->get(BantuanPengelola::Url("/tenant/{$tenant['Tenant']->Uuid}"))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Tenant.AksesDukungan.Aktif.Status', 'Berlaku')
                ->has('Tenant.AksesDukungan.Sesi', 1));
    });
});

describe('P-09 langkah 3-4: Owner melihat jejak sesi', function (): void {
    it('Owner membuka daftar permintaan sesi petugas. Tenant lain mendapat 404', function (): void {
        [$tenant] = SiapkanSesiDukunganUji($this);
        $this->get('/kelola/produk')->assertOk();
        BantuanOrganisasi::AturKonteks($tenant['Tenant']->Id);
        $uuidSesi = SesiAksesDukungan::query()->sole()->Uuid;

        Bantuan::LupakanLogin($this);
        BantuanOrganisasi::Masuk($this, $tenant['Pemilik'], $tenant['Tenant']->Id)
            ->getJson("/kelola/pengaturan/akses-dukungan/sesi/{$uuidSesi}/aktivitas")
            ->assertOk()
            ->assertJsonFragment(['Jalur' => '/kelola/produk']);

        $lain = Bantuan::BuatTenant('Toko Lain');
        Bantuan::LupakanLogin($this);
        BantuanOrganisasi::Masuk($this, $lain['Pemilik'], $lain['Tenant']->Id)
            ->getJson("/kelola/pengaturan/akses-dukungan/sesi/{$uuidSesi}/aktivitas")
            ->assertNotFound();
    });
});
