<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\TambahkanAkunTemplate;
use App\Domain\Akuntansi\Data\DataAkunTemplate;
use App\Domain\Akuntansi\Enum\SaldoNormal;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Aksi\PastikanSatuanStandar;
use App\Domain\Katalog\Aksi\TambahkanKategoriTemplate;
use App\Domain\Katalog\Aksi\TambahkanSatuanTemplate;
use App\Domain\Katalog\Data\DataSatuanStandar;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Aksi\SimpanOutlet;
use App\Domain\Organisasi\Data\DataOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\PeranIzin;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pajak\Aksi\TambahkanKelompokPajakTemplate;
use App\Domain\Pajak\Data\DataKelompokPajakTemplate;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Referensi\Enum\JenisReferensiBank;
use App\Domain\Referensi\Model\ReferensiBank;
use App\Domain\Tenant\Aksi\TambahkanFiturOutletTemplate;
use App\Domain\Tenant\Model\OutletFitur;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Uji adversarial F-01 (Tim QA & Keamanan): idempotensi & aditivitas BR-01.1, urutan kunci kirim ganda, isolasi
 * tenant, izin, kasus tepi validasi uang/berkas, dan log audit tanpa path berkas.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanOrganisasi::BuatKota();
    Storage::fake('local');
    Mail::fake();
});

/**
 * Tabel yang dikunci (FOR UPDATE) selama `$jalankan`, berurutan menurut kunci pertama per tabel.
 *
 * @return list<string>
 */
function RekamUrutanKunciPanduanUji(Closure $jalankan): array
{
    $urutan = [];
    DB::listen(function ($kueri) use (&$urutan): void {
        if (preg_match('/^select .* from `(\w+)` .*for update$/i', $kueri->sql, $cocok) === 1 && ! in_array($cocok[1], $urutan, true)) {
            $urutan[] = $cocok[1];
        }
    });
    $jalankan();

    return $urutan;
}

/**
 * Unggahan sungguhan (bukan `UploadedFile::fake()`, yang menebak MIME dari nama berkas): jenis ditebak dari isi
 * berkas seperti di produksi.
 */
function BuatUnggahanIsiUji(string $nama, string $isi): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'unggah');
    file_put_contents((string) $path, $isi);

    return new UploadedFile((string) $path, $nama, null, null, true);
}

