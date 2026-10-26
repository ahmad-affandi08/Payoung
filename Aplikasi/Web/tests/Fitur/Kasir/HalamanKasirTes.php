<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
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

describe('F-06 halaman back-office shift', function (): void {
    it('daftar & detail shift: ringkasan kas non-penjualan dan mutasi dengan jurnal. Tautan sumber jurnal membuka shift', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $shift = BantuanKasir::ItemBukaShift($k['Kasir'], '500000.00');
        $keluar = BantuanKasir::ItemMutasiKas($shift['Uuid'], $k['Kasir'], 'Keluar', '45000.00', $k['KategoriKeluar']);
        BantuanKasir::KirimRingkas($this, $k['Token'], [
            $shift,
            $keluar,
            BantuanKasir::ItemMutasiKas($shift['Uuid'], $k['Kasir'], 'Masuk', '20000.00', $k['KategoriMasuk']),
            BantuanKasir::ItemMutasiKas($shift['Uuid'], $k['Kasir'], 'Setoran', '300000.00', null),
        ]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

        $this->get('/kelola/kasir/shift')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Shift/Daftar')
            ->where('Shift.Meta.Total', 1)
            ->where('Shift.Data.0.Uuid', $shift['Uuid'])
            ->where('Shift.Data.0.NamaKasir', $k['Kasir']->Nama)
            ->where('Shift.Data.0.TotalMasuk', '20000.00')
            ->where('Shift.Data.0.TotalKeluar', '45000.00')
            ->where('Shift.Data.0.TotalSetoran', '300000.00')
            ->where('Shift.Data.0.KasNonPenjualan', '175000.00'));

        $this->get("/kelola/kasir/shift/{$shift['Uuid']}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Shift/Detail')
            ->where('Shift.KasAwal', '500000.00')
            ->has('MutasiKas', 3)
            ->where('MutasiKas.0.NamaKategori', 'Beli es batu & galon')
            ->where('MutasiKas.0.NomorJurnal', fn (?string $nomor): bool => is_string($nomor) && str_starts_with($nomor, 'JU/')));

        $this->get("/kelola/kasir/mutasi-kas/{$keluar['Uuid']}")->assertRedirect("/kelola/kasir/shift/{$shift['Uuid']}");
    });

    it('D-16 TabelData: URL yang sama melayani JSON {Data, Meta}; cari nama kasir, filter status/tanggal/tinjauan, urut & paginasi', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $perangkat2 = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Belakang');
        $pertama = BantuanKasir::ItemBukaShift($k['Kasir'], '500000.00', ['DibukaPada' => '2026-09-20T01:00:00Z']);
        $kedua = BantuanKasir::ItemBukaShift($k['Supervisor'], '750000.00', ['DibukaPada' => '2026-09-22T01:00:00Z']);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$pertama]);
        BantuanKasir::KirimRingkas($this, $perangkat2['Token'], [$kedua]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $json = fn (string $query) => $this->getJson('/kelola/kasir/shift'.$query)->assertOk();

        $semua = $json('');
        expect($semua->json('Meta'))->toBe(['Halaman' => 1, 'PerHalaman' => 25, 'Total' => 2, 'JumlahHalaman' => 1])
            ->and(array_column($semua->json('Data'), 'Uuid'))->toBe([$kedua['Uuid'], $pertama['Uuid']])
            ->and($semua->headers->get('Content-Type'))->toContain('application/json');

        expect(array_column($json('?urut=KasAwal')->json('Data'), 'Uuid'))->toBe([$pertama['Uuid'], $kedua['Uuid']])
            ->and(array_column($json('?cari='.urlencode(mb_substr($k['Supervisor']->Nama, 0, 5)))->json('Data'), 'Uuid'))->toBe([$kedua['Uuid']])
            ->and($json('?saring[TanggalBisnis]=2026-09-21..2026-09-30')->json('Meta.Total'))->toBe(1)
            ->and($json('?saring[Status]=Tertutup')->json('Meta.Total'))->toBe(0)
            ->and($json('?saring[PerluTinjauan]=1')->json('Meta.Total'))->toBe(0)
            ->and($json('?perHalaman=100&halaman=9')->json('Meta'))->toBe(['Halaman' => 1, 'PerHalaman' => 100, 'Total' => 2, 'JumlahHalaman' => 1]);

        // Kolom urut/saring di luar daftar putih diabaikan (tidak pernah masuk SQL); ukuran halaman tak dikenal = 25.
        expect(array_column($json('?urut=Id;DROP TABLE Shift,-Uuid&saring[IdTenant]=999&perHalaman=7')->json('Data'), 'Uuid'))
            ->toBe([$kedua['Uuid'], $pertama['Uuid']])
            ->and($json('?perHalaman=7')->json('Meta.PerHalaman'))->toBe(25);

        // Kunjungan Inertia tetap mendapat halaman beserta tabel awal sesuai query URL.
        $this->get('/kelola/kasir/shift?urut=KasAwal')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Shift/Daftar')
            ->where('Shift.Data.0.Uuid', $pertama['Uuid'])
            ->where('Shift.Meta.Total', 2)
            ->has('OpsiStatus'));

        // JSON juga dijaga izin: Kasir 403.
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->getJson('/kelola/kasir/shift')->assertForbidden();
    });

    it('izin & isolasi: Kasir 403. Shift tenant lain 404. Pengguna per outlet hanya melihat shift outletnya', function (): void {
        $a = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        $shiftA = BantuanKasir::ItemBukaShift($a['Kasir']);
        BantuanKasir::KirimRingkas($this, $a['Token'], [$shiftA]);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $cabang = BantuanJurnal::BuatOutlet();
        $perangkatCabang = BantuanPerangkat::BuatDanAktifkan($this, $a['Tenant']->Id, $cabang, 'Kasir Cabang');
        $shiftCabang = BantuanKasir::ItemBukaShift($a['Pemilik']);
        expect(BantuanKasir::KirimRingkas($this, $perangkatCabang['Token'], [$shiftCabang]))->toBe([['Diterima', null]]);

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/kasir/shift')->assertForbidden();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $manajerCabang = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        BantuanOrganisasi::Masuk($this, $manajerCabang, $a['Tenant']->Id);

        $this->get('/kelola/kasir/shift')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Shift.Meta.Total', 1)
            ->where('Shift.Data.0.Uuid', $shiftCabang['Uuid']));
        $this->get("/kelola/kasir/shift/{$shiftA['Uuid']}")->assertNotFound();

        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get("/kelola/kasir/shift/{$shiftA['Uuid']}")->assertNotFound();
    });
});

