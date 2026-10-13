<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Persediaan\Aksi\BatalkanStokOpname;
use App\Domain\Persediaan\Aksi\LepasProdukRak;
use App\Domain\Persediaan\Aksi\MulaiStokOpname;
use App\Domain\Persediaan\Aksi\SimpanHitungStokOpname;
use App\Domain\Persediaan\Aksi\SimpanRakGudang;
use App\Domain\Persediaan\Aksi\TetapkanProdukRak;
use App\Domain\Persediaan\Aksi\UbahStatusRakGudang;
use App\Domain\Persediaan\Data\DataHitungOpname;
use App\Domain\Persediaan\Model\ProdukRak;
use App\Domain\Persediaan\Model\RakGudang;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05c INV-07 (v6.00): rak gudang dan stok opname per rak. Rak = cakupan hitung produk dalam satu lokasi stok (satu
 * produk satu rak); opname per rak hanya memuat produk rak itu, rak berbeda boleh dihitung berbarengan, isi rak
 * dibekukan selama opname rak itu berjalan, dan detail opname merangkum siapa menghitung berapa baris.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SiapkanRak(): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
        BantuanStokAwal::Baris($p['BahanBaku'], '25.5', '14750'),
        BantuanStokAwal::Baris($p['Produksi'], '12', '9000'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
    $a = app(SimpanRakGudang::class)->Jalankan($t['Gudang']->Id, null, 'a-01', 'Rak Minyak & Sembako', 1, $t['Pemilik']->Id);
    $b = app(SimpanRakGudang::class)->Jalankan($t['Gudang']->Id, null, 'B-01', 'Rak Bahan Kue', 2, $t['Pemilik']->Id);

    return [...$t, 'Produk' => $p, 'RakA' => $a, 'RakB' => $b];
}

describe('rak gudang: master dan penempatan produk', function (): void {
    it('kode disimpan huruf besar dan unik per lokasi stok; ubah tidak memindah lokasi; audit tercatat', function (): void {
        $t = SiapkanRak();

        expect($t['RakA']->Kode)->toBe('A-01')
            ->and(B::KodeGalat(fn () => app(SimpanRakGudang::class)->Jalankan($t['Gudang']->Id, null, 'A-01', 'Duplikat', 0, $t['Pemilik']->Id)))->toBe('KodeRakDipakai')
            ->and(B::KodeGalat(fn () => app(SimpanRakGudang::class)->Jalankan($t['Gudang']->Id, null, ' ', 'Tanpa kode', 0, $t['Pemilik']->Id)))->toBe('RakTidakLengkap');

        $ubah = app(SimpanRakGudang::class)->Jalankan(0, $t['RakA'], 'a-09', 'Rak Sembako', 5, $t['Pemilik']->Id);
        expect($ubah->Kode)->toBe('A-09')->and($ubah->IdGudang)->toBe($t['Gudang']->Id)
            ->and(B::KodeGalat(fn () => app(SimpanRakGudang::class)->Jalankan(0, $t['RakA'], 'B-01', 'Bentrok', 5, $t['Pemilik']->Id)))->toBe('KodeRakDipakai');
    });

    it('menempatkan produk: tambah, pindah dari rak lain, lewati yang sudah ada; hanya produk berstok', function (): void {
        $t = SiapkanRak();
        $stok = $t['Produk']['Stok']->Id;
        $tetapkan = app(TetapkanProdukRak::class);

        expect($tetapkan->Jalankan($t['RakA'], [$stok, $stok], $t['Pemilik']->Id))->toBe(['Ditambah' => 1, 'Dipindah' => 0, 'Dilewati' => 0])
            ->and($tetapkan->Jalankan($t['RakA'], [$stok], $t['Pemilik']->Id))->toBe(['Ditambah' => 0, 'Dipindah' => 0, 'Dilewati' => 1])
            ->and($tetapkan->Jalankan($t['RakB'], [$stok, $t['Produk']['BahanBaku']->Id], $t['Pemilik']->Id))->toBe(['Ditambah' => 1, 'Dipindah' => 1, 'Dilewati' => 0])
            ->and(ProdukRak::query()->where('IdRak', $t['RakA']->Id)->count())->toBe(0)
            ->and(ProdukRak::query()->where('IdRak', $t['RakB']->Id)->count())->toBe(2)
            ->and(B::KodeGalat(fn () => $tetapkan->Jalankan($t['RakB'], [$t['Produk']['Jasa']->Id], $t['Pemilik']->Id)))->toBe('ProdukTanpaStok')
            ->and(B::KodeGalat(fn () => $tetapkan->Jalankan($t['RakB'], [$t['Produk']['Konsinyasi']->Id], $t['Pemilik']->Id)))->toBe('ProdukTanpaStok')
            ->and(B::KodeGalat(fn () => $tetapkan->Jalankan($t['RakB'], [], $t['Pemilik']->Id)))->toBe('ProdukBelumDipilih');

        expect(app(LepasProdukRak::class)->Jalankan($t['RakB'], [$stok, 999999], $t['Pemilik']->Id))->toBe(1)
            ->and(app(LepasProdukRak::class)->Jalankan($t['RakB'], [$stok], $t['Pemilik']->Id))->toBe(0);
    });

    it('rak nonaktif tidak bisa diisi atau dipakai opname; aktifkan kembali memulihkan; idempoten', function (): void {
        $t = SiapkanRak();
        app(TetapkanProdukRak::class)->Jalankan($t['RakA'], [$t['Produk']['Stok']->Id], $t['Pemilik']->Id);
        $mati = app(UbahStatusRakGudang::class)->Jalankan($t['RakA'], false, $t['Pemilik']->Id);

        expect($mati->Aktif)->toBeFalse()
            ->and(app(UbahStatusRakGudang::class)->Jalankan($t['RakA'], false, $t['Pemilik']->Id)->Aktif)->toBeFalse()
            ->and(B::KodeGalat(fn () => app(TetapkanProdukRak::class)->Jalankan($mati, [$t['Produk']['Stok']->Id], $t['Pemilik']->Id)))->toBe('RakNonaktif')
            ->and(B::KodeGalat(fn () => app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id, null, $mati->Uuid)))->toBe('RakNonaktif');

        expect(app(UbahStatusRakGudang::class)->Jalankan($t['RakA'], true, $t['Pemilik']->Id)->Aktif)->toBeTrue();
    });
});

