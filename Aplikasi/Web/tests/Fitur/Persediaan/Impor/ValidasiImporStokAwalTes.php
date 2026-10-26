<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Impor\Layanan\KonteksTugasImpor;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Persediaan\Enum\StatusBarisImporStokAwal;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PemvalidasiImporStokAwal;
use App\Domain\Persediaan\Impor\Tugas\ValidasiImporStokAwalTugas;
use App\Domain\Persediaan\Model\ImporStokAwalBaris;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanImporStokAwal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/**
 * Galat per nomor baris impor terakhir tenant konteks.
 *
 * @return array<int, list<array{Bidang: string, Pesan: string}>>
 */
function AmbilGalatPerBarisImpor(int $idImpor): array
{
    return ImporStokAwalBaris::query()->where('IdImporStokAwal', $idImpor)->where('Status', StatusBarisImporStokAwal::Galat->value)
        ->orderBy('NomorBaris')->get()->mapWithKeys(fn (ImporStokAwalBaris $b): array => [$b->NomorBaris => $b->Galat ?? []])->all();
}

/** Jalankan tugas validasi seperti worker: kunci unik dilepas saat tugas mulai diproses (ShouldBeUniqueUntilProcessing). */
function JalankanValidasiImporUji(ValidasiImporStokAwalTugas $tugas): void
{
    (new UniqueLock(app(Cache::class)))->release($tugas);
    $tugas->handle(app(KonteksTugasImpor::class), app(PemvalidasiImporStokAwal::class));
}

describe('F-05a impor stok awal: pemetaan (DesainF05a C.7)', function (): void {
    it('menolak pemetaan tanpa pencocok produk, tanpa Stok/Harga Modal, tanpa lokasi, kolom ganda, dan tanggal masa depan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([BantuanImporStokAwal::JUDUL, ['MGS-2L', '', '', '', '24', '38500']]));
        $dasar = $impor->Pemetaan ?? [];

        BantuanImporStokAwal::Petakan($masuk, $impor, null, null, ['Sku' => null, 'NamaProduk' => null] + $dasar)
            ->assertSessionHasErrors(['Pemetaan.Sku' => 'Petakan minimal satu kolom pencocok produk: SKU, Barcode, atau Nama Produk.']);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid, null, ['HargaModal' => null] + $dasar)
            ->assertSessionHasErrors(['Pemetaan.HargaModal' => 'Harga Modal wajib dipetakan ke satu kolom.']);
        BantuanImporStokAwal::Petakan($masuk, $impor, null, null, ['Lokasi' => null] + $dasar)
            ->assertSessionHasErrors(['Pemetaan.Lokasi' => 'Petakan kolom Lokasi Stok atau pilih lokasi stok bawaan.']);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid, null, ['HargaModal' => 4] + $dasar)
            ->assertSessionHasErrors(['Pemetaan.HargaModal' => 'Kolom ini sudah dipakai untuk Stok.']);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid, null, ['NomorSeri' => 40] + $dasar)
            ->assertSessionHasErrors(['Pemetaan.NomorSeri' => 'Kolom yang dipilih tidak ada di berkas.']);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid, now()->addDays(3)->toDateString())
            ->assertSessionHasErrors('Tanggal');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporStokAwal::MenungguPemetaan)
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.impor.pemetaan')->count())->toBe(0);
    });
});

