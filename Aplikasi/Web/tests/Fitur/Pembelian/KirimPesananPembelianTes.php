<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Aksi\BatalkanPesananPembelian;
use App\Domain\Pembelian\Enum\StatusPengirimanPesananPembelian;
use App\Domain\Pembelian\Layanan\PengirimPesananPembelian;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PengirimanPesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-04 (F-e): kirim pesanan pembelian yang sudah disetujui ke pemasok lewat WhatsApp (D-33: tanpa email). Syarat: PO disetujui,
 * pemasok bernomor HP sah, WhatsApp usaha aktif; jeda 10 menit per PO; nomor terenkripsi dan tidak masuk audit/galat; PO yang
 * dibatalkan sebelum terkirim = Dibatalkan; izin `pembelian.kelola`; isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8101']])]);
});

/** @return Collection<int, PermintaanHttp> */
function PesanPoTerkirim(): Collection
{
    return collect(Http::recorded())->map(fn (array $pasangan): PermintaanHttp => $pasangan[0])
        ->filter(fn (PermintaanHttp $r): bool => str_contains($r->url(), 'api.fonnte.com/send'))->values();
}

/**
 * Toko grosir dengan PO disetujui (dua barang) ke pemasok bernomor HP 0812-3456-7890; fitur WhatsApp usaha aktif; pemilik masuk.
 *
 * @return array<string, mixed>
 */