describe('BR-01.1 idempoten & aditif: A → B → A', function (): void {
    it('tidak menggandakan data template, tidak menimpa suntingan tenant, dan mencatat versi terakhir di outlet', function (): void {
        $versiKafe = BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        Akun::query()->where('Kode', '1-1100')->update(['Nama' => 'Kas Laci Depan']);
        $kopi = Kategori::query()->where('Nama', 'Kopi')->sole();
        $kopi->fill(['Nama' => 'kopi'])->save();
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => 'Es Kopi Susu Aren', 'Harga' => '18000', 'Kategori' => $kopi->Uuid]]])
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        Produk::query()->update(['Nama' => 'Es Kopi Susu Aren Gula Jawa']);

        BantuanPanduanAwal::Terapkan($outlet->fresh() ?? $outlet, 'RTL-GEN');
        BantuanPanduanAwal::Terapkan($outlet->fresh() ?? $outlet, 'FNB-CAF');
        BantuanPanduanAwal::Terapkan($outlet->fresh() ?? $outlet, 'FNB-CAF');

        $ganda = fn (string $tabel, string $kolom): int => DB::table($tabel)->where('IdTenant', $tenant->Id)
            ->select(DB::raw("LOWER(`{$kolom}`) AS K"))->groupBy('K')->havingRaw('COUNT(*) > 1')->get()->count();
        expect($ganda('Akun', 'Kode'))->toBe(0)
            ->and($ganda('Kategori', 'Nama'))->toBe(0)
            ->and($ganda('Satuan', 'KodeStandar'))->toBe(0)
            ->and($ganda('KelompokPajak', 'Nama'))->toBe(0)
            ->and($ganda('OutletFitur', 'KunciFitur'))->toBe(0)
            ->and(PemetaanAkun::query()->whereNull('IdOutlet')->count())->toBe(PemetaanAkun::query()->whereNull('IdOutlet')->distinct()->count('Kunci'))
            ->and(MetodePembayaran::query()->where('Jenis', 'Tunai')->count())->toBe(1)
            ->and(Akun::query()->where('Kode', '1-1100')->sole()->Nama)->toBe('Kas Laci Depan')
            ->and($kopi->refresh()->Nama)->toBe('kopi')
            ->and(Produk::query()->sole()->Nama)->toBe('Es Kopi Susu Aren Gula Jawa')
            ->and(Produk::query()->sole()->IdKategori)->toBe($kopi->Id);

        expect(Tenant::query()->findOrFail($tenant->Id)->Pengaturan['Sektor'])->toBe(['FNB-CAF', 'RTL-GEN']);
        $outlet = Outlet::query()->findOrFail($outlet->Id);
        expect($outlet->TemplateSektor)->toBe('FNB-CAF')
            ->and($outlet->IdTemplateSektorVersi)->toBe($versiKafe->Id)
            ->and($outlet->TemplateSektorDiterapkanPada)->not->toBeNull()
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'outlet.template.ubah')->count())->toBe(3);
    });

    it('sektor tambahan yang dipilih setelah penerapan pertama ikut tercatat (tidak hilang diam-diam)', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
        BantuanPanduanAwal::TerbitkanTemplate('FNB-QSR');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        $tes()->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => 'FNB-CAF', 'SektorLain' => ['RTL-GEN']])->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => 'FNB-CAF', 'SektorLain' => ['RTL-GEN', 'FNB-QSR']])->assertSessionHasNoErrors();

        expect(Tenant::query()->findOrFail($tenant->Id)->Pengaturan['Sektor'])->toBe(['FNB-CAF', 'RTL-GEN', 'FNB-QSR']);
    });
});

describe('Kirim ganda: urutan kunci Tenant → Langganan → Outlet', function (): void {
    it('setiap POST panduan awal mengunci Tenant sebelum Outlet (mencegah deadlock dengan penerapan template)', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ReferensiBank::query()->create(['Kode' => 'BCA', 'Nama' => 'Bank Central Asia', 'Jenis' => JenisReferensiBank::Bank]);
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);
        $isian = [
            '/kelola/panduan-awal/profil-usaha' => ['NamaUsaha' => 'Kopi Nusantara Laweyan', 'KodeKota' => '33.72', 'Pkp' => '0'],
            '/kelola/panduan-awal/sektor' => ['KodeTemplate' => 'FNB-CAF'],
            '/kelola/panduan-awal/pajak' => ['PungutPbjt' => '1', 'BiayaLayananAktif' => '1', 'PersenBiayaLayanan' => '5', 'HargaTermasukPajak' => '0'],
            '/kelola/panduan-awal/produk' => ['Produk' => [['Nama' => 'Es Teh Manis', 'Harga' => '5000', 'Kategori' => null]]],
            '/kelola/panduan-awal/metode-pembayaran' => ['Jenis' => 'Edc', 'Nama' => 'EDC BCA', 'KodeBank' => 'BCA'],
            '/kelola/panduan-awal/perangkat' => ['Nama' => 'Kasir Depan'],
            '/kelola/panduan-awal/langkah/produk/selesai' => [],
            '/kelola/panduan-awal/selesai' => [],
        ];

        foreach ($isian as $url => $data) {
            $kunci = RekamUrutanKunciPanduanUji(fn () => $tes()->post($url, $data)->assertSessionHasNoErrors());
            $posisiTenant = array_search('Tenant', $kunci, true);
            $posisiOutlet = array_search('Outlet', $kunci, true);

            if ($posisiOutlet !== false && $posisiTenant !== false) {
                expect($posisiTenant)->toBeLessThan($posisiOutlet, "{$url}: ".implode(' → ', $kunci));
            }
        }
    });
});

