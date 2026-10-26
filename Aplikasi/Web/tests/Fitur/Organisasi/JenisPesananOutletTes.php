<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\StasiunDapur;
use App\Domain\Tenant\Model\OutletFitur;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * v3.51 jenis pesanan kasir per outlet (§9.1–§9.2): otomatis menurut mode kasir template (FnB → makan di tempat &
 * bawa pulang, retail → tanpa pilihan) atau stasiun dapur aktif; diatur manual di detail outlet (izin outlet.kelola,
 * bawaan wajib dari daftar, audit); dikirim ke kasir di data awal `Outlet.JenisPesanan` & `Outlet.JenisPesananBawaan`.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('Jenis pesanan kasir per outlet (v3.51)', function (): void {
    it('otomatis: retail tanpa pilihan. Mode kasir Cepat/Meja atau stasiun dapur aktif → makan di tempat & bawa pulang', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $dataAwal = fn () => $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();

        $dataAwal()->assertJsonPath('Outlet.JenisPesanan', [])->assertJsonPath('Outlet.JenisPesananBawaan', null);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        OutletFitur::query()->create([
            'IdOutlet' => $k['Outlet']->Id,
            'KunciFitur' => OutletFitur::KUNCI_POS,
            'Aktif' => true,
            'Konfigurasi' => ['ModeKasir' => ['Cepat'], 'ModeKasirDefault' => 'Cepat'],
        ]);
        $dataAwal()->assertJsonPath('Outlet.JenisPesanan', ['MakanDiTempat', 'BawaPulang'])->assertJsonPath('Outlet.JenisPesananBawaan', 'MakanDiTempat');

        OutletFitur::query()->where('IdOutlet', $k['Outlet']->Id)->update(['Konfigurasi' => json_encode(['ModeKasir' => ['Retail']])]);
        $dataAwal()->assertJsonPath('Outlet.JenisPesanan', []);

        StasiunDapur::query()->create(['Nama' => 'Dapur']);
        $dataAwal()->assertJsonPath('Outlet.JenisPesanan', ['MakanDiTempat', 'BawaPulang']);
    });

    it('diatur manual di detail outlet: urutan tetap, bawaan dari daftar, audit. Kosong = tanpa pilihan. Otomatis lagi', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}";
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get($alamat)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('JenisPesanan.Otomatis', true)
            ->where('JenisPesanan.JenisPesanan', [])
            ->has('JenisPesanan.Pilihan', 3));

        $this->post("{$alamat}/jenis-pesanan", ['Otomatis' => false, 'JenisPesanan' => ['Antar', 'BawaPulang'], 'JenisPesananBawaan' => 'MakanDiTempat'])
            ->assertSessionHasErrors('JenisPesananBawaan');
        $this->post("{$alamat}/jenis-pesanan", ['Otomatis' => false, 'JenisPesanan' => ['Bungkus'], 'JenisPesananBawaan' => null])
            ->assertSessionHasErrors('JenisPesanan.0');

        $this->post("{$alamat}/jenis-pesanan", ['Otomatis' => false, 'JenisPesanan' => ['Antar', 'BawaPulang'], 'JenisPesananBawaan' => 'BawaPulang'])
            ->assertSessionHasNoErrors()->assertRedirect();
        expect($k['Outlet']->refresh()->PengaturanKasir)->toBe(['JenisPesanan' => ['BawaPulang', 'Antar'], 'JenisPesananBawaan' => 'BawaPulang'])
            ->and(LogAudit::query()->where('Peristiwa', 'outlet.jenis-pesanan.ubah')->count())->toBe(1);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')
            ->assertJsonPath('Outlet.JenisPesanan', ['BawaPulang', 'Antar'])
            ->assertJsonPath('Outlet.JenisPesananBawaan', 'BawaPulang');

        // Daftar kosong mematikan pilihan walau tenant punya stasiun dapur.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        StasiunDapur::query()->create(['Nama' => 'Dapur']);
        $this->post("{$alamat}/jenis-pesanan", ['Otomatis' => false, 'JenisPesanan' => [], 'JenisPesananBawaan' => null])->assertSessionHasNoErrors();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertJsonPath('Outlet.JenisPesanan', []);

        $this->post("{$alamat}/jenis-pesanan", ['Otomatis' => true, 'JenisPesanan' => [], 'JenisPesananBawaan' => null])->assertSessionHasNoErrors();
        expect($k['Outlet']->refresh()->PengaturanKasir)->toBeNull();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertJsonPath('Outlet.JenisPesanan', ['MakanDiTempat', 'BawaPulang']);
    });

    it('K-8: data awal membawa mode kasir template & bawaannya (nilai tak dikenal dibuang)', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $dataAwal = fn () => $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();
        $dataAwal()->assertJsonPath('Outlet.ModeKasir', [])->assertJsonPath('Outlet.ModeKasirBawaan', null);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        OutletFitur::query()->create([
            'IdOutlet' => $k['Outlet']->Id,
            'KunciFitur' => OutletFitur::KUNCI_POS,
            'Aktif' => true,
            'Konfigurasi' => ['ModeKasir' => ['Cepat', 'Meja', 'Kapal'], 'ModeKasirDefault' => 'Meja'],
        ]);
        $dataAwal()->assertJsonPath('Outlet.ModeKasir', ['Cepat', 'Meja'])->assertJsonPath('Outlet.ModeKasirBawaan', 'Meja');

        // Bawaan yang tidak ada di daftar jatuh ke mode pertama.
        OutletFitur::query()->where('IdOutlet', $k['Outlet']->Id)
            ->update(['Konfigurasi' => json_encode(['ModeKasir' => ['Grosir', 'Retail'], 'ModeKasirDefault' => 'Meja'])]);
        $dataAwal()->assertJsonPath('Outlet.ModeKasir', ['Grosir', 'Retail'])->assertJsonPath('Outlet.ModeKasirBawaan', 'Grosir');
    });

    it('kasir tanpa izin outlet.kelola ditolak. Outlet tenant lain 404', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id);
        $this->post("/kelola/outlet/{$k['Outlet']->Uuid}/jenis-pesanan", ['Otomatis' => true, 'JenisPesanan' => []])->assertForbidden();

        $lain = BantuanKasir::Siapkan($this, 'Kopi Lain Banget');
        BantuanOrganisasi::Masuk($this, $lain['Pemilik'], $lain['Tenant']->Id);
        $this->post("/kelola/outlet/{$k['Outlet']->Uuid}/jenis-pesanan", ['Otomatis' => true, 'JenisPesanan' => []])->assertNotFound();
    });
});
