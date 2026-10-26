<?php

declare(strict_types=1);

use App\Domain\Katalog\Kontrak\PenyediaHppBahan;
use App\Domain\Katalog\Resep\Kueri\HppResep;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Kueri\HppBahanDariSaldo;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanLaporan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a BR-03.5 PenyediaHppBahan dari SaldoStok (DesainF05a C.8)', function (): void {
    it('provider mengikat PenyediaHppBahan ke HppBahanDariSaldo (menimpa ikatan bawaan F-03)', function (): void {
        expect(app(PenyediaHppBahan::class))->toBeInstanceOf(HppBahanDariSaldo::class);
    });

    it('per lokasi stok = HppRataRata lokasi itu. Lokasi tanpa saldo = null', function (): void {
        $t = BantuanPersediaan::SiapkanTenant('Kedai Kopi Senja Temaram');
        $gula = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['BahanBaku'];
        $dapur = BantuanPersediaan::BuatGudang($t['Outlet'], 'Dapur Belakang');
        BantuanLaporan::CatatMutasi($gula, $t['Gudang'], '12.5000', '206250.00');

        $penyedia = app(PenyediaHppBahan::class);

        expect((string) $penyedia->AmbilHppSatuan($gula->Id, $t['Gudang']->Id))->toBe('16500.000000')
            ->and($penyedia->AmbilHppSatuan($gula->Id, $dapur->Id))->toBeNull();
    });

    it('rata-rata tenant = Σ nilai ÷ Σ jumlah lokasi berstok positif (skala 6, HalfUp). Lokasi minus/nol diabaikan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant('Kedai Kopi Senja Temaram', stokBolehMinus: true);
        $gula = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['BahanBaku'];
        $dapur = BantuanPersediaan::BuatGudang($t['Outlet'], 'Dapur Belakang');
        $bar = BantuanPersediaan::BuatGudang($t['Outlet'], 'Bar Depan');

        BantuanLaporan::CatatMutasi($gula, $t['Gudang'], '10.0000', '150000.00');
        BantuanLaporan::CatatMutasi($gula, $dapur, '3.0000', '50000.00');
        // Lokasi minus (HPP diketahui, tetapi jumlah < 0) tidak ikut dirata-rata.
        BantuanLaporan::CatatMutasi($gula, $bar, '2.0000', '40000.00');
        BantuanLaporan::CatatMutasi($gula, $bar, '-5.0000', '-100000.00', jenis: JenisMutasi::Penjualan, referensi: JenisReferensiMutasi::Penjualan);

        // (150000 + 50000) ÷ 13 = 15384.615384615… → 15384.615385
        expect((string) app(PenyediaHppBahan::class)->AmbilHppSatuan($gula->Id, null))->toBe('15384.615385')
            ->and(PemeriksaInvarian::PeriksaSaldoStok($t['Tenant']->Id))->toBe([])
            ->and(PemeriksaInvarian::PeriksaRantaiMutasi($t['Tenant']->Id))->toBe([]);
    });

    it('tanpa lokasi berstok positif = HppRataRata bukan null terakhir, tanpa saldo sama sekali = null', function (): void {
        $t = BantuanPersediaan::SiapkanTenant('Kedai Kopi Senja Temaram');
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $gula = $produk['BahanBaku'];

        expect(app(PenyediaHppBahan::class)->AmbilHppSatuan($gula->Id, null))->toBeNull();

        BantuanLaporan::CatatMutasi($gula, $t['Gudang'], '4.0000', '70000.00');
        BantuanLaporan::CatatMutasi($gula, $t['Gudang'], '-4.0000', '-70000.00', jenis: JenisMutasi::ProduksiPakai, referensi: JenisReferensiMutasi::Produksi);

        expect((string) app(PenyediaHppBahan::class)->AmbilHppSatuan($gula->Id, null))->toBe('17500.000000')
            ->and(app(PenyediaHppBahan::class)->AmbilHppSatuan($produk['Stok']->Id, null))->toBeNull();
    });

    it('isolasi tenant: saldo bahan tenant lain tidak terbaca', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Kedai Kopi Senja Temaram');
        $gulaA = BantuanPersediaan::BuatProdukSemuaJenis($a['Pcs'], $a['Kg'])['BahanBaku'];
        BantuanLaporan::CatatMutasi($gulaA, $a['Gudang'], '5.0000', '80000.00');

        BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');

        expect(app(PenyediaHppBahan::class)->AmbilHppSatuan($gulaA->Id, null))->toBeNull()
            ->and(app(PenyediaHppBahan::class)->AmbilHppSatuan($gulaA->Id, $a['Gudang']->Id))->toBeNull();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect((string) app(PenyediaHppBahan::class)->AmbilHppSatuan($gulaA->Id, null))->toBe('16000.000000');
    });

    it('BR-03.5 estimasi HPP resep kini terisi dari saldo bahan (tanpa ikatan palsu)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant('Kedai Kopi Senja Temaram');
        $kopi = BantuanKomposisi::BuatBahan();
        $susu = BantuanKomposisi::BuatBahan('Susu Segar Full Cream Pasteurisasi', 'ml', 'Mililiter');
        $menu = BantuanKomposisi::BuatProdukResep();
        BantuanKomposisi::SimpanResep($menu, [[$kopi, '18'], [$susu, '150', null, '10']]);

        expect(app(HppResep::class)->Hitung($menu)->status)->toBe('BelumTersedia');

        BantuanLaporan::CatatMutasi($kopi, $t['Gudang'], '1000.0000', '250000.00');
        BantuanLaporan::CatatMutasi($susu, $t['Gudang'], '5000.0000', '100000.00');

        $hpp = app(HppResep::class)->Hitung($menu);

        // 18 × 250 = 4500; 166.6667 × 20 = 3333.334 → 7833.334000
        expect($hpp->status)->toBe('Tersedia')
            ->and($hpp->hppSatuan)->toBe('7833.334000')
            ->and($hpp->baris[0]['HppSatuanBahan'])->toBe('250.000000')
            ->and($hpp->baris[1]['HppSatuanBahan'])->toBe('20.000000');
    });
});
