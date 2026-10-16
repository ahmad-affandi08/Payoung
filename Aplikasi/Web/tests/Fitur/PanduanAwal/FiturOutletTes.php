<?php

declare(strict_types=1);

use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OutletFitur;
use App\Domain\Tenant\Model\OverrideTenant;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('BR-01.3: modul template per outlet (OutletFitur) dibatasi paket', function (): void {
    it('OutletFitur = kunci fitur template (aktif); fitur efektif = paket ∩ OutletFitur', function (): void {
        $versi = BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant(kodePaket: 'GRATIS');

        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        $baris = OutletFitur::query()->where('IdOutlet', $outlet->Id)->get();
        expect($baris->pluck('KunciFitur')->sort()->values()->all())->toBe(collect($versi->Isi['KunciFitur'])->sort()->values()->all())
            ->and($baris->every(fn (OutletFitur $fitur) => $fitur->Aktif))->toBeTrue();

        $pemeriksa = app(PemeriksaFiturTenant::class);
        // Paket GRATIS tidak memuat KDS walau template kafe mengaktifkannya.
        expect($pemeriksa->CekAktifDiOutlet($tenant->Id, $outlet->Id, 'pos.kds'))->toBeFalse()
            ->and($pemeriksa->CekAktifDiOutlet($tenant->Id, $outlet->Id, 'pos.retail'))->toBeTrue();

        OverrideTenant::query()->create([
            'IdTenant' => $tenant->Id,
            'Jenis' => JenisOverride::Fitur,
            'Kunci' => 'pos.kds',
            'BerakhirPada' => now()->addDays(14),
            'Alasan' => 'Uji coba KDS untuk kafe baru',
            'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);

        expect($pemeriksa->CekAktifDiOutlet($tenant->Id, $outlet->Id, 'pos.kds'))->toBeTrue();
    });

    it('fitur paket di luar template tidak aktif di outlet bertemplate, tetapi outlet tanpa baris OutletFitur tidak dibatasi', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant(kodePaket: 'STARTER');
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        $outletLain = Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => 'SLO2', 'Nama' => 'Kopi Nusantara Cabang Solo Baru']);

        $pemeriksa = app(PemeriksaFiturTenant::class);
        expect($pemeriksa->CekAktifDiOutlet($tenant->Id, $outlet->Id, 'struk.tanpa-watermark'))->toBeFalse()
            ->and($pemeriksa->CekAktifDiOutlet($tenant->Id, $outletLain->Id, 'struk.tanpa-watermark'))->toBeTrue()
            ->and($pemeriksa->CekAktif($tenant->Id, 'struk.tanpa-watermark'))->toBeTrue();
    });

    it('konfigurasi mode kasir template disimpan di fitur pos.retail dan tidak ditimpa penerapan berikutnya', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        BantuanPanduanAwal::Terapkan($outlet->fresh() ?? $outlet, 'RTL-GEN');

        $pos = OutletFitur::query()->where('IdOutlet', $outlet->Id)->where('KunciFitur', 'pos.retail')->sole();
        expect($pos->Konfigurasi)->toBe(['ModeKasir' => ['Cepat', 'Meja'], 'ModeKasirDefault' => 'Cepat']);
    });

    it('halaman Sektor menandai fitur yang tidak ada di paket tenant', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant(kodePaket: 'GRATIS');

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/sektor')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/PanduanAwal/Sektor')
                ->where('Template.0.Kode', 'FNB-CAF')
                ->where('Template.0.JumlahAkun', 53) // F-21: + PPh Dibayar Dimuka 1-1750; CRM-06: + Saldo Gift Card; E8: + Beban Piutang Tak Tertagih; v3.61: + Kliring Tukar Barang; v3.42: + Giro Mundur Diterima ->where('Template.0.JumlahAkun', 47) Hutang Giro
                ->where('Template.0.ModeKasir.0', ['Nilai' => 'Cepat', 'Label' => 'Cepat (tombol produk besar)'])
                ->where('Template.0.Fitur', fn ($fitur) => collect($fitur)->firstWhere('Kunci', 'pos.kds')['TersediaDiPaket'] === false
                    && collect($fitur)->firstWhere('Kunci', 'pos.retail')['TersediaDiPaket'] === true)
                ->where('TemplateTerpilih', null)
                ->where('NamaPaket', 'Gratis')
                ->has('Progres.Langkah', 6));
    });
});
