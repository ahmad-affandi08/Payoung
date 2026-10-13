<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\SimpanTransaksiKasBank;
use App\Domain\Akuntansi\Data\DataTransaksiKasBank;
use App\Domain\Akuntansi\Enum\JenisTransaksiKasBank;
use App\Domain\Akuntansi\Kueri\AnggaranVsRealisasi;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Anggaran;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Transaksi kas & bank langsung lewat Aksi (tenant konteks) sebagai realisasi uji. */
function CatatRealisasiAnggaran(string $jenis, string $tanggal, string $kodeSumber, string $kodeTujuan, string $jumlah, ?int $idOutlet = null): void
{
    app(SimpanTransaksiKasBank::class)->Jalankan(new DataTransaksiKasBank(
        JenisTransaksiKasBank::from($jenis),
        CarbonImmutable::parse($tanggal),
        $idOutlet,
        (string) Akun::query()->where('Kode', $kodeSumber)->value('Uuid'),
        (string) Akun::query()->where('Kode', $kodeTujuan)->value('Uuid'),
        Uang::Dari($jumlah),
        'Uji anggaran',
        null,
        null,
    ));
}

/** Satu baris isian anggaran: angka per bulan (indeks 0 = Januari), bulan yang tidak disebut kosong. */
function BarisAnggaran(string $kodeAkun, array $perBulan): array
{
    $isi = array_fill(0, 12, '');

    foreach ($perBulan as $bulan => $jumlah) {
        $isi[$bulan - 1] = $jumlah;
    }

    return ['UuidAkun' => (string) Akun::query()->where('Kode', $kodeAkun)->value('Uuid'), 'PerBulan' => $isi];
}

