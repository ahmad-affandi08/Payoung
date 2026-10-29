<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Organisasi\Aksi\AturPinSendiri;
use App\Domain\Organisasi\Aksi\CabutPerangkat;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Layanan\VerifierPinOffline;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Membuka verifier terbungkus dengan kunci perangkat (sama dengan yang dilakukan aplikasi kasir). */
function BukaVerifierPinUji(array $pin, string $kunciBase64): string
{
    $sandi = (string) base64_decode($pin['Sandi'], true);
    $hasil = openssl_decrypt(substr($sandi, 0, -16), 'aes-256-gcm', (string) base64_decode($kunciBase64, true), OPENSSL_RAW_DATA, (string) base64_decode($pin['Nonce'], true), substr($sandi, -16));

    return is_string($hasil) ? $hasil : '';
}

describe('F-06 PIN kasir offline & data awal (GET /api/pos/v1/data-awal)', function (): void {
    it('vektor uji bersama Spesifikasi/VektorUjiPin: Argon2id & AES-256-GCM PHP sama dengan nilai harapan', function (): void {
        $vektor = json_decode((string) file_get_contents(base_path('../../Spesifikasi/VektorUjiPin/VerifierPin.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($vektor['Parameter'])->toBe(VerifierPinOffline::AmbilParameter());

        foreach ($vektor['Kasus'] as $kasus) {
            $hash = VerifierPinOffline::HitungHash($kasus['Pin'], (string) base64_decode($kasus['Garam'], true));
            expect(base64_encode($hash))->toBe($kasus['Hash'])
                ->and(BukaVerifierPinUji($kasus, $vektor['KunciPerangkat']))->toBe($hash);
        }
    });

    it('§25.2 no. 3: aktivasi mengirim kunci PIN perangkat. Data awal membungkus verifier yang cocok dengan PIN, tanpa hash PIN mentah', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        ['Kode' => $kode] = BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $k['Outlet'], nama: 'Kasir Teras');
        $aktivasi = $this->postJson('/api/pos/v1/perangkat/aktivasi', ['Kode' => $kode, 'Platform' => 'Android', 'VersiAplikasi' => '1.0.0'])->assertCreated();
        $kunci = (string) $aktivasi->json('KunciPinOffline');
        app(AturPinSendiri::class)->Jalankan($k['Tenant']->Id, $k['Kasir']->Id, '739415');

        $respons = $this->withToken((string) $aktivasi->json('TokenPerangkat'))->getJson('/api/pos/v1/data-awal')->assertOk();
        $staf = collect($respons->json('Staf'))->keyBy('Uuid');
        $kasir = $staf->get($k['Kasir']->Uuid);

        expect(strlen((string) base64_decode($kunci, true)))->toBe(32)
            ->and($respons->json('PinOffline.Tersedia'))->toBeTrue()
            ->and($respons->json('PinOffline.Parameter'))->toBe(VerifierPinOffline::AmbilParameter())
            ->and($kasir['PinDiatur'])->toBeTrue()
            ->and($kasir['Izin'])->toContain('penjualan.buat')
            ->and(BukaVerifierPinUji($kasir['Pin'], $kunci))->toBe(VerifierPinOffline::HitungHash('739415', (string) base64_decode($kasir['Pin']['Garam'], true)))
            ->and(BukaVerifierPinUji($kasir['Pin'], $kunci))->not->toBe(VerifierPinOffline::HitungHash('739416', (string) base64_decode($kasir['Pin']['Garam'], true)))
            ->and($staf->get($k['Supervisor']->Uuid)['Pin'])->toBeNull()
            ->and($staf->get($k['Supervisor']->Uuid)['PinDiatur'])->toBeFalse()
            ->and($respons->getContent())->not->toContain('$2y$')
            ->and($respons->getContent())->not->toContain('HashPin');
    });

    it('staf hanya anggota aktif dengan akses outlet perangkat. Kategori kas hanya yang aktif. Pengaturan kasir ikut', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $cabang = BantuanJurnal::BuatOutlet();
        $kasirCabang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $kasirCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::Kasir)->Id]);
        BantuanKasir::BuatKategori('Parkir lama', JenisKategoriKas::Keluar, '6-9000', aktif: false);

        $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();
        $uuidStaf = array_column($respons->json('Staf'), 'Uuid');

        expect($uuidStaf)->toContain($k['Kasir']->Uuid, $k['Supervisor']->Uuid, $k['Pemilik']->Uuid)
            ->and($uuidStaf)->not->toContain($kasirCabang->Uuid)
            ->and(array_column($respons->json('KategoriKas'), 'Nama'))->toBe(['Beli es batu & galon', 'Tambahan uang receh'])
            ->and($respons->json('Pengaturan'))->toBe([
                'BatasKasKeluar' => '200000.00',
                'ShiftBersama' => false,
                // F-07b: batas diskon (BR-07.3) & pembulatan tunai (BR-08.6).
                'BatasDiskonManual' => '10.00',
                'BatasDiskonPenyetuju' => '30.00',
                'PembulatanTunai' => null,
                // F-11: tutup shift buta & toleransi selisih kas (§19.2).
                'TutupShiftButa' => true,
                'ToleransiSelisihKas' => '10000.00',
                // F-09: batas hari retur sejak tanggal bisnis penjualan.
                'BatasHariRetur' => 7,
                // F-12: tempo butuh penyetuju bila ada piutang lewat jatuh tempo > N hari (bawaan 0).
                'BatasHariLewatJatuhTempo' => 0,
                // Cetak struk bagian 4: buka laci manual wajib PIN (bawaan mati).
                'BukaLaciPerluPin' => false,
                'BatasReturTanpaStrukHarian' => '1000000.00',
                // X4: persetujuan jarak jauh lewat Aplikasi Owner (fitur paket; bawaan mati).
                'PersetujuanJarakJauh' => false,
                // v3.55: barcode timbangan EAN-13 (bawaan mati, awalan 27, nilai berat).
                'BarcodeTimbangan' => ['Aktif' => false, 'Awalan' => ['27'], 'Nilai' => 'Berat'],
            ]);
    });

    it('isolasi & pencabutan: staf tenant lain tidak ikut. Perangkat dicabut 403 dan kunci PIN-nya dikosongkan', function (): void {
        $a = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');

        $staf = array_column($this->withToken($b['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Staf'), 'Uuid');
        expect($staf)->not->toContain($a['Kasir']->Uuid);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        app(CabutPerangkat::class)->Jalankan($a['Perangkat']);
        $this->withToken($a['Token'])->getJson('/api/pos/v1/data-awal')->assertForbidden();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(Perangkat::query()->findOrFail($a['Perangkat']->Id)->KunciPinOffline)->toBeNull();
    });

    it('K26: data awal membawa awalan antrian perangkat (null bila belum diatur)', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('Perangkat.AwalanAntrian', null);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['AwalanAntrian' => 'B']);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('Perangkat.AwalanAntrian', 'B');
    });

    it('pengaturan struk (PRD v1.79): bawaan, simpan dari back-office (audit), dan blok Struk di data awal: NPWP hanya bila outlet PKP, logo, tanda air paket', function (): void {
        $k = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Tenant::query()->whereKey($k['Tenant']->Id)->update(['Npwp' => '0123456789012345']);
        UbahLanggananStrukUji($k['Tenant']->Id, 'GRATIS');

        $struk = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Struk');
        expect($struk)->toMatchArray([
            'TampilkanLogo' => true,
            'NamaDicetak' => null,
            'TeksKepala' => [],
            'TampilkanNpwp' => true,
            'CatatanKaki' => null,
            'TeksPenutup' => null,
            'NamaUsaha' => $k['Outlet']->Nama,
            'Npwp' => null,
            'AdaLogo' => false,
            'TandaAir' => true,
        ]);
        $this->withToken($k['Token'])->get('/api/pos/v1/logo-struk')->assertNotFound();

        Storage::fake((string) config('tenant.DiskLogo'));
        Storage::disk((string) config('tenant.DiskLogo'))->put("logo/{$k['Tenant']->Id}/logo.png", 'png-palsu');
        $tenant = Tenant::query()->findOrFail($k['Tenant']->Id);
        $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'PathLogo' => "logo/{$k['Tenant']->Id}/logo.png"];
        $tenant->save();
        expect($this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->json('Struk.AdaLogo'))->toBeTrue();
        $this->withToken($k['Token'])->get('/api/pos/v1/logo-struk')->assertOk();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $outlet = Outlet::query()->findOrFail($k['Outlet']->Id);
        $outlet->ProfilPajak = [...($outlet->ProfilPajak ?? []), 'Pkp' => true];
        $outlet->save();
        UbahLanggananStrukUji($k['Tenant']->Id, 'PRO');

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/kasir/struk')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);
        $this->get('/kelola/kasir/struk')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Struk')
            ->where('Pengaturan.TampilkanKasir', true)
            ->where('Profil.NamaUsaha', 'Kopi Senja Solo')
            ->where('Profil.TandaAir', false));

        $isian = [
            'TampilkanLogo' => false, 'TampilkanAlamat' => true, 'TampilkanTelepon' => false, 'TampilkanNpwp' => true,
            'TampilkanKasir' => true, 'TampilkanPelanggan' => false, 'TampilkanHemat' => true,
            'NamaDicetak' => '  Senja Coffee  ', 'TeksKepala' => ['Buka 07.00-22.00', '', '@kopisenja'],
            'CatatanKaki' => 'Barang yang sudah dibeli bisa ditukar 7 hari.', 'TeksPenutup' => '',
        ];
        $this->put('/kelola/kasir/struk', [...$isian, 'TeksKepala' => ['a', 'b', 'c', 'd']])->assertSessionHasErrors('TeksKepala');
        $this->put('/kelola/kasir/struk', [...$isian, 'CatatanKaki' => str_repeat('a', 201)])->assertSessionHasErrors('CatatanKaki');
        $this->put('/kelola/kasir/struk', [...$isian, 'NamaDicetak' => str_repeat('a', 49)])->assertSessionHasErrors('NamaDicetak');
        // Baris kepala kosong dibuang sebelum batas 3 baris diperiksa.
        $this->put('/kelola/kasir/struk', [...$isian, 'TeksKepala' => ['Buka 07.00-22.00', '', '@kopisenja', '']])->assertRedirect('/kelola/kasir/struk');
        $this->put('/kelola/kasir/struk', $isian)->assertRedirect('/kelola/kasir/struk');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'struk.pengaturan.ubah')->count())->toBe(1);
        // Logo dimatikan di pengaturan struk: tidak dikirim ke perangkat.
        $this->withToken($k['Token'])->get('/api/pos/v1/logo-struk')->assertNotFound();

        $struk = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Struk');
        expect($struk)->toBe([
            'TampilkanLogo' => false,
            'NamaDicetak' => 'Senja Coffee',
            'TeksKepala' => ['Buka 07.00-22.00', '@kopisenja'],
            'TampilkanAlamat' => true,
            'TampilkanTelepon' => false,
            'TampilkanNpwp' => true,
            'TampilkanKasir' => true,
            'TampilkanPelanggan' => false,
            'TampilkanHemat' => true,
            'CatatanKaki' => 'Barang yang sudah dibeli bisa ditukar 7 hari.',
            'TeksPenutup' => null,
            'TampilkanStrukDigital' => true,
            'TampilkanUmpanBalik' => true,
            'NamaUsaha' => $k['Outlet']->Nama,
            'Npwp' => '0123456789012345',
            'AdaLogo' => false,
            'TandaAir' => false,
            'AwalanStrukDigital' => url('/s/'.base_convert((string) $k['Tenant']->Id, 10, 36).'.'),
        ]);
    });
});

