<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Persediaan\Aksi\PostingStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\StokAwalDetail;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanBuku;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05a QA keamanan dokumen stok awal: IDOR rute buang (tenant lain / outlet lain), mass assignment form, Uuid klien
 * yang bentrok lintas tenant, batch ganda menurut kolasi database, penjaga model aturan #8 (dokumen terposting tidak
 * diubah/dihapus), dan kesiapan akun Selisih HPP (BR-04.3) sebelum jurnal disusun.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array{0: array<string, mixed>, 1: array<string, Produk>}
 */
function SiapkanKeamananStokAwal(string $nama = 'Toko Sembako Berkah Jaya', bool $stokBolehMinus = false): array
{
    $t = BantuanPersediaan::SiapkanTenant($nama, stokBolehMinus: $stokBolehMinus);

    return [$t, BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])];
}

describe('F-05a QA: IDOR rute stok awal', function (): void {
    it('buang draf tenant lain = 404 dan draf tetap Draf', function (): void {
        [$tA, $pA] = SiapkanKeamananStokAwal('Toko Sembako Berkah Jaya');
        $drafA = BantuanStokAwal::BuatDraf($tA['Gudang'], [BantuanStokAwal::Baris($pA['Stok'], '10', '38000')]);
        [$tB] = SiapkanKeamananStokAwal('Apotek Sehat Sentosa');
        BantuanPersediaan::MasukSebagai($this, $tB['Tenant']->Id);

        $this->post("/kelola/persediaan/stok-awal/{$drafA->Uuid}/buang")->assertNotFound();

        BantuanOrganisasi::AturKonteks($tA['Tenant']->Id);
        expect($drafA->fresh()?->Status)->toBe(StatusStokAwal::Draf);
    });

    it('buang draf di outlet di luar akses pelaku = 404. Draf di outletnya sendiri boleh dibuang', function (): void {
        [$t, $p] = SiapkanKeamananStokAwal();
        $cabang = BantuanHarga::BuatOutlet('SKH-03', 'Cabang Sukoharjo');
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Gudang Cabang Sukoharjo');
        $drafUtama = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);
        $drafCabang = BantuanStokAwal::BuatDraf($gudangCabang, [BantuanStokAwal::Baris($p['Stok'], '3', '38000')]);
        $manajer = BantuanHarga::TambahAnggotaOutlet($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, $cabang);
        BantuanOrganisasi::Masuk($this, $manajer, $t['Tenant']->Id);

        $this->post("/kelola/persediaan/stok-awal/{$drafUtama->Uuid}/buang")->assertNotFound();
        $this->post("/kelola/persediaan/stok-awal/{$drafCabang->Uuid}/buang")->assertRedirect('/kelola/persediaan/stok-awal');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($drafUtama->fresh()?->Status)->toBe(StatusStokAwal::Draf)
            ->and($drafCabang->fresh()?->Status)->toBe(StatusStokAwal::Dibuang);
    });

    it('mengirim ulang Uuid draf outlet lain lewat form tidak mengubah draf itu (idempotensi bukan jalan pintas IDOR)', function (): void {
        [$t, $p] = SiapkanKeamananStokAwal();
        $cabang = BantuanHarga::BuatOutlet('SKH-03', 'Cabang Sukoharjo');
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Gudang Cabang Sukoharjo');
        $drafUtama = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);
        $manajer = BantuanHarga::TambahAnggotaOutlet($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, $cabang);
        BantuanOrganisasi::Masuk($this, $manajer, $t['Tenant']->Id);

        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($gudangCabang, [BantuanStokAwal::IsiBaris($p['Stok'], '999', '1')], uuid: $drafUtama->Uuid));
        $this->get("/kelola/persediaan/stok-awal/{$drafUtama->Uuid}")->assertNotFound();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($drafUtama->fresh()?->IdGudang)->toBe($t['Gudang']->Id)
            ->and($drafUtama->fresh()?->TotalNilai)->toBe('380000.00')
            ->and(StokAwal::query()->count())->toBe(1);
    });

    it('Uuid klien yang sudah dipakai dokumen tenant lain ditolak 422 (bukan 500), dokumen tenant lain utuh', function (): void {
        [$tA, $pA] = SiapkanKeamananStokAwal('Toko Sembako Berkah Jaya');
        $drafA = BantuanStokAwal::BuatDraf($tA['Gudang'], [BantuanStokAwal::Baris($pA['Stok'], '10', '38000')]);
        [$tB, $pB] = SiapkanKeamananStokAwal('Apotek Sehat Sentosa');
        BantuanPersediaan::MasukSebagai($this, $tB['Tenant']->Id);

        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($tB['Gudang'], [BantuanStokAwal::IsiBaris($pB['Stok'], '5', '12000')], uuid: $drafA->Uuid))
            ->assertStatus(302)
            ->assertSessionHasErrors(['Uuid']);

        BantuanOrganisasi::AturKonteks($tB['Tenant']->Id);
        expect(StokAwal::query()->count())->toBe(0);
        BantuanOrganisasi::AturKonteks($tA['Tenant']->Id);
        expect($drafA->fresh()?->TotalNilai)->toBe('380000.00');
    });

    it('mass assignment: Status, Nomor, TotalNilai, IdTenant, dan DibuatOleh kiriman form diabaikan', function (): void {
        [$t, $p] = SiapkanKeamananStokAwal();
        [$lain] = SiapkanKeamananStokAwal('Apotek Sehat Sentosa');
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $isi = [
            ...BantuanStokAwal::IsiForm($t['Gudang'], [BantuanStokAwal::IsiBaris($p['Stok'], '2', '38000')]),
            'Status' => 'Diposting',
            'Nomor' => 'SA/2026/09/9999',
            'TotalNilai' => '1.00',
            'IdTenant' => $lain['Tenant']->Id,
            'DibuatOleh' => $lain['Pemilik']->Id,
            'IdJurnal' => 1,
        ];

        $this->post('/kelola/persediaan/stok-awal', $isi)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $dokumen = StokAwal::query()->where('Uuid', $isi['Uuid'])->sole();
        expect($dokumen->Status)->toBe(StatusStokAwal::Draf)
            ->and($dokumen->Nomor)->toBeNull()
            ->and($dokumen->IdJurnal)->toBeNull()
            ->and($dokumen->TotalNilai)->toBe('76000.00')
            ->and($dokumen->IdTenant)->toBe($t['Tenant']->Id)
            ->and($dokumen->DibuatOleh)->not->toBe($lain['Pemilik']->Id)
            ->and(MutasiStok::query()->count())->toBe(0);
    });
});

