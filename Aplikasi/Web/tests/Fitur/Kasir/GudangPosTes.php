<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Aksi\MulaiStokOpname;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use App\Domain\Persediaan\Model\TransferStok;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * POS-25 modul Gudang di aplikasi (PRD §13.5 mode Gudang, F-04 langkah 5, F-05b): staf gudang yang masuk dengan PIN di
 * perangkat menerima barang dari PO, menerima transfer masuk, dan menghitung stok opname untuk lokasi stok outlet
 * perangkat. Online-first; izin diperiksa di server per staf; dokumen di luar outlet = 404; kirim ulang dengan
 * Idempotency-Key yang sama tidak menggandakan dokumen.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** @return array<string, mixed> */
function SiapkanGudangPos(TestCase $tes): array
{
    $t = BantuanPembelian::SiapkanTenant();
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $perangkat = BantuanPerangkat::BuatDanAktifkan($tes, $t['Tenant']->Id, $t['Outlet']);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    return [
        ...$t,
        'Produk' => $p,
        'Token' => $perangkat['Token'],
        'StafGudang' => BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::StafGudang),
        'Kasir' => BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Kasir),
        'Pemasok' => BantuanPembelian::BuatPemasok(),
    ];
}

/**
 * @param  array<string, mixed>  $isi
 * @return TestResponse<JsonResponse>
 */
function KirimGudang(TestCase $tes, string $token, string $metode, string $jalur, array $isi = [], ?string $kunci = null): TestResponse
{
    $tes->flushHeaders();
    $tes->withToken($token);

    if ($kunci !== null) {
        $tes->withHeader('Idempotency-Key', $kunci);
    }

    return $metode === 'GET' ? $tes->getJson('/api/pos/v1/'.$jalur) : $tes->postJson('/api/pos/v1/'.$jalur, $isi);
}