describe('Judul struk = nama outlet (D-75)', function (): void {
    it('judul struk memakai nama outlet perangkat, bukan nama usaha/akun maupun nama merek bawaan', function (): void {
        $k = BantuanKasir::Siapkan($this, 'Sudirman Group');
        Outlet::query()->whereKey($k['Outlet']->Id)->update(['Nama' => "Lil' Escape Coffee & Eatery"]);

        $struk = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Struk');

        expect($struk['NamaUsaha'])->toBe("Lil' Escape Coffee & Eatery")->not->toBe('Sudirman Group');
    });
});

describe('Pengaturan struk per outlet (D-76)', function (): void {
    it('saklar & logo disimpan khusus outlet: outlet lain dan tenant tidak berubah, teks isian tetap bersama', function (): void {
        $k = BantuanKasir::Siapkan($this, 'Budi Santoso');
        Storage::fake((string) config('tenant.DiskLogo'));
        $outletA = $k['Outlet'];
        $outletB = BantuanJurnal::BuatOutlet();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);

        $isian = [
            'UuidOutlet' => $outletA->Uuid,
            'TampilkanLogo' => true, 'TampilkanAlamat' => true, 'TampilkanTelepon' => true, 'TampilkanNpwp' => true,
            'TampilkanKasir' => false, 'TampilkanPelanggan' => true, 'TampilkanHemat' => true,
            'NamaDicetak' => null, 'TeksKepala' => ['@bersama'], 'CatatanKaki' => null, 'TeksPenutup' => null,
        ];
        $this->put('/kelola/kasir/struk', [...$isian, 'Logo' => UploadedFile::fake()->image('logo-a.png', 100, 100)])->assertRedirect();

        $struk = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Struk');
        expect($struk['TampilkanKasir'])->toBeFalse()
            ->and($struk['AdaLogo'])->toBeTrue()
            ->and($struk['TeksKepala'])->toBe(['@bersama']);
        $this->withToken($k['Token'])->get('/api/pos/v1/logo-struk')->assertOk();

        $pengaturan = app(PengaturanStrukTenant::class);
        expect($pengaturan->Ambil($k['Tenant']->Id)->tampilkanKasir)->toBeTrue()
            ->and($pengaturan->Ambil($k['Tenant']->Id, (int) $outletB->Id)->tampilkanKasir)->toBeTrue()
            ->and($pengaturan->AmbilPathLogo($k['Tenant']->Id, (int) $outletB->Id))->toBeNull()
            ->and($pengaturan->Ambil($k['Tenant']->Id, (int) $outletB->Id)->teksKepala)->toBe(['@bersama']);

        $this->put('/kelola/kasir/struk', [...$isian, 'HapusLogo' => 1])->assertRedirect();
        expect($pengaturan->AmbilPathLogoOutlet($k['Tenant']->Id, (int) $outletA->Id))->toBeNull();
    });
});

