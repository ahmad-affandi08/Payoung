<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\TransferStok;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit kemudahan pakai #13: tombol utama formulir dokumen stok & pembelian mengirim `Lanjutkan` — server menyimpan
 * draf lalu langsung memposting/mengirim/mengajukan lewat aksi yang sama dengan tombol di detail. Batas persetujuan
 * tetap berlaku; galat langkah lanjut tidak membatalkan draf (detail dibuka dengan pesannya); izin posting tetap dijaga.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array<string, mixed>
 */
function SiapkanLanjutkan(): array
{
    $t = BantuanPersediaan::SiapkanTenant('Toko Sembako Lanjut Jaya Boyolali');
    BantuanFitur::Beri($t['Tenant']->Id, 'stok.transfer');
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $cabang = B::BuatOutlet();
    $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '137', '38500')], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    return [...$t, 'Produk' => $p, 'GudangCabang' => $gudangCabang];
}

it('transfer & penyesuaian: Lanjutkan langsung mengirim / memposting; penyesuaian di atas batas tetap menunggu persetujuan', function (): void {
    $t = SiapkanLanjutkan();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

    $uuid = (string) Str::ulid();
    $this->post('/kelola/persediaan/transfer', [
        'Uuid' => $uuid, 'UuidGudangAsal' => $t['Gudang']->Uuid, 'UuidGudangTujuan' => $t['GudangCabang']->Uuid, 'Tanggal' => B::Kemarin(),
        'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Jumlah' => '12']], 'Lanjutkan' => true,
    ])->assertSessionHasNoErrors()->assertRedirect("/kelola/persediaan/transfer/{$uuid}");
    expect(TransferStok::query()->where('Uuid', $uuid)->value('Status'))->toBe(StatusTransferStok::Dikirim);

    $kecil = (string) Str::ulid();
    $this->post('/kelola/persediaan/penyesuaian', [
        'Uuid' => $kecil, 'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => B::Kemarin(), 'KodeAlasan' => 'Hilang', 'Keterangan' => null,
        'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Arah' => 'Keluar', 'Jumlah' => '2']], 'Lanjutkan' => true,
    ])->assertSessionHasNoErrors()->assertRedirect("/kelola/persediaan/penyesuaian/{$kecil}");
    // Di atas batas oleh bukan-Pemilik (D-38: Pemilik sendiri langsung memposting) tetap menunggu persetujuan.
    $besar = (string) Str::ulid();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang)->post('/kelola/persediaan/penyesuaian', [
        'Uuid' => $besar, 'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => B::Kemarin(), 'KodeAlasan' => 'Hilang', 'Keterangan' => null,
        'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Arah' => 'Keluar', 'Jumlah' => '20']], 'Lanjutkan' => true,
    ])->assertSessionHasNoErrors();
    $tanpa = (string) Str::ulid();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/persediaan/penyesuaian', [
        'Uuid' => $tanpa, 'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => B::Kemarin(), 'KodeAlasan' => 'Hilang', 'Keterangan' => null,
        'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Arah' => 'Keluar', 'Jumlah' => '1']],
    ])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(PenyesuaianStok::query()->where('Uuid', $kecil)->value('Status'))->toBe(StatusPenyesuaianStok::Diposting)
        ->and(PenyesuaianStok::query()->where('Uuid', $besar)->value('Status'))->toBe(StatusPenyesuaianStok::MenungguPersetujuan)
        ->and(PenyesuaianStok::query()->where('Uuid', $tanpa)->value('Status'))->toBe(StatusPenyesuaianStok::Draf)
        ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
});

it('stok awal: Lanjutkan memposting; galat posting menyimpan draf dan membuka detail dengan pesannya; tanpa izin posting tetap draf', function (): void {
    $t = SiapkanLanjutkan();
    $baru = Produk::query()->whereKey($t['Produk']['Batch']->Id)->sole();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

    $isi = BantuanStokAwal::IsiForm($t['Gudang'], [BantuanStokAwal::IsiBaris($baru, '12', '18250', 'UHT-3001', '2027-06-30')]);
    $this->post('/kelola/persediaan/stok-awal', [...$isi, 'Lanjutkan' => true])->assertSessionHasNoErrors()
        ->assertRedirect("/kelola/persediaan/stok-awal/{$isi['Uuid']}");
    expect(StokAwal::query()->where('Uuid', $isi['Uuid'])->value('Status'))->toBe(StatusStokAwal::Diposting);

    // Produk Stok sudah punya stok awal Diposting di lokasi ini → posting ditolak, draf tetap tersimpan.
    $ganda = BantuanStokAwal::IsiForm($t['Gudang'], [BantuanStokAwal::IsiBaris($t['Produk']['Stok'], '5', '38500')]);
    $this->post('/kelola/persediaan/stok-awal', [...$ganda, 'Lanjutkan' => true])
        ->assertRedirect("/kelola/persediaan/stok-awal/{$ganda['Uuid']}")
        ->assertSessionHasErrors('Umum');
    expect(StokAwal::query()->where('Uuid', $ganda['Uuid'])->value('Status'))->toBe(StatusStokAwal::Draf);

    $staf = BantuanStokAwal::IsiForm($t['GudangCabang'], [BantuanStokAwal::IsiBaris($t['Produk']['Stok'], '3', '38500')]);
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang)
        ->post('/kelola/persediaan/stok-awal', [...$staf, 'Lanjutkan' => true])
        ->assertRedirect("/kelola/persediaan/stok-awal/{$staf['Uuid']}")
        ->assertSessionHasErrors('Umum');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(StokAwal::query()->where('Uuid', $staf['Uuid'])->value('Status'))->toBe(StatusStokAwal::Draf);
});

it('pesanan pembelian: Lanjutkan langsung mengajukan (di bawah batas = disetujui otomatis)', function (): void {
    $t = BantuanPembelian::SiapkanTenant();
    $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
    $pemasok = BantuanPembelian::BuatPemasok();
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

    $this->post('/kelola/pembelian/pesanan', [
        'UuidPemasok' => $pemasok->Uuid, 'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
        'Ongkir' => '0', 'Baris' => [['UuidProduk' => $minyak->Uuid, 'Jumlah' => '24', 'Harga' => '31500']], 'Lanjutkan' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(PesananPembelian::query()->sole()->Status)->toBe(StatusPesananPembelian::Disetujui);
});