describe('F-06 kategori kas', function (): void {
    it('tambah, ubah, nonaktifkan: akun wajib sesuai jenis, nama unik per jenis, jenis tidak bisa diubah; diaudit', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Akuntan);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $beban = Akun::query()->where('Kode', '6-2000')->sole();
        $pendapatan = Akun::query()->where('Kode', '4-9000')->sole();

        $this->get('/kelola/kasir/kategori-kas')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/KategoriKas')
            ->has('Kategori', 2)
            ->where('OpsiAkun.Keluar', fn ($opsi): bool => collect($opsi)->contains('Uuid', $beban->Uuid) && ! collect($opsi)->contains('Uuid', $pendapatan->Uuid)));

        $this->post('/kelola/kasir/kategori-kas', ['Nama' => 'Bayar parkir motor', 'Jenis' => 'Keluar', 'UuidAkun' => $beban->Uuid])->assertRedirect('/kelola/kasir/kategori-kas');
        $this->post('/kelola/kasir/kategori-kas', ['Nama' => 'Bayar parkir motor', 'Jenis' => 'Keluar', 'UuidAkun' => $beban->Uuid])->assertSessionHasErrors('Nama');
        $this->post('/kelola/kasir/kategori-kas', ['Nama' => 'Salah akun', 'Jenis' => 'Keluar', 'UuidAkun' => $pendapatan->Uuid])->assertSessionHasErrors('UuidAkun');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $parkir = KategoriKas::query()->where('Nama', 'Bayar parkir motor')->sole();
        $this->put("/kelola/kasir/kategori-kas/{$parkir->Uuid}", ['Nama' => 'Parkir & retribusi', 'Jenis' => 'Masuk', 'UuidAkun' => $pendapatan->Uuid])->assertSessionHasErrors('Jenis');
        $this->put("/kelola/kasir/kategori-kas/{$parkir->Uuid}", ['Nama' => 'Parkir & retribusi', 'Jenis' => 'Keluar', 'UuidAkun' => $beban->Uuid])->assertRedirect();
        $this->put("/kelola/kasir/kategori-kas/{$parkir->Uuid}/status", ['Aktif' => false])->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $parkir->refresh();
        expect($parkir->Nama)->toBe('Parkir & retribusi')
            ->and($parkir->Aktif)->toBeFalse()
            ->and(LogAudit::query()->where('Peristiwa', 'kas.kategori.simpan')->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'kas.kategori.status')->count())->toBe(1);
    });

    it('izin akuntansi.kelola (Manajer Outlet 403). Kategori tenant lain 404', function (): void {
        $a = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $this->get('/kelola/kasir/kategori-kas')->assertForbidden();

        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->put("/kelola/kasir/kategori-kas/{$a['KategoriKeluar']->Uuid}/status", ['Aktif' => false])->assertNotFound();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($a['KategoriKeluar']->fresh()?->Aktif)->toBeTrue();
    });
});