describe('Aksi publik template mengunci Tenant sendiri', function (): void {
    it('dipanggil langsung tanpa kunci pemanggil, setiap Aksi tetap mengunci baris Tenant lebih dulu', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        $aksi = [
            'TambahkanAkunTemplate' => fn () => app(TambahkanAkunTemplate::class)->Jalankan([new DataAkunTemplate('1-1100', 'Kas Outlet', TipeAkun::Aset, SaldoNormal::Debit)], []),
            'TambahkanKategoriTemplate' => fn () => app(TambahkanKategoriTemplate::class)->Jalankan(['Roti Manis']),
            'TambahkanSatuanTemplate' => fn () => app(TambahkanSatuanTemplate::class)->Jalankan([new DataSatuanStandar('PCS', 'Pcs', 'pcs', false)]),
            'PastikanSatuanStandar' => fn () => app(PastikanSatuanStandar::class)->Jalankan(new DataSatuanStandar('KG', 'Kilogram', 'kg', true)),
            'TambahkanKelompokPajakTemplate' => fn () => app(TambahkanKelompokPajakTemplate::class)->Jalankan([new DataKelompokPajakTemplate('Makan & minum', [])]),
            'TambahkanFiturOutletTemplate' => fn () => app(TambahkanFiturOutletTemplate::class)->Jalankan($outlet->Id, ['pos.retail'], null),
        ];

        foreach ($aksi as $nama => $jalankan) {
            expect(RekamUrutanKunciPanduanUji($jalankan)[0] ?? null)->toBe('Tenant', $nama);
        }
    });

    it('konfigurasi mode kasir yang diisi ke baris pos.retail lama tercatat di log audit', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        OutletFitur::query()->create(['IdOutlet' => $outlet->Id, 'KunciFitur' => 'pos.retail', 'Aktif' => true, 'Konfigurasi' => null]);

        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        $log = LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'outlet.fitur.konfigurasi-template')->sole();
        expect($log->NilaiBaru['Konfigurasi'] ?? null)->toBe(['ModeKasir' => ['Cepat', 'Meja'], 'ModeKasirDefault' => 'Cepat'])
            ->and($log->NilaiLama)->toBe(['Konfigurasi' => null]);
    });
});

describe('Kirim ganda metode pembayaran', function (): void {
    it('mengirim QRIS yang sama dua kali tidak membuat metode ganda maupun berkas yatim', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $isian = fn (): array => ['Jenis' => 'QrisStatis', 'Nama' => 'QRIS Kopi Nusantara', 'GambarQris' => UploadedFile::fake()->image('qris.png', 300, 300)];

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/metode-pembayaran', $isian())->assertSessionHasNoErrors();
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/metode-pembayaran', [...$isian(), 'Nama' => ' qris kopi nusantara '])
            ->assertSessionHasErrors(['Nama' => 'Metode pembayaran dengan nama ini sudah ada.']);

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(MetodePembayaran::query()->where('Jenis', 'QrisStatis')->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles("metode-pembayaran/{$tenant->Id}"))->toHaveCount(1);
    });
});

