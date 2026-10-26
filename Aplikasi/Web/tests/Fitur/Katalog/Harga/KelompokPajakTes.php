<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pajak\Aksi\SimpanKelompokPajak;
use App\Domain\Pajak\Enum\DasarPengenaanPajak;
use App\Domain\Pajak\Enum\KategoriPajakProduk;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Pajak\Model\KelompokPajak;
use App\Domain\Pajak\Model\KelompokPajakDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanHarga::SiapkanJenisPajak();
    ['Tenant' => $this->tenant] = BantuanKatalog::BuatTenant();
});

/**
 * @param  list<string>  $kode
 */
function SimpanKelompok(string $nama, KategoriPajakProduk $kategori, array $kode, ?KelompokPajak $kelompok = null): KelompokPajak
{
    return app(SimpanKelompokPajak::class)->Jalankan(
        $kelompok,
        $nama,
        $kategori,
        array_map(fn (string $kodeJenis): array => ['KodeJenisPajak' => $kodeJenis, 'DasarPengenaan' => DasarPengenaanPajak::Subtotal], $kode),
    );
}

/**
 * @return list<string>
 */
function KodeDetail(KelompokPajak $kelompok): array
{
    return KelompokPajakDetail::query()->where('IdKelompokPajak', $kelompok->Id)->orderBy('Urutan')->with('JenisPajak')->get()
        ->map(fn (KelompokPajakDetail $detail): string => $detail->JenisPajak->Kode)->values()->all();
}

