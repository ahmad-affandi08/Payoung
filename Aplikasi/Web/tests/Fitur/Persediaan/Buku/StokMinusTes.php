<?php

declare(strict_types=1);

use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Layanan\PemeriksaStokMinus;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Tenant\Data\DataPengaturanPersediaan;
use Tests\Pendukung\Persediaan\BantuanBuku;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * BR-05.2 stok minus (DesainF05a C.4) dan BR-04.3 penerimaan saat stok minus (C.3 contoh #3).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a BR-05.2 stok minus', function (): void {
    it('BR-05.2 matriks: Produk.BolehMinus ?? Tenant.StokBolehMinus menentukan boleh minus (produk tanpa pelacakan)', function (?bool $produkBoleh, bool $tenantBoleh, bool $boleh): void {
        $t = BantuanPersediaan::SiapkanTenant(stokBolehMinus: $tenantBoleh);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $p['Stok']->forceFill(['BolehMinus' => $produkBoleh])->save();
        $g = $t['Gudang']->Id;
        BantuanBuku::CatatMasuk($p['Stok']->Id, $g, '3', '115500.00');

        if ($boleh) {
            $hasil = BantuanBuku::CatatKeluar($p['Stok']->Id, $g, '5');

            expect($hasil->baris['K/1']->saldoSetelah->KeString())->toBe('-2.0000')
                ->and($hasil->TotalHpp()->KeString())->toBe('-192500.00')
                ->and(BantuanBuku::AmbilSaldo($p['Stok']->Id, $g)?->NilaiPersediaan)->toBe('-77000.00');
        } else {
            $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::CatatKeluar($p['Stok']->Id, $g, '5'));

            expect($galat->kode)->toBe('StokTidakCukup')
                ->and($galat->bidang)->toBe('Jumlah')
                ->and($galat->getMessage())->toBe('Stok Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter di '.$t['Gudang']->Nama.' tidak cukup: tersedia 3, dibutuhkan 5.')
                ->and($galat->detail)->toBe(['UuidProduk' => $p['Stok']->Uuid, 'UuidGudang' => $t['Gudang']->Uuid, 'Tersedia' => '3.0000', 'Diminta' => '5.0000'])
                ->and(MutasiStok::query()->count())->toBe(1)
                ->and(BantuanBuku::AmbilSaldo($p['Stok']->Id, $g)?->JumlahTersedia)->toBe('3.0000');
        }

        expect(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    })->with([
        'produk ikut tenant, tenant tidak boleh' => [null, false, false],
        'produk ikut tenant, tenant boleh' => [null, true, true],
        'produk boleh menimpa tenant tidak boleh' => [true, false, true],
        'produk tidak boleh menimpa tenant boleh' => [false, true, false],
        'keduanya boleh' => [true, true, true],
        'keduanya tidak boleh' => [false, false, false],
    ]);

    it('BR-05.2: produk ber-pelacakan batch/seri tidak pernah boleh minus walau produk & tenant membolehkan', function (PelacakanProduk $pelacakan, ?bool $produkBoleh, bool $tenantBoleh, bool $harapan): void {
        $produk = new DataInfoProdukStok(1, '01K5PRODUKTIMA0000000000001', 'Susu UHT Full Cream 1 Liter', null, JenisProduk::Stok, $pelacakan, $produkBoleh, 1, 'pcs', false, false, false);

        expect((new PemeriksaStokMinus)->CekBolehMinus($produk, new DataPengaturanPersediaan(MetodeHpp::RataRata, $tenantBoleh)))->toBe($harapan);
    })->with([
        'batch, keduanya boleh' => [PelacakanProduk::Batch, true, true, false],
        'seri, tenant boleh' => [PelacakanProduk::Seri, null, true, false],
        'tanpa pelacakan, tenant boleh' => [PelacakanProduk::Tidak, null, true, true],
    ]);

    it('F-07b abaikanBatasMinus: dokumen tetap dicatat walau stok tidak cukup. Baris yang melanggar BR-05.2 dilaporkan, baris yang cukup atau boleh minus tidak. Bawaan tetap menolak', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $p['Produksi']->forceFill(['BolehMinus' => true])->save();
        $g = $t['Gudang']->Id;
        BantuanBuku::CatatMasuk($p['Stok']->Id, $g, '3', '115500.00');
        BantuanBuku::CatatMasuk($p['BahanBaku']->Id, $g, '10', '150000.00');
        $baris = [
            BantuanBuku::BuatBaris('K/1', $p['Stok']->Id, $g, '-5', null, JenisMutasi::Penjualan),
            BantuanBuku::BuatBaris('K/2', $p['BahanBaku']->Id, $g, '-2.5', null, JenisMutasi::Penjualan),
            BantuanBuku::BuatBaris('K/3', $p['Produksi']->Id, $g, '-1', null, JenisMutasi::Penjualan),
        ];

        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat($baris, JenisReferensiMutasi::Penjualan));
        expect($galat->kode)->toBe('StokTidakCukup');

        $dokumen = BantuanBuku::BuatDokumen($baris, JenisReferensiMutasi::Penjualan);
        $hasil = app(CatatMutasiStok::class)->Jalankan(new DataDokumenMutasi(
            $dokumen->jenisReferensi,
            $dokumen->idReferensi,
            $dokumen->uuidReferensi,
            $dokumen->nomorReferensi,
            $dokumen->tanggalBisnis,
            $dokumen->idPengguna,
            $dokumen->idPerangkat,
            $dokumen->baris,
            abaikanBatasMinus: true,
        ));

        expect($hasil->baris['K/1']->stokTidakCukup)->toBeTrue()
            ->and($hasil->baris['K/1']->saldoSetelah->KeString())->toBe('-2.0000')
            ->and($hasil->baris['K/2']->stokTidakCukup)->toBeFalse()
            ->and($hasil->baris['K/3']->stokTidakCukup)->toBeFalse()
            ->and(array_map(fn ($b) => $b->kunciBaris, $hasil->AmbilBarisStokTidakCukup()))->toBe(['K/1'])
            ->and(BantuanBuku::AmbilSaldo($p['Stok']->Id, $g)?->JumlahTersedia)->toBe('-2.0000')
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });

    it('F-07b abaikanBatasMinus hanya untuk produk tanpa pelacakan: dokumen yang memuat produk batch atau seri ditolak PelacakanBelumDidukung tanpa mutasi tersimpan', function (string $jenisProduk): void {
        $t = BantuanPersediaan::SiapkanTenant(stokBolehMinus: true);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $g = $t['Gudang']->Id;
        $dokumen = BantuanBuku::BuatDokumen([
            BantuanBuku::BuatBaris('K/1', $p['Stok']->Id, $g, '-1', null, JenisMutasi::Penjualan),
            BantuanBuku::BuatBaris('K/2', $p[$jenisProduk]->Id, $g, '-1', null, JenisMutasi::Penjualan, idBatchStok: $jenisProduk === 'Batch' ? 1 : null, idNomorSeri: $jenisProduk === 'Seri' ? 1 : null),
        ], JenisReferensiMutasi::Penjualan);

        $galat = BantuanBuku::TangkapPelanggaran(fn () => app(CatatMutasiStok::class)->Jalankan(new DataDokumenMutasi(
            $dokumen->jenisReferensi,
            $dokumen->idReferensi,
            $dokumen->uuidReferensi,
            $dokumen->nomorReferensi,
            $dokumen->tanggalBisnis,
            $dokumen->idPengguna,
            $dokumen->idPerangkat,
            $dokumen->baris,
            abaikanBatasMinus: true,
        )));

        expect($galat->kode)->toBe('PelacakanBelumDidukung')
            ->and($galat->detail)->toBe(['KunciBaris' => 'K/2'])
            ->and(MutasiStok::query()->count())->toBe(0);
    })->with(['batch' => ['Batch'], 'seri' => ['Seri']]);

    it('BR-05.2 tidak berlaku untuk mutasi masuk dan stok yang pas habis', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $g = $t['Gudang']->Id;
        BantuanBuku::CatatMasuk($p['Stok']->Id, $g, '4', '154000.00');

        $habis = BantuanBuku::CatatKeluar($p['Stok']->Id, $g, '4');

        expect($habis->baris['K/1']->saldoSetelah->KeString())->toBe('0.0000')
            ->and(BantuanBuku::AmbilSaldo($p['Stok']->Id, $g)?->NilaiPersediaan)->toBe('0.00');
    });

    it('BR-05.2 dinilai per baris berurutan: baris kedua pada pasangan yang sama memakai saldo setelah baris pertama', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $g = $t['Gudang']->Id;

        $hasil = BantuanBuku::Catat([
            BantuanBuku::BuatBaris('1', $p['Stok']->Id, $g, '6', '231000.00', JenisMutasi::PenyesuaianMasuk),
            BantuanBuku::BuatBaris('2', $p['Stok']->Id, $g, '-6', null, JenisMutasi::PenyesuaianKeluar),
        ]);

        expect($hasil->TotalHpp()->KeString())->toBe('0.00')
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });
});

