<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\SaldoNormal;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Akun tenant konteks dari kode. */
function AkunKode(string $kode): Akun
{
    return Akun::query()->where('Kode', $kode)->sole();
}

/** Pesan galat sesi pertama untuk bidang (galat aturan bisnis dirender sebagai galat validasi). */
function PesanGalatSesi(TestResponse $respons, string $bidang = 'Umum'): string
{
    $respons->assertSessionHasErrors($bidang);
    $galat = session('errors');

    return $galat instanceof ViewErrorBag ? (string) $galat->first($bidang) : '';
}

describe('F-13a bagan akun (FIN-01, PRD "Rincian F-13a")', function (): void {
    it('daftar pohon: induk lalu anak, kedalaman, tipe, saldo normal, kas/bank, tipe terkunci setelah jurnal, peran dipetakan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());
        $bank = AkunKode('1-1200');
        Akun::query()->create(['Kode' => '1-1210', 'Nama' => 'Bank BRI Cabang Solo Baru Giro Operasional', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit, 'IdInduk' => $bank->Id, 'KasBank' => true]);

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan)->get('/kelola/akuntansi/akun')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Akun/Daftar')
                ->where('Izin.Kelola', true)
                ->where('Akun', function ($akun): bool {
                    $daftar = collect($akun)->values();
                    $iBank = $daftar->search(fn ($a) => $a['Kode'] === '1-1200');
                    $anak = $daftar[$iBank + 1];
                    $persediaan = $daftar->firstWhere('Kode', '1-1500');
                    $diskon = $daftar->firstWhere('Kode', '4-1100');
                    $modal = $daftar->firstWhere('Kode', '3-1000');

                    return $anak['Kode'] === '1-1210' && $anak['Kedalaman'] === 1 && $anak['KodeInduk'] === '1-1200' && $anak['KasBank'] === true
                        && $daftar[$iBank]['PunyaAnak'] === true && $daftar[$iBank]['BisaDihapus'] === false && $daftar[$iBank]['KasBank'] === true
                        && $persediaan['AdaJurnal'] === true && $persediaan['PeranDipetakan'] === ['Persediaan barang dagang']
                        && $diskon['Kontra'] === true && $diskon['SaldoNormal'] === 'Debit'
                        && $modal['BisaDihapus'] === true && $modal['PeranDipetakan'] === [];
                })
                ->has('OpsiTipe', 6));
    });

    it('tambah akun anak: mewarisi tipe induk, kode diawali digit tipe & unik per tenant, kas/bank hanya aset; diaudit', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $bank = AkunKode('1-1200');
        $isi = ['Kode' => '1-1210', 'Nama' => 'Bank Mandiri KCP Solo Baru', 'Jenis' => 'Aset', 'Kontra' => false, 'KasBank' => true, 'UuidInduk' => $bank->Uuid];

        $this->post('/kelola/akuntansi/akun', $isi)->assertRedirect('/kelola/akuntansi/akun');
        $this->post('/kelola/akuntansi/akun', [...$isi, 'Nama' => 'Kembar'])->assertSessionHasErrors('Kode');
        $this->post('/kelola/akuntansi/akun', [...$isi, 'Kode' => '6-2100', 'Jenis' => 'Beban'])->assertSessionHasErrors('Jenis');
        $this->post('/kelola/akuntansi/akun', [...$isi, 'Kode' => '6-2100', 'Jenis' => 'Beban', 'UuidInduk' => null, 'KasBank' => false])->assertRedirect();
        $this->post('/kelola/akuntansi/akun', [...$isi, 'Kode' => '2-2100', 'Jenis' => 'Beban', 'UuidInduk' => null, 'KasBank' => false])->assertSessionHasErrors('Kode');
        $this->post('/kelola/akuntansi/akun', [...$isi, 'Kode' => '6-2200', 'Jenis' => 'Beban', 'UuidInduk' => null, 'KasBank' => true])->assertSessionHasErrors('KasBank');
        $this->post('/kelola/akuntansi/akun', [...$isi, 'Kode' => '11100'])->assertSessionHasErrors('Kode');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $anak = AkunKode('1-1210');
        expect($anak->IdInduk)->toBe($bank->Id)
            ->and($anak->Jenis)->toBe(TipeAkun::Aset)
            ->and($anak->SaldoNormal)->toBe(SaldoNormal::Debit)
            ->and($anak->KasBank)->toBeTrue()
            ->and($anak->Sistem)->toBeFalse()
            ->and(AkunKode('6-2100')->SaldoNormal)->toBe(SaldoNormal::Debit)
            ->and(LogAudit::query()->where('Peristiwa', 'akun.tambah')->count())->toBe(2);
    });

    it('akun kontra memakai saldo normal kebalikan tipenya', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->post('/kelola/akuntansi/akun', ['Kode' => '4-1150', 'Nama' => 'Potongan Penjualan Grosir', 'Jenis' => 'Pendapatan', 'Kontra' => true])->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(AkunKode('4-1150')->SaldoNormal)->toBe(SaldoNormal::Debit)->and(AkunKode('4-1150')->CekKontra())->toBeTrue();
    });

    it('ubah nama selalu boleh. Kode & tipe terkunci setelah ada jurnal. Tipe harus cocok dengan peran yang dipetakan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $persediaan = AkunKode('1-1500');
        $modal = AkunKode('3-1000');
        $hpp = AkunKode('5-1200');

        $this->put("/kelola/akuntansi/akun/{$persediaan->Uuid}", ['Kode' => '1-1500', 'Nama' => 'Persediaan Barang Dagang Toko', 'Jenis' => 'Aset'])->assertRedirect();
        $this->put("/kelola/akuntansi/akun/{$persediaan->Uuid}", ['Kode' => '1-1501', 'Nama' => 'Persediaan Barang Dagang Toko', 'Jenis' => 'Aset'])->assertSessionHasErrors('Kode');
        $this->put("/kelola/akuntansi/akun/{$persediaan->Uuid}", ['Kode' => '6-1500', 'Nama' => 'Persediaan', 'Jenis' => 'Beban'])->assertSessionHasErrors('Jenis');
        // Tanpa jurnal tipe boleh diubah (kode ikut digit tipe), kecuali akun dipetakan ke peran bertipe lain.
        $this->put("/kelola/akuntansi/akun/{$modal->Uuid}", ['Kode' => '2-5000', 'Nama' => 'Pinjaman Pemilik', 'Jenis' => 'Kewajiban'])->assertRedirect();
        $this->put("/kelola/akuntansi/akun/{$hpp->Uuid}", ['Kode' => '6-1200', 'Nama' => 'Susut', 'Jenis' => 'Beban'])->assertSessionHasErrors('Jenis');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($persediaan->fresh()?->Nama)->toBe('Persediaan Barang Dagang Toko')
            ->and($persediaan->fresh()?->Kode)->toBe('1-1500')
            ->and($modal->fresh()?->Jenis)->toBe(TipeAkun::Kewajiban)
            ->and($modal->fresh()?->SaldoNormal)->toBe(SaldoNormal::Kredit)
            ->and($hpp->fresh()?->Jenis)->toBe(TipeAkun::Hpp)
            ->and(LogAudit::query()->where('Peristiwa', 'akun.ubah')->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'akun.ubah')->orderBy('Id')->first()?->NilaiLama)->toMatchArray(['Nama' => 'Persediaan Barang Dagang']);
    });

    it('akun peran kas/bank tetap bertanda kas/bank', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $bank = AkunKode('1-1200');

        $this->put("/kelola/akuntansi/akun/{$bank->Uuid}", ['Kode' => '1-1200', 'Nama' => 'Bank', 'Jenis' => 'Aset', 'KasBank' => false])->assertSessionHasErrors('KasBank');
    });

    it('hapus hanya akun yang belum dipakai: jurnal, pemetaan, anak, kategori kas, metode pembayaran ditolak (nonaktifkan saja)', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Akuntan);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Induk tanpa pemetaan peran (1-2000 Aset Tetap kini dipetakan FIN-10, jadi tertolak lebih dulu karena pemetaan).
        $induk = Akun::query()->create(['Kode' => '1-8000', 'Nama' => 'Aset Lain-lain', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit]);
        Akun::query()->create(['Kode' => '1-8010', 'Nama' => 'Mesin Kasir', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit, 'IdInduk' => $induk->Id]);

        BantuanKasir::BuatKategori('Cetak brosur promo', JenisKategoriKas::Keluar, '6-4000');

        foreach (['1-1500' => 'sudah punya jurnal', '1-1400' => 'dipetakan untuk Piutang usaha', '1-8000' => 'punya akun anak', '1-2000' => 'dipetakan untuk Aset tetap (harga perolehan)', '6-4000' => 'dipakai kategori kas "Cetak brosur promo"', '1-1100' => 'dipetakan untuk Kas outlet'] as $kode => $alasan) {
            expect(PesanGalatSesi($this->delete('/kelola/akuntansi/akun/'.AkunKode($kode)->Uuid)))->toContain($alasan)->toContain('Nonaktifkan');
            BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        }

        // Akun yang dirujuk metode pembayaran (bukan peran) juga ditolak.
        $bca = Akun::query()->create(['Kode' => '1-1220', 'Nama' => 'Bank BCA', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit, 'KasBank' => true]);
        MetodePembayaran::query()->create(['Jenis' => JenisMetodePembayaran::Transfer, 'Nama' => 'Transfer BRI', 'Aktif' => true, 'Urutan' => 10, 'IdAkun' => $bca->Id]);
        expect(PesanGalatSesi($this->delete("/kelola/akuntansi/akun/{$bca->Uuid}")))->toContain('dipakai metode pembayaran "Transfer BRI"');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $modal = AkunKode('3-1000');
        $this->delete("/kelola/akuntansi/akun/{$modal->Uuid}")->assertRedirect('/kelola/akuntansi/akun');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Akun::query()->where('Kode', '3-1000')->exists())->toBeFalse()
            ->and(Akun::query()->where('Kode', '1-1500')->exists())->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'akun.hapus')->count())->toBe(1);
    });

    it('nonaktifkan: akun yang masih dipetakan ditolak. Akun nonaktif tidak ditawarkan untuk kategori kas', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Akuntan);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kas = AkunKode('1-1100');
        $beban = AkunKode('6-4000');

        expect(PesanGalatSesi($this->put("/kelola/akuntansi/akun/{$kas->Uuid}/status", ['Aktif' => false])))->toContain('masih dipetakan untuk Kas outlet');
        $this->put("/kelola/akuntansi/akun/{$beban->Uuid}/status", ['Aktif' => false])->assertRedirect();
        $this->get('/kelola/kasir/kategori-kas')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('OpsiAkun.Keluar', fn ($opsi): bool => ! collect($opsi)->contains('Uuid', $beban->Uuid)));
        $this->post('/kelola/kasir/kategori-kas', ['Nama' => 'Promosi brosur', 'Jenis' => 'Keluar', 'UuidAkun' => $beban->Uuid])->assertSessionHasErrors('UuidAkun');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($kas->fresh()?->Aktif)->toBeTrue()
            ->and($beban->fresh()?->Aktif)->toBeFalse()
            ->and(LogAudit::query()->where('Peristiwa', 'akun.status')->count())->toBe(1);
    });

    it('izin: Kasir 403 melihat; Manajer Outlet tanpa akuntansi.kelola 403 mengubah. Akun tenant lain 404', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        $modalA = AkunKode('3-1000');
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/akuntansi/akun')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)
            ->post('/kelola/akuntansi/akun', ['Kode' => '6-9100', 'Nama' => 'Beban Lain', 'Jenis' => 'Beban'])->assertForbidden();

        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->put("/kelola/akuntansi/akun/{$modalA->Uuid}", ['Kode' => '3-1000', 'Nama' => 'Dibajak', 'Jenis' => 'Ekuitas'])->assertNotFound();
        $this->delete("/kelola/akuntansi/akun/{$modalA->Uuid}")->assertNotFound();
        $this->get('/kelola/akuntansi/akun')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Akun', fn ($akun): bool => ! collect($akun)->contains('Uuid', $modalA->Uuid)));

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($modalA->fresh()?->Nama)->toBe('Modal Pemilik');
    });
});

describe('PeranAkun::PeriksaAkun satu sumber aturan BR-P03.3', function (): void {
    it('tipe & kontra sesuai peran', function (): void {
        expect(PeranAkun::Bank->PeriksaAkun(TipeAkun::Aset, false, '1-1200'))->toBe([])
            ->and(PeranAkun::Bank->PeriksaAkun(TipeAkun::Beban, false, '6-1000'))->toBe(['Peran "Bank" harus memakai akun Aset, bukan Beban (6-1000).'])
            ->and(PeranAkun::DiskonPenjualan->PeriksaAkun(TipeAkun::Pendapatan, false, '4-1000'))->toBe(['Peran "Diskon penjualan" harus memakai akun kontra (4-1000 bukan akun kontra).'])
            ->and(PeranAkun::Penjualan->PeriksaAkun(TipeAkun::Pendapatan, true, '4-1100'))->toBe(['Peran "Penjualan" tidak boleh memakai akun kontra (4-1100).']);
    });
});
