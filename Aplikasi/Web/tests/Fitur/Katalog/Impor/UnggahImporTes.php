<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProdukBaris;
use App\Domain\Katalog\Model\Produk;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

describe('F-03 BR-03.6 unggah berkas impor', function (): void {
    it('CSV: BOM, pemisah titik koma, Windows-1252 (é, ñ) dibaca benar; judul & contoh untuk pemetaan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $bom = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv([
            ['Nama Produk', 'Harga Jual', 'Kelompok Pajak'],
            ['Es Kopi Susu Gula Aren', '18.000', 'Barang kena PPN'],
            ['Croissant Cokelat', '22.500,50', 'Barang kena PPN'],
        ], ';', true));
        expect($bom->Format)->toBe('csv')
            ->and($bom->AmbilOpsiPembaca()['PemisahCsv'])->toBe(';')
            ->and($bom->KolomSumber[0])->toEqual(['Indeks' => 0, 'Judul' => 'Nama Produk', 'Contoh' => ['Es Kopi Susu Gula Aren', 'Croissant Cokelat']])
            ->and($bom->Pemetaan)->toMatchArray(['Nama' => 0, 'HargaJual' => 1, 'KelompokPajak' => 2]);

        $win = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv([
            ['Nama Produk', 'Merek', 'Harga Jual', 'Kelompok Pajak'],
            ['Crème Brûlée Vanila', 'Señor Pâtisserie', '35000', 'Barang kena PPN'],
        ], "\t", false, 'Windows-1252'));
        expect($win->AmbilOpsiPembaca()['PemisahCsv'])->toBe("\t")
            ->and($win->KolomSumber[0]['Contoh'])->toBe(['Crème Brûlée Vanila']);
        // Berkas tersimpan sudah UTF-8; hash dihitung dari berkas asli.
        expect(mb_check_encoding((string) Storage::disk('local')->get($win->PathBerkas), 'UTF-8'))->toBeTrue();

        BantuanImpor::Petakan($masuk, $win)->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$win->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('Nama', 'Crème Brûlée Vanila')->sole()->Merek)->toBe('Señor Pâtisserie');
    });

    it('jenis berkas diperiksa dari isi, bukan nama: gambar/teks biner, xls lama, dan isi yang tidak cocok ekstensinya ditolak', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $png = "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR".str_repeat("\x00", 40);
        $xlsxAsli = file_get_contents(BantuanImpor::BuatXlsx([['Nama Produk'], ['Kopi']])->getRealPath());

        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah($png, 'produk.csv'), 'Sumber' => 'Umum'])
            ->assertSessionHasErrors(['Berkas' => 'Isi berkas bukan Excel .xlsx atau teks CSV.']);
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 40), 'produk.xlsx'), 'Sumber' => 'Umum'])
            ->assertSessionHasErrors(['Berkas' => 'Berkas Excel lama (.xls) belum didukung. Simpan ulang sebagai .xlsx lalu unggah lagi.']);
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah("Nama Produk,Harga\nKopi,1000\n", 'produk.xlsx'), 'Sumber' => 'Umum'])
            ->assertSessionHasErrors(['Berkas' => 'Isi berkas adalah csv, tidak sesuai dengan nama berkas .xlsx.']);
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah((string) $xlsxAsli, 'produk.csv'), 'Sumber' => 'Umum'])
            ->assertSessionHasErrors(['Berkas' => 'Isi berkas adalah xlsx, tidak sesuai dengan nama berkas .csv.']);
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah("PK\x03\x04bukan-xlsx", 'produk.xlsx'), 'Sumber' => 'Umum'])
            ->assertSessionHasErrors(['Berkas' => 'Isi berkas bukan lembar kerja Excel .xlsx yang sah.']);
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah("Nama,Harga\n", 'produk.txt'), 'Sumber' => 'Umum'])
            ->assertSessionHasErrors(['Berkas' => 'Format berkas harus .xlsx atau .csv.']);
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah("Nama Produk\n", 'produk.csv'), 'Sumber' => 'Umum'])
            ->assertSessionHasErrors(['Berkas' => 'Berkas tidak berisi baris produk di bawah judul kolom.']);
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatCsv([['Nama Produk'], ['Kopi']]), 'Sumber' => 'Shopee'])
            ->assertSessionHasErrors('Sumber');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ImporProduk::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles('impor'))->toBe([]);
    });

    it('batas ukuran (UkuranMaksimalKb) dan batas baris (MaksimalBaris) ditegakkan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        config(['katalog.Impor.MaksimalBaris' => 3]);
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatCsv([['Nama Produk'], ['A1'], ['A2'], ['A3'], ['A4']]), 'Sumber' => 'Umum'])
            ->assertSessionHasErrors(['Berkas' => 'Berkas berisi lebih dari 3 baris produk. Bagi berkas menjadi beberapa bagian.']);
        BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv([['Nama Produk'], ['A1'], [''], ['A2'], ['A3']]));

        config(['katalog.Impor.UkuranMaksimalKb' => 1]);
        $besar = [['Nama Produk']];
        foreach (range(1, 3) as $nomor) {
            $besar[] = [str_repeat("Produk Panjang {$nomor} ", 30)];
        }
        $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatCsv($besar), 'Sumber' => 'Umum'])->assertSessionHasErrors('Berkas');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ImporProduk::query()->count())->toBe(1);
    });

    it('halaman riwayat: preset (Asumsi), batas berkas, BatasSku; unggah dicatat audit; berkas sama pernah diimpor ditandai', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $baris = [['Nama Produk', 'SKU', 'Harga Jual', 'Kelompok Pajak'], ['Kopi Susu', 'KPS-1', '18000', 'Barang kena PPN']];
        $pertama = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv($baris));
        BantuanImpor::Petakan($masuk, $pertama)->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$pertama->Uuid}/terapkan")->assertSessionHasNoErrors();
        $kedua = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv($baris));

        expect($kedua->HashBerkas)->toBe($pertama->HashBerkas);

        $masuk->get('/kelola/produk/impor')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Produk/Impor/Daftar')
            ->has('Riwayat.Data', 2)
            ->where('Riwayat.Data.0.Uuid', $kedua->Uuid)
            ->where('Riwayat.Data.0.Status', 'MenungguPemetaan')
            ->where('Riwayat.Data.0.BerkasPernahDiimpor', fn (?string $tanggal): bool => $tanggal !== null)
            ->where('Riwayat.Data.1.Status', 'Selesai')
            ->where('Riwayat.Data.1.JumlahDibuat', 1)
            ->where('Riwayat.Data.1.BerkasPernahDiimpor', null)
            ->where('Riwayat.Data.1.NamaPengguna', fn (?string $nama): bool => $nama !== null)
            ->where('Preset.0', ['Kode' => 'Umum', 'Nama' => 'Templat bawaan (Excel/CSV)', 'Keterangan' => 'Templat bawaan dan hasil ekspor daftar produk. Unduh templat, isi, lalu unggah kembali.', 'Asumsi' => false])
            ->where('Preset.1.Asumsi', true)
            ->where('BatasBerkas', ['UkuranMaksimalKb' => 10240, 'MaksimalBaris' => 20000, 'Ekstensi' => ['xlsx', 'csv']])
            ->where('BatasSku.Terpakai', 1)
            ->where('Izin.Kelola', true));

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $audit = LogAudit::query()->where('Peristiwa', 'produk.impor.unggah')->where('IdObjek', $kedua->Id)->sole();
        expect($audit->NilaiBaru)->toMatchArray(['Sumber' => 'Umum', 'Format' => 'csv', 'JumlahBaris' => 1]);
    });

    it('templat xlsx/csv berisi judul kolom Umum yang dikenali pemetaan otomatis', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $xlsx = BantuanImpor::BacaUnduhan($masuk->get('/kelola/produk/impor/templat?format=xlsx')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff'));
        $csv = BantuanImpor::BacaUnduhan($masuk->get('/kelola/produk/impor/templat?format=csv')->assertOk(), 'csv');

        expect($xlsx)->toHaveCount(1)
            ->and($xlsx[0][0])->toBe('Nama Produk *')
            ->and($xlsx[0])->toContain('Harga Jual', 'Satuan Alternatif 3', 'Min. Qty Grosir 1', 'Status')
            ->and(array_filter($xlsx[0], fn (string $judul): bool => str_ends_with($judul, ' *')))->toHaveCount(1)
            ->and($xlsx[0])->not->toContain('Harga Modal', 'Stok')
            ->and($csv[0])->toBe($xlsx[0]);
    });

    it('impor lebih tua dari HariSimpan dipangkas malas saat tenant itu mengunggah (berkas & baris), tenant lain tidak tersentuh', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko Tenant A');
        $masukA = BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id);
        $lamaA = BantuanImpor::Unggah($masukA, BantuanImpor::BuatCsv([['Nama Produk'], ['Kopi A']]));
        BantuanImpor::Petakan($masukA, $lamaA, ['UuidKelompokPajakBawaan' => $a['KelompokPajak']->Uuid]);

        $b = BantuanKatalog::SiapkanTenantProduk('Toko Tenant B');
        $masukB = BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
        $lamaB = BantuanImpor::Unggah($masukB, BantuanImpor::BuatCsv([['Nama Produk'], ['Kopi B']]));

        $this->travel(31)->days();
        BantuanImpor::Unggah(BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id), BantuanImpor::BuatCsv([['Nama Produk'], ['Kopi A baru']]));

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(ImporProduk::query()->whereKey($lamaA->Id)->exists())->toBeFalse()
            ->and(ImporProdukBaris::query()->where('IdImporProduk', $lamaA->Id)->exists())->toBeFalse()
            ->and(ImporProduk::query()->count())->toBe(1);
        Storage::disk('local')->assertMissing($lamaA->PathBerkas);

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(ImporProduk::query()->whereKey($lamaB->Id)->sole()->Status)->toBe(StatusImporProduk::MenungguPemetaan);
        Storage::disk('local')->assertExists($lamaB->PathBerkas);
    });
});