describe('opname per rak', function (): void {
    it('snapshot hanya produk di rak; lembar hitung menolak produk di luar rak; rak kosong atau rak + kategori ditolak', function (): void {
        $t = SiapkanRak();
        app(TetapkanProdukRak::class)->Jalankan($t['RakA'], [$t['Produk']['Stok']->Id], $t['Pemilik']->Id);
        app(TetapkanProdukRak::class)->Jalankan($t['RakB'], [$t['Produk']['BahanBaku']->Id], $t['Pemilik']->Id);
        $kosong = app(SimpanRakGudang::class)->Jalankan($t['Gudang']->Id, null, 'C-01', 'Rak Kosong', 3, $t['Pemilik']->Id);
        $kategori = BantuanKatalog::BuatKategori('Minuman');

        expect(B::KodeGalat(fn () => app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id, null, $kosong->Uuid)))->toBe('RakKosong')
            ->and(B::KodeGalat(fn () => app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, $kategori->Uuid, false, null, $t['Pemilik']->Id, null, $t['RakA']->Uuid)))->toBe('CakupanGanda')
            ->and(B::KodeGalat(fn () => app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id, null, (string) Str::ulid())))->toBe('RakTidakDikenal');

        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id, null, $t['RakA']->Uuid);

        expect($opname->IdRak)->toBe($t['RakA']->Id)
            ->and($opname->NamaRak)->toBe('Rak Minyak & Sembako')
            ->and(StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->pluck('IdProduk')->all())->toBe([$t['Produk']['Stok']->Id])
            ->and(B::KodeGalat(fn () => app(SimpanHitungStokOpname::class)->Jalankan($opname, [new DataHitungOpname(null, $t['Produk']['BahanBaku']->Id, Kuantitas::Dari('3'))], $t['Pemilik']->Id)))->toBe('ProdukDiLuarRak');
    });

    it('rak berbeda boleh berbarengan; rak sama, seluruh produk, dan kategori beririsan bentrok', function (): void {
        $t = SiapkanRak();
        $stok = $t['Produk']['Stok'];
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        $stok->forceFill(['IdKategori' => $minuman->Id])->save();
        app(TetapkanProdukRak::class)->Jalankan($t['RakA'], [$stok->Id], $t['Pemilik']->Id);
        app(TetapkanProdukRak::class)->Jalankan($t['RakB'], [$t['Produk']['BahanBaku']->Id], $t['Pemilik']->Id);
        $mulai = fn (?string $kategori, ?string $rak) => app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, $kategori, false, null, $t['Pemilik']->Id, null, $rak);

        $opnameA = $mulai(null, $t['RakA']->Uuid);
        $opnameB = $mulai(null, $t['RakB']->Uuid);

        expect($opnameA->Id)->not->toBe($opnameB->Id)
            ->and(B::KodeGalat(fn () => $mulai(null, $t['RakA']->Uuid)))->toBe('OpnameAktifSudahAda')
            ->and(B::KodeGalat(fn () => $mulai(null, null)))->toBe('OpnameAktifSudahAda')
            ->and(B::KodeGalat(fn () => $mulai($minuman->Uuid, null)))->toBe('OpnameAktifSudahAda');

        app(BatalkanStokOpname::class)->Jalankan($opnameA, 'Selesai dipecah', $t['Pemilik']->Id);
        // Setelah rak A dibatalkan, kategori Minuman (produk di rak A) masih bentrok? Tidak: tinggal rak B yang tidak beririsan.
        expect($mulai($minuman->Uuid, null)->Status->value)->toBe('Berlangsung');
    });

    it('isi rak dibekukan selama opname rak itu berjalan, lalu terbuka lagi; nonaktifkan rak juga ditolak', function (): void {
        $t = SiapkanRak();
        app(TetapkanProdukRak::class)->Jalankan($t['RakA'], [$t['Produk']['Stok']->Id], $t['Pemilik']->Id);
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id, null, $t['RakA']->Uuid);

        expect(B::KodeGalat(fn () => app(TetapkanProdukRak::class)->Jalankan($t['RakA'], [$t['Produk']['Produksi']->Id], $t['Pemilik']->Id)))->toBe('RakSedangDiopname')
            ->and(B::KodeGalat(fn () => app(TetapkanProdukRak::class)->Jalankan($t['RakB'], [$t['Produk']['Stok']->Id], $t['Pemilik']->Id)))->toBe('RakSedangDiopname')
            ->and(B::KodeGalat(fn () => app(LepasProdukRak::class)->Jalankan($t['RakA'], [$t['Produk']['Stok']->Id], $t['Pemilik']->Id)))->toBe('RakSedangDiopname')
            ->and(B::KodeGalat(fn () => app(UbahStatusRakGudang::class)->Jalankan($t['RakA'], false, $t['Pemilik']->Id)))->toBe('RakSedangDiopname');

        app(BatalkanStokOpname::class)->Jalankan($opname, 'Tidak jadi', $t['Pemilik']->Id);

        expect(app(TetapkanProdukRak::class)->Jalankan($t['RakA'], [$t['Produk']['Produksi']->Id], $t['Pemilik']->Id)['Ditambah'])->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

describe('HTTP rak gudang dan ringkasan penghitung', function (): void {
    it('daftar, tambah, ubah, isi rak, lepas produk, nonaktifkan; opsi rak di daftar opname; ringkasan penghitung di detail', function (): void {
        $t = SiapkanRak();
        $stok = $t['Produk']['Stok'];
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->get('/kelola/persediaan/rak')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Rak/Daftar')
            ->has('Rak', 2)
            ->where('Rak.0.Kode', 'A-01')
            ->where('Rak.0.JumlahProduk', 0)
            ->where('Izin.Kelola', true));

        $this->post('/kelola/persediaan/rak', ['UuidGudang' => $t['Gudang']->Uuid, 'Kode' => 'c-02', 'Nama' => 'Rak Kulkas', 'Urutan' => 3])->assertSessionHasNoErrors();
        $baru = RakGudang::query()->where('Kode', 'C-02')->sole();
        $this->post('/kelola/persediaan/rak', ['UuidGudang' => $t['Gudang']->Uuid, 'Kode' => 'C-02', 'Nama' => 'Dobel'])->assertSessionHasErrors('Kode');
        $this->put("/kelola/persediaan/rak/{$baru->Uuid}", ['Kode' => 'C-02', 'Nama' => 'Rak Kulkas Besar', 'Urutan' => 3])->assertSessionHasNoErrors();
        expect($baru->fresh()->Nama)->toBe('Rak Kulkas Besar');

        $this->post("/kelola/persediaan/rak/{$t['RakA']->Uuid}/produk", ['UuidProduk' => [$stok->Uuid]])->assertSessionHasNoErrors();
        $kategori = BantuanKatalog::BuatKategori('Bahan');
        $t['Produk']['BahanBaku']->forceFill(['IdKategori' => $kategori->Id])->save();
        $this->post("/kelola/persediaan/rak/{$t['RakB']->Uuid}/produk", ['UuidKategori' => $kategori->Uuid])->assertSessionHasNoErrors();
        expect(ProdukRak::query()->where('IdRak', $t['RakA']->Id)->count())->toBe(1)
            ->and(ProdukRak::query()->where('IdRak', $t['RakB']->Id)->count())->toBe(1);

        $this->get("/kelola/persediaan/rak/{$t['RakA']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Rak/Detail')
            ->where('Rak.JumlahProduk', 1)
            ->where('Produk.Data.0.Uuid', $stok->Uuid)
            ->has('OpsiKategori'));

        $this->get('/kelola/persediaan/opname')->assertInertia(fn (AssertableInertia $h) => $h
            ->has('OpsiRak', 2)
            ->where('OpsiRak.0.Kode', 'A-01'));

        $uuid = (string) Str::ulid();
        $this->post('/kelola/persediaan/opname', ['Uuid' => $uuid, 'UuidGudang' => $t['Gudang']->Uuid, 'UuidRak' => $t['RakA']->Uuid, 'HitungButa' => false])->assertSessionHasNoErrors()->assertRedirect("/kelola/persediaan/opname/{$uuid}");
        $opname = StokOpname::query()->where('Uuid', $uuid)->sole();
        $urutan = (int) StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->value('Urutan');
        $this->put("/kelola/persediaan/opname/{$uuid}/hitung", ['Hitung' => [['Urutan' => $urutan, 'JumlahFisik' => '98']]])->assertSessionHasNoErrors();

        $this->get("/kelola/persediaan/opname/{$uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Opname.NamaRak', 'Rak Minyak & Sembako')
            ->where('Penghitung.0.JumlahBaris', 1)
            ->where('Baris.0.JumlahFisik', '98.0000')
            ->where('Baris.0.DihitungOleh', fn ($nama) => is_string($nama) && $nama !== ''));

        $this->post("/kelola/persediaan/rak/{$t['RakA']->Uuid}/nonaktifkan")->assertSessionHasErrors('Umum');
        $this->post("/kelola/persediaan/rak/{$t['RakB']->Uuid}/nonaktifkan")->assertSessionHasNoErrors();
        expect($t['RakB']->fresh()->Aktif)->toBeFalse();
    });

    it('ringkasan penghitung merangkum dua orang yang menghitung baris berbeda', function (): void {
        $t = SiapkanRak();
        app(TetapkanProdukRak::class)->Jalankan($t['RakA'], [$t['Produk']['Stok']->Id, $t['Produk']['Produksi']->Id], $t['Pemilik']->Id);
        $opname = app(MulaiStokOpname::class)->Jalankan($t['Gudang']->Id, null, false, null, $t['Pemilik']->Id, null, $t['RakA']->Uuid);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $pelaku = Pengguna::query()->latest('Id')->firstOrFail();
        $barisStok = (int) StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->where('IdProduk', $t['Produk']['Stok']->Id)->value('Urutan');
        $barisRoti = (int) StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->where('IdProduk', $t['Produk']['Produksi']->Id)->value('Urutan');

        app(SimpanHitungStokOpname::class)->Jalankan($opname, [new DataHitungOpname($barisStok, null, Kuantitas::Dari('99'))], $t['Pemilik']->Id);
        app(SimpanHitungStokOpname::class)->Jalankan($opname, [new DataHitungOpname($barisRoti, null, Kuantitas::Dari('12'))], $pelaku->Id);

        $peta = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->pluck('DihitungOleh', 'Urutan')->all();
        expect($peta[$barisStok])->toBe($t['Pemilik']->Id)->and($peta[$barisRoti])->toBe($pelaku->Id);
    });
});
