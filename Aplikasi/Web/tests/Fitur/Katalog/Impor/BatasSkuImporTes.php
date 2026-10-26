<?php

declare(strict_types=1);

use App\Domain\Katalog\Impor\Enum\StatusBarisImpor;
use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProdukBaris;
use App\Domain\Katalog\Impor\Tugas\TerapkanImporProdukTugas;
use App\Domain\Katalog\Model\Produk;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
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
 * @return list<list<string>>
 */
function BarisBatasSku(int $jumlah, string $awalan): array
{
    $baris = [['Nama Produk', 'SKU', 'Harga Jual', 'Kelompok Pajak']];

    foreach (range(1, $jumlah) as $n) {
        $baris[] = ["Keripik Singkong Balado {$awalan} {$n}", "{$awalan}-{$n}", '12.500', 'Barang kena PPN'];
    }

    return $baris;
}

describe('F-03 BR-P04.3 / BR-02.1 BatasSku untuk impor', function (): void {
    it('pratinjau menampilkan blokir bila produk baru melebihi sisa kuota. Terapkan ditolak, yang muat boleh', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk(kodePaket: 'GRATIS');
        foreach (range(1, 97) as $n) {
            BantuanKatalog::BuatProduk(['Nama' => "Produk Pengisi {$n}"], null, $t['Pcs']);
        }
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $lebih = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisBatasSku(5, 'LBH')));
        BantuanImpor::Petakan($masuk, $lebih)->assertSessionHasNoErrors();
        $masuk->get("/kelola/produk/impor/{$lebih->Uuid}")->assertInertia(fn ($h) => $h
            ->where('Pratinjau.RingkasanAksi.Buat', 5)
            ->where('Pratinjau.DiblokirBatasSku', 'Impor ini menambah 5 produk, sedangkan paket Anda mencakup maksimal 100 SKU produk dan tersisa 3. Tingkatkan paket atau tambah add-on di menu Langganan, atau kurangi baris di berkas.'));
        $masuk->post("/kelola/produk/impor/{$lebih->Uuid}/terapkan")->assertSessionHasErrors('Impor');
        expect($lebih->refresh()->Status)->toBe(StatusImporProduk::Pratinjau);

        $muat = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisBatasSku(3, 'MUA')));
        BantuanImpor::Petakan($masuk, $muat)->assertSessionHasNoErrors();
        $masuk->get("/kelola/produk/impor/{$muat->Uuid}")->assertInertia(fn ($h) => $h->where('Pratinjau.DiblokirBatasSku', null));
        $masuk->post("/kelola/produk/impor/{$muat->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($muat->refresh()->Status)->toBe(StatusImporProduk::Selesai)->and(Produk::query()->count())->toBe(100);
    });

    it('batas turun di tengah penerapan → berhenti rapi di batas (Gagal + pesan), sisa baris tetap Valid. Setelah kuota ada, Lanjutkan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk(kodePaket: 'GRATIS');
        foreach (range(1, 97) as $n) {
            BantuanKatalog::BuatProduk(['Nama' => "Produk Pengisi {$n}"], null, $t['Pcs']);
        }
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv(BarisBatasSku(3, 'TGH')));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();

        Queue::fake();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        // Kasir lain menambah 2 produk sebelum worker berjalan: tinggal 1 slot.
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penyela = [BantuanKatalog::BuatProduk(['Nama' => 'Produk Kasir Lain 1'], null, $t['Pcs']), BantuanKatalog::BuatProduk(['Nama' => 'Produk Kasir Lain 2'], null, $t['Pcs'])];
        $tugas = new TerapkanImporProdukTugas($impor->IdTenant, $impor->IdPengguna, $impor->Id);
        app()->call([$tugas, 'handle']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Gagal)
            ->and($impor->PesanGalat)->toContain('maksimal 100 SKU produk')
            ->and($impor->PesanGalat)->toContain('Lanjutkan impor')
            ->and($impor->JumlahDiterapkan)->toBe(1)
            ->and(ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->where('Status', StatusBarisImpor::Valid->value)->count())->toBe(2)
            ->and(Produk::query()->count())->toBe(100);

        Produk::query()->whereIn('Id', array_map(fn (Produk $p): int => $p->Id, $penyela))->update(['Aktif' => false, 'DiarsipkanPada' => now()]);
        Queue::fake();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/lanjutkan")->assertSessionHasNoErrors();
        Cache::lock(UniqueLock::getKey($tugas))->forceRelease();
        app()->call([$tugas, 'handle']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Selesai)
            ->and($impor->JumlahDiterapkan)->toBe(3)
            ->and(Produk::query()->where('Sku', 'like', 'TGH-%')->count())->toBe(3);
    });
});
