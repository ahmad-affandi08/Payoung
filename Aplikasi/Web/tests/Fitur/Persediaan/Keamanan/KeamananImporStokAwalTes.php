<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Persediaan\Aksi\PostingStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\ImporStokAwal;
use App\Domain\Persediaan\Model\ImporStokAwalBaris;
use App\Domain\Persediaan\Model\StokAwal;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanImporStokAwal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05a QA keamanan impor stok awal: idempotensi unggahan per pengunggah (impor pengguna lain tidak pernah
 * dikembalikan), dan retensi yang tidak menghapus asal dokumen transaksi (CLAUDE.md #8, PRD v1.33: catatan impor
 * yang dirujuk dokumen tetap disimpan sebagai jejak asal).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

describe('F-05a QA: unggah impor stok awal lintas pengguna', function (): void {
    it('berkas sama dari pengguna lain membuat impor sendiri (bukan impor pengguna lain yang 404 baginya)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $baris = [BantuanImporStokAwal::JUDUL, [$p['Stok']->Sku, '', 'pcs', '', '24', '38.500']];
        $pemilik = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $imporPemilik = BantuanImporStokAwal::Unggah($pemilik, BantuanImporStokAwal::BuatCsv($baris), $t['Gudang']->Uuid);

        $staf = BantuanHarga::TambahAnggotaOutlet($t['Tenant']->Id, PeranTenantBawaan::StafGudang, $t['Outlet']);
        BantuanOrganisasi::Masuk($this, $staf, $t['Tenant']->Id);
        $imporStaf = BantuanImporStokAwal::Unggah($this, BantuanImporStokAwal::BuatCsv($baris), $t['Gudang']->Uuid);

        $this->get("/kelola/persediaan/stok-awal/impor/{$imporStaf->Uuid}")->assertOk();
        $this->get("/kelola/persediaan/stok-awal/impor/{$imporPemilik->Uuid}")->assertNotFound();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($imporStaf->Id)->not->toBe($imporPemilik->Id)
            ->and($imporStaf->IdPengguna)->toBe($staf->Id)
            ->and(ImporStokAwal::query()->count())->toBe(2);
    });
});

describe('F-05a QA: retensi impor tidak menghapus asal dokumen (aturan #8)', function (): void {
    it('impor lama yang dirujuk stok awal Diposting disimpan (berkas & baris dipangkas). Impor tanpa dokumen dihapus', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $imporDipakai = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([
            BantuanImporStokAwal::JUDUL,
            [$p['Stok']->Sku, '', 'pcs', '', '24', '38.500'],
        ]), $t['Gudang']->Uuid);
        BantuanImporStokAwal::Petakan($masuk, $imporDipakai, $t['Gudang']->Uuid)->assertSessionHasNoErrors();
        $masuk->post("/kelola/persediaan/stok-awal/impor/{$imporDipakai->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $draf = StokAwal::query()->where('IdImporStokAwal', $imporDipakai->Id)->sole();
        app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);

        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $imporTerbengkalai = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([
            BantuanImporStokAwal::JUDUL,
            [$p['Produksi']->Sku, '', 'pcs', '', '5', '9000'],
        ]), $t['Gudang']->Uuid);

        $this->travel(31)->days();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([
            BantuanImporStokAwal::JUDUL,
            [$p['BahanBaku']->Sku, '', 'kg', '', '7,5', '14.250'],
        ]), $t['Gudang']->Uuid);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $disimpan = ImporStokAwal::query()->whereKey($imporDipakai->Id)->first();
        expect($disimpan)->not->toBeNull()
            ->and($disimpan?->PathBerkas)->toBe('')
            ->and(ImporStokAwalBaris::query()->where('IdImporStokAwal', $imporDipakai->Id)->count())->toBe(0)
            ->and($draf->fresh()?->IdImporStokAwal)->toBe($imporDipakai->Id)
            ->and($draf->fresh()?->Status)->toBe(StatusStokAwal::Diposting)
            ->and(ImporStokAwal::query()->whereKey($imporTerbengkalai->Id)->exists())->toBeFalse();
        Storage::disk('local')->assertMissing($imporDipakai->PathBerkas);
        Storage::disk('local')->assertMissing($imporTerbengkalai->PathBerkas);

        // Detail dokumen & impor yang dipangkas tetap bisa dibuka (tautan asal utuh).
        $masuk->get("/kelola/persediaan/stok-awal/{$draf->Uuid}")->assertOk();
        $masuk->get("/kelola/persediaan/stok-awal/impor/{$imporDipakai->Uuid}")->assertOk();

        // Unggahan berikutnya tidak memproses ulang impor yang sudah dipangkas.
        BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([
            BantuanImporStokAwal::JUDUL,
            [$p['Batch']->Sku, '', 'pcs', '', '1', '19500'],
        ]), $t['Gudang']->Uuid);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ImporStokAwal::query()->whereKey($imporDipakai->Id)->value('PathBerkas'))->toBe('');
    });
});
