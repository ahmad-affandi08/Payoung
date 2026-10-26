<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Model\ImporStokAwal;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanImporStokAwal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/** xlsx sah + entri berisi `$ukuranAsli` bita nol yang ukuran terdeklarasinya dipalsukan (bom ZIP). */
function BuatXlsxBomZipStokAwal(int $ukuranAsli, int $ukuranDeklarasi): string
{
    $path = (string) BantuanImporStokAwal::BuatXlsx([BantuanImporStokAwal::JUDUL, ['MGS-2L', 'Minyak Goreng', 'pcs', '', 10, 38500]])->getRealPath();
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->addFromString('xl/media/isi.bin', str_repeat("\x00", $ukuranAsli));
    $zip->close();

    $isi = (string) file_get_contents($path);
    $nama = 'xl/media/isi.bin';
    $lokal = strpos($isi, $nama) - 30;
    $pusat = strpos($isi, $nama, $lokal + 31) - 46;
    $isi = substr_replace($isi, pack('V', $ukuranDeklarasi), $lokal + 22, 4);

    return substr_replace($isi, pack('V', $ukuranDeklarasi), $pusat + 24, 4);
}

describe('F-05a impor stok awal: unggah berkas (pola BR-03.6)', function (): void {
    it('unggah xlsx templat → MenungguPemetaan, berkas privat per tenant, pemetaan otomatis, audit stok-awal.impor.unggah', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatXlsx([
            BantuanImporStokAwal::JUDUL,
            ['MGS-2L', 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter', 'pcs', $t['Gudang']->Kode, 24, '38.500'],
            ['GULA-CURAH', 'Gula Pasir Kristal Putih Curah', 'kg', '', '12,5', '14.250,75'],
        ], 'stok awal toko.xlsx'), $t['Gudang']->Uuid);

        expect($impor->Status)->toBe(StatusImporStokAwal::MenungguPemetaan)
            ->and($impor->NamaBerkas)->toBe('stok awal toko.xlsx')
            ->and($impor->Format)->toBe('xlsx')
            ->and($impor->JumlahBaris)->toBe(2)
            ->and($impor->IdGudangBawaan)->toBe($t['Gudang']->Id)
            ->and($impor->PathBerkas)->toStartWith('impor/'.$t['Tenant']->Id.'/')
            ->and($impor->Pemetaan)->toEqual(['Sku' => 0, 'Barcode' => null, 'NamaProduk' => 1, 'Lokasi' => 3, 'Jumlah' => 4, 'HargaModal' => 5, 'NomorBatch' => 6, 'TanggalKedaluwarsa' => 7, 'NomorSeri' => 8]);
        Storage::disk('local')->assertExists($impor->PathBerkas);

        $log = LogAudit::query()->where('Peristiwa', 'stok-awal.impor.unggah')->sole();
        expect($log->NilaiBaru)->toMatchArray(['NamaBerkas' => 'stok awal toko.xlsx', 'JumlahBaris' => 2, 'IdGudangBawaan' => $t['Gudang']->Id]);

        $masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/StokAwal/Impor/Detail')
            ->where('Impor.Status', 'MenungguPemetaan')
            ->where('Impor.NamaGudangBawaan', $t['Gudang']->Nama)
            ->where('Impor.JumlahDokumen', 0)
            ->has('Pemetaan.KolomSumber', 9)
            ->has('Pemetaan.Bidang', 9)
            ->where('Pemetaan.Bidang.4', ['Kunci' => 'Jumlah', 'Label' => 'Stok', 'Wajib' => true, 'Keterangan' => 'Jumlah dalam satuan dasar produk, lebih dari 0.'])
            ->where('Pemetaan.Pemetaan.HargaModal', 5)
            ->where('Pemetaan.UuidGudangBawaan', $t['Gudang']->Uuid)
            ->where('Pemetaan.Tanggal', fn (string $tanggal): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) === 1)
            ->where('Pratinjau', null)
            ->where('Dokumen', [])
            ->has('OpsiGudang', 1));

        $masuk->get('/kelola/persediaan/stok-awal/impor')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/StokAwal/Impor/Daftar')
            ->has('Riwayat.Data', 1)
            ->where('Riwayat.Data.0.Uuid', $impor->Uuid)
            ->where('BatasBerkas.Ekstensi', ['xlsx', 'csv']));
    });

    it('idempoten per HashBerkas: berkas yang sama selama impor masih aktif tidak membuat impor baru. Setelah dibatalkan boleh unggah ulang', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $baris = [BantuanImporStokAwal::JUDUL, ['MGS-2L', '', '', '', '10', '38500']];

        $pertama = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv($baris));
        $kedua = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv($baris));

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($kedua->Id)->toBe($pertama->Id)
            ->and(ImporStokAwal::query()->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles('impor/'.$t['Tenant']->Id))->toHaveCount(1);

        $masuk->post("/kelola/persediaan/stok-awal/impor/{$pertama->Uuid}/batalkan")->assertSessionHasNoErrors();
        $ketiga = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv($baris));

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($ketiga->Id)->not->toBe($pertama->Id)
            ->and(ImporStokAwal::query()->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.impor.unggah')->count())->toBe(2);
    });

    it('menolak berkas palsu, .xls lama, terlalu besar, terlalu banyak baris, dan bom ZIP (penjaga F-03 dipakai ulang)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $url = '/kelola/persediaan/stok-awal/impor';

        $masuk->post($url, ['Berkas' => BantuanImpor::BuatBerkasMentah("%PDF-1.7\x00\x01\x02", 'stok.xlsx')])
            ->assertSessionHasErrors(['Berkas' => 'Isi berkas bukan Excel .xlsx atau teks CSV.']);
        $masuk->post($url, ['Berkas' => BantuanImpor::BuatBerkasMentah("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 64), 'stok.xls')])
            ->assertSessionHasErrors(['Berkas' => 'Format berkas harus .xlsx atau .csv.']);
        $masuk->post($url, ['Berkas' => BantuanImporStokAwal::BuatXlsx([BantuanImporStokAwal::JUDUL, ['A', 'B']], 'stok.csv')])
            ->assertSessionHasErrors(['Berkas' => 'Isi berkas adalah xlsx, tidak sesuai dengan nama berkas .csv.']);

        config(['persediaan.Impor.MaksimalBaris' => 2]);
        $masuk->post($url, ['Berkas' => BantuanImporStokAwal::BuatCsv([BantuanImporStokAwal::JUDUL, ['A1', '', '', '', '1', '1'], ['A2', '', '', '', '1', '1'], ['A3', '', '', '', '1', '1']])])
            ->assertSessionHasErrors('Berkas');

        config(['persediaan.Impor.MaksimalBaris' => 20000, 'persediaan.Impor.UkuranMaksimalKb' => 1]);
        $masuk->post($url, ['Berkas' => BantuanImporStokAwal::BuatCsv([BantuanImporStokAwal::JUDUL, [str_repeat('A', 2048), '', '', '', '1', '1']])])
            ->assertSessionHasErrors('Berkas');

        config(['persediaan.Impor.UkuranMaksimalKb' => 10240, 'katalog.Impor.UkuranEkstrakMaksimalKb' => 1024]);
        $masuk->post($url, ['Berkas' => BantuanImpor::BuatBerkasMentah(BuatXlsxBomZipStokAwal(3 * 1024 * 1024, 100), 'stok.xlsx')])
            ->assertSessionHasErrors(['Berkas' => 'Isi berkas bukan lembar kerja Excel .xlsx yang sah.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ImporStokAwal::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles('impor'))->toBe([]);
    });

    it('lokasi stok bawaan tenant lain = 404. Lokasi diarsipkan ditolak GudangDiarsipkan', function (): void {
        $lain = BantuanPersediaan::SiapkanTenant('Apotek Sehat Sentosa');
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $berkas = fn () => BantuanImporStokAwal::BuatCsv([BantuanImporStokAwal::JUDUL, ['MGS-2L', '', '', '', '10', '38500']]);

        $masuk->post('/kelola/persediaan/stok-awal/impor', ['Berkas' => $berkas(), 'UuidGudangBawaan' => $lain['Gudang']->Uuid])->assertNotFound();

        $arsip = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Lama Pasar Legi');
        $arsip->Status = StatusOrganisasi::Diarsipkan;
        $arsip->save();
        $masuk->post('/kelola/persediaan/stok-awal/impor', ['Berkas' => $berkas(), 'UuidGudangBawaan' => $arsip->Uuid])
            ->assertSessionHasErrors(['UuidGudangBawaan' => 'Lokasi stok bawaan sudah diarsipkan. Pilih lokasi stok lain.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ImporStokAwal::query()->count())->toBe(0);
    });
});