describe('F-03 SimpanKelompokPajak (§12.2)', function (): void {
    it('kombinasi kategori dan detail yang sesuai disimpan tanpa angka tarif', function (KategoriPajakProduk $kategori, array $kode): void {
        $kelompok = SimpanKelompok('Kelompok '.$kategori->value, $kategori, $kode);

        expect($kelompok->refresh()->Kategori)->toBe($kategori)
            ->and(KodeDetail($kelompok))->toBe($kode)
            ->and(KelompokPajakDetail::query()->where('IdKelompokPajak', $kelompok->Id)->whereNotNull('IdTarifPajak')->exists())->toBeFalse()
            ->and(KelompokPajakDetail::query()->where('IdKelompokPajak', $kelompok->Id)->orderBy('Urutan')->pluck('Urutan')->all())->toBe($kode === [] ? [] : range(1, count($kode)))
            ->and(LogAudit::query()->where('Peristiwa', 'kelompok-pajak.buat')->sole()->NilaiBaru)
            ->toEqual(['Nama' => 'Kelompok '.$kategori->value, 'Kategori' => $kategori->value, 'Pajak' => array_map(fn (string $k): array => ['KodeJenisPajak' => $k, 'DasarPengenaan' => 'Subtotal', 'KenaBiayaKirim' => false], $kode)]);
    })->with([
        'Kena PPN' => [KategoriPajakProduk::KenaPpn, ['Ppn']],
        'Kena PB1' => [KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman']],
        'Kena PB1 + pajak daerah lain' => [KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman', 'PbjtJasaHiburan']],
        'Bebas PPN' => [KategoriPajakProduk::BebasPpn, []],
        'Non-pajak' => [KategoriPajakProduk::NonPajak, []],
        'Pajak lain' => [KategoriPajakProduk::Lainnya, ['PbjtJasaHiburan']],
    ]);

    it('kombinasi kategori dan detail yang tidak sesuai ditolak (KelompokPajakTidakSesuai)', function (KategoriPajakProduk $kategori, array $kode): void {
        expect(fn () => SimpanKelompok('Kelompok salah', $kategori, $kode))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect($galat->kode)->toBe('KelompokPajakTidakSesuai'));
        expect(KelompokPajak::query()->count())->toBe(0);
    })->with([
        'Kena PPN tanpa PPN' => [KategoriPajakProduk::KenaPpn, []],
        'Kena PPN + PB1' => [KategoriPajakProduk::KenaPpn, ['Ppn', 'PbjtMakananMinuman']],
        'Kena PB1 tanpa PB1' => [KategoriPajakProduk::KenaPbjt, ['PbjtJasaHiburan']],
        'Kena PB1 + PPN (§12.1)' => [KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman', 'Ppn']],
        'Bebas PPN berisi PPN' => [KategoriPajakProduk::BebasPpn, ['Ppn']],
        'Non-pajak berisi pajak' => [KategoriPajakProduk::NonPajak, ['PbjtJasaHiburan']],
        'Pajak lain berisi PPN' => [KategoriPajakProduk::Lainnya, ['Ppn']],
        'Pajak lain berisi PB1' => [KategoriPajakProduk::Lainnya, ['PbjtMakananMinuman']],
        'jenis pajak tidak dikenal' => [KategoriPajakProduk::Lainnya, ['PajakKarangan']],
        'jenis pajak ganda' => [KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman', 'PbjtMakananMinuman']],
    ]);

    it('nama kelompok pajak unik tanpa beda huruf besar/kecil (KelompokPajakGanda)', function (): void {
        SimpanKelompok('Makan & minum', KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman']);

        expect(fn () => SimpanKelompok('  MAKAN & MINUM ', KategoriPajakProduk::NonPajak, []))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect([$galat->kode, $galat->bidang])->toBe(['KelompokPajakGanda', 'Nama']));
    });

    it('mengubah kelompok mengganti detail, mencatat audit ubah, dan memperbarui DiubahPada', function (): void {
        $kelompok = SimpanKelompok('Barang dagang', KategoriPajakProduk::NonPajak, []);
        DB::table('KelompokPajak')->where('Id', $kelompok->Id)->update(['DiubahPada' => now()->subDay()]);

        $kelompok = SimpanKelompok('Barang kena PPN', KategoriPajakProduk::KenaPpn, ['Ppn'], $kelompok->refresh());

        expect($kelompok->refresh()->only(['Nama']))->toBe(['Nama' => 'Barang kena PPN'])
            ->and($kelompok->Kategori)->toBe(KategoriPajakProduk::KenaPpn)
            ->and(KodeDetail($kelompok))->toBe(['Ppn'])
            ->and($kelompok->DiubahPada?->isToday())->toBeTrue();

        $audit = LogAudit::query()->where('Peristiwa', 'kelompok-pajak.ubah')->sole();
        expect($audit->NilaiLama)->toEqual(['Nama' => 'Barang dagang', 'Kategori' => 'NonPajak', 'Pajak' => []])
            ->and($audit->NilaiBaru)->toEqual(['Nama' => 'Barang kena PPN', 'Kategori' => 'KenaPpn', 'Pajak' => [['KodeJenisPajak' => 'Ppn', 'DasarPengenaan' => 'Subtotal', 'KenaBiayaKirim' => false]]]);
    });

    it('perubahan detail saja memperbarui DiubahPada kelompok (untuk katalog POS delta)', function (): void {
        $kelompok = SimpanKelompok('Hiburan', KategoriPajakProduk::Lainnya, ['PbjtJasaHiburan']);
        DB::table('KelompokPajak')->where('Id', $kelompok->Id)->update(['DiubahPada' => now()->subDay()]);

        SimpanKelompok('Hiburan', KategoriPajakProduk::Lainnya, [], $kelompok->refresh());

        expect(KodeDetail($kelompok))->toBe([])
            ->and($kelompok->refresh()->DiubahPada?->isToday())->toBeTrue();
    });

    it('simpan ulang tanpa perubahan tidak menulis audit ubah', function (): void {
        $kelompok = SimpanKelompok('Makan & minum', KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman']);

        SimpanKelompok('Makan & minum', KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman'], $kelompok);

        expect(LogAudit::query()->where('Peristiwa', 'kelompok-pajak.ubah')->exists())->toBeFalse();
    });

    it('menolak nama kosong', function (): void {
        expect(fn () => SimpanKelompok('   ', KategoriPajakProduk::NonPajak, []))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect([$galat->kode, $galat->bidang])->toBe(['NamaTidakValid', 'Nama']));
    });
});

describe('F-03 DaftarKelompokPajak (kueri untuk Tim 1 & Tim 4)', function (): void {
    it('AmbilOpsi mengembalikan Id, Uuid, Nama, Kategori, dan LabelKategori urut nama', function (): void {
        $pb1 = SimpanKelompok('Makan & minum', KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman']);
        $lama = KelompokPajak::query()->create(['Nama' => 'Lama tanpa kategori']);
        $bebas = SimpanKelompok('Bebas pajak', KategoriPajakProduk::NonPajak, []);

        expect(app(DaftarKelompokPajak::class)->AmbilOpsi())->toBe([
            ['Id' => $bebas->Id, 'Uuid' => $bebas->Uuid, 'Nama' => 'Bebas pajak', 'Kategori' => 'NonPajak', 'LabelKategori' => 'Non-pajak'],
            ['Id' => $lama->Id, 'Uuid' => $lama->Uuid, 'Nama' => 'Lama tanpa kategori', 'Kategori' => null, 'LabelKategori' => 'Belum dikategorikan'],
            ['Id' => $pb1->Id, 'Uuid' => $pb1->Uuid, 'Nama' => 'Makan & minum', 'Kategori' => 'KenaPbjt', 'LabelKategori' => 'Kena PB1 (PBJT)'],
        ]);
    });

    it('CariIdBerdasarkanKategori memilih Id terkecil; CariIdBerdasarkanNama tanpa beda huruf. Hanya tenant aktif', function (): void {
        $pertama = SimpanKelompok('Makan & minum', KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman']);
        SimpanKelompok('Minuman kemasan', KategoriPajakProduk::KenaPbjt, ['PbjtMakananMinuman']);
        $kueri = app(DaftarKelompokPajak::class);

        expect($kueri->CariIdBerdasarkanKategori(KategoriPajakProduk::KenaPbjt))->toBe($pertama->Id)
            ->and($kueri->CariIdBerdasarkanKategori(KategoriPajakProduk::KenaPpn))->toBeNull()
            ->and($kueri->CariIdBerdasarkanNama(' MAKAN & MINUM '))->toBe($pertama->Id);

        BantuanKatalog::BuatTenant('Toko Sinar Terang');
        expect($kueri->CariIdBerdasarkanKategori(KategoriPajakProduk::KenaPbjt))->toBeNull()
            ->and($kueri->CariIdBerdasarkanNama('Makan & minum'))->toBeNull()
            ->and($kueri->AmbilOpsi())->toBe([]);

        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        expect($kueri->AmbilOpsi())->toHaveCount(2);
    });
});

it('migrasi 000124 mengisi Kategori kelompok pajak lama dari detailnya', function (): void {
    $jenis = JenisPajak::query()->pluck('Id', 'Kode');
    $buat = function (string $nama, array $kode) use ($jenis): int {
        $kelompok = KelompokPajak::query()->create(['Nama' => $nama]);

        foreach (array_values($kode) as $i => $k) {
            KelompokPajakDetail::query()->create(['IdKelompokPajak' => $kelompok->Id, 'IdJenisPajak' => $jenis[$k], 'DasarPengenaan' => DasarPengenaanPajak::Subtotal, 'Urutan' => $i + 1]);
        }

        return $kelompok->Id;
    };
    $id = [
        'KenaPpn' => $buat('Barang kena PPN', ['Ppn']),
        'KenaPbjt' => $buat('Makan & minum', ['PbjtMakananMinuman']),
        'NonPajak' => $buat('Tanpa pajak', []),
        'Lainnya' => $buat('Hiburan', ['PbjtJasaHiburan']),
    ];
    $sudahAda = KelompokPajak::query()->create(['Nama' => 'Sudah dikategorikan', 'Kategori' => KategoriPajakProduk::BebasPpn]);

    $migrasi = require database_path('migrations/2026_09_27_000124_TambahKolomKategoriKeKelompokPajak.php');
    $migrasi->IsiKategori();

    foreach ($id as $kategori => $idKelompok) {
        expect(KelompokPajak::query()->whereKey($idKelompok)->sole()->Kategori)->toBe(KategoriPajakProduk::from($kategori));
    }

    expect($sudahAda->refresh()->Kategori)->toBe(KategoriPajakProduk::BebasPpn)
        ->and(collect(Schema::getColumns('KelompokPajak'))->firstWhere('name', 'Kategori')['nullable'] ?? null)->toBeTrue();
});
