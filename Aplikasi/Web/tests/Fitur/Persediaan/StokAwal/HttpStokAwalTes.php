<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\StokAwal;
use Illuminate\Support\Str;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05a Tim C: rute stok awal (DesainF05a D): simpan/ubah draf, buang, posting, batalkan, status JSON, dan cari
 * produk. Matriks izin (StafGudang membuat draf tetapi 403 saat posting; ManajerOutlet & Akuntan boleh posting),
 * pembatasan outlet (404), isolasi tenant (404), dan idempotensi kirim ulang.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array{0: array<string, mixed>, 1: array<string, Produk>}
 */
function SiapkanHttpStokAwal(): array
{
    $t = BantuanPersediaan::SiapkanTenant();

    return [$t, BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])];
}

describe('F-05a HTTP stok awal: draf', function (): void {
    it('Pemilik menyimpan draf lewat form; kirim ulang dengan Uuid yang sama tidak membuat dokumen kedua', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        $isi = BantuanStokAwal::IsiForm($t['Gudang'], [
            BantuanStokAwal::IsiBaris($p['Stok'], '24', '37500'),
            BantuanStokAwal::IsiBaris($p['Batch'], '12', '18250.5', 'UHT-2609A', '2027-03-31'),
        ]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/persediaan/stok-awal', $isi)
            ->assertRedirect("/kelola/persediaan/stok-awal/{$isi['Uuid']}")
            ->assertSessionHasNoErrors();
        $this->post('/kelola/persediaan/stok-awal', $isi)->assertRedirect("/kelola/persediaan/stok-awal/{$isi['Uuid']}");

        $dokumen = StokAwal::query()->where('Uuid', $isi['Uuid'])->sole();
        expect(StokAwal::query()->count())->toBe(1)
            ->and($dokumen->Status)->toBe(StatusStokAwal::Draf)
            ->and($dokumen->TotalNilai)->toBe('1119006.00')
            ->and($dokumen->DibuatOleh)->not->toBeNull();
    });

    it('validasi form: jumlah berformat ribuan, HPP > 6 desimal, dan baris kosong ditolak', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($t['Gudang'], [
            BantuanStokAwal::IsiBaris($p['Stok'], '1.000,5', '37500.1234567'),
        ]))->assertSessionHasErrors(['Baris.0.Jumlah', 'Baris.0.HppSatuan']);
        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($t['Gudang'], []))->assertSessionHasErrors(['Baris']);

        expect(StokAwal::query()->count())->toBe(0);
    });

    it('pelanggaran aturan baris tampil sebagai masalah bidang baris (Konsinyasi ditolak)', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($t['Gudang'], [
            BantuanStokAwal::IsiBaris($p['Stok'], '2', '37500'),
            BantuanStokAwal::IsiBaris($p['Konsinyasi'], '5', '9000'),
        ]))->assertSessionHasErrors(['Baris.1.UuidProduk']);
    });

    it('ubah draf dengan VersiDiubahPada; dokumen Diposting tidak bisa diubah atau dibuang (StatusTidakSesuai)', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '5', '38000')]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $isi = [...BantuanStokAwal::IsiForm($t['Gudang'], [BantuanStokAwal::IsiBaris($p['Stok'], '6', '38000')]), 'VersiDiubahPada' => $draf->DiubahPada?->toIso8601String()];
        unset($isi['Uuid']);

        $this->put("/kelola/persediaan/stok-awal/{$draf->Uuid}", $isi)->assertSessionHasNoErrors();
        expect($draf->fresh()?->TotalNilai)->toBe('228000.00');

        $this->post("/kelola/persediaan/stok-awal/{$draf->Uuid}/posting")->assertSessionHasNoErrors();
        $posting = $draf->fresh();
        $isi['VersiDiubahPada'] = $posting?->DiubahPada?->toIso8601String();

        $this->from("/kelola/persediaan/stok-awal/{$draf->Uuid}")->put("/kelola/persediaan/stok-awal/{$draf->Uuid}", $isi)->assertSessionHasErrors(['Umum']);
        $this->post("/kelola/persediaan/stok-awal/{$draf->Uuid}/buang")->assertSessionHasErrors(['Umum']);
        $this->get("/kelola/persediaan/stok-awal/{$draf->Uuid}/ubah")->assertRedirect("/kelola/persediaan/stok-awal/{$draf->Uuid}")
            ->assertSessionHasErrors('Umum')->assertSessionMissing('Kilat');

        expect($draf->fresh()?->Status)->toBe(StatusStokAwal::Diposting)
            ->and($draf->fresh()?->TotalNilai)->toBe('228000.00');
    });

    it('buang draf lewat rute: status Dibuang, dokumen tetap ada', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '5', '38000')]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post("/kelola/persediaan/stok-awal/{$draf->Uuid}/buang")->assertRedirect('/kelola/persediaan/stok-awal');

        expect($draf->fresh()?->Status)->toBe(StatusStokAwal::Dibuang);
    });
});

