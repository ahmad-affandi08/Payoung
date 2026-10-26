<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-05a InfoProdukStok (DesainF05a C.1)', function (): void {
    it('AmbilBanyak/AmbilDariUuid memetakan jenis, pelacakan, BolehMinus, dan satuan dasar. Produk terhapus hanya bila diminta', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $produk['Stok']->update(['BolehMinus' => true]);
        $produk['Produksi']->delete();
        $info = app(InfoProdukStok::class);

        $hasil = $info->AmbilBanyak([$produk['Stok']->Id, $produk['BahanBaku']->Id, $produk['Batch']->Id, $produk['Produksi']->Id]);
        expect(array_keys($hasil))->toEqualCanonicalizing([$produk['Stok']->Id, $produk['BahanBaku']->Id, $produk['Batch']->Id])
            ->and($hasil[$produk['Stok']->Id]->bolehMinus)->toBeTrue()
            ->and($hasil[$produk['Stok']->Id]->simbolSatuan)->toBe('pcs')
            ->and($hasil[$produk['Stok']->Id]->bolehDesimal)->toBeFalse()
            ->and($hasil[$produk['BahanBaku']->Id]->jenis)->toBe(JenisProduk::BahanBaku)
            ->and($hasil[$produk['BahanBaku']->Id]->bolehDesimal)->toBeTrue()
            ->and($hasil[$produk['BahanBaku']->Id]->bolehMinus)->toBeNull()
            ->and($hasil[$produk['Batch']->Id]->pelacakan)->toBe(PelacakanProduk::Batch);

        $denganTerhapus = $info->AmbilBanyak([$produk['Produksi']->Id], denganTerhapus: true);
        expect($denganTerhapus[$produk['Produksi']->Id]->dihapus)->toBeTrue()
            ->and(array_keys($info->AmbilDariUuid([$produk['Seri']->Uuid, 'TIDAKADA'])))->toBe([$produk['Seri']->Uuid]);
    });

    it('CariUntukStok: hanya jenis berstok (tanpa Konsinyasi/Jasa) dan belum diarsipkan. Nama/SKU mengandung atau barcode persis', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $produk['Seri']->update(['DiarsipkanPada' => now(), 'Aktif' => false]);
        ProdukBarcode::query()->create([
            'IdProduk' => $produk['Stok']->Id,
            'IdProdukSatuan' => ProdukSatuan::query()->where('IdProduk', $produk['Stok']->Id)->value('Id'),
            'Barcode' => '8992761111111',
        ]);
        $info = app(InfoProdukStok::class);
        $nama = fn (array $daftar): array => array_map(fn ($p) => $p->nama, $daftar);

        expect($nama($info->CariUntukStok('')))->toEqualCanonicalizing([
            $produk['Stok']->Nama, $produk['BahanBaku']->Nama, $produk['Produksi']->Nama, $produk['Batch']->Nama,
        ])
            ->and($nama($info->CariUntukStok('minyak goreng')))->toBe([$produk['Stok']->Nama])
            ->and($nama($info->CariUntukStok('8992761111111')))->toBe([$produk['Stok']->Nama])
            ->and($info->CariUntukStok('899276111'))->toBe([])
            ->and($nama($info->CariUntukStok($produk['Batch']->Sku ?? '')))->toBe([$produk['Batch']->Nama])
            ->and($info->CariUntukStok('Keripik'))->toBe([])
            ->and($info->CariUntukStok('Rice Cooker'))->toBe([])
            ->and($info->HitungBerstok())->toBe(4);
    });

    it('CariKunciImpor: SKU tanpa beda huruf, lalu barcode persis, lalu nama persis. Nama ganda = ambigu. Tenant lain tidak ikut', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $produk['Stok']->update(['Sku' => 'MGR-2L']);
        $kembar = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['Stok'];
        ProdukBarcode::query()->create([
            'IdProduk' => $produk['Batch']->Id,
            'IdProdukSatuan' => ProdukSatuan::query()->where('IdProduk', $produk['Batch']->Id)->value('Id'),
            'Barcode' => '8998009010019',
        ]);

        $hasil = app(InfoProdukStok::class)->CariKunciImpor(['mgr-2l', ' 8998009010019 ', 'gula pasir kristal putih curah', $kembar->Nama, 'Tidak Ada Produk Ini', '']);

        expect($hasil['mgr-2l'])->toBe([$produk['Stok']->Id])
            ->and($hasil[' 8998009010019 '])->toBe([$produk['Batch']->Id])
            ->and($hasil['gula pasir kristal putih curah'])->toHaveCount(2)
            ->and($hasil[$kembar->Nama])->toHaveCount(2)
            ->and($hasil['Tidak Ada Produk Ini'])->toBe([])
            ->and($hasil[''])->toBe([]);

        BantuanPersediaan::SiapkanTenant('Toko Tetangga Abadi');
        expect(app(InfoProdukStok::class)->CariKunciImpor(['MGR-2L'])['MGR-2L'])->toBe([]);
    });
});