describe('terima barang dari PO', function (): void {
    it('daftar PO siap diterima dengan sisa per baris, lalu GRN sebagian → stok bertambah, PO Diterima sebagian', function (): void {
        $k = SiapkanGudangPos($this);
        $po = BantuanPembelian::BuatPoDisetujui($k['Pemasok'], $k['Gudang'], [
            [$k['Produk']['Stok'], '24', '36000'],
            [$k['Produk']['Batch'], '12', '18500'],
        ], $k['Pemilik']->Id);

        $daftar = KirimGudang($this, $k['Token'], 'GET', 'gudang/pesanan-pembelian')->assertOk()->json('Pesanan');
        expect($daftar)->toHaveCount(1)
            ->and($daftar[0]['Uuid'])->toBe($po->Uuid)
            ->and($daftar[0]['NamaPemasok'])->toBe('PT Sumber Pangan Nusantara')
            ->and(array_column($daftar[0]['Baris'], 'Urutan'))->toBe([1, 2])
            ->and($daftar[0]['Baris'][0]['UuidProduk'])->toBe($k['Produk']['Stok']->Uuid)
            ->and($daftar[0]['Baris'][0]['Sisa'])->toBe('24.0000')
            ->and($daftar[0]['Baris'][1]['Pelacakan'])->toBe('Batch')
            ->and($daftar[0])->not->toHaveKey('Total');

        $isi = [
            'UuidPesananPembelian' => $po->Uuid,
            'UuidPengguna' => $k['StafGudang']->Uuid,
            'NomorSuratJalan' => 'SJ/SPN/2609/0142',
            'Baris' => [
                ['Urutan' => 1, 'Jumlah' => '20'],
                ['Urutan' => 2, 'Jumlah' => '12', 'NomorBatch' => 'UHT-2610B', 'TanggalKedaluwarsa' => '2027-04-30'],
            ],
        ];
        $kunci = 'gudang-'.Str::ulid();
        $respons = KirimGudang($this, $k['Token'], 'POST', 'gudang/penerimaan', $isi, $kunci)->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect($respons->json('Penerimaan.Nomor'))->toStartWith('GR/')
            ->and($respons->json('Pesanan.Baris.0.Sisa'))->toBe('4.0000')
            ->and($po->refresh()->Status)->toBe(StatusPesananPembelian::DiterimaSebagian)
            ->and(BantuanDokumenPersediaan::Saldo($k['Produk']['Stok'], $k['Gudang'])[0])->toBe('20.0000')
            ->and(BantuanDokumenPersediaan::Saldo($k['Produk']['Batch'], $k['Gudang'])[0])->toBe('12.0000');

        $grn = PenerimaanBarang::query()->sole();
        expect($grn->NomorSuratJalan)->toBe('SJ/SPN/2609/0142')
            ->and($grn->Tanggal->toDateString())->toBe($po->AmbilTanggalPenerimaanBawaan()->toDateString())
            ->and($grn->DibuatOleh)->toBe($k['StafGudang']->Id);

        // Kirim ulang (respons hilang) dengan kunci sama = diputar ulang, bukan GRN kedua.
        KirimGudang($this, $k['Token'], 'POST', 'gudang/penerimaan', $isi, $kunci)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PenerimaanBarang::query()->count())->toBe(1)
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);
    });

    it('melebihi sisa, batch tanpa nomor, dan baris tak dikenal ditolak tanpa menulis stok', function (): void {
        $k = SiapkanGudangPos($this);
        $po = BantuanPembelian::BuatPoDisetujui($k['Pemasok'], $k['Gudang'], [
            [$k['Produk']['Stok'], '10', '36000'],
            [$k['Produk']['Batch'], '5', '18500'],
        ], $k['Pemilik']->Id);
        $kirim = fn (array $baris): TestResponse => KirimGudang($this, $k['Token'], 'POST', 'gudang/penerimaan', [
            'UuidPesananPembelian' => $po->Uuid,
            'UuidPengguna' => $k['StafGudang']->Uuid,
            'Baris' => $baris,
        ]);

        expect($kirim([['Urutan' => 1, 'Jumlah' => '11']])->assertStatus(422)->json('Galat.Kode'))->toBe('MelebihiPesanan')
            ->and($kirim([['Urutan' => 2, 'Jumlah' => '5']])->assertStatus(422)->json('Galat.Kode'))->not->toBeNull()
            ->and($kirim([['Urutan' => 9, 'Jumlah' => '1']])->assertStatus(422)->json('Galat.Kode'))->toBe('BarisTidakDikenal');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PenerimaanBarang::query()->count())->toBe(0)
            ->and($po->refresh()->Status)->toBe(StatusPesananPembelian::Disetujui);
    });

    it('kasir tanpa izin persediaan/pembelian ditolak 403; PO lokasi outlet lain tidak tampil dan 404', function (): void {
        $k = SiapkanGudangPos($this);
        $cabang = BantuanDokumenPersediaan::BuatOutlet();
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Gudang Cabang Solo Baru');
        $poCabang = BantuanPembelian::BuatPoDisetujui($k['Pemasok'], $gudangCabang, [[$k['Produk']['Stok'], '10', '36000']], $k['Pemilik']->Id);
        $po = BantuanPembelian::BuatPoDisetujui($k['Pemasok'], $k['Gudang'], [[$k['Produk']['Stok'], '10', '36000']], $k['Pemilik']->Id);
        $isi = fn (PesananPembelian $p, string $uuidPengguna): array => ['UuidPesananPembelian' => $p->Uuid, 'UuidPengguna' => $uuidPengguna, 'Baris' => [['Urutan' => 1, 'Jumlah' => '1']]];

        expect(array_column(KirimGudang($this, $k['Token'], 'GET', 'gudang/pesanan-pembelian')->json('Pesanan'), 'Uuid'))->toBe([$po->Uuid]);
        KirimGudang($this, $k['Token'], 'POST', 'gudang/penerimaan', $isi($po, $k['Kasir']->Uuid))->assertForbidden()->assertJsonPath('Galat.Kode', 'TanpaIzin');
        KirimGudang($this, $k['Token'], 'POST', 'gudang/penerimaan', $isi($poCabang, $k['StafGudang']->Uuid))->assertNotFound();
    });
});