describe('Logo merek outlet untuk bilah atas (D-16)', function (): void {
    it('Outlet.AdaLogo & /logo-outlet memberi logo usaha walau saklar tampil struk dimatikan. Struk tetap 404', function (): void {
        $k = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Storage::fake((string) config('tenant.DiskLogo'));

        expect($this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->json('Outlet.AdaLogo'))->toBeFalse();
        $this->withToken($k['Token'])->get('/api/pos/v1/logo-outlet')->assertNotFound();

        Storage::disk((string) config('tenant.DiskLogo'))->put("logo/{$k['Tenant']->Id}/logo.png", 'png-palsu');
        $tenant = Tenant::query()->findOrFail($k['Tenant']->Id);
        $tenant->Pengaturan = [
            ...($tenant->Pengaturan ?? []),
            'PathLogo' => "logo/{$k['Tenant']->Id}/logo.png",
            'Struk' => ['TampilkanLogo' => false],
        ];
        $tenant->save();

        $dataAwal = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal');
        expect($dataAwal->json('Outlet.AdaLogo'))->toBeTrue()
            ->and($dataAwal->json('Struk.AdaLogo'))->toBeFalse();
        $this->withToken($k['Token'])->get('/api/pos/v1/logo-outlet')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->withToken($k['Token'])->get('/api/pos/v1/logo-struk')->assertNotFound();
    });

    it('isolasi tenant: logo outlet tenant A tidak diberikan ke perangkat tenant B', function (): void {
        $a = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        Storage::fake((string) config('tenant.DiskLogo'));
        Storage::disk((string) config('tenant.DiskLogo'))->put("logo/{$a['Tenant']->Id}/logo.png", 'png-palsu');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $tenantA = Tenant::query()->findOrFail($a['Tenant']->Id);
        $tenantA->Pengaturan = [...($tenantA->Pengaturan ?? []), 'PathLogo' => "logo/{$a['Tenant']->Id}/logo.png"];
        $tenantA->save();

        $this->withToken($a['Token'])->get('/api/pos/v1/logo-outlet')->assertOk();
        $this->withToken($b['Token'])->get('/api/pos/v1/logo-outlet')->assertNotFound();
        expect($this->withToken($b['Token'])->getJson('/api/pos/v1/data-awal')->json('Outlet.AdaLogo'))->toBeFalse();
    });
});

