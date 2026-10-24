<?php

declare(strict_types=1);

use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Impor\Enum\AksiBarisImpor;
use App\Domain\Katalog\Impor\Enum\StatusBarisImpor;
use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProdukBaris;
use App\Domain\Katalog\Impor\Tugas\TerapkanImporProdukTugas;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Tenant\Enum\StatusLangganan;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/**
 * Berkas CSV `jumlah` produk ber-SKU `IDM-{n}` (harga 10.000 + n × 1.000).
 *
 * @return list<list<string>>
 */
function BarisIdempotensi(int $jumlah, string $awalanNama = 'Produk Baris'): array
{
    $baris = [['Nama Produk', 'SKU', 'Harga Jual', 'Barcode', 'Kelompok Pajak']];

    foreach (range(1, $jumlah) as $n) {
        $baris[] = ["{$awalanNama} ".($n + 1), "IDM-{$n}", (string) (10000 + $n * 1000), '89900000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'Barang kena PPN'];
    }

    return $baris;
}

/** Seperti worker antrean: kunci unik tugas dilepas saat mulai diproses (`ShouldBeUniqueUntilProcessing`). */
function JalankanTugasTerapkan(ImporProduk $impor): void
{
    $tugas = new TerapkanImporProdukTugas($impor->IdTenant, $impor->IdPengguna, $impor->Id);
    Cache::lock(UniqueLock::getKey($tugas))->forceRelease();
    app()->call([$tugas, 'handle']);
}

describe('F-03 BR-03.6 impor idempoten & bisa dilanjutkan', function (): void {
    it('tugas terapkan dijalankan dua kali (dan dikirim ulang setelah selesai) → tanpa produk ganda, JumlahDiterapkan stabil', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisIdempotensi(3)));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();

        Queue::fake();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();
        Queue::assertPushed(TerapkanImporProdukTugas::class, fn (TerapkanImporProdukTugas $tugas): bool => $tugas->connection === 'sync');
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Menerapkan);

        JalankanTugasTerapkan($impor);
        JalankanTugasTerapkan($impor);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(3)
            ->and($impor->refresh()->Status)->toBe(StatusImporProduk::Selesai)
            ->and($impor->JumlahDiterapkan)->toBe(3)
            ->and($impor->JumlahDibuat)->toBe(3);

        // Pengiriman ganda yang terlambat (status dipaksa kembali Menerapkan) tidak menerapkan apa pun lagi.
        ImporProduk::query()->whereKey($impor->Id)->update(['Status' => StatusImporProduk::Menerapkan->value]);
        JalankanTugasTerapkan($impor);

        expect(Produk::query()->count())->toBe(3)
            ->and(ProdukBarcode::query()->count())->toBe(3)
            ->and(ProdukHarga::query()->count())->toBe(3)
            ->and(RiwayatHarga::query()->count())->toBe(3)
            ->and($impor->refresh()->Status)->toBe(StatusImporProduk::Selesai)
            ->and($impor->JumlahDiterapkan)->toBe(3);
    });

    it('MaksimalDetikPerTugas = 0 → satu potongan per tugas, tugas mengirim dirinya lagi ke antrean sampai selesai', function (): void {
        config(['katalog.Impor.MaksimalDetikPerTugas' => 0, 'katalog.Impor.UkuranPotongan' => 2]);
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisIdempotensi(5)));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();

        Queue::fake();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();
        $diterapkan = fn (): int => ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->where('Status', StatusBarisImpor::Diterapkan->value)->count();

        JalankanTugasTerapkan($impor);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($diterapkan())->toBe(2)
            ->and($impor->refresh()->Status)->toBe(StatusImporProduk::Menerapkan)
            ->and($impor->JumlahDiterapkan)->toBe(2);
        $masuk->getJson("/kelola/produk/impor/{$impor->Uuid}/status")->assertOk()->assertJson(['Status' => 'Menerapkan', 'Progres' => 40, 'JumlahDiterapkan' => 2]);
        Queue::assertPushed(TerapkanImporProdukTugas::class, fn (TerapkanImporProdukTugas $tugas): bool => $tugas->connection === null && $tugas->idImporProduk === $impor->Id);

        JalankanTugasTerapkan($impor);
        JalankanTugasTerapkan($impor);
        JalankanTugasTerapkan($impor);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($diterapkan())->toBe(5)
            ->and($impor->refresh()->Status)->toBe(StatusImporProduk::Selesai)
            ->and(Produk::query()->count())->toBe(5)
            ->and(Queue::pushed(TerapkanImporProdukTugas::class)->filter(fn (TerapkanImporProdukTugas $tugas): bool => $tugas->connection === null))->toHaveCount(3);
    });

    it('masalah sistem di tengah potongan → potongan itu dibatalkan utuh, impor Gagal; "Lanjutkan" menerapkan tiap baris tepat sekali', function (): void {
        config(['katalog.Impor.UkuranPotongan' => 2]);
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisIdempotensi(5)));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();

        $meledak = true;
        Produk::creating(function (Produk $produk) use (&$meledak): void {
            if ($meledak && $produk->Nama === 'Produk Baris 5') {
                throw new RuntimeException('Koneksi database terputus (simulasi).');
            }
        });

        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Gagal)
            ->and($impor->PesanGalat)->toContain('Lanjutkan impor')
            ->and($impor->JumlahDiterapkan)->toBe(2)
            ->and(Produk::query()->pluck('Nama')->sort()->values()->all())->toBe(['Produk Baris 2', 'Produk Baris 3'])
            // Baris 4 ada di potongan yang gagal: ikut dibatalkan (tidak setengah jadi).
            ->and(ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->where('NomorBaris', 4)->sole()->Status)->toBe(StatusBarisImpor::Valid);
        $masuk->get("/kelola/produk/impor/{$impor->Uuid}")->assertInertia(fn ($h) => $h->where('Impor.BolehLanjutkan', true)->where('Impor.Status', 'Gagal'));

        $meledak = false;
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/lanjutkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Selesai)
            ->and($impor->JumlahDiterapkan)->toBe(5)
            ->and(Produk::query()->count())->toBe(5)
            ->and(Produk::query()->select('Nama')->groupBy('Nama')->havingRaw('COUNT(*) > 1')->count())->toBe(0);

        // Selesai tidak bisa dilanjutkan lagi.
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/lanjutkan")->assertSessionHasErrors('Impor');
    });

    it('impor yang tampak terhenti (Menerapkan tanpa kemajuan > 10 menit) boleh dilanjutkan; yang baru berjalan tidak', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisIdempotensi(2)));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
        Queue::fake();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/lanjutkan")->assertSessionHasErrors('Impor');

        $this->travel(11)->minutes();
        $masuk->get("/kelola/produk/impor/{$impor->Uuid}")->assertInertia(fn ($h) => $h->where('Impor.BolehLanjutkan', true));
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/lanjutkan")->assertSessionHasNoErrors();
        Queue::assertPushed(TerapkanImporProdukTugas::class, 2);
    });

    it('unggah ulang berkas sama: TambahDanPerbarui memperbarui lewat SKU/nama (0 produk baru); TambahSaja melewati', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $lama = BantuanKatalog::BuatProduk(['Nama' => 'Kerupuk Udang Sidoarjo 500 gram', 'Sku' => null, 'IdKelompokPajak' => $t['KelompokPajak']->Id], '20000.00', $t['Pcs']);
        $baris = [...BarisIdempotensi(2), ['Kerupuk Udang Sidoarjo 500 gram', '', '21.000', '', 'Barang kena PPN']];

        $pertama = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv($baris));
        BantuanImpor::Petakan($masuk, $pertama)->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$pertama->Uuid}/terapkan")->assertSessionHasNoErrors();
        expect($pertama->refresh()->JumlahDibuat)->toBe(2)->and($pertama->JumlahDiperbarui)->toBe(1);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $riwayatAwal = RiwayatHarga::query()->count();
        expect(ProdukHarga::query()->where('IdProduk', $lama->Id)->sole()->Harga)->toBe('21000.00');

        // Berkas sama, mode perbarui: semuanya Perbarui, tidak ada produk baru, harga sama → tanpa riwayat baru.
        $kedua = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv($baris));
        BantuanImpor::Petakan($masuk, $kedua)->assertSessionHasNoErrors();
        expect(ImporProdukBaris::query()->where('IdImporProduk', $kedua->Id)->pluck('Aksi')->map(fn (AksiBarisImpor $a): string => $a->value)->unique()->values()->all())->toBe(['Perbarui']);
        $masuk->post("/kelola/produk/impor/{$kedua->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($kedua->refresh()->JumlahDibuat)->toBe(0)
            ->and($kedua->JumlahDiperbarui)->toBe(3)
            ->and(Produk::query()->count())->toBe(3)
            ->and(RiwayatHarga::query()->count())->toBe($riwayatAwal);

        // Mode tambah saja: baris produk yang sudah ada dilewati, sehingga tidak ada yang bisa diterapkan.
        $ketiga = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv($baris));
        BantuanImpor::Petakan($masuk, $ketiga, ['Mode' => 'TambahSaja'])->assertSessionHasNoErrors();
        expect($ketiga->refresh()->JumlahDilewati)->toBe(3)->and($ketiga->JumlahValid)->toBe(0);
        $masuk->post("/kelola/produk/impor/{$ketiga->Uuid}/terapkan")->assertSessionHasErrors(['Impor' => 'Tidak ada baris valid untuk diimpor. Perbaiki berkas lalu unggah ulang.']);

        // Harga berubah di berkas → diperbarui dengan riwayat harga baru.
        $ubah = BarisIdempotensi(2);
        $ubah[1][2] = '11.500';
        $keempat = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv($ubah));
        BantuanImpor::Petakan($masuk, $keempat)->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$keempat->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $idm1 = Produk::query()->where('Sku', 'IDM-1')->sole();
        expect(ProdukHarga::query()->where('IdProduk', $idm1->Id)->sole()->Harga)->toBe('11500.00')
            ->and(RiwayatHarga::query()->count())->toBe($riwayatAwal + 1)
            ->and(RiwayatHarga::query()->where('IdProduk', $idm1->Id)->latest('Id')->first()?->HargaLama)->toBe('11000.00');
    });

    it('barcode dipakai produk lain setelah validasi → baris itu GagalDiterapkan, baris lain tetap diimpor, laporan masalah memuatnya', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisIdempotensi(3)));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
        expect($impor->refresh()->JumlahValid)->toBe(3);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penyerobot = BantuanKatalog::BuatProduk(['Nama' => 'Produk Kasir Lain'], null, $t['Pcs']);
        ProdukBarcode::query()->create([
            'IdProduk' => $penyerobot->Id,
            'IdProdukSatuan' => ProdukSatuan::query()->where('IdProduk', $penyerobot->Id)->sole()->Id,
            'Barcode' => '8990000000002',
        ]);

        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $baris = ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->orderBy('NomorBaris')->get();
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Selesai)
            ->and($impor->JumlahDiterapkan)->toBe(2)
            ->and($impor->JumlahGagal)->toBe(1)
            ->and($baris->pluck('Status')->all())->toBe([StatusBarisImpor::Diterapkan, StatusBarisImpor::GagalDiterapkan, StatusBarisImpor::Diterapkan])
            ->and($baris[1]->Galat[0]['Pesan'])->toContain('8990000000002')
            ->and(Produk::query()->where('Sku', 'IDM-2')->exists())->toBeFalse()
            ->and(DB::table('Produk')->where('IdTenant', $t['Tenant']->Id)->where('Sku', 'IDM-2')->exists())->toBeFalse();

        $laporan = BantuanImpor::BacaUnduhan($masuk->get("/kelola/produk/impor/{$impor->Uuid}/laporan?jenis=galat&format=csv")->assertOk(), 'csv');
        expect($laporan)->toHaveCount(2)
            ->and($laporan[1][1])->toBe('IDM-2')
            ->and($laporan[1][6])->toBe('Gagal diimpor');
    });
});

describe('audit F-03: otorisasi diperiksa ulang di tiap potongan tugas antrean', function (): void {
    it('pengunggah dinonaktifkan atau langganan ditangguhkan setelah impor dimulai → tugas berhenti tanpa menulis produk', function (string $perubahan): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisIdempotensi(3)));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();
        Queue::fake();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();
        $impor->refresh();

        if ($perubahan === 'nonaktif') {
            DB::table('TenantPengguna')->where('IdTenant', $impor->IdTenant)->where('IdPengguna', $impor->IdPengguna)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);
        } else {
            DB::table('Langganan')->where('IdTenant', $impor->IdTenant)->update(['Status' => StatusLangganan::Ditangguhkan->value]);
        }

        JalankanTugasTerapkan($impor);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(0)
            ->and($impor->refresh()->Status)->toBe(StatusImporProduk::Gagal)
            ->and($impor->PesanGalat)->toStartWith('Impor dihentikan karena '.($perubahan === 'nonaktif' ? 'pengguna yang memulai sudah tidak aktif' : 'langganan usaha sedang ditangguhkan'));
    })->with(['nonaktif', 'ditangguhkan']);
});
