<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Pemenuhan\Aksi\UbahStatusPengirimanPesanan;
use App\Domain\Pemenuhan\Enum\StatusPengirimanPesanan;
use App\Domain\Pemenuhan\Model\Kurir;
use App\Domain\Pemenuhan\Model\PengirimanPesanan;
use App\Domain\Penjualan\Aksi\UbahStatusPesananOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-10 (v3.49): portal kurir tanpa akun. Staf membuat tautan rahasia per kurir; kurir melihat pengiriman yang
 * ditugaskan kepadanya saja, menandai berangkat, diterima (nama penerima + foto bukti tanpa EXIF), atau gagal. Tautan
 * yang dicabut/dibuat ulang tidak berlaku lagi; data pelanggan tidak tampil setelah pengiriman selesai.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/** @return array<string, mixed> */
function SiapkanPortalKurir(TestCase $tes): array
{
    $k = BantuanTokoOnline::Siapkan($tes);
    $kiriman = BantuanTokoOnline::Kiriman($k, 'Kirim');
    $tes->postJson("/{$k['Slug']}/pesan", $kiriman)->assertCreated();
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan = PesananOnline::query()->where('Uuid', $kiriman['Uuid'])->sole();

    foreach (['Dikonfirmasi', 'Diproses', 'Siap'] as $status) {
        $tes->post("/kelola/toko-online/pesanan/{$pesanan->Uuid}/status", ['Status' => $status])->assertSessionHasNoErrors();
    }

    $tes->post('/kelola/pengiriman/kurir', ['Nama' => 'Joko Santoso', 'NoHp' => '081377778888', 'Jenis' => 'Internal', 'Status' => 'Aktif'])->assertSessionHasNoErrors();
    $tes->post('/kelola/pengiriman/kurir', ['Nama' => 'Bayu Pratama', 'Jenis' => 'Internal', 'Status' => 'Aktif'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $joko = Kurir::query()->where('Nama', 'Joko Santoso')->sole();
    $bayu = Kurir::query()->where('Nama', 'Bayu Pratama')->sole();
    $kirim = PengirimanPesanan::query()->where('IdPesananOnline', $pesanan->Id)->sole();
    $tes->post("/kelola/pengiriman/{$kirim->Uuid}/status", ['Status' => 'Dikemas', 'Kurir' => $joko->Uuid])->assertSessionHasNoErrors();

    return $k + ['Pesanan' => $pesanan, 'Joko' => $joko, 'Bayu' => $bayu, 'Kirim' => $kirim];
}

/** @param array<string, mixed> $k */
function TautanKurir(TestCase $tes, array $k, Kurir $kurir): string
{
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);
    $tes->post("/kelola/pengiriman/kurir/{$kurir->Uuid}/tautan-portal")->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $token = (string) $kurir->refresh()->TokenPortal;
    $tes->post('/keluar');

    return "/{$k['Slug']}/kurir/{$token}";
}

it('kurir membuka tautan, berangkat, lalu menyerahkan dengan foto tanpa EXIF. Staf melihat buktinya', function (): void {
    $k = SiapkanPortalKurir($this);
    $tautan = TautanKurir($this, $k, $k['Joko']);
    $tautanBayu = TautanKurir($this, $k, $k['Bayu']);

    $this->get($tautan)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Publik/PortalKurir')
        ->where('UrlLogo', null)
        ->where('NamaKurir', 'Joko Santoso')
        ->has('Pengiriman', 1)
        ->where('Pengiriman.0.Nomor', $k['Pesanan']->Nomor)
        ->where('Pengiriman.0.Status', 'Dikemas')
        ->where('Pengiriman.0.NoHp', '6281234567890')
        ->where('Pengiriman.0.Alamat', 'Jl. Merdeka 10, Braga, Sumur Bandung, Bandung, 40123'));
    // Kurir lain tidak melihat & tidak bisa mengubah pengiriman Joko.
    $this->get($tautanBayu)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Pengiriman', 0));
    $this->from($tautanBayu)->post("{$tautanBayu}/pengiriman/{$k['Kirim']->Uuid}/kirim")->assertSessionHasErrors(['Umum' => 'Pengiriman ini tidak ditugaskan kepada Anda.']);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($k['Kirim']->refresh()->Status)->toBe(StatusPengirimanPesanan::Dikemas);

    $this->from($tautan)->post("{$tautan}/pengiriman/{$k['Kirim']->Uuid}/kirim")->assertRedirect($tautan);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($k['Kirim']->refresh()->Status)->toBe(StatusPengirimanPesanan::Dikirim)
        ->and($k['Kirim']->DiubahOleh)->toBeNull()
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'PengirimanPesanan')->latest('Id')->value('Alasan'))->toBe('Berangkat (kurir Joko Santoso).');

    // Belum ditagih kasir → belum bisa diterima (aturan sama dengan back-office); foto yang telanjur diunggah dibuang.
    $foto = UploadedFile::fake()->image('bukti.jpg', 3000, 2000);
    $this->from($tautan)->post("{$tautan}/pengiriman/{$k['Kirim']->Uuid}/terima", ['NamaPenerima' => 'Pak Darto', 'Foto' => $foto])
        ->assertSessionHasErrors('Status');
    expect(Storage::disk('local')->allFiles())->toBe([]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id), 'Jumlah' => '1', 'Harga' => '38500.00']]], ['UuidPesananOnline' => $k['Pesanan']->Uuid, 'Kanal' => 'Online']);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    $this->from($tautan)->post("{$tautan}/pengiriman/{$k['Kirim']->Uuid}/terima", ['NamaPenerima' => 'Pak Darto', 'Foto' => UploadedFile::fake()->image('bukti.jpg', 3000, 2000)])
        ->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $kirim = $k['Kirim']->refresh();
    expect($kirim->Status)->toBe(StatusPengirimanPesanan::Diterima)
        ->and($kirim->NamaPenerima)->toBe('Pak Darto')
        ->and($kirim->PathBukti)->toStartWith("pemenuhan/bukti/{$k['Tenant']->Id}/")
        ->and($k['Pesanan']->refresh()->Status)->toBe(StatusPesananOnline::Selesai);
    $ukuran = getimagesizefromstring((string) Storage::disk('local')->get((string) $kirim->PathBukti));
    expect($ukuran[0] ?? 0)->toBe(1600)->and($ukuran['mime'] ?? '')->toBe('image/jpeg');

    // Setelah selesai: masih tampil (24 jam) tetapi tanpa nomor HP & alamat pelanggan.
    $this->get($tautan)->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Pengiriman.0.Status', 'Diterima')
        ->where('Pengiriman.0.NoHp', null)
        ->where('Pengiriman.0.Alamat', null)
        ->where('Pengiriman.0.AdaBukti', true));

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->get("/kelola/pengiriman/{$kirim->Uuid}/bukti")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('membuat ulang dan mencabut tautan mematikan tautan lama. Kurir diarsipkan ditolak. Gagal wajib alasan', function (): void {
    $k = SiapkanPortalKurir($this);
    $lama = TautanKurir($this, $k, $k['Joko']);
    $baru = TautanKurir($this, $k, $k['Joko']);
    $this->get($lama)->assertNotFound();
    $this->get($baru)->assertOk();

    $this->from($baru)->post("{$baru}/pengiriman/{$k['Kirim']->Uuid}/kirim")->assertSessionHasNoErrors();
    $this->from($baru)->post("{$baru}/pengiriman/{$k['Kirim']->Uuid}/gagal", ['Alasan' => ''])->assertSessionHasErrors('Alasan');
    $this->from($baru)->post("{$baru}/pengiriman/{$k['Kirim']->Uuid}/gagal", ['Alasan' => 'Rumah kosong, pelanggan tidak bisa dihubungi'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($k['Kirim']->refresh()->Status)->toBe(StatusPengirimanPesanan::Gagal)
        ->and($k['Kirim']->Alasan)->toBe('Rumah kosong, pelanggan tidak bisa dihubungi (kurir Joko Santoso)');

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->get('/kelola/pengiriman')->assertInertia(fn (AssertableInertia $h) => $h->where('Kurir', fn ($daftar) => collect($daftar)->firstWhere('Nama', 'Joko Santoso')['TautanPortal'] === url($baru)));
    $this->delete("/kelola/pengiriman/kurir/{$k['Joko']->Uuid}/tautan-portal")->assertSessionHasNoErrors();
    $this->get($baru)->assertNotFound();

    $this->put("/kelola/pengiriman/kurir/{$k['Bayu']->Uuid}", ['Nama' => 'Bayu Pratama', 'Jenis' => 'Internal', 'Status' => 'Diarsipkan'])->assertSessionHasNoErrors();
    $this->post("/kelola/pengiriman/kurir/{$k['Bayu']->Uuid}/tautan-portal")->assertSessionHasErrors('Kurir');
    $this->get('/kopi-tidak-ada/kurir/'.str_repeat('a', 40))->assertNotFound();
});

it('kurir yang menekan Dikirim dari data basi tidak menimpa pesanan yang baru dibatalkan staf', function (): void {
    $k = SiapkanPortalKurir($this);
    $pesananBasi = PesananOnline::query()->whereKey($k['Pesanan']->Id)->sole();
    $kirimBasi = PengirimanPesanan::query()->whereKey($k['Kirim']->Id)->sole();
    expect($pesananBasi->Status)->toBe(StatusPesananOnline::Siap)
        ->and($kirimBasi->Status)->toBe(StatusPengirimanPesanan::Dikemas);

    // Staf membatalkan pesanan (pengiriman ikut dibatalkan) sementara layar kurir masih memuat data lama.
    app(UbahStatusPesananOnline::class)->Jalankan(PesananOnline::query()->whereKey($k['Pesanan']->Id)->sole(), StatusPesananOnline::Dibatalkan, $k['Pemilik']->Id, 'Pembeli minta batal');

    expect(fn () => app(UbahStatusPengirimanPesanan::class)->Jalankan($kirimBasi, $pesananBasi, StatusPengirimanPesanan::Dikirim, ['IdKurir' => $k['Joko']->Id], null))
        ->toThrow(LogicException::class)
        ->and(PengirimanPesanan::query()->whereKey($k['Kirim']->Id)->sole()->Status)->toBe(StatusPengirimanPesanan::Dibatalkan)
        ->and(PesananOnline::query()->whereKey($k['Pesanan']->Id)->sole()->Status)->toBe(StatusPesananOnline::Dibatalkan);
});