describe('Pengaturan struk: isolasi tenant', function (): void {
    it('pengaturan, identitas, dan logo struk tenant A tidak terlihat oleh perangkat tenant B. Simpan A tidak mengubah B', function (): void {
        $a = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        Tenant::query()->whereKey($a['Tenant']->Id)->update(['Npwp' => '0123456789012345']);
        Storage::fake((string) config('tenant.DiskLogo'));
        Storage::disk((string) config('tenant.DiskLogo'))->put("logo/{$a['Tenant']->Id}/logo.png", 'png-palsu');
        $tenantA = Tenant::query()->findOrFail($a['Tenant']->Id);
        $tenantA->Pengaturan = [...($tenantA->Pengaturan ?? []), 'PathLogo' => "logo/{$a['Tenant']->Id}/logo.png"];
        $tenantA->save();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Admin);
        $this->put('/kelola/kasir/struk', [
            'TampilkanLogo' => true, 'TampilkanAlamat' => true, 'TampilkanTelepon' => true, 'TampilkanNpwp' => true,
            'TampilkanKasir' => true, 'TampilkanPelanggan' => true, 'TampilkanHemat' => true,
            'NamaDicetak' => 'Senja Coffee', 'TeksKepala' => ['@kopisenja'], 'CatatanKaki' => 'Rahasia A', 'TeksPenutup' => null,
        ])->assertRedirect('/kelola/kasir/struk');

        $strukB = $this->withToken($b['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('Struk');
        expect($strukB['NamaUsaha'])->toBe($b['Outlet']->Nama)
            ->and($strukB['NamaDicetak'])->toBeNull()
            ->and($strukB['TeksKepala'])->toBe([])
            ->and($strukB['CatatanKaki'])->toBeNull()
            ->and($strukB['Npwp'])->toBeNull()
            ->and($strukB['AdaLogo'])->toBeFalse();
        $this->withToken($b['Token'])->get('/api/pos/v1/logo-struk')->assertNotFound();
        $this->withToken($a['Token'])->get('/api/pos/v1/logo-struk')->assertOk();

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(Tenant::query()->findOrFail($b['Tenant']->Id)->Pengaturan['Struk'] ?? null)->toBeNull();
    });
});

function UbahLanggananStrukUji(int $idTenant, string $kodePaket): void
{
    Langganan::query()->where('IdTenant', $idTenant)->update(['IdPaket' => Paket::query()->where('Kode', $kodePaket)->value('Id')]);
}