describe('F-05a HTTP stok awal: posting, batalkan, status', function (): void {
    it('posting berulang lewat HTTP menghasilkan satu set mutasi dan satu jurnal; status JSON memuat nomor', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000'), BantuanStokAwal::Baris($p['BahanBaku'], '7.25', '14750')]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post("/kelola/persediaan/stok-awal/{$draf->Uuid}/posting")->assertRedirect("/kelola/persediaan/stok-awal/{$draf->Uuid}")->assertSessionHasNoErrors();
        $this->post("/kelola/persediaan/stok-awal/{$draf->Uuid}/posting")->assertSessionHasNoErrors();

        $nomor = $draf->fresh()?->Nomor;
        expect(MutasiStok::query()->count())->toBe(2)
            ->and(Jurnal::query()->count())->toBe(1);
        $this->getJson("/kelola/persediaan/stok-awal/{$draf->Uuid}/status")
            ->assertOk()
            ->assertExactJson(['Status' => 'Diposting', 'LabelStatus' => 'Tercatat', 'Nomor' => $nomor, 'PesanGalat' => null]);
        expect(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('batalkan lewat HTTP: alasan wajib 5–255 karakter; berhasil membalik stok', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post("/kelola/persediaan/stok-awal/{$dokumen->Uuid}/batalkan", ['Alasan' => 'ok'])->assertSessionHasErrors(['Alasan']);
        $this->post("/kelola/persediaan/stok-awal/{$dokumen->Uuid}/batalkan", ['Alasan' => 'Salah pilih lokasi stok'])
            ->assertRedirect("/kelola/persediaan/stok-awal/{$dokumen->Uuid}")
            ->assertSessionHasNoErrors();

        expect($dokumen->fresh()?->Status)->toBe(StatusStokAwal::Dibatalkan)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('cari produk: hanya produk berstok, dengan saldo di lokasi dan tanda stok awal sudah ada', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $hasil = $this->getJson("/kelola/persediaan/produk/cari?kata=Minyak&gudang={$t['Gudang']->Uuid}")->assertOk()->json('Data');
        $tanpaLokasi = $this->getJson('/kelola/persediaan/produk/cari?kata=Keripik')->assertOk()->json('Data');

        expect($hasil)->toHaveCount(1)
            ->and($hasil[0])->toMatchArray([
                'Uuid' => $p['Stok']->Uuid, 'Jenis' => 'Stok', 'Pelacakan' => 'Tidak', 'SimbolSatuan' => 'pcs', 'BolehDesimal' => false,
                'SaldoDiGudang' => '10.0000', 'HppRataRata' => '38000.000000', 'StokAwalSudahAda' => true,
            ])
            ->and($tanpaLokasi)->toBe([]);
    });
});

describe('F-05a HTTP stok awal: izin, outlet, dan isolasi tenant', function (): void {
    it('matriks izin: StafGudang membuat draf tetapi 403 saat posting; Akuntan boleh posting tetapi 403 membuat draf; ManajerOutlet boleh keduanya; Kasir 403', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        $isi = BantuanStokAwal::IsiForm($t['Gudang'], [BantuanStokAwal::IsiBaris($p['Stok'], '10', '38000')]);

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $this->post('/kelola/persediaan/stok-awal', $isi)->assertRedirect();
        $this->post("/kelola/persediaan/stok-awal/{$isi['Uuid']}/posting")->assertForbidden();
        $this->post("/kelola/persediaan/stok-awal/{$isi['Uuid']}/batalkan", ['Alasan' => 'Coba batalkan'])->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);
        $this->post('/kelola/persediaan/stok-awal', [...$isi, 'Uuid' => (string) Str::ulid()])->assertForbidden();
        $this->post("/kelola/persediaan/stok-awal/{$isi['Uuid']}/posting")->assertSessionHasNoErrors();
        expect(StokAwal::query()->where('Uuid', $isi['Uuid'])->value('Status'))->toBe(StatusStokAwal::Diposting);

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $kedua = BantuanStokAwal::IsiForm($t['Gudang'], [BantuanStokAwal::IsiBaris($p['Produksi'], '20', '9500')]);
        $this->post('/kelola/persediaan/stok-awal', $kedua)->assertRedirect();
        $this->post("/kelola/persediaan/stok-awal/{$kedua['Uuid']}/posting")->assertSessionHasNoErrors();
        expect(StokAwal::query()->where('Uuid', $kedua['Uuid'])->value('Status'))->toBe(StatusStokAwal::Diposting);

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->getJson("/kelola/persediaan/stok-awal/{$isi['Uuid']}/status")->assertForbidden();
        $this->getJson('/kelola/persediaan/produk/cari?kata=Minyak')->assertForbidden();
    });

    it('pembatasan outlet: manajer cabang tidak bisa melihat, memposting, atau memakai lokasi stok outlet lain (404)', function (): void {
        [$t, $p] = SiapkanHttpStokAwal();
        $cabang = BantuanHarga::BuatOutlet('SLO-02', 'Cabang Solo Baru');
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Gudang Cabang Solo Baru');
        $drafUtama = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);
        $manajer = BantuanHarga::TambahAnggotaOutlet($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, $cabang);
        BantuanOrganisasi::Masuk($this, $manajer, $t['Tenant']->Id);

        $this->getJson("/kelola/persediaan/stok-awal/{$drafUtama->Uuid}/status")->assertNotFound();
        $this->post("/kelola/persediaan/stok-awal/{$drafUtama->Uuid}/posting")->assertNotFound();
        $this->post("/kelola/persediaan/stok-awal/{$drafUtama->Uuid}/buang")->assertNotFound();
        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($t['Gudang'], [BantuanStokAwal::IsiBaris($p['Stok'], '1', '38000')]))->assertNotFound();
        $this->getJson("/kelola/persediaan/produk/cari?kata=Minyak&gudang={$t['Gudang']->Uuid}")->assertNotFound();

        $isiCabang = BantuanStokAwal::IsiForm($gudangCabang, [BantuanStokAwal::IsiBaris($p['Stok'], '4', '38000')]);
        $this->post('/kelola/persediaan/stok-awal', $isiCabang)->assertRedirect("/kelola/persediaan/stok-awal/{$isiCabang['Uuid']}");
        $this->getJson("/kelola/persediaan/stok-awal/{$isiCabang['Uuid']}/status")->assertOk()->assertJsonPath('Status', 'Draf');

        expect($drafUtama->fresh()?->Status)->toBe(StatusStokAwal::Draf);
    });

    it('isolasi tenant: dokumen, lokasi stok, dan produk tenant lain = 404 / tidak dikenal', function (): void {
        [$tA, $pA] = SiapkanHttpStokAwal();
        $drafA = BantuanStokAwal::BuatDraf($tA['Gudang'], [BantuanStokAwal::Baris($pA['Stok'], '10', '38000')]);
        [$tB, $pB] = SiapkanHttpStokAwal();
        BantuanPersediaan::MasukSebagai($this, $tB['Tenant']->Id);

        $this->getJson("/kelola/persediaan/stok-awal/{$drafA->Uuid}/status")->assertNotFound();
        $this->post("/kelola/persediaan/stok-awal/{$drafA->Uuid}/posting")->assertNotFound();
        $this->post("/kelola/persediaan/stok-awal/{$drafA->Uuid}/batalkan", ['Alasan' => 'Coba lintas tenant'])->assertNotFound();
        $this->put("/kelola/persediaan/stok-awal/{$drafA->Uuid}", [
            ...BantuanStokAwal::IsiForm($tB['Gudang'], [BantuanStokAwal::IsiBaris($pB['Stok'], '1', '1')]), 'VersiDiubahPada' => 'x',
        ])->assertNotFound();
        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($tA['Gudang'], [BantuanStokAwal::IsiBaris($pB['Stok'], '1', '38000')]))->assertNotFound();
        $this->getJson("/kelola/persediaan/produk/cari?gudang={$tA['Gudang']->Uuid}")->assertNotFound();
        $this->post('/kelola/persediaan/stok-awal', BantuanStokAwal::IsiForm($tB['Gudang'], [BantuanStokAwal::IsiBaris($pA['Stok'], '1', '38000')]))
            ->assertSessionHasErrors(['Baris.0.UuidProduk']);
        $cari = $this->getJson('/kelola/persediaan/produk/cari?kata=Minyak')->assertOk()->json('Data');

        BantuanOrganisasi::AturKonteks($tA['Tenant']->Id);
        expect($drafA->fresh()?->Status)->toBe(StatusStokAwal::Draf)
            ->and(array_column($cari, 'Uuid'))->toBe([$pB['Stok']->Uuid]);
        BantuanOrganisasi::AturKonteks($tB['Tenant']->Id);
        expect(StokAwal::query()->count())->toBe(0);
    });
});