describe('transfer masuk', function (): void {
    it('daftar transfer Dikirim ke lokasi outlet, terima sebagian lalu sisanya → Diterima', function (): void {
        $k = SiapkanGudangPos($this);
        $cabang = BantuanDokumenPersediaan::BuatOutlet();
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
        BantuanStokAwal::BuatDanPosting($gudangCabang, [BantuanStokAwal::Baris($k['Produk']['Stok'], '100', '38500')], $k['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
        $transfer = app(KirimTransferStok::class)->Jalankan(
            BantuanDokumenPersediaan::DrafTransfer($gudangCabang, $k['Gudang'], [BantuanDokumenPersediaan::Baris($k['Produk']['Stok'], '30')]),
            $k['Pemilik']->Id,
        );

        $daftar = KirimGudang($this, $k['Token'], 'GET', 'gudang/transfer')->assertOk()->json('Transfer');
        expect($daftar)->toHaveCount(1)
            ->and($daftar[0]['Uuid'])->toBe($transfer->Uuid)
            ->and($daftar[0]['NamaAsal'])->toContain('Toko Cabang Solo Baru')
            ->and($daftar[0]['Baris'][0]['Sisa'])->toBe('30.0000');

        $terima = fn (string $jumlah): TestResponse => KirimGudang($this, $k['Token'], 'POST', "gudang/transfer/{$transfer->Uuid}/terima", [
            'UuidPengguna' => $k['StafGudang']->Uuid,
            'Baris' => [['Urutan' => 1, 'Jumlah' => $jumlah]],
        ]);
        expect($terima('20')->assertOk()->json('Transfer.Baris.0.Sisa'))->toBe('10.0000')
            ->and($terima('11')->assertStatus(422)->json('Galat.Kode'))->toBe('JumlahMelebihiSisa')
            ->and($terima('10')->assertOk()->json('Transfer.Status'))->toBe(StatusTransferStok::Diterima->value);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(BantuanDokumenPersediaan::Saldo($k['Produk']['Stok'], $k['Gudang'])[0])->toBe('30.0000')
            ->and(KirimGudang($this, $k['Token'], 'GET', 'gudang/transfer')->json('Transfer'))->toBe([]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);
    });

    it('D-105 terima per barang dari aplikasi: Rusak jadi susut, Ditolak kembali ke asal; Jumlah saja tetap diterima (kompatibel mundur)', function (): void {
        $k = SiapkanGudangPos($this);
        $cabang = BantuanDokumenPersediaan::BuatOutlet();
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
        BantuanStokAwal::BuatDanPosting($gudangCabang, [BantuanStokAwal::Baris($k['Produk']['Stok'], '100', '38500')], $k['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
        $transfer = app(KirimTransferStok::class)->Jalankan(
            BantuanDokumenPersediaan::DrafTransfer($gudangCabang, $k['Gudang'], [BantuanDokumenPersediaan::Baris($k['Produk']['Stok'], '30')]),
            $k['Pemilik']->Id,
        );
        $terima = fn (array $baris): TestResponse => KirimGudang($this, $k['Token'], 'POST', "gudang/transfer/{$transfer->Uuid}/terima", [
            'UuidPengguna' => $k['StafGudang']->Uuid,
            'Baris' => [$baris],
        ]);

        // Rusak & Ditolak tanpa Jumlah diterima; angka tidak valid ditolak validasi.
        $terima(['Urutan' => 1, 'Rusak' => '2,5'])->assertStatus(422);
        expect($terima(['Urutan' => 1, 'Jumlah' => '20', 'Rusak' => '4', 'Ditolak' => '3'])->assertOk()->json('Transfer.Baris.0.Sisa'))->toBe('3.0000')
            ->and($terima(['Urutan' => 1, 'Ditolak' => '3'])->assertOk()->json('Transfer.Status'))->toBe(StatusTransferStok::Diterima->value);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $detail = TransferStok::query()->sole()->Detail()->sole();

        expect($detail->JumlahDiterima)->toBe('20.0000')->and($detail->JumlahSusut)->toBe('4.0000')->and($detail->JumlahDitolak)->toBe('6.0000')
            ->and(BantuanDokumenPersediaan::Saldo($k['Produk']['Stok'], $k['Gudang'])[0])->toBe('20.0000')
            ->and(BantuanDokumenPersediaan::Saldo($k['Produk']['Stok'], $gudangCabang)[0])->toBe('76.0000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);
    });

    it('transfer keluar dari outlet ini (bukan tujuan) tidak bisa diterima dari perangkat ini', function (): void {
        $k = SiapkanGudangPos($this);
        $cabang = BantuanDokumenPersediaan::BuatOutlet();
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
        BantuanStokAwal::BuatDanPosting($k['Gudang'], [BantuanStokAwal::Baris($k['Produk']['Stok'], '50', '38500')], $k['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
        $keluar = app(KirimTransferStok::class)->Jalankan(
            BantuanDokumenPersediaan::DrafTransfer($k['Gudang'], $gudangCabang, [BantuanDokumenPersediaan::Baris($k['Produk']['Stok'], '5')]),
            $k['Pemilik']->Id,
        );

        expect(KirimGudang($this, $k['Token'], 'GET', 'gudang/transfer')->json('Transfer'))->toBe([]);
        KirimGudang($this, $k['Token'], 'POST', "gudang/transfer/{$keluar->Uuid}/terima", [
            'UuidPengguna' => $k['StafGudang']->Uuid,
            'Baris' => [['Urutan' => 1, 'Jumlah' => '5']],
        ])->assertNotFound();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TransferStok::query()->sole()->Status)->toBe(StatusTransferStok::Dikirim);
    });
});

describe('stok opname', function (): void {
    it('daftar opname berlangsung; hitung baris lama & produk baru hasil pindai; hitung buta menyembunyikan jumlah sistem', function (): void {
        $k = SiapkanGudangPos($this);
        BantuanStokAwal::BuatDanPosting($k['Gudang'], [
            BantuanStokAwal::Baris($k['Produk']['Stok'], '100', '38500'),
            BantuanStokAwal::Baris($k['Produk']['BahanBaku'], '25.5', '14750'),
        ], $k['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
        $opname = app(MulaiStokOpname::class)->Jalankan($k['Gudang']->Id, null, true, 'Opname akhir bulan', $k['Pemilik']->Id);

        $daftar = KirimGudang($this, $k['Token'], 'GET', 'gudang/opname')->assertOk()->json('Opname');
        expect($daftar)->toHaveCount(1)
            ->and($daftar[0]['HitungButa'])->toBeTrue()
            ->and(array_column($daftar[0]['Baris'], 'JumlahSistem'))->toBe([null, null])
            ->and(array_column($daftar[0]['Baris'], 'UuidProduk'))->toContain($k['Produk']['Stok']->Uuid);
        $urutanStok = array_values(array_filter($daftar[0]['Baris'], fn (array $b): bool => $b['UuidProduk'] === $k['Produk']['Stok']->Uuid))[0]['Urutan'];

        $hasil = KirimGudang($this, $k['Token'], 'POST', "gudang/opname/{$opname->Uuid}/hitung", [
            'UuidPengguna' => $k['StafGudang']->Uuid,
            'Hitung' => [
                ['Urutan' => $urutanStok, 'JumlahFisik' => '97'],
                ['UuidProduk' => $k['Produk']['Produksi']->Uuid, 'JumlahFisik' => '4'],
            ],
        ])->assertOk()->json('Opname');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect($hasil['JumlahDihitung'])->toBe(2)
            ->and($hasil['Baris'])->toHaveCount(3)
            ->and(StokOpnameDetail::query()->where('IdProduk', $k['Produk']['Stok']->Id)->value('JumlahFisik'))->toBe('97.0000')
            ->and(StokOpnameDetail::query()->where('IdProduk', $k['Produk']['Produksi']->Id)->value('DihitungOleh'))->toBe($k['StafGudang']->Id);
    });

    it('kasir tanpa izin ditolak; produk tidak dikenal ditolak; tenant lain tidak bisa menyentuh opname', function (): void {
        $k = SiapkanGudangPos($this);
        $opname = app(MulaiStokOpname::class)->Jalankan($k['Gudang']->Id, null, false, null, $k['Pemilik']->Id);
        $hitung = fn (string $token, string $uuidPengguna, array $baris): TestResponse => KirimGudang($this, $token, 'POST', "gudang/opname/{$opname->Uuid}/hitung", ['UuidPengguna' => $uuidPengguna, 'Hitung' => $baris]);

        $hitung($k['Token'], $k['Kasir']->Uuid, [['UuidProduk' => $k['Produk']['Stok']->Uuid, 'JumlahFisik' => '1']])->assertForbidden();
        expect($hitung($k['Token'], $k['StafGudang']->Uuid, [['UuidProduk' => (string) Str::ulid(), 'JumlahFisik' => '1']])->assertStatus(422)->json('Galat.Kode'))->toBe('ProdukTidakDikenal');

        $lain = SiapkanGudangPos($this);
        expect(KirimGudang($this, $lain['Token'], 'GET', 'gudang/opname')->json('Opname'))->toBe([]);
        $hitung($lain['Token'], $lain['StafGudang']->Uuid, [['UuidProduk' => $lain['Produk']['Stok']->Uuid, 'JumlahFisik' => '1']])->assertNotFound();
    });
});
