<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Aksi\PastikanSatuanStandar;
use App\Domain\Katalog\Aksi\TambahProdukCepat;
use App\Domain\Katalog\Data\DataProdukCepat;
use App\Domain\Katalog\Data\DataSatuanStandar;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\PanduanAwal\Kueri\LangkahBerikutnya;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Persediaan\BantuanLaporan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('F-01: progres wizard (lewati, lanjutkan, selesai)', function (): void {
    it('langkah bisa dilewati lalu diselesaikan; Selesai tidak turun menjadi Dilewati', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        $tes()->post('/kelola/panduan-awal/langkah/sektor/lewati')
            ->assertRedirect('/kelola/panduan-awal/pajak')
            ->assertSessionHas('Kilat', 'Langkah Jenis usaha & template dilewati. Anda bisa kembali kapan saja.');
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(ProgresPanduanAwal::query()->sole()->StatusLangkah['Sektor']['Status'])->toBe('Dilewati')
            ->and(ProgresPanduanAwal::query()->sole()->IdOutlet)->toBe($outlet->Id);

        $tes()->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => 'FNB-CAF'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(ProgresPanduanAwal::query()->sole()->StatusLangkah['Sektor']['Status'])->toBe('Selesai');

        $tes()->post('/kelola/panduan-awal/langkah/sektor/lewati')->assertRedirect('/kelola/panduan-awal/pajak');
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(ProgresPanduanAwal::query()->sole()->StatusLangkah['Sektor']['Status'])->toBe('Selesai')
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'panduan-awal.lewati')->count())->toBe(1);

        $tes()->post('/kelola/panduan-awal/langkah/produk/selesai')->assertRedirect('/kelola/panduan-awal/metode-pembayaran');
        $tes()->post('/kelola/panduan-awal/langkah/perangkat/selesai')->assertRedirect('/kelola/panduan-awal');

        $tes()->get('/kelola/panduan-awal')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/PanduanAwal/Indeks')
                ->where('Progres.Langkah', fn ($langkah) => collect($langkah)->pluck('Status', 'Kunci')->all() === [
                    'ProfilUsaha' => 'Belum', 'Sektor' => 'Selesai', 'Pajak' => 'Belum', 'Produk' => 'Selesai', 'MetodePembayaran' => 'Belum', 'Perangkat' => 'Selesai',
                ])
                ->where('Progres.Langkah.3.Slug', 'produk')
                ->where('Progres.Langkah.3.Judul', 'Produk awal')
                ->where('Progres.Langkah.3.Tautan', route('kelola.panduan-awal.produk'))
                ->where('Progres.SelesaiPada', null)
                ->where('Progres.Outlet', ['Uuid' => $outlet->Uuid, 'Kode' => $outlet->Kode, 'Nama' => $outlet->Nama]));
    });

    it('slug langkah tidak dikenal → 404', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/langkah/stok-awal/lewati')->assertNotFound();
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/langkah/ProfilUsaha/selesai')->assertNotFound();
    });

    it('selesaikan panduan mengisi SelesaiPada sekali (idempoten) dan mencatat audit sekali', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/selesai')
            ->assertRedirect('/kelola')
            ->assertSessionHas('Kilat', 'Panduan awal selesai. Lanjutkan dengan langkah berikutnya di bawah.');
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $pertama = ProgresPanduanAwal::query()->sole();
        $this->travel(5)->minutes();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/selesai')->assertRedirect('/kelola');
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $kedua = ProgresPanduanAwal::query()->sole();
        expect($kedua->SelesaiPada?->equalTo($pertama->SelesaiPada))->toBeTrue()
            ->and($kedua->IdPenggunaPenyelesai)->toBe($pemilik->Id)
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'panduan-awal.selesai')->count())->toBe(1);
    });
});

/**
 * Checklist "Langkah berikutnya" (kueri `LangkahBerikutnya`) untuk satu anggota.
 *
 * @return list<array{Kunci: string, Judul: string, Keterangan: string, Tautan: string, Selesai: bool}>
 */
function AmbilLangkahUji(Pengguna $pengguna, int $idTenant): array
{
    BantuanOrganisasi::AturKonteks($idTenant);

    return app(LangkahBerikutnya::class)->Ambil($idTenant, $pengguna->Id);
}