describe('Langkah hanya Selesai lewat Aksinya', function (): void {
    it('profil usaha, sektor, dan pajak tidak bisa ditandai Selesai langsung (404). Produk, metode pembayaran, perangkat bisa. Semua bisa dilewati', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        // Sektor tanpa template terbit boleh dilanjutkan langsung (panduan tidak macet); di sini ada template terbit.
        BantuanPanduanAwal::TerbitkanTemplate();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        foreach (['profil-usaha', 'sektor', 'pajak'] as $slug) {
            $tes()->post("/kelola/panduan-awal/langkah/{$slug}/selesai")->assertNotFound();
        }

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(ProgresPanduanAwal::query()->count())->toBe(0);

        foreach (['produk', 'metode-pembayaran', 'perangkat'] as $slug) {
            $tes()->post("/kelola/panduan-awal/langkah/{$slug}/selesai")->assertRedirect();
        }

        foreach (['profil-usaha', 'sektor', 'pajak'] as $slug) {
            $tes()->post("/kelola/panduan-awal/langkah/{$slug}/lewati")->assertRedirect();
        }

        $status = array_map(fn (array $langkah) => $langkah['Status'], ProgresPanduanAwal::query()->sole()->StatusLangkah ?? []);
        ksort($status);
        expect($status)->toBe([
            'MetodePembayaran' => 'Selesai', 'Pajak' => 'Dilewati', 'Perangkat' => 'Selesai',
            'Produk' => 'Selesai', 'ProfilUsaha' => 'Dilewati', 'Sektor' => 'Dilewati',
        ]);
    });
});