describe('F-05a impor stok awal: validasi baris (produk, lokasi, jumlah, harga modal)', function (): void {
    it('baris ditolak dengan pesan per kolom: produk tidak dikenal/ambigu/konsinyasi/jasa/diarsipkan, lokasi tidak dikenal/ambigu/diarsipkan/di luar tenant, desimal di satuan pcs', function (): void {
        $lain = BantuanPersediaan::SiapkanTenant('Apotek Sehat Sentosa');
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Temanggung 250 gram', 'Sku' => 'KOPI-A'], '25000.00', $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Temanggung 250 gram', 'Sku' => 'KOPI-B'], '25000.00', $t['Pcs']);
        $arsip = BantuanKatalog::BuatProduk(['Nama' => 'Teh Celup Melati Isi 25', 'Sku' => 'TEH-ARSIP', 'DiarsipkanPada' => now()], '6500.00', $t['Pcs']);
        BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Belakang');
        BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Belakang');
        $gudangArsip = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Lama Pasar Legi');
        $gudangArsip->Status = StatusOrganisasi::Diarsipkan;
        $gudangArsip->save();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([
            BantuanImporStokAwal::JUDUL,
            ['TIDAK-ADA-99', '', '', '', '10', '1000'],                                         // 2
            ['', 'Kopi Bubuk Robusta Temanggung 250 gram', '', '', '10', '25000'],              // 3
            [$produk['Konsinyasi']->Sku, '', '', '', '10', '9000'],                             // 4
            [$produk['Jasa']->Sku, '', '', '', '1', '0'],                                       // 5
            [$arsip->Sku, '', '', '', '10', '5000'],                                            // 6
            [$produk['Stok']->Sku, '', '', 'LOKASI-ANEH', '10', '38500'],                       // 7
            [$produk['Stok']->Sku, '', '', 'Gudang Belakang', '10', '38500'],                   // 8
            [$produk['Stok']->Sku, '', '', $gudangArsip->Kode, '10', '38500'],                  // 9
            [$produk['Stok']->Sku, '', '', $lain['Gudang']->Kode.'-X', '10', '38500'],          // 10
            [$produk['Stok']->Sku, '', '', '', '2,5', '38500'],                                 // 11
            [$produk['Stok']->Sku, '', '', '', '0', '1.234,1234567'],                           // 12
        ]));
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $impor->refresh();
        $galat = AmbilGalatPerBarisImpor($impor->Id);

        expect($impor->Status)->toBe(StatusImporStokAwal::Pratinjau)
            ->and($impor->JumlahValid)->toBe(0)
            ->and($impor->JumlahGalat)->toBe(11)
            ->and(array_keys($galat))->toBe(range(2, 12))
            ->and($galat[2][0])->toEqual(['Bidang' => 'Produk', 'Pesan' => 'Produk "TIDAK-ADA-99" tidak ditemukan. Periksa SKU, barcode, atau nama produk.'])
            ->and($galat[3][0]['Pesan'])->toBe('"Kopi Bubuk Robusta Temanggung 250 gram" cocok dengan 2 produk. Isi SKU agar produk tidak ambigu.')
            ->and($galat[4][0]['Pesan'])->toContain('barang konsinyasi')
            ->and($galat[5][0]['Pesan'])->toContain('tidak punya stok')
            ->and($galat[6][0]['Pesan'])->toBe('Teh Celup Melati Isi 25 sudah diarsipkan. Aktifkan lagi produknya bila stoknya masih ada.')
            ->and($galat[7][0])->toEqual(['Bidang' => 'Lokasi Stok', 'Pesan' => 'Lokasi stok "LOKASI-ANEH" tidak ditemukan atau di luar outlet yang boleh Anda akses.'])
            ->and($galat[8][0]['Pesan'])->toBe('Lokasi "Gudang Belakang" cocok dengan 2 lokasi stok. Pakai kode lokasi.')
            ->and($galat[9][0]['Pesan'])->toBe('Lokasi stok Gudang Lama Pasar Legi sudah diarsipkan.')
            ->and($galat[10][0]['Bidang'])->toBe('Lokasi Stok')
            ->and($galat[11][0])->toEqual(['Bidang' => 'Stok', 'Pesan' => 'Satuan pcs untuk '.$produk['Stok']->Nama.' tidak boleh desimal.'])
            ->and(array_column($galat[12], 'Bidang'))->toBe(['Stok', 'Harga Modal']);

        $masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Impor.Status', 'Pratinjau')
            ->where('Impor.Progres', 100)
            ->has('Pratinjau.BarisGalat', 11)
            ->where('Pratinjau.BarisGalat.0.NomorBaris', 2)
            ->where('Pratinjau.BarisGalat.0.Data.Produk', 'TIDAK-ADA-99')
            ->where('Pratinjau.RingkasanDokumen', []));

        $masuk->post("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/terapkan")
            ->assertSessionHasErrors(['Impor' => 'Tidak ada baris valid untuk dijadikan draf. Perbaiki berkas lalu unggah ulang.']);
    });

    it('ubah pemetaan dari Pratinjau menghapus hasil validasi lama lalu memeriksa ulang. Audit stok-awal.impor.pemetaan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([
            ['Kode', 'Qty', 'Modal', 'Catatan'],
            ['TIDAK-ADA-1', '5', '1000', 'x'],
            ['TIDAK-ADA-2', '6', '2000', 'y'],
        ]));
        expect($impor->Pemetaan)->toMatchArray(['Jumlah' => 1, 'HargaModal' => 2, 'Sku' => null]);

        $pemetaan = ['Sku' => 0] + ($impor->Pemetaan ?? []);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid, null, $pemetaan)->assertSessionHasNoErrors();
        BantuanImporStokAwal::Petakan($masuk, $impor->refresh(), $t['Gudang']->Uuid, null, ['Sku' => null, 'NamaProduk' => 3] + $pemetaan)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $impor->refresh();
        expect($impor->Status)->toBe(StatusImporStokAwal::Pratinjau)
            ->and(ImporStokAwalBaris::query()->where('IdImporStokAwal', $impor->Id)->count())->toBe(2)
            ->and(AmbilGalatPerBarisImpor($impor->Id)[2][0]['Pesan'])->toBe('Produk "x" tidak ditemukan. Periksa SKU, barcode, atau nama produk.')
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.impor.pemetaan')->count())->toBe(2);
    });
});