describe('F-05a QA: batch ganda menurut kolasi database', function (): void {
    it('nomor batch yang hanya beda aksen (É/E) di satu dokumen ditolak BarisGanda 422, bukan masalah SQL 500', function (): void {
        [$t, $p] = SiapkanKeamananStokAwal();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($t['Gudang'], [
            BantuanStokAwal::IsiBaris($p['Batch'], '12', '18250', 'LOT-É2609', '2027-03-31'),
            BantuanStokAwal::IsiBaris($p['Batch'], '8', '18250', 'LOT-E2609', '2027-03-31'),
        ]))->assertStatus(302)->assertSessionHasErrors(['Baris']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(StokAwal::query()->count())->toBe(0);
    });
});

describe('F-05a QA: penjaga model aturan #8 (dokumen terposting tidak diubah/dihapus)', function (): void {
    it('baris dan kepala stok awal Diposting tidak bisa diubah atau dihapus lewat model', function (): void {
        [$t, $p] = SiapkanKeamananStokAwal();
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);
        $baris = StokAwalDetail::query()->where('IdStokAwal', $dokumen->Id)->sole();

        expect(fn () => $baris->update(['Jumlah' => '99.0000']))->toThrow(LogicException::class)
            ->and(fn () => $baris->delete())->toThrow(LogicException::class)
            ->and(fn () => $dokumen->fresh()?->update(['Catatan' => 'diubah diam-diam', 'TotalNilai' => '1.00']))->toThrow(LogicException::class)
            ->and(fn () => $dokumen->fresh()?->delete())->toThrow(LogicException::class);

        $segar = $dokumen->fresh();
        expect($segar?->TotalNilai)->toBe('380000.00')
            ->and(StokAwalDetail::query()->whereKey($baris->Id)->value('Jumlah'))->toBe('10.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('dokumen Dibuang tidak bisa diubah lagi. Baris draf tetap bisa diubah selama Draf', function (): void {
        [$t, $p] = SiapkanKeamananStokAwal();
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);
        $baris = StokAwalDetail::query()->where('IdStokAwal', $draf->Id)->sole();

        $baris->update(['Sku' => 'MGS-2L-BARU']);
        expect(StokAwalDetail::query()->whereKey($baris->Id)->value('Sku'))->toBe('MGS-2L-BARU');

        $dibuang = $draf->fresh();
        $dibuang?->UbahStatus(StatusStokAwal::Dibuang);
        $dibuang?->save();

        expect(fn () => $draf->fresh()?->update(['Catatan' => 'dihidupkan lagi']))->toThrow(LogicException::class)
            ->and(fn () => StokAwalDetail::query()->whereKey($baris->Id)->sole()->update(['Jumlah' => '1.0000']))->toThrow(LogicException::class);
    });
});

describe('F-05a QA: kesiapan akun Selisih HPP (BR-04.3)', function (): void {
    it('stok awal setelah stok minus tanpa pemetaan Selisih HPP ditolak PemetaanAkunBelumAda berdaftar peran, tanpa efek stok', function (): void {
        [$t, $p] = SiapkanKeamananStokAwal(stokBolehMinus: true);
        $hariIni = CarbonImmutable::now('Asia/Jakarta');
        BantuanStokAwal::Jual($p['Stok'], $t['Gudang'], '4', $hariIni->subDays(2)->format('Y-m-d'));
        PemetaanAkun::query()->where('Kunci', 'SelisihHpp')->delete();
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '1100')], $hariIni->subDay()->format('Y-m-d'));
        $jumlahMutasi = MutasiStok::query()->count();

        $galat = BantuanBuku::TangkapPelanggaran(fn () => app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id));

        expect($galat)->toBeInstanceOf(PelanggaranAturanBisnis::class)
            ->and($galat->kode)->toBe('PemetaanAkunBelumAda')
            ->and(array_column($galat->detail['PeranBelumDipetakan'] ?? [], 'Kunci'))->toBe(['SelisihHpp'])
            ->and($draf->fresh()?->Status)->toBe(StatusStokAwal::Draf)
            ->and(MutasiStok::query()->count())->toBe($jumlahMutasi);
    });

    it('stok awal tanpa selisih HPP tidak mewajibkan pemetaan Selisih HPP', function (): void {
        [$t, $p] = SiapkanKeamananStokAwal();
        PemetaanAkun::query()->where('Kunci', 'SelisihHpp')->delete();

        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);

        expect($dokumen->Status)->toBe(StatusStokAwal::Diposting);
    });
});