describe('Perubahan ProfilPajak yang baru di-commit tidak tertimpa', function (): void {
    it('SimpanOutlet menggabungkan ProfilPajak dari baris yang sudah terkunci, bukan dari bacaan sebelum kunci', function (): void {
        ['Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        $outlet = Outlet::query()->with('Merek')->findOrFail($outlet->Id);
        $disisipkan = false;

        // Simulasi langkah pajak (tab lain) yang commit tepat sebelum SimpanOutlet mengunci baris outlet.
        DB::listen(function ($kueri) use (&$disisipkan, $outlet): void {
            if (! $disisipkan && str_contains($kueri->sql, 'from `Merek`')) {
                $disisipkan = true;
                DB::table('Outlet')->where('Id', $outlet->Id)->update(['ProfilPajak' => json_encode([
                    'Pkp' => false, 'PungutPbjt' => true, 'BiayaLayanan' => ['Aktif' => true, 'Persen' => '5.00'], 'HargaTermasukPajak' => true,
                ])]);
            }
        });

        app(SimpanOutlet::class)->Jalankan($outlet, new DataOutlet(
            nama: $outlet->Nama,
            kode: $outlet->Kode,
            uuidMerek: $outlet->Merek->Uuid,
            alamat: 'Jl. Slamet Riyadi No. 1',
            kodeKota: '33.72',
            zonaWaktu: 'WIB',
            jamTutupBuku: $outlet->JamTutupBuku,
            pkp: false,
            nitku: null,
            pungutPbjt: true,
        ));

        $profil = Outlet::query()->findOrFail($outlet->Id)->ProfilPajak;
        expect($disisipkan)->toBeTrue()
            ->and($profil['BiayaLayanan'] ?? null)->toBe(['Aktif' => true, 'Persen' => '5.00'])
            ->and($profil['HargaTermasukPajak'] ?? null)->toBeTrue()
            ->and($profil['PungutPbjt'])->toBeTrue();
    });
});

describe('Isolasi tenant & berkas', function (): void {
    it('Uuid metode pembayaran tenant lain tidak bisa diaktifkan; QRIS tenant lain & path traversal tidak terlayani', function (): void {
        ['Tenant' => $a, 'Pemilik' => $pemilikA] = BantuanPanduanAwal::BuatTenant();
        ['Tenant' => $b, 'Pemilik' => $pemilikB] = BantuanPanduanAwal::BuatTenant('Toko Roti Harum');
        BantuanPanduanAwal::Masuk($this, $pemilikA, $a)->post('/kelola/panduan-awal/metode-pembayaran', [
            'Jenis' => 'QrisStatis', 'Nama' => 'QRIS Kopi', 'GambarQris' => UploadedFile::fake()->image('qris.png', 300, 300),
        ])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($a->Id);
        $qrisA = MetodePembayaran::query()->where('Jenis', 'QrisStatis')->sole();
        BantuanPanduanAwal::Masuk($this, $pemilikA, $a)->post("/kelola/panduan-awal/metode-pembayaran/{$qrisA->Uuid}/nonaktifkan")->assertSessionHasNoErrors();

        BantuanPanduanAwal::Masuk($this, $pemilikA, $a)->post('/kelola/panduan-awal/profil-usaha', [
            'NamaUsaha' => 'Kopi Nusantara', 'KodeKota' => '33.72', 'Pkp' => '0', 'Logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertSessionHasNoErrors();
        BantuanPanduanAwal::Masuk($this, $pemilikA, $a)->get('/kelola/panduan-awal/profil-usaha/logo')->assertOk();

        $tesB = fn () => BantuanPanduanAwal::Masuk($this, $pemilikB, $b);
        $tesB()->get('/kelola/panduan-awal/profil-usaha/logo')->assertNotFound();
        $tesB()->get("/kelola/panduan-awal/metode-pembayaran/{$qrisA->Uuid}/gambar-qris")->assertNotFound();
        $tesB()->post("/kelola/panduan-awal/metode-pembayaran/{$qrisA->Uuid}/aktifkan")->assertNotFound();
        $tesB()->get('/kelola/panduan-awal/metode-pembayaran/..%2F..%2F.env/gambar-qris')->assertNotFound();
        $tesB()->get('/kelola/panduan-awal/profil-usaha/logo?path=../../.env')->assertNotFound();
        expect($qrisA->refresh()->Aktif)->toBeFalse();

        BantuanPanduanAwal::Masuk($this, $pemilikA, $a)->get("/kelola/panduan-awal/metode-pembayaran/{$qrisA->Uuid}/gambar-qris")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private');
    });
});

describe('Izin', function (): void {
    it('peran kustom tanpa panduan-awal.kelola ditolak di GET & POST; Pemilik tetap bisa', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $peran = Peran::query()->create(['Kode' => 'PengecekStok', 'Nama' => 'Pengecek Stok', 'Bawaan' => false]);
        PeranIzin::query()->create(['IdPeran' => $peran->Id, 'KunciIzin' => 'perangkat.kelola']);
        $anggota = Pengguna::factory()->create();
        TenantPengguna::query()->create(['IdTenant' => $tenant->Id, 'IdPengguna' => $anggota->Id, 'Pemilik' => false, 'IdPeran' => $peran->Id, 'SemuaOutlet' => true]);

        BantuanPanduanAwal::Masuk($this, $anggota, $tenant)->get('/kelola/panduan-awal')->assertForbidden();
        BantuanPanduanAwal::Masuk($this, $anggota, $tenant)->post('/kelola/panduan-awal/perangkat', ['Nama' => 'Kasir Depan'])->assertForbidden();
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal')->assertOk();
    });
});

describe('Validasi uang, nama, dan berkas', function (): void {
    it('harga: "0" dan batas DECIMAL(18,2) diterima persis. Negatif, notasi ilmiah, koma, angka Unicode, dan array ditolak tanpa 500', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        foreach (['-1', '1e5', '12,5', '١٥٠٠٠', '１５０００', '99999999999999999', '1.005', '0x1F', ' '] as $harga) {
            $tes()->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => "Produk {$harga}", 'Harga' => $harga, 'Kategori' => null]]])
                ->assertSessionHasErrors('Produk.0.Harga');
        }

        $tes()->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => 'Array', 'Harga' => ['15000'], 'Kategori' => null]]])->assertSessionHasErrors('Produk.0.Harga');
        $tes()->post('/kelola/panduan-awal/produk', ['Produk' => 'bukan-daftar'])->assertSessionHasErrors('Produk');
        $tes()->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => ['x'], 'Harga' => '1000']]])->assertSessionHasErrors('Produk.0.Nama');
        $tes()->post('/kelola/panduan-awal/produk/contoh', ['ProdukContoh' => [['Nama' => 'Kopi', 'Harga' => '1e3']]])->assertSessionHasErrors('ProdukContoh.0.Harga');

        $tes()->post('/kelola/panduan-awal/produk', ['Produk' => [
            ['Nama' => 'Air Putih Gratis', 'Harga' => '0', 'Kategori' => null],
            ['Nama' => 'Paket Batas Atas', 'Harga' => '9999999999999999.99', 'Kategori' => null],
        ]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Produk::query()->where('Nama', 'Air Putih Gratis')->sole()->Harga->sole()->Harga)->toBe('0.00')
            ->and(Produk::query()->where('Nama', 'Paket Batas Atas')->sole()->Harga->sole()->Harga)->toBe('9999999999999999.99');
    });

    it('persen biaya MDR & service charge: notasi ilmiah, koma, negatif, Unicode ditolak tanpa 500', function (): void {
        ReferensiBank::query()->create(['Kode' => 'BCA', 'Nama' => 'Bank Central Asia', 'Jenis' => JenisReferensiBank::Bank]);
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        foreach (['1e1', '0,7', '-1', '٥', '10.00001', '11'] as $persen) {
            $tes()->post('/kelola/panduan-awal/metode-pembayaran', ['Jenis' => 'Edc', 'Nama' => 'EDC BCA', 'KodeBank' => 'BCA', 'PersenBiaya' => $persen])
                ->assertSessionHasErrors('PersenBiaya');
            $tes()->post('/kelola/panduan-awal/pajak', ['PungutPbjt' => '0', 'BiayaLayananAktif' => '1', 'PersenBiayaLayanan' => $persen, 'HargaTermasukPajak' => '0'])
                ->assertSessionHasErrors('PersenBiayaLayanan');
        }

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(MetodePembayaran::query()->count())->toBe(1);
    });

    it('nama Indonesia panjang: 150 karakter multibyte diterima utuh, 151 ditolak', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $nama = mb_substr(str_repeat('Kue Lapis Legit Spesial Ñ Ramadhan Berkah é ', 5), 0, 150);
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        $tes()->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => $nama.'x', 'Harga' => '1000', 'Kategori' => null]]])->assertSessionHasErrors('Produk.0.Nama');
        $tes()->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => $nama, 'Harga' => '1000', 'Kategori' => null]]])->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/profil-usaha', ['NamaUsaha' => $nama.'x', 'KodeKota' => '33.72', 'Pkp' => '0'])->assertSessionHasErrors('NamaUsaha');
        $tes()->post('/kelola/panduan-awal/profil-usaha', ['NamaUsaha' => $nama, 'KodeKota' => '33.72', 'Pkp' => '0'])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Produk::query()->sole()->Nama)->toBe(trim($nama))
            ->and(Tenant::query()->findOrFail($tenant->Id)->Nama)->toBe(trim($nama));
    });

    it('unggahan: SVG berskrip, ekstensi palsu, berkas kosong, dan berkas terlalu besar ditolak untuk logo & QRIS', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';
        $berkasJahat = fn (): array => [
            BuatUnggahanIsiUji('logo.svg', $svg),
            BuatUnggahanIsiUji('logo.png', $svg),
            BuatUnggahanIsiUji('logo.png', '<?php echo "x"; ?>'),
            BuatUnggahanIsiUji('logo.jpg', '<html><script>alert(1)</script></html>'),
            BuatUnggahanIsiUji('logo.png', ''),
            UploadedFile::fake()->image('logo.png', 3000, 3000)->size(5000),
        ];

        foreach ($berkasJahat() as $berkas) {
            $tes()->post('/kelola/panduan-awal/profil-usaha', ['NamaUsaha' => 'Kopi', 'KodeKota' => '33.72', 'Pkp' => '0', 'Logo' => $berkas])->assertSessionHasErrors('Logo');
        }

        foreach ($berkasJahat() as $berkas) {
            $tes()->post('/kelola/panduan-awal/metode-pembayaran', ['Jenis' => 'QrisStatis', 'Nama' => 'QRIS', 'GambarQris' => $berkas])->assertSessionHasErrors('GambarQris');
        }

        expect(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('Pajak & PKP', function (): void {
    it('tenant non-PKP tidak bisa memaksa PPN lewat isian pajak. Berpindah PKP setelah penerapan mengikuti profil usaha', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        $tes()->post('/kelola/panduan-awal/profil-usaha', ['NamaUsaha' => 'Kopi', 'KodeKota' => '33.72', 'Pkp' => '0'])->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/pajak', ['PungutPbjt' => '1', 'BiayaLayananAktif' => '0', 'HargaTermasukPajak' => '0', 'Pkp' => '1', 'PungutPpn' => '1'])->assertSessionHasNoErrors();
        expect(Outlet::query()->findOrFail($outlet->Id)->ProfilPajak['Pkp'])->toBeFalse()
            ->and(Tenant::query()->findOrFail($tenant->Id)->Pkp)->toBeFalse();

        $tes()->post('/kelola/panduan-awal/profil-usaha', ['NamaUsaha' => 'Kopi', 'KodeKota' => '33.72', 'Pkp' => '1', 'Npwp' => '012345678901000'])->assertSessionHasNoErrors();
        $profil = Outlet::query()->findOrFail($outlet->Id)->ProfilPajak;
        expect($profil['Pkp'])->toBeTrue()
            ->and($profil['PungutPbjt'])->toBeTrue()
            ->and($profil['BiayaLayanan']['Aktif'] ?? null)->toBeFalse();
    });
});

describe('Log audit', function (): void {
    it('seluruh wizard mencatat log audit tenant tanpa path berkas', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);
        $awal = (int) LogAudit::query()->max('Id');

        $tes()->post('/kelola/panduan-awal/profil-usaha', ['NamaUsaha' => 'Kopi', 'KodeKota' => '33.72', 'Pkp' => '0', 'Logo' => UploadedFile::fake()->image('logo.png', 64, 64)])->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/sektor', ['KodeTemplate' => 'FNB-CAF'])->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/pajak', ['PungutPbjt' => '1', 'BiayaLayananAktif' => '0', 'HargaTermasukPajak' => '0'])->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => 'Es Teh', 'Harga' => '5000', 'Kategori' => null]]])->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', ['Jenis' => 'QrisStatis', 'Nama' => 'QRIS', 'GambarQris' => UploadedFile::fake()->image('qris.png', 64, 64)])->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/langkah/perangkat/lewati')->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/selesai')->assertSessionHasNoErrors();

        $log = LogAudit::query()->where('Id', '>', $awal)->get();
        $peristiwa = $log->pluck('Peristiwa')->unique()->values()->all();
        expect($log->every(fn (LogAudit $baris) => $baris->IdTenant === $tenant->Id))->toBeTrue()
            ->and($peristiwa)->toContain('tenant.profil.ubah', 'template-sektor.terapkan', 'outlet.pajak.ubah', 'produk.buat', 'metode-pembayaran.buat', 'panduan-awal.lewati', 'panduan-awal.selesai');

        $teks = json_encode($log->map(fn (LogAudit $baris) => [$baris->NilaiLama, $baris->NilaiBaru])->all(), JSON_UNESCAPED_SLASHES);
        expect($teks)->not->toContain("tenant/{$tenant->Id}/")
            ->and($teks)->not->toContain("metode-pembayaran/{$tenant->Id}/")
            ->and($teks)->not->toContain('PathLogo')
            ->and($teks)->not->toContain('PathGambarQris');
    });
});

arch('CLAUDE.md #14: Kueri & Layanan PanduanAwal tidak memakai Model domain lain (hanya DTO/Kueri publik)')
    ->expect(['App\Domain\PanduanAwal\Kueri', 'App\Domain\PanduanAwal\Layanan'])
    ->not->toUse([
        'App\Domain\Akuntansi\Model',
        'App\Domain\Katalog\Model',
        'App\Domain\Organisasi\Model',
        'App\Domain\Pajak\Model',
        'App\Domain\Penjualan\Model',
        'App\Domain\Referensi\Model',
        'App\Domain\Tenant\Model',
    ]);