describe('F-05a BR-04.3 penerimaan saat stok minus', function (): void {
    it('BR-04.3 contoh #3 (rata-rata): Q −4 N −4000, terima 10 @ 1100 → TotalHpp 10600, SelisihHpp −400', function (MetodeHpp $metode): void {
        $t = BantuanPersediaan::SiapkanTenant(metodeHpp: $metode, stokBolehMinus: true);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $id = $p['Stok']->Id;
        $g = $t['Gudang']->Id;

        BantuanBuku::CatatMasuk($id, $g, '5', '5000.00');
        BantuanBuku::CatatKeluar($id, $g, '5');
        $minus = BantuanBuku::CatatKeluar($id, $g, '4');
        $terima = BantuanBuku::Catat([BantuanBuku::BuatBaris('M/1', $id, $g, '10', '11000.00', JenisMutasi::PenerimaanPembelian)], JenisReferensiMutasi::PenerimaanBarang);
        $m = MutasiStok::query()->findOrFail($terima->baris['M/1']->idMutasiStok);
        $saldo = BantuanBuku::AmbilSaldo($id, $g);

        expect($minus->TotalHpp()->KeString())->toBe('-4000.00')
            ->and($terima->TotalHpp()->KeString())->toBe('10600.00')
            ->and($terima->TotalNilaiDiminta()->KeString())->toBe('11000.00')
            ->and($terima->TotalSelisih()->KeString())->toBe('-400.00')
            ->and($m->SelisihHpp)->toBe('-400.00')
            ->and($m->TotalHpp)->toBe('10600.00')
            ->and($m->HppSatuan)->toBe('1100.000000')
            ->and($saldo?->JumlahTersedia)->toBe('6.0000')
            ->and($saldo?->NilaiPersediaan)->toBe('6600.00')
            ->and($saldo?->HppRataRata)->toBe('1100.000000')
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id, $metode === MetodeHpp::Fifo))->toBe([]);
    })->with([MetodeHpp::RataRata, MetodeHpp::Fifo]);

    it('contoh #4 melewati nol: Q 2 N 2000 jual 5 (boleh minus) → TotalHpp −5000, Q −3, N −3000', function (MetodeHpp $metode): void {
        $t = BantuanPersediaan::SiapkanTenant(metodeHpp: $metode, stokBolehMinus: true);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);

        BantuanBuku::CatatMasuk($p['Stok']->Id, $t['Gudang']->Id, '2', '2000.00');
        $jual = BantuanBuku::CatatKeluar($p['Stok']->Id, $t['Gudang']->Id, '5');

        expect($jual->TotalHpp()->KeString())->toBe('-5000.00')
            ->and(BantuanBuku::AmbilSaldo($p['Stok']->Id, $t['Gudang']->Id)?->NilaiPersediaan)->toBe('-3000.00')
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id, $metode === MetodeHpp::Fifo))->toBe([]);
    })->with([MetodeHpp::RataRata, MetodeHpp::Fifo]);
});
