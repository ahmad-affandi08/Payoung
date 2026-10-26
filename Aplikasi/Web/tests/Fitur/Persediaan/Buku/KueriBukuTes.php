<?php

declare(strict_types=1);

use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Kueri\CekAdaMutasi;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Persediaan\Model\MutasiStok;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanBuku;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Kueri buku stok milik Tim A (DesainF05a C.2): `MutasiDokumen` (dipakai pembatalan stok awal) dan `CekAdaMutasi`
 * (mengunci perubahan MetodeHpp, H-4).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a kueri buku stok', function (): void {
    it('MutasiDokumen: baris satu dokumen urut Id, opsional hanya berawalan kunci tertentu. Tenant lain tidak ikut', function (): void {
        $lain = BantuanPersediaan::SiapkanTenant('Warung Kopi Tetangga');
        $pLain = BantuanPersediaan::BuatProdukSemuaJenis($lain['Pcs'], $lain['Kg']);
        BantuanBuku::Catat([BantuanBuku::BuatBaris('P/1', $pLain['Stok']->Id, $lain['Gudang']->Id, '1', '1000.00')], JenisReferensiMutasi::StokAwal, 555);

        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $g = $t['Gudang']->Id;
        $posting = BantuanBuku::Catat([
            BantuanBuku::BuatBaris('P/10', $p['Stok']->Id, $g, '4', '154000.00'),
            BantuanBuku::BuatBaris('P/11', $p['Produksi']->Id, $g, '2', '50000.00'),
            BantuanBuku::BuatBaris('P%', $p['BahanBaku']->Id, $g, '1', '14250.00'),
        ], JenisReferensiMutasi::StokAwal, 555);
        BantuanBuku::Catat([
            BantuanBuku::BuatBaris('B/P/10', $p['Stok']->Id, $g, '-4', '154000.00', idMutasiAsal: $posting->baris['P/10']->idMutasiStok),
        ], JenisReferensiMutasi::StokAwal, 555);
        BantuanBuku::Catat([BantuanBuku::BuatBaris('P/1', $p['Stok']->Id, $g, '1', '38500.00')], JenisReferensiMutasi::StokAwal, 556);

        $kueri = new MutasiDokumen;
        $semua = array_map(fn (MutasiStok $m): string => $m->KunciBaris, $kueri->Ambil(JenisReferensiMutasi::StokAwal, 555));
        $posting = array_map(fn (MutasiStok $m): string => $m->KunciBaris, $kueri->Ambil(JenisReferensiMutasi::StokAwal, 555, 'P/'));
        $persen = array_map(fn (MutasiStok $m): string => $m->KunciBaris, $kueri->Ambil(JenisReferensiMutasi::StokAwal, 555, 'P%'));

        expect($semua)->toBe(['P/10', 'P/11', 'P%', 'B/P/10'])
            ->and($posting)->toBe(['P/10', 'P/11'])
            ->and($persen)->toBe(['P%'])
            ->and($kueri->Ambil(JenisReferensiMutasi::PenerimaanBarang, 555))->toBe([]);
    });

    it('CekAdaMutasi: false sebelum ada mutasi di tenant aktif, true sesudahnya. Mutasi tenant lain tidak dihitung', function (): void {
        $lain = BantuanPersediaan::SiapkanTenant('Warung Kopi Tetangga');
        $pLain = BantuanPersediaan::BuatProdukSemuaJenis($lain['Pcs'], $lain['Kg']);
        BantuanBuku::CatatMasuk($pLain['Stok']->Id, $lain['Gudang']->Id, '1', '1000.00');

        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $sebelum = app(CekAdaMutasi::class)->Jalankan();
        BantuanBuku::CatatMasuk($p['Stok']->Id, $t['Gudang']->Id, '1', '38500.00');

        expect($sebelum)->toBeFalse()
            ->and(app(CekAdaMutasi::class)->Jalankan())->toBeTrue();

        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        expect(app(CekAdaMutasi::class)->Jalankan())->toBeTrue();
    });
});