describe('F-01 langkah 7 (D-24: kini butir "Persiapan toko" di Kotak Tindakan): checklist "Langkah berikutnya"', function (): void {
    it('status item mengikuti data: produk ada, metode pembayaran, perangkat aktif, staf diundang, PIN sendiri', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        $langkah = AmbilLangkahUji($pemilik, $tenant->Id);
        expect(collect($langkah)->pluck('Selesai', 'Kunci')->all())->toBe([
            'PanduanAwal' => false, 'TambahProduk' => false, 'AturMetodePembayaran' => false, 'AktifkanPerangkat' => false, 'UndangStaf' => false, 'AturPin' => false,
        ])->and($langkah[0]['Tautan'])->toBe(route('kelola.panduan-awal'));

        // Butir yang belum selesai tampil di Kotak Tindakan & ringkasan Beranda; Beranda tidak lagi memuat checklist.
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Beranda')
                ->missing('LangkahBerikutnya')
                ->where('Tindakan', fn ($butir) => collect($butir)->pluck('Kunci')->intersect(['awal.PanduanAwal', 'awal.AturPin'])->count() === 2));

        BantuanOrganisasi::AturKonteks($tenant->Id);
        app(TambahProdukCepat::class)->Jalankan([new DataProdukCepat('Kopi Susu Aren', Uang::Dari('18000'), null, app(PastikanSatuanStandar::class)->Jalankan(new DataSatuanStandar('PCS', 'Pcs', 'pcs', false)), JenisProduk::NonStok, null)]);
        BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanPerangkat::AturPin($tenant->Id, $pemilik->Id, '482915');

        // Yang belum selesai tampil lebih dulu.
        expect(collect(AmbilLangkahUji($pemilik, $tenant->Id))->map(fn ($baris) => [$baris['Kunci'], $baris['Selesai']])->all())->toBe([
            ['PanduanAwal', false], ['AturMetodePembayaran', false],
            ['TambahProduk', true], ['AktifkanPerangkat', true], ['UndangStaf', true], ['AturPin', true],
        ]);
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/tindakan')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Butir', fn ($butir) => collect($butir)->filter(fn ($b) => str_starts_with($b['Kunci'], 'awal.'))->pluck('Kunci')->sort()->values()->all() === ['awal.AturMetodePembayaran', 'awal.PanduanAwal']));
        expect(Satuan::query()->count())->toBe(1);
    });

    it('item tanpa izin tidak tampil. Semua selesai → daftar kosong', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $manajer = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::ManajerOutlet);

        expect(collect(AmbilLangkahUji($kasir, $tenant->Id))->pluck('Kunci')->all())->toBe(['AturPin'])
            ->and(collect(AmbilLangkahUji($manajer, $tenant->Id))->pluck('Kunci')->all())->toBe(['AktifkanPerangkat', 'AturPin']);

        BantuanPerangkat::AturPin($tenant->Id, $kasir->Id, '193847');
        expect(AmbilLangkahUji($kasir, $tenant->Id))->toBe([]);
    });

    it('F-05a butir "Isi stok awal": tampil bila ada produk berstok & izin persediaan.kelola. Selesai hanya bila ada stok awal Diposting', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        $stafGudang = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::StafGudang);
        $butirStokAwal = fn (Pengguna $pengguna): ?array => collect(AmbilLangkahUji($pengguna, $tenant->Id))->firstWhere('Kunci', 'StokAwal');

        // Belum ada produk berstok (Jasa/NonStok tidak dihitung) → butir tidak tampil.
        BantuanOrganisasi::AturKonteks($tenant->Id);
        BantuanKatalog::BuatProduk(['Nama' => 'Jasa Antar Belanja Dalam Kota', 'Jenis' => JenisProduk::Jasa]);
        expect($butirStokAwal($pemilik))->toBeNull();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pandan Wangi Cianjur Premium 5 kg', 'Jenis' => JenisProduk::Stok], '82000.00');
        $gudang = Gudang::query()->where('IdOutlet', $outlet->Id)->orderBy('Id')->firstOrFail();

        expect($butirStokAwal($pemilik))->toBe([
            'Kunci' => 'StokAwal',
            'Judul' => 'Isi stok awal',
            'Keterangan' => 'Jumlah & harga modal barang yang sudah ada, supaya stok dan HPP benar.',
            'Tautan' => route('kelola.persediaan.stok-awal.daftar'),
            'Selesai' => false,
        ])
            ->and($butirStokAwal($stafGudang)['Selesai'] ?? null)->toBeFalse()
            ->and($butirStokAwal($kasir))->toBeNull();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        BantuanLaporan::BuatStokAwal($gudang, StatusStokAwal::Draf, [[$beras, '40.0000', '75000']]);
        BantuanLaporan::BuatStokAwal($gudang, StatusStokAwal::Dibatalkan, [[$beras, '40.0000', '75000']]);
        expect($butirStokAwal($pemilik)['Selesai'] ?? null)->toBeFalse();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        BantuanLaporan::BuatStokAwal($gudang, StatusStokAwal::Diposting, [[$beras, '40.0000', '75000']]);
        expect($butirStokAwal($pemilik)['Selesai'] ?? null)->toBeTrue();
    });
});