describe('F-05a InfoGudang (DesainF05a C.1)', function (): void {
    it('AmbilBoleh membatasi ke outlet yang boleh diakses & status; CariKunciImpor Kode lalu Nama. Data tenant lain tidak terlihat', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $belakang = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Belakang Toko');
        $cabang = Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => 'SOLO', 'Nama' => 'Cabang Solo Baru']);
        $dapurCabang = BantuanPersediaan::BuatGudang($cabang, 'Dapur Cabang', JenisGudang::Dapur);
        $belakang->update(['Status' => StatusOrganisasi::Diarsipkan, 'DiarsipkanPada' => now()]);
        $info = app(InfoGudang::class);
        $uuid = fn (array $daftar): array => array_map(fn ($g) => $g->uuid, $daftar);

        expect($uuid($info->AmbilBoleh(null)))->toEqualCanonicalizing([$t['Gudang']->Uuid, $dapurCabang->Uuid])
            ->and($uuid($info->AmbilBoleh(null, hanyaAktif: false)))->toHaveCount(3)
            ->and($uuid($info->AmbilBoleh([$cabang->Id])))->toBe([$dapurCabang->Uuid])
            ->and($info->AmbilBoleh([]))->toBe([]);

        $data = $info->AmbilDariUuid([$belakang->Uuid])[$belakang->Uuid];
        expect($data->aktif)->toBeFalse()
            ->and($data->namaOutlet)->toBe($t['Outlet']->Nama)
            ->and($data->jenis)->toBe(JenisGudang::Gudang)
            ->and($info->AmbilBanyak([$dapurCabang->Id])[$dapurCabang->Id]->namaOutlet)->toBe('Cabang Solo Baru');

        $kunci = $info->CariKunciImpor([strtolower($dapurCabang->Kode), 'gudang belakang toko', 'Gudang Entah']);
        expect($kunci[strtolower($dapurCabang->Kode)])->toBe([$dapurCabang->Id])
            ->and($kunci['gudang belakang toko'])->toBe([$belakang->Id])
            ->and($kunci['Gudang Entah'])->toBe([]);

        BantuanPersediaan::SiapkanTenant('Toko Tetangga Abadi');
        expect(app(InfoGudang::class)->AmbilDariUuid([$belakang->Uuid]))->toBe([]);
    });
});

describe('F-05a TanggalBisnisOutlet (DesainF05a C.1)', function (): void {
    it('memakai zona outlet dan JamTutupBuku: sebelum jam tutup buku = hari sebelumnya, tanpa outlet = zona tenant', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $t['Outlet']->update(['ZonaWaktu' => 'Asia/Jakarta', 'JamTutupBuku' => '04:00']);
        $makassar = Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => 'MKS', 'Nama' => 'Cabang Makassar', 'ZonaWaktu' => 'Asia/Makassar', 'JamTutupBuku' => '00:00']);
        $hitung = fn (?int $idOutlet, string $utc): string => app(TanggalBisnisOutlet::class)->Hitung($idOutlet, CarbonImmutable::parse($utc, 'UTC'))->toDateString();

        // 03:30 WIB tanggal 25 masih tanggal bisnis 24; 04:30 WIB sudah 25.
        expect($hitung($t['Outlet']->Id, '2026-09-24 20:30:00'))->toBe('2026-09-24')
            ->and($hitung($t['Outlet']->Id, '2026-09-24 21:30:00'))->toBe('2026-09-25')
            // 16:30 UTC = 00:30 WITA tanggal 25 (jam tutup buku 00:00).
            ->and($hitung($makassar->Id, '2026-09-24 16:30:00'))->toBe('2026-09-25')
            ->and($hitung(null, '2026-09-24 16:30:00'))->toBe('2026-09-24')
            ->and($hitung(null, '2026-09-24 17:30:00'))->toBe('2026-09-25');
    });
});

describe('F-05a PengaturanPersediaanTenant (DesainF05a C.1)', function (): void {
    it('bawaan RataRata & tidak boleh minus. Membaca Tenant.Pengaturan. Kunci baca di dalam transaksi', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $kueri = app(PengaturanPersediaanTenant::class);

        Tenant::query()->whereKey($t['Tenant']->Id)->update(['Pengaturan' => null]);
        expect($kueri->Ambil()->metodeHpp)->toBe(MetodeHpp::RataRata)
            ->and($kueri->Ambil()->stokBolehMinus)->toBeFalse();

        BantuanPersediaan::AturMetodeHpp($t['Tenant'], MetodeHpp::Fifo, true);
        $terkunci = DB::transaction(fn () => $kueri->AmbilDenganKunciBaca());

        expect($kueri->Ambil()->metodeHpp)->toBe(MetodeHpp::Fifo)
            ->and($terkunci->metodeHpp)->toBe(MetodeHpp::Fifo)
            ->and($terkunci->stokBolehMinus)->toBeTrue();

        BantuanPersediaan::SiapkanTenant('Toko Tetangga Abadi');
        expect(app(PengaturanPersediaanTenant::class)->Ambil()->metodeHpp)->toBe(MetodeHpp::RataRata);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(app(PengaturanPersediaanTenant::class)->Ambil()->metodeHpp)->toBe(MetodeHpp::Fifo);
    });
});