describe('F-05a impor stok awal: validasi di antrean (berkas besar)', function (): void {
    it('lebih dari BatasBarisSinkron → antrean. Anggaran waktu habis → tugas mengirim dirinya lagi dan melanjutkan tanpa baris ganda', function (): void {
        config(['persediaan.Impor.BatasBarisSinkron' => 10]);
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $baris = [BantuanImporStokAwal::JUDUL];

        for ($i = 1; $i <= 620; $i++) {
            $baris[] = ['BELUM-ADA-'.$i, '', '', '', '3', '12.500'];
        }

        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv($baris));
        Queue::fake();
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporStokAwal::Memvalidasi);
        Queue::assertPushed(ValidasiImporStokAwalTugas::class, fn (ValidasiImporStokAwalTugas $tugas): bool => $tugas->idImporStokAwal === $impor->Id && $tugas->idTenant === $t['Tenant']->Id);

        $masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/status")->assertOk()
            ->assertJson(['Status' => 'Memvalidasi', 'LabelStatus' => 'Memeriksa data', 'Progres' => 0, 'JumlahDokumen' => 0]);

        // Anggaran 0 detik: berhenti setelah potongan sisip pertama (500 baris).
        config(['persediaan.Impor.MaksimalDetikPerTugas' => 0]);
        JalankanValidasiImporUji(new ValidasiImporStokAwalTugas($t['Tenant']->Id, $impor->IdPengguna, $impor->Id));
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ImporStokAwalBaris::query()->where('IdImporStokAwal', $impor->Id)->count())->toBe(500)
            ->and($impor->refresh()->Status)->toBe(StatusImporStokAwal::Memvalidasi);
        Queue::assertPushed(ValidasiImporStokAwalTugas::class, 2);
        $masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/status")->assertJson(['Progres' => 80]);

        config(['persediaan.Impor.MaksimalDetikPerTugas' => 40]);
        JalankanValidasiImporUji(new ValidasiImporStokAwalTugas($t['Tenant']->Id, $impor->IdPengguna, $impor->Id));
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $impor->refresh();
        expect($impor->Status)->toBe(StatusImporStokAwal::Pratinjau)
            ->and($impor->JumlahBaris)->toBe(620)
            ->and($impor->JumlahGalat)->toBe(620)
            ->and(ImporStokAwalBaris::query()->where('IdImporStokAwal', $impor->Id)->distinct()->count('NomorBaris'))->toBe(620);
    });

    it('masalah sistem di tugas antrean menandai impor Gagal dengan pesan (failed)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([BantuanImporStokAwal::JUDUL, ['X-1', '', '', '', '1', '1']]));
        Queue::fake();
        config(['persediaan.Impor.BatasBarisSinkron' => 0]);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasNoErrors();

        (new ValidasiImporStokAwalTugas($t['Tenant']->Id, $impor->IdPengguna, $impor->Id))->failed(new RuntimeException('disk hilang'));

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $impor->refresh();
        expect($impor->Status)->toBe(StatusImporStokAwal::Gagal)
            ->and($impor->PesanGalat)->toBe(ValidasiImporStokAwalTugas::PESAN_GAGAL);
        // Gagal saat memeriksa (belum pernah membuat draf) tidak bisa dilanjutkan: unggah ulang.
        $masuk->post("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/lanjutkan")->assertSessionHasErrors('Impor');
    });
});

it('F-05a impor stok awal: produk jenis IndukVarian tidak punya stok', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    $induk = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Katun Combed 30s', 'Sku' => 'KAOS-INDUK', 'Jenis' => JenisProduk::IndukVarian], null, $t['Pcs']);
    $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
    $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([BantuanImporStokAwal::JUDUL, [$induk->Sku, '', '', '', '10', '45000']]));
    BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(AmbilGalatPerBarisImpor($impor->Id)[2][0]['Pesan'])->toContain('tidak punya stok');
});