function SiapkanPoKirim(TestCase $tes, string $nama = 'Toko Grosir Sembako Makmur Jaya'): array
{
    $t = BantuanPembelian::SiapkanTenant(namaUsaha: $nama);
    $pemasok = BantuanPembelian::BuatPemasok('PT Sumber Pangan Nusantara');
    $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Delanggu Premium Pulen 1 kg']);
    $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Kemasan Pouch 2 L']);
    $po = BantuanPembelian::BuatPoDisetujui($pemasok, $t['Gudang'], [[$beras, '24', '13500'], [$minyak, '3', '34500']], $t['Pemilik']->Id);
    OverrideTenant::query()->create([
        'IdTenant' => $t['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(90),
        'Alasan' => 'Uji kirim PO lewat WhatsApp',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    BantuanOrganisasi::Masuk($tes, $t['Pemilik'], $t['Tenant']->Id);

    return $t + ['Pemasok' => $pemasok, 'Po' => $po];
}

it('mengirim PO yang disetujui ke WhatsApp pemasok: isi pesan lengkap, status Terkirim, audit tanpa nomor, riwayat di detail', function (): void {
    $t = SiapkanPoKirim($this);
    $po = $t['Po'];

    $this->post("/kelola/pembelian/pesanan/{$po->Uuid}/kirim-whatsapp")->assertRedirect("/kelola/pembelian/pesanan/{$po->Uuid}");
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    $pengiriman = PengirimanPesananPembelian::query()->sole();
    expect($pengiriman->Status)->toBe(StatusPengirimanPesananPembelian::Terkirim)
        ->and($pengiriman->Tujuan)->toBe('6281234567890')
        ->and($pengiriman->Penyedia)->toBe('Fonnte')
        ->and($pengiriman->IdPesanPenyedia)->toBe('8101')
        ->and($pengiriman->TerkirimPada)->not->toBeNull()
        ->and($pengiriman->DikirimOleh)->toBe($t['Pemilik']->Id);

    $permintaan = PesanPoTerkirim()->sole();
    $teks = (string) $permintaan->data()['message'];
    expect($permintaan->data()['target'])->toBe('6281234567890')
        ->and($teks)->toContain('Halo Bu Ratna Kusumawati')
        ->and($teks)->toContain($po->Nomor)
        ->and($teks)->toContain('- Beras Delanggu Premium Pulen 1 kg x 24')
        ->and($teks)->toContain('- Minyak Goreng Sawit Kemasan Pouch 2 L x 3')
        ->and($teks)->toContain('Total: Rp')
        ->and($teks)->toContain('Mohon konfirmasi ketersediaan');

    $audit = LogAudit::query()->where('Peristiwa', 'pesanan-pembelian.kirim-whatsapp')->sole();
    expect(json_encode($audit->NilaiBaru))->not->toContain('6281234567890')->not->toContain('0812');

    $this->get("/kelola/pembelian/pesanan/{$po->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Tindakan.KirimWhatsapp', true)
        ->where('Pengiriman.0.Status', 'Terkirim')
        ->where('Pengiriman.0.LabelStatus', 'Terkirim'));
});

it('ditolak: PO belum disetujui, pemasok tanpa nomor sah, WhatsApp usaha tidak aktif, atau dikirim lagi dalam 10 menit', function (): void {
    $t = SiapkanPoKirim($this);
    $po = $t['Po'];
    $draf = BantuanPembelian::BuatPo($t['Pemasok'], $t['Gudang'], [[BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir 1 kg']), '10', '15000']], $t['Pemilik']->Id);

    $this->post("/kelola/pembelian/pesanan/{$draf->Uuid}/kirim-whatsapp")->assertSessionHasErrors('Umum');
    expect(PengirimanPesananPembelian::query()->count())->toBe(0);

    $this->post("/kelola/pembelian/pesanan/{$po->Uuid}/kirim-whatsapp")->assertSessionHasNoErrors();
    $this->post("/kelola/pembelian/pesanan/{$po->Uuid}/kirim-whatsapp")->assertSessionHasErrors('Umum');
    expect(PesanPoTerkirim())->toHaveCount(1);
    $this->travel(11)->minutes();
    $this->post("/kelola/pembelian/pesanan/{$po->Uuid}/kirim-whatsapp")->assertSessionHasNoErrors();
    expect(PesanPoTerkirim())->toHaveCount(2);

    Pemasok::query()->whereKey($t['Pemasok']->Id)->update(['NoHp' => '021-555']);
    $this->travel(11)->minutes();
    $this->post("/kelola/pembelian/pesanan/{$po->Uuid}/kirim-whatsapp")->assertSessionHasErrors('Umum');

    Pemasok::query()->whereKey($t['Pemasok']->Id)->update(['NoHp' => '081234567890']);
    OverrideTenant::query()->delete();
    config(['integrasi.Whatsapp' => ['Penyedia' => '', 'Pengaturan' => [], 'Kredensial' => []]]);
    $this->post("/kelola/pembelian/pesanan/{$po->Uuid}/kirim-whatsapp")->assertSessionHasErrors('Umum');
    expect(PesanPoTerkirim())->toHaveCount(2);
});

it('PO yang dibatalkan sebelum terkirim menjadi Dibatalkan dan tidak ada pesan keluar; kegagalan penyedia dicatat tanpa nomor', function (): void {
    $t = SiapkanPoKirim($this);
    $po = $t['Po'];
    $dibatalkan = PengirimanPesananPembelian::query()->create(['IdPesananPembelian' => $po->Id, 'Tujuan' => '6281234567890', 'Status' => StatusPengirimanPesananPembelian::Diantrekan]);
    app(BatalkanPesananPembelian::class)->Jalankan(PesananPembelian::query()->findOrFail($po->Id), $t['Pemilik']->Id, alasan: 'Salah pesan');

    expect(app(PengirimPesananPembelian::class)->Kirim($dibatalkan->Id, false))->toBeFalse();
    expect($dibatalkan->fresh()?->Status)->toBe(StatusPengirimanPesananPembelian::Dibatalkan)
        ->and(PesanPoTerkirim())->toHaveCount(0);

    // Penyedia menolak: percobaan pertama minta coba ulang, percobaan terakhir menutup sebagai Gagal; nomor disamarkan.
    $po2 = BantuanPembelian::BuatPoDisetujui($t['Pemasok'], $t['Gudang'], [[BantuanKatalog::BuatProduk(['Nama' => 'Tepung Terigu 1 kg']), '5', '12000']], $t['Pemilik']->Id);
    Http::swap(new Factory);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'target 6281234567890 tidak valid'])]);
    $gagal = PengirimanPesananPembelian::query()->create(['IdPesananPembelian' => $po2->Id, 'Tujuan' => '6281234567890', 'Status' => StatusPengirimanPesananPembelian::Diantrekan]);

    expect(app(PengirimPesananPembelian::class)->Kirim($gagal->Id, false))->toBeTrue()
        ->and($gagal->fresh()?->Status)->toBe(StatusPengirimanPesananPembelian::Diantrekan);
    expect(app(PengirimPesananPembelian::class)->Kirim($gagal->Id, true))->toBeFalse();
    $akhir = $gagal->fresh();
    expect($akhir?->Status)->toBe(StatusPengirimanPesananPembelian::Gagal)
        ->and($akhir?->PesanGalat)->not->toContain('6281234567890')
        ->and($akhir?->Percobaan)->toBe(2);
});

it('izin pembelian.kelola dan isolasi tenant: kasir ditolak, PO tenant lain 404', function (): void {
    $a = SiapkanPoKirim($this, 'Toko Grosir Sembako Makmur Jaya');
    $poA = $a['Po'];
    $b = BantuanPembelian::SiapkanTenant(namaUsaha: 'Warung Bakso Pak Kumis');
    BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id);
    $this->post("/kelola/pembelian/pesanan/{$poA->Uuid}/kirim-whatsapp")->assertNotFound();

    BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
    $this->post("/kelola/pembelian/pesanan/{$poA->Uuid}/kirim-whatsapp")->assertForbidden();
    expect(PesanPoTerkirim())->toHaveCount(0);
});

it('teks pesan memotong daftar barang panjang dan merapikan jumlah', function (): void {
    expect(PengirimPesananPembelian::FormatJumlah('12.0000'))->toBe('12')
        ->and(PengirimPesananPembelian::FormatJumlah('1.5000'))->toBe('1,5')
        ->and(PengirimPesananPembelian::FormatJumlah('0.2500'))->toBe('0,25')
        ->and(PengirimPesananPembelian::FormatJumlah('100'))->toBe('100');
});
