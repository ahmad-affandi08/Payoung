<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Impor\Layanan\KonteksTugasImpor;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Persediaan\Enum\StatusBarisImporStokAwal;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Enum\SumberStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PenerapImporStokAwal;
use App\Domain\Persediaan\Impor\Tugas\TerapkanImporStokAwalTugas;
use App\Domain\Persediaan\Model\ImporStokAwalBaris;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\StokAwalDetail;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanImporStokAwal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Butuh Tim C (`SimpanStokAwal`) dan Tim D (`PemvalidasiPelacakan`): baris valid diperiksa pelacakannya dan draf
 * dibuat lewat `SimpanStokAwal`.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

describe('F-05a impor stok awal: pratinjau & pembuatan draf per lokasi (tidak memposting)', function (): void {
    it('baris valid dikelompokkan per lokasi → draf Sumber Impor. Baris ganda & stok awal sudah diposting ditolak. Tidak ada mutasi stok atau jurnal', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $gudangBelakang = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Belakang Pasar Gede');
        $kecap = BantuanKatalog::BuatProduk(['Nama' => 'Kecap Manis Botol 600 ml', 'Sku' => 'KCP-600'], '24500.00', $t['Pcs']);
        BantuanImporStokAwal::BuatStokAwalDiposting($t['Gudang']->Id, $kecap->Id, $kecap->Nama);
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatXlsx([
            BantuanImporStokAwal::JUDUL,
            [$produk['Stok']->Sku, '', 'pcs', '', 24, '38.500'],                                             // 2 valid, lokasi bawaan
            ['', $produk['BahanBaku']->Nama, 'kg', $gudangBelakang->Kode, '12,5', '14.250,125'],             // 3 valid, gudang belakang
            [$produk['Batch']->Sku, '', 'pcs', $gudangBelakang->Nama, 48, '19500', 'B-2026-09', '31/12/2027'], // 4 valid batch
            [$produk['Seri']->Sku, '', 'pcs', '', 2, '675.000', '', '', 'RC-0001; RC-0002'],                // 5 valid seri
            [$produk['Produksi']->Sku, '', 'pcs', '', 10, '9000'],                                          // 6 ganda
            [$produk['Produksi']->Sku, '', 'pcs', $t['Gudang']->Kode, 5, '9000'],                           // 7 ganda
            [$kecap->Sku, '', 'pcs', '', 10, '20000'],                                                      // 8 sudah diposting
            [$produk['Batch']->Sku, '', 'pcs', '', 10, '19500'],                                            // 9 batch tanpa nomor batch
            [$produk['Seri']->Sku, '', 'pcs', '', 3, '675000', '', '', 'RC-0009'],                          // 10 seri kurang
        ]), $t['Gudang']->Uuid);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid, now()->subDays(2)->toDateString())->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $impor->refresh();
        $galat = ImporStokAwalBaris::query()->where('IdImporStokAwal', $impor->Id)->where('Status', StatusBarisImporStokAwal::Galat->value)
            ->orderBy('NomorBaris')->get()->mapWithKeys(fn (ImporStokAwalBaris $b): array => [$b->NomorBaris => $b->Galat ?? []])->all();

        expect($impor->Status)->toBe(StatusImporStokAwal::Pratinjau)
            ->and($impor->JumlahValid)->toBe(4)
            ->and($impor->JumlahGalat)->toBe(5)
            ->and(array_keys($galat))->toBe([6, 7, 8, 9, 10])
            ->and($galat[6][0]['Pesan'])->toBe('Produk, lokasi stok, dan batch yang sama ada di beberapa baris (baris 6, 7). Gabungkan menjadi satu baris.')
            ->and($galat[8][0]['Pesan'])->toBe("Stok awal {$kecap->Nama} di {$t['Gudang']->Nama} sudah dicatat. Koreksi lewat penyesuaian stok.");

        $bahan = ImporStokAwalBaris::query()->where('IdImporStokAwal', $impor->Id)->where('NomorBaris', 3)->sole();
        expect($bahan->Data)->toMatchArray(['IdProduk' => $produk['BahanBaku']->Id, 'IdGudang' => $gudangBelakang->Id, 'Jumlah' => '12.5000', 'HargaModal' => '14250.125000', 'Nilai' => '178126.56']);

        $masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Pratinjau.RingkasanDokumen', [
                ['NamaGudang' => $t['Gudang']->Nama, 'JumlahBaris' => 2, 'TotalNilai' => '2274000.00'],
                ['NamaGudang' => $gudangBelakang->Nama, 'JumlahBaris' => 2, 'TotalNilai' => '1114126.56'],
            ])
            ->has('Pratinjau.BarisGalat', 5));

        $masuk->post("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $impor->refresh();
        $draf = StokAwal::query()->where('IdImporStokAwal', $impor->Id)->orderBy('IdGudang')->get();

        expect($impor->Status)->toBe(StatusImporStokAwal::Selesai)
            ->and($impor->JumlahDokumen)->toBe(2)
            ->and($impor->SelesaiPada)->not->toBeNull()
            ->and($draf)->toHaveCount(2)
            ->and($draf->pluck('Status')->all())->toBe([StatusStokAwal::Draf, StatusStokAwal::Draf])
            ->and($draf->pluck('Sumber')->all())->toBe([SumberStokAwal::Impor, SumberStokAwal::Impor])
            ->and($draf->pluck('IdGudang')->all())->toBe([$t['Gudang']->Id, $gudangBelakang->Id])
            ->and($draf->pluck('Nomor')->all())->toBe([null, null])
            ->and($draf->pluck('DibuatOleh')->all())->toBe([$impor->IdPengguna, $impor->IdPengguna])
            ->and($draf[0]->Tanggal->toDateString())->toBe(now()->subDays(2)->toDateString())
            ->and($draf->pluck('TotalNilai')->all())->toBe(['2274000.00', '1114126.56'])
            ->and(StokAwalDetail::query()->where('IdStokAwal', $draf[1]->Id)->where('IdProduk', $produk['Batch']->Id)->sole()->NomorBatch)->toBe('B-2026-09')
            ->and(StokAwalDetail::query()->where('IdStokAwal', $draf[0]->Id)->where('IdProduk', $produk['Seri']->Id)->sole()->DaftarNomorSeri)->toBe(['RC-0001', 'RC-0002'])
            ->and(ImporStokAwalBaris::query()->where('IdImporStokAwal', $impor->Id)->where('Status', StatusBarisImporStokAwal::Diterapkan->value)->whereNotNull('IdStokAwal')->count())->toBe(4)
            // Impor tidak pernah memposting: tanpa mutasi stok, saldo, maupun jurnal.
            ->and(MutasiStok::query()->count())->toBe(0)
            ->and(SaldoStok::query()->count())->toBe(0)
            ->and(Jurnal::query()->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.impor.terapkan')->pluck('NilaiBaru')->pluck('Tahap')->all())->toBe(['Mulai', 'Selesai']);

        $masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Impor.Status', 'Selesai')
            ->where('Impor.JumlahDokumen', 2)
            ->has('Dokumen', 2)
            ->where('Dokumen.0.Status', 'Draf')
            ->where('Dokumen.0.Uuid', $draf[0]->Uuid));

        // Terapkan lagi (klik ganda / kirim ulang) tidak membuat draf baru.
        $masuk->post("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/terapkan")->assertSessionHasErrors('Impor');
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(StokAwal::query()->count())->toBe(3);
    });

    it('lokasi dengan baris lebih dari persediaan.StokAwal.MaksimalBaris dipecah menjadi beberapa draf', function (): void {
        config(['persediaan.StokAwal.MaksimalBaris' => 2]);
        $t = BantuanPersediaan::SiapkanTenant();
        $baris = [BantuanImporStokAwal::JUDUL];

        for ($i = 1; $i <= 5; $i++) {
            $p = BantuanKatalog::BuatProduk(['Nama' => "Mi Instan Goreng Rasa Rendang Isi 5 Varian {$i}", 'Sku' => "MIE-{$i}"], '3500.00', $t['Pcs']);
            $baris[] = [$p->Sku, '', '', '', 40 * $i, '2.750'];
        }

        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv($baris), $t['Gudang']->Uuid);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasNoErrors();
        $masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Pratinjau.RingkasanDokumen', [
                ['NamaGudang' => $t['Gudang']->Nama, 'JumlahBaris' => 2, 'TotalNilai' => '330000.00'],
                ['NamaGudang' => $t['Gudang']->Nama, 'JumlahBaris' => 2, 'TotalNilai' => '770000.00'],
                ['NamaGudang' => $t['Gudang']->Nama, 'JumlahBaris' => 1, 'TotalNilai' => '550000.00'],
            ]));

        $masuk->post("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->JumlahDokumen)->toBe(3)
            ->and(StokAwal::query()->where('IdImporStokAwal', $impor->Id)->orderBy('Id')->pluck('JumlahBaris')->all())->toBe([2, 2, 1]);
    });

    it('produk diarsipkan setelah pratinjau → impor Gagal dengan pesan. Draf yang sudah dibuat tidak diulang saat dilanjutkan', function (): void {
        config(['persediaan.StokAwal.MaksimalBaris' => 1]);
        $t = BantuanPersediaan::SiapkanTenant();
        $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kristal Putih 1 kg', 'Sku' => 'GULA-1'], '17500.00', $t['Pcs']);
        $teh = BantuanKatalog::BuatProduk(['Nama' => 'Teh Celup Melati Isi 25', 'Sku' => 'TEH-25'], '6500.00', $t['Pcs']);
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([BantuanImporStokAwal::JUDUL, [$gula->Sku, '', '', '', '100', '15.000'], [$teh->Sku, '', '', '', '60', '5.200']]), $t['Gudang']->Uuid);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        Produk::query()->whereKey($teh->Id)->update(['DiarsipkanPada' => now()]);
        $masuk->post("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $impor->refresh();
        expect($impor->Status)->toBe(StatusImporStokAwal::Gagal)
            ->and($impor->PesanGalat)->toStartWith('Draf stok awal gagal dibuat: Baris berkas 3: ')
            ->and($impor->JumlahDokumen)->toBe(1)
            ->and(StokAwal::query()->count())->toBe(1);

        Produk::query()->whereKey($teh->Id)->update(['DiarsipkanPada' => null]);
        $masuk->post("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/lanjutkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $impor->refresh();
        expect($impor->Status)->toBe(StatusImporStokAwal::Selesai)
            ->and($impor->JumlahDokumen)->toBe(2)
            ->and(StokAwal::query()->count())->toBe(2)
            ->and(StokAwalDetail::query()->orderBy('Id')->pluck('IdProduk')->all())->toBe([$gula->Id, $teh->Id])
            ->and(MutasiStok::query()->count())->toBe(0);
    });

    it('lebih dari BatasBarisSinkron baris valid → pembuatan draf di antrean. Pengunggah menjadi pembuat draf & pelaku audit', function (): void {
        config(['persediaan.Impor.BatasBarisSinkron' => 1]);
        $t = BantuanPersediaan::SiapkanTenant();
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit 2 Liter', 'Sku' => 'MGS-2L'], '38500.00', $t['Pcs']);
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pandan Wangi 5 kg', 'Sku' => 'BRS-5'], '78500.00', $t['Pcs']);
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([BantuanImporStokAwal::JUDUL, [$minyak->Sku, '', '', '', '24', '34.000'], [$beras->Sku, '', '', '', '15', '71.500']]), $t['Gudang']->Uuid);
        config(['persediaan.Impor.BatasBarisSinkron' => 300]);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasNoErrors();
        config(['persediaan.Impor.BatasBarisSinkron' => 1]);

        Queue::fake();
        $masuk->post("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporStokAwal::Menerapkan)
            ->and(StokAwal::query()->count())->toBe(0);
        Queue::assertPushed(TerapkanImporStokAwalTugas::class, fn (TerapkanImporStokAwalTugas $tugas): bool => $tugas->idImporStokAwal === $impor->Id);

        // Seperti worker: tanpa pengguna masuk dan tanpa konteks audit/tenant dari request.
        Auth::guard('web')->forgetUser();
        app(PencatatAudit::class)->AturKonteks(null, null, null);
        $tugas = new TerapkanImporStokAwalTugas($t['Tenant']->Id, $impor->IdPengguna, $impor->Id);
        (new UniqueLock(app(Cache::class)))->release($tugas);
        $tugas->handle(app(KonteksTugasImpor::class), app(PenerapImporStokAwal::class));

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $draf = StokAwal::query()->where('IdImporStokAwal', $impor->Id)->sole();
        expect($impor->refresh()->Status)->toBe(StatusImporStokAwal::Selesai)
            ->and($draf->DibuatOleh)->toBe($impor->IdPengguna)
            ->and($draf->TotalNilai)->toBe('1888500.00')
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.buat')->sole()->IdPengguna)->toBe($impor->IdPengguna)
            ->and(MutasiStok::query()->count())->toBe(0);
    });
});