describe('F8 anggaran vs realisasi (FIN-11)', function (): void {
    it('menyimpan anggaran lalu laporan membandingkannya dengan jurnal: selisih, capaian, status, dan laba', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        CatatRealisasiAnggaran('Pengeluaran', '2026-09-10', '1-1100', '6-2000', '1250000.00');
        CatatRealisasiAnggaran('Penerimaan', '2026-09-12', '4-1000', '1-1100', '3000000.00');
        CatatRealisasiAnggaran('Pengeluaran', '2026-10-02', '1-1100', '6-2000', '400000.00');
        $akuntan = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $akuntan->put('/kelola/akuntansi/anggaran', [
            'Tahun' => 2026,
            'Baris' => [
                BarisAnggaran('6-2000', [9 => '1000000', 10 => '2000000']),
                BarisAnggaran('4-1000', [9 => '5000000']),
            ],
        ])->assertRedirect('/kelola/akuntansi/anggaran/susun?tahun=2026&outlet=');

        expect(Anggaran::query()->count())->toBe(3);
        $logAudit = LogAudit::query()->where('Peristiwa', 'anggaran.simpan')->sole();
        expect($logAudit->NilaiBaru['Dibuat'])->toBe(3);

        $akuntan->get('/kelola/akuntansi/anggaran?tahun=2026&bulanDari=9&bulanSampai=9')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Anggaran/Laporan')
                ->where('Laporan.AdaAnggaran', true)
                ->where('Laporan.Periode.Dari', '2026-09-01')
                ->where('Laporan.Periode.Sampai', '2026-09-30')
                ->where('Laporan.Ringkasan.LabaBersih', ['Anggaran' => '4000000.00', 'Realisasi' => '1750000.00'])
                ->where('Laporan.Baris', function ($baris): bool {
                    $baris = collect($baris);
                    $beban = $baris->firstWhere('Kode', '6-2000');
                    $pendapatan = $baris->firstWhere('Kode', '4-1000');
                    $laba = $baris->firstWhere('Id', 'Laba|LabaBersih');

                    return $beban['Anggaran'] === '1000000.00' && $beban['Realisasi'] === '1250000.00' && $beban['Selisih'] === '250000.00'
                        && $beban['Capaian'] === '125.00' && $beban['Status'] === 'Buruk'
                        && $pendapatan['Anggaran'] === '5000000.00' && $pendapatan['Realisasi'] === '3000000.00' && $pendapatan['Selisih'] === '-2000000.00'
                        && $pendapatan['Capaian'] === '60.00' && $pendapatan['Status'] === 'Buruk'
                        && $laba['Selisih'] === '-2250000.00' && $laba['Status'] === 'Buruk';
                }));

        // Rentang September–Oktober menjumlah anggaran dan realisasi kedua bulan.
        $akuntan->get('/kelola/akuntansi/anggaran?tahun=2026&bulanDari=9&bulanSampai=10')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.Baris', fn ($baris): bool => collect($baris)->firstWhere('Kode', '6-2000')['Anggaran'] === '3000000.00'
                && collect($baris)->firstWhere('Kode', '6-2000')['Realisasi'] === '1650000.00'
                && collect($baris)->firstWhere('Kode', '6-2000')['Status'] === 'Baik'));
    });

    it('menyimpan ulang mengubah dan menghapus angka; yang tidak berubah tidak ditulis dan tidak diaudit', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akuntan = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [1 => '500000', 2 => '500000'])]])->assertRedirect();

        // Persis sama: tidak ada perubahan, tidak ada audit baru.
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [1 => '500000', 2 => '500000'])]])->assertRedirect();
        expect(LogAudit::query()->where('Peristiwa', 'anggaran.simpan')->count())->toBe(1);

        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [1 => '750000.50', 2 => '0'])]])->assertRedirect();
        $baris = Anggaran::query()->get();
        expect($baris)->toHaveCount(1)
            ->and($baris->first()->Bulan)->toBe(1)
            ->and($baris->first()->Jumlah)->toBe('750000.50');
        $audit = LogAudit::query()->where('Peristiwa', 'anggaran.simpan')->latest('Id')->first();
        expect($audit->NilaiBaru['Diubah'])->toBe(1)->and($audit->NilaiBaru['Dihapus'])->toBe(1);
    });

    it('menolak: akun bukan pendapatan/HPP/beban, akun ganda, angka negatif atau bukan Rupiah, tahun di luar rentang', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akuntan = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('1-1100', [1 => '1000'])]])
            ->assertSessionHasErrors('Umum');
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [1 => '1000']), BarisAnggaran('6-2000', [2 => '1000'])]])
            ->assertSessionHasErrors('Umum');
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [1 => '-5'])]])
            ->assertSessionHasErrors('Baris.0.PerBulan.0');
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [1 => '12,5'])]])
            ->assertSessionHasErrors('Baris.0.PerBulan.0');
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 1999, 'Baris' => [BarisAnggaran('6-2000', [1 => '1000'])]])
            ->assertSessionHasErrors('Tahun');
        expect(Anggaran::query()->count())->toBe(0);
    });

    it('cakupan outlet terpisah dari seluruh usaha; laporan memakai anggaran sesuai outlet dan realisasi outlet itu', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        CatatRealisasiAnggaran('Pengeluaran', '2026-09-05', '1-1100', '6-2000', '300000.00', $t['Outlet']->Id);
        CatatRealisasiAnggaran('Pengeluaran', '2026-09-06', '1-1100', '6-2000', '700000.00', $solo->Id);
        $akuntan = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [9 => '2000000'])]])->assertRedirect();
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Outlet' => $solo->Uuid, 'Baris' => [BarisAnggaran('6-2000', [9 => '600000'])]])->assertRedirect();
        expect(Anggaran::query()->count())->toBe(2);

        $akuntan->get('/kelola/akuntansi/anggaran?tahun=2026&bulanDari=9&bulanSampai=9')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.Baris', fn ($baris): bool => collect($baris)->firstWhere('Kode', '6-2000')['Anggaran'] === '2000000.00'
                && collect($baris)->firstWhere('Kode', '6-2000')['Realisasi'] === '1000000.00'));
        $akuntan->get("/kelola/akuntansi/anggaran?tahun=2026&bulanDari=9&bulanSampai=9&outlet={$solo->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Saring.Outlet', $solo->Uuid)
            ->where('Laporan.Baris', fn ($baris): bool => collect($baris)->firstWhere('Kode', '6-2000')['Anggaran'] === '600000.00'
                && collect($baris)->firstWhere('Kode', '6-2000')['Realisasi'] === '700000.00'
                && collect($baris)->firstWhere('Kode', '6-2000')['Status'] === 'Buruk'));
        $akuntan->get("/kelola/akuntansi/anggaran/susun?tahun=2026&outlet={$solo->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/Anggaran/Susun')
            ->where('Akun', fn ($akun): bool => collect($akun)->firstWhere('Kode', '6-2000')['PerBulan'][8] === '600000.00'));
    });

    it('salin dari tahun lalu: mengisi tahun kosong, menolak menimpa, dan menolak bila tahun asal kosong', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $akuntan = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2025, 'Baris' => [BarisAnggaran('6-2000', [1 => '100000', 12 => '200000'])]])->assertRedirect();

        $akuntan->post('/kelola/akuntansi/anggaran/salin?tahun=2026')->assertRedirect('/kelola/akuntansi/anggaran/susun?tahun=2026&outlet=');
        expect(Anggaran::query()->where('Tahun', 2026)->orderBy('Bulan')->pluck('Jumlah')->all())->toBe(['100000.00', '200000.00']);

        $akuntan->post('/kelola/akuntansi/anggaran/salin?tahun=2026')->assertSessionHasErrors('Umum');
        $akuntan->post('/kelola/akuntansi/anggaran/salin?tahun=2030')->assertSessionHasErrors('Umum');
        expect(Anggaran::query()->count())->toBe(4);
    });

    it('pelaku berbatas outlet: tanpa cakupan seluruh usaha, laporan otomatis ke outlet aksesnya, outlet lain 404', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        $akuntan = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Akuntan, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $akuntan->Id, 'IdPeran' => BantuanOrganisasi::Peran($t['Tenant']->Id, PeranTenantBawaan::Akuntan)->Id]);
        BantuanOrganisasi::Masuk($this, $akuntan, $t['Tenant']->Id);

        $this->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [1 => '1000'])]])->assertSessionHasErrors('Umum');
        $this->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Outlet' => $solo->Uuid, 'Baris' => [BarisAnggaran('6-2000', [1 => '1000'])]])->assertRedirect();
        $this->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Outlet' => $t['Outlet']->Uuid, 'Baris' => [BarisAnggaran('6-2000', [1 => '1000'])]])->assertNotFound();
        expect(Anggaran::query()->pluck('IdOutlet')->all())->toBe([$solo->Id]);

        $this->get('/kelola/akuntansi/anggaran?tahun=2026&bulanDari=1&bulanSampai=1')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Saring.Outlet', $solo->Uuid)
            ->where('SeluruhUsahaBoleh', false));
        $this->get("/kelola/akuntansi/anggaran?tahun=2026&outlet={$t['Outlet']->Uuid}")->assertNotFound();
    });

    it('izin dan isolasi tenant: kasir ditolak, anggaran dan akun tenant lain tidak terlihat', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        $uuidAkunA = (string) Akun::query()->where('Kode', '6-2000')->value('Uuid');
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Akuntan)
            ->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [1 => '1000'])]])->assertRedirect();

        $kasir = BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);

        foreach (['get' => ['/kelola/akuntansi/anggaran', '/kelola/akuntansi/anggaran/susun', '/kelola/akuntansi/anggaran/ekspor'], 'put' => ['/kelola/akuntansi/anggaran'], 'post' => ['/kelola/akuntansi/anggaran/salin']] as $metode => $alamat) {
            foreach ($alamat as $satu) {
                $kasir->{$metode}($satu)->assertForbidden();
            }
        }

        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        $akuntanB = BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $akuntanB->get('/kelola/akuntansi/anggaran?tahun=2026&bulanDari=1&bulanSampai=12')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Laporan.AdaAnggaran', false));
        $akuntanB->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [['UuidAkun' => $uuidAkunA, 'PerBulan' => array_fill(0, 12, '1000')]]])->assertSessionHasErrors('Umum');
        expect(Anggaran::query()->count())->toBe(0);
    });

    it('ekspor D-43: kop, selisih, capaian, dan status sesuai laporan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        CatatRealisasiAnggaran('Pengeluaran', '2026-09-10', '1-1100', '6-2000', '1250000.00');
        $akuntan = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $akuntan->put('/kelola/akuntansi/anggaran', ['Tahun' => 2026, 'Baris' => [BarisAnggaran('6-2000', [9 => '1000000'])]])->assertRedirect();

        $csv = $akuntan->get('/kelola/akuntansi/anggaran/ekspor?tahun=2026&bulanDari=9&bulanSampai=9&format=csv')->assertOk()->streamedContent();

        expect($csv)->toContain('Kode,Keterangan,Anggaran,Realisasi,Selisih,"Capaian (%)",Status')
            ->toContain('6-2000,"Beban Sewa, Listrik, Air, Internet",1000000.00,1250000.00,250000.00,"125,00",Buruk')
            ->toContain('"Laba bersih",-1000000.00,-1250000.00,-250000.00,,Buruk');
    });

    it('capaian dan status dihitung eksak: persen dua desimal, anggaran nol tanpa capaian, arah biaya vs pendapatan', function (): void {
        expect(AnggaranVsRealisasi::HitungCapaian(Uang::Dari('3000000'), Uang::Dari('1000000')))->toBe('33.33')
            ->and(AnggaranVsRealisasi::HitungCapaian(Uang::Dari('3'), Uang::Dari('2')))->toBe('66.67')
            ->and(AnggaranVsRealisasi::HitungCapaian(Uang::Nol(), Uang::Dari('500')))->toBeNull()
            ->and(AnggaranVsRealisasi::HitungCapaian(Uang::Dari('-1000'), Uang::Dari('-1250')))->toBeNull()
            ->and(AnggaranVsRealisasi::TentukanStatus(Uang::Dari('10'), false))->toBe('Baik')
            ->and(AnggaranVsRealisasi::TentukanStatus(Uang::Dari('-10'), false))->toBe('Buruk')
            ->and(AnggaranVsRealisasi::TentukanStatus(Uang::Dari('10'), true))->toBe('Buruk')
            ->and(AnggaranVsRealisasi::TentukanStatus(Uang::Dari('-10'), true))->toBe('Baik')
            ->and(AnggaranVsRealisasi::TentukanStatus(Uang::Nol(), true))->toBe('Sesuai');
    });
});
