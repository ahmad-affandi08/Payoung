<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Kontrak\PemeriksaRiwayatStok;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Kueri\RincianBatchSaldo;
use App\Domain\Persediaan\Kueri\RiwayatStokProduk;
use App\Domain\Persediaan\Layanan\PelacakBatchStok;
use App\Domain\Persediaan\Layanan\PelacakNomorSeri;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPelacakan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a RincianBatchSaldo (DesainF05a C.4, halaman saldo)', function (): void {
    it('batch bersisa per pasangan, urut kedaluwarsa terdekat. Batch habis & pasangan lain tidak ikut', function (): void {
        $t = BantuanPelacakan::SiapkanTenant();
        $susu = $t['Produk']['Batch'];
        $pelacak = app(PelacakBatchStok::class);
        $isi = function (int $idGudang, string $nomor, ?string $kedaluwarsa, string $jumlah) use ($pelacak, $susu): void {
            $pelacak->Terapkan($pelacak->KunciMasuk($susu->Id, $idGudang, new DataBatchMasuk($nomor, $kedaluwarsa === null ? null : CarbonImmutable::parse($kedaluwarsa))), Kuantitas::Dari($jumlah));
        };
        $isi($t['Gudang']->Id, 'UHT-B', '2027-05-01', '12');
        $isi($t['Gudang']->Id, 'UHT-A', '2027-01-15', '24.5');
        $isi($t['Gudang']->Id, 'UHT-C', null, '3');
        $isi($t['Gudang']->Id, 'UHT-HABIS', '2026-12-01', '0');
        $isi($t['GudangBelakang']->Id, 'UHT-G', '2027-02-01', '100');

        $kunci = SaldoStok::BuatKunciPasangan($susu->Id, $t['Gudang']->Id);
        $hasil = app(RincianBatchSaldo::class)->UntukPasangan([[$susu->Id, $t['Gudang']->Id], [$t['Produk']['Stok']->Id, $t['Gudang']->Id]]);

        expect(array_keys($hasil))->toBe([$kunci])
            ->and($hasil[$kunci])->toBe([
                ['NomorBatch' => 'UHT-A', 'TanggalKedaluwarsa' => '2027-01-15', 'JumlahSisa' => '24.5000'],
                ['NomorBatch' => 'UHT-B', 'TanggalKedaluwarsa' => '2027-05-01', 'JumlahSisa' => '12.0000'],
                ['NomorBatch' => 'UHT-C', 'TanggalKedaluwarsa' => null, 'JumlahSisa' => '3.0000'],
            ])
            ->and(app(RincianBatchSaldo::class)->UntukPasangan([]))->toBe([]);
    });

    it('HitungSeriTersedia menghitung hanya nomor seri Tersedia per pasangan. Isolasi tenant', function (): void {
        $a = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $rice = $a['Produk']['Seri'];
        $pelacak = app(PelacakNomorSeri::class);

        foreach (['RC-01' => $a['Gudang'], 'RC-02' => $a['Gudang'], 'RC-03' => $a['Gudang'], 'RC-04' => $a['GudangBelakang']] as $nomor => $gudang) {
            $pelacak->TandaiMasuk($pelacak->KunciMasuk($rice->Id, $gudang->Id, $nomor), $gudang->Id);
        }

        // RC-02 terjual; RC-05 tercatat tetapi belum pernah masuk stok.
        $rc02 = NomorSeri::query()->where('Nomor', 'RC-02')->sole();
        $pelacak->TandaiKeluar($pelacak->KunciKeluar($rc02->Id, $rice->Id, $a['Gudang']->Id), StatusNomorSeri::Terjual);
        $pelacak->KunciMasuk($rice->Id, $a['Gudang']->Id, 'RC-05');

        $pasangan = [[$rice->Id, $a['Gudang']->Id], [$rice->Id, $a['GudangBelakang']->Id]];
        expect(app(RincianBatchSaldo::class)->HitungSeriTersedia($pasangan))->toBe([
            SaldoStok::BuatKunciPasangan($rice->Id, $a['Gudang']->Id) => 2,
            SaldoStok::BuatKunciPasangan($rice->Id, $a['GudangBelakang']->Id) => 1,
        ]);

        BantuanPelacakan::SiapkanTenant('Toko Elektronik Sebelah');
        expect(app(RincianBatchSaldo::class)->HitungSeriTersedia($pasangan))->toBe([])
            ->and(app(RincianBatchSaldo::class)->UntukPasangan($pasangan))->toBe([]);
    });
});

describe('F-05a RiwayatStokProduk (DesainF05a C.4)', function (): void {
    it('PemeriksaRiwayatStok diikat ke RiwayatStokProduk. True hanya bila produk punya MutasiStok di tenant aktif', function (): void {
        $a = BantuanPelacakan::SiapkanTenant();
        $pemeriksa = app(PemeriksaRiwayatStok::class);
        expect($pemeriksa)->toBeInstanceOf(RiwayatStokProduk::class)
            ->and($pemeriksa->CekPunyaRiwayatStok($a['Produk']['Batch']->Id))->toBeFalse();

        BantuanPelacakan::BuatMutasiMentah($a['Produk']['Batch'], $a['Gudang']);
        expect($pemeriksa->CekPunyaRiwayatStok($a['Produk']['Batch']->Id))->toBeTrue()
            ->and($pemeriksa->CekPunyaRiwayatStok($a['Produk']['Seri']->Id))->toBeFalse();

        BantuanPelacakan::SiapkanTenant('Apotek Lain Jaya');
        expect($pemeriksa->CekPunyaRiwayatStok($a['Produk']['Batch']->Id))->toBeFalse();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($pemeriksa->CekPunyaRiwayatStok($a['Produk']['Batch']->Id))->toBeTrue();
    });
});