describe('F-06 pengaturan kasir', function (): void {
    it('BR-06.4/BR-06.2 bawaan Rp 200.000 & shift bersama mati. Simpan tercatat audit. Izin outlet.kelola. Nilai tidak valid ditolak', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/kasir/pengaturan')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Admin);
        $this->get('/kelola/kasir/pengaturan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Pengaturan')
            ->where('BatasKasKeluar', '200000.00')
            ->where('ShiftBersama', false));

        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '-1', 'ShiftBersama' => true])->assertSessionHasErrors('BatasKasKeluar');
        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '1.5e6', 'ShiftBersama' => true])->assertSessionHasErrors('BatasKasKeluar');
        // Kosong tidak dianggap 0 (0 = setiap kas keluar/selisih butuh persetujuan): server meminta diisi.
        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '', 'ShiftBersama' => true, 'ToleransiSelisihKas' => '', 'BatasHariRetur' => null])
            ->assertSessionHasErrors([
                'BatasKasKeluar' => 'Isi batas kas keluar. Isi 0 agar setiap kas keluar butuh persetujuan.',
                'ToleransiSelisihKas' => 'Isi toleransi selisih kas. Isi 0 agar setiap selisih butuh persetujuan.',
                'BatasHariRetur',
            ]);
        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '500000', 'ShiftBersama' => true])->assertRedirect('/kelola/kasir/pengaturan');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pengaturan = app(PengaturanKasirTenant::class)->Ambil();
        expect($pengaturan->batasKasKeluar->KeString())->toBe('500000.00')
            ->and($pengaturan->shiftBersama)->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'kasir.pengaturan.ubah')->count())->toBe(1);

        $this->put('/kelola/kasir/pengaturan', ['BatasKasKeluar' => '500000.00', 'ShiftBersama' => true])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'kasir.pengaturan.ubah')->count())->toBe(1);
    });
});

describe('Tutup paksa shift dari back-office', function (): void {
    it('supervisor menutup shift yang tidak ditutup kasir: alasan wajib, tanpa selisih = tanpa jurnal, riwayat & audit tercatat, kasir bisa buka shift baru', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $shift = BantuanKasir::ItemBukaShift($k['Kasir'], '500000.00');
        BantuanKasir::KirimRingkas($this, $k['Token'], [$shift]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $url = "/kelola/kasir/shift/{$shift['Uuid']}";

        $this->get($url)->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.TutupPaksa', true)->where('Shift.Status', 'Terbuka'));

        $this->post("{$url}/tutup-paksa", ['Alasan' => 'ok'])->assertSessionHasErrors('Alasan');
        expect(Shift::query()->where('Uuid', $shift['Uuid'])->sole()->Status)->toBe(StatusShift::Terbuka);

        $this->post("{$url}/tutup-paksa", ['Alasan' => 'Kasir lupa menutup shift kemarin'])->assertSessionHasNoErrors();
        $tutup = Shift::query()->where('Uuid', $shift['Uuid'])->sole();
        expect($tutup->Status)->toBe(StatusShift::Tertutup)
            ->and($tutup->KasAktual)->toBe($tutup->KasSeharusnya)
            ->and($tutup->PerluTinjauan)->toBeTrue()
            ->and($tutup->AlasanSelisih)->toBe('Kasir lupa menutup shift kemarin')
            ->and(LogAudit::query()->where('Peristiwa', 'shift.tutup-paksa')->count())->toBe(1);

        // Sudah tertutup: tidak bisa ditutup paksa lagi.
        $this->post("{$url}/tutup-paksa", ['Alasan' => 'Coba lagi nanti ya'])->assertSessionHasErrors();
    });

    it('kas aktual yang diisi supervisor diposting sebagai selisih kas', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $shift = BantuanKasir::ItemBukaShift($k['Kasir'], '500000.00');
        BantuanKasir::KirimRingkas($this, $k['Token'], [$shift]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);

        $this->post("/kelola/kasir/shift/{$shift['Uuid']}/tutup-paksa", ['Alasan' => 'Perangkat rusak, uang dihitung manual', 'KasAktual' => '490000'])->assertSessionHasNoErrors();

        $tutup = Shift::query()->where('Uuid', $shift['Uuid'])->sole();
        expect((string) $tutup->Selisih)->toBe('-10000.00');
    });

    it('kasir biasa tidak boleh menutup paksa (403) dan shift tenant lain 404', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $shift = BantuanKasir::ItemBukaShift($k['Kasir'], '500000.00');
        BantuanKasir::KirimRingkas($this, $k['Token'], [$shift]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->post("/kelola/kasir/shift/{$shift['Uuid']}/tutup-paksa", ['Alasan' => 'Tidak berwenang sama sekali'])->assertForbidden();

        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $this->post("/kelola/kasir/shift/{$shift['Uuid']}/tutup-paksa", ['Alasan' => 'Shift milik tenant lain'])->assertSessionHasErrors();
        expect(Shift::query()->withoutGlobalScopes()->where('Uuid', $shift['Uuid'])->sole()->Status)->toBe(StatusShift::Terbuka);
    });
});
