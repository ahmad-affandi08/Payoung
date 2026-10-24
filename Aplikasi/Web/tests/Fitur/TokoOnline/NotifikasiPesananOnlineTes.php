<?php

declare(strict_types=1);

use App\Domain\Pemenuhan\Model\PengirimanPesanan;
use App\Domain\Penjualan\Enum\PeristiwaPesananOnline;
use App\Domain\Penjualan\Layanan\PemberitahuPesananOnline;
use App\Domain\Penjualan\Model\NotifikasiPesananOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-17 toko online bagian 3 (v3.32): pembeli dikabari lewat WhatsApp saat status pesanannya berubah — dikonfirmasi,
 * siap diambil, dikirim, ditolak, dibatalkan, kedaluwarsa — satu pesan per peristiwa, bisa dimatikan toko.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
});

/**
 * @param  array<string, mixed>  $k
 */
function BuatPesananUntukNotifikasi(array $k, string $pemenuhan = 'AmbilSendiri'): PesananOnline
{
    $kiriman = BantuanTokoOnline::Kiriman($k, $pemenuhan);
    test()->postJson('/'.$k['Slug'].'/pesan', $kiriman)->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return PesananOnline::query()->where('Uuid', $kiriman['Uuid'])->sole();
}

/** @return list<string> isi pesan WhatsApp yang terkirim, urut. */
function AmbilPesanTerkirim(): array
{
    return Http::recorded()->map(fn (array $r): string => (string) $r[0]['message'])->values()->all();
}

it('ambil sendiri: pembeli dikabari saat dikonfirmasi dan saat siap diambil, dengan tautan status; tidak untuk Diproses', function (): void {
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9101']])]);
    $k = BantuanTokoOnline::Siapkan($this);
    $p = BuatPesananUntukNotifikasi($k);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    foreach (['Dikonfirmasi', 'Diproses', 'Siap'] as $status) {
        $this->post("/kelola/toko-online/pesanan/{$p->Uuid}/status", ['Status' => $status])->assertSessionHasNoErrors();
    }

    $pesan = AmbilPesanTerkirim();
    expect($pesan)->toHaveCount(2)
        ->and($pesan[0])->toContain("Halo Bu, pesanan {$p->Nomor}")->toContain('sudah dikonfirmasi')->toContain("/{$k['Slug']}/pesanan/{$p->KodeAkses}")
        ->and($pesan[1])->toContain('sudah siap diambil');
    Http::assertSent(fn (PermintaanHttp $r): bool => $r['target'] === '6281234567890');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(NotifikasiPesananOnline::query()->whereNotNull('TerkirimPada')->pluck('Peristiwa')->map(fn ($x) => $x->value)->all())
        ->toEqualCanonicalizing(['Dikonfirmasi', 'SiapDiambil']);
});

it('kirim: Siap tidak dikabarkan, kurir berangkat dikabarkan "dalam perjalanan"', function (): void {
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9102']])]);
    $k = BantuanTokoOnline::Siapkan($this);
    $p = BuatPesananUntukNotifikasi($k, 'Kirim');
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    foreach (['Dikonfirmasi', 'Diproses', 'Siap'] as $status) {
        $this->post("/kelola/toko-online/pesanan/{$p->Uuid}/status", ['Status' => $status])->assertSessionHasNoErrors();
    }
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $kirim = PengirimanPesanan::query()->where('IdPesananOnline', $p->Id)->sole();
    foreach (['Dikemas', 'Dikirim'] as $status) {
        $this->post("/kelola/pengiriman/{$kirim->Uuid}/status", ['Status' => $status, 'NamaPenyedia' => 'JNE', 'NomorResi' => 'JNE0012345678'])->assertSessionHasNoErrors();
    }

    $pesan = AmbilPesanTerkirim();
    expect($pesan)->toHaveCount(2)->and($pesan[1])->toContain('sedang dalam perjalanan ke alamat Anda');
});

it('ditolak dan kedaluwarsa dikabarkan; satu pesan per peristiwa walau dipicu ulang', function (): void {
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9103']])]);
    $k = BantuanTokoOnline::Siapkan($this);
    $ditolak = BuatPesananUntukNotifikasi($k);
    $hangus = BuatPesananUntukNotifikasi($k);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->post("/kelola/toko-online/pesanan/{$ditolak->Uuid}/status", ['Status' => 'Ditolak', 'Alasan' => 'Stok habis'])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PesananOnline::query()->whereKey($hangus->Id)->update(['DibuatPada' => now()->subHours(3)]);
    $this->artisan('pesanan-online:kedaluwarsa')->assertSuccessful();
    $this->artisan('pesanan-online:kedaluwarsa')->assertSuccessful();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    app(PemberitahuPesananOnline::class)->Antrekan($hangus->refresh(), PeristiwaPesananOnline::Kedaluwarsa);

    $pesan = AmbilPesanTerkirim();
    expect($pesan)->toHaveCount(2)
        ->and($pesan[0])->toContain('tidak dapat diproses oleh toko')
        ->and($pesan[1])->toContain('dibatalkan otomatis');
});

it('toko mematikan notifikasi: tidak ada pesan; WhatsApp gagal: tercatat dengan masalah, status tetap berubah', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanTokoOnline::query()->sole()->forceFill(['NotifikasiWhatsappAktif' => false])->save();
    $p = BuatPesananUntukNotifikasi($k);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'quota'])]);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->post("/kelola/toko-online/pesanan/{$p->Uuid}/status", ['Status' => 'Dikonfirmasi'])->assertSessionHasNoErrors();
    Http::assertNothingSent();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanTokoOnline::query()->sole()->forceFill(['NotifikasiWhatsappAktif' => true])->save();
    $this->post("/kelola/toko-online/pesanan/{$p->Uuid}/status", ['Status' => 'Dibatalkan', 'Alasan' => 'Pembeli minta batal'])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $n = NotifikasiPesananOnline::query()->sole();
    expect($n->Peristiwa)->toBe(PeristiwaPesananOnline::Dibatalkan)
        ->and($n->TerkirimPada)->toBeNull()
        ->and($n->Percobaan)->toBe(1)
        ->and($n->Galat)->not->toBeNull()
        ->and($p->refresh()->Status->value)->toBe('Dibatalkan');
});
