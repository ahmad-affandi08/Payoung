<?php

declare(strict_types=1);

use App\Domain\Penjualan\Enum\StatusPesananTerbuka;
use App\Domain\Penjualan\Model\PesananTerbuka;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Penjualan\BantuanPesanSendiri;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * D-338: status pesanan tamu dari QR meja ikut pembayaran. `Pembayaran` dibaca dari pesanan terbuka yang ditautkan saat
 * kasir menerima pesanan, jadi halaman tamu berhenti berkata "bayar di kasir" setelah tagihan meja lunas.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // Item outbox kasir memakai waktu relatif ke sekarang. Dibekukan di pagi UTC supaya tidak bergantung jam jalan tes.
    $this->travelTo(CarbonImmutable::now('UTC')->setTime(5, 0));
});

/**
 * Tamu memesan, kasir menerima dengan pesanan terbuka `$uuidTerbuka`. Bila `$sinkronkan`, pesanan terbuka itu sudah sampai
 * ke server (outbox kasir terkirim), selain itu kasir masih luring.
 *
 * @param  array<string, mixed>  $k
 */
function TerimaPesananMeja(TestCase $tes, array $k, string $uuidTerbuka, bool $sinkronkan = true): string
{
    $kiriman = BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]);
    $tes->postJson("{$k['Alamat']}/pesan", $kiriman)->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    if ($sinkronkan) {
        BantuanPesananTerbuka::Kirim($tes, $k, [BantuanPesananTerbuka::ItemBuka($k, $k['Meja'], $uuidTerbuka)]);
    }

    $tes->withToken($k['Token'])->postJson("/api/pos/v1/pesan-sendiri/{$kiriman['Uuid']}/terima", ['UuidPengguna' => $k['Kasir']->Uuid, 'UuidPesananTerbuka' => $uuidTerbuka])->assertOk();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return $kiriman['Uuid'];
}

describe('D-338 status pembayaran pesanan meja', function (): void {
    it('belum diterima kasir: Pembayaran null. Diterima: BelumDibayar sampai tagihan meja dilunasi, lalu Dibayar', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kiriman = BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]);
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertCreated();
        $this->getJson("{$k['Alamat']}/pesanan/{$kiriman['Uuid']}")->assertJsonPath('Status', 'MenungguKonfirmasi')->assertJsonPath('Pembayaran', null);

        $uuidTerbuka = (string) Str::ulid();
        $uuid = TerimaPesananMeja($this, $k, $uuidTerbuka);
        $this->getJson("{$k['Alamat']}/pesanan/{$uuid}")->assertJsonPath('Status', 'Diterima')->assertJsonPath('Pembayaran', 'BelumDibayar');

        // Permintaan publik melepas konteks tenant, jadi dipasang lagi sebelum mengubah data.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        PesananTerbuka::query()->where('Uuid', $uuidTerbuka)->sole()->forceFill(['Status' => StatusPesananTerbuka::Dibayar])->save();
        $this->getJson("{$k['Alamat']}/pesanan/{$uuid}")->assertJsonPath('Status', 'Diterima')->assertJsonPath('Pembayaran', 'Dibayar');
    });

    it('tagihan meja dibatalkan di kasir: Dibatalkan. Digabung ke tagihan lain: tetap BelumDibayar', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $uuidTerbuka = (string) Str::ulid();
        $uuid = TerimaPesananMeja($this, $k, $uuidTerbuka);
        $tagihan = PesananTerbuka::query()->where('Uuid', $uuidTerbuka)->sole();

        $tagihan->forceFill(['Status' => StatusPesananTerbuka::Dibatalkan])->save();
        $this->getJson("{$k['Alamat']}/pesanan/{$uuid}")->assertJsonPath('Pembayaran', 'Dibatalkan');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tagihan->forceFill(['Status' => StatusPesananTerbuka::Digabung])->save();
        $this->getJson("{$k['Alamat']}/pesanan/{$uuid}")->assertJsonPath('Pembayaran', 'BelumDibayar');
    });

    it('kasir luring: pesanan terbuka belum sampai ke server, tetap BelumDibayar tanpa galat', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $uuid = TerimaPesananMeja($this, $k, (string) Str::ulid(), sinkronkan: false);

        $this->getJson("{$k['Alamat']}/pesanan/{$uuid}")->assertOk()->assertJsonPath('Status', 'Diterima')->assertJsonPath('Pembayaran', 'BelumDibayar');
    });

    it('pesanan ditolak: Pembayaran null. Dan hanya milik meja itu yang bisa dibuka (404 untuk meja lain)', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $kiriman = BantuanPesanSendiri::Kiriman([[$k['Nasi'], 1]]);
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $this->withToken($k['Token'])->postJson("/api/pos/v1/pesan-sendiri/{$kiriman['Uuid']}/tolak", ['UuidPengguna' => $k['Kasir']->Uuid, 'Alasan' => 'Menu habis'])->assertOk();

        $this->getJson("{$k['Alamat']}/pesanan/{$kiriman['Uuid']}")->assertJsonPath('Status', 'Ditolak')->assertJsonPath('Pembayaran', null);
        $this->getJson("{$k['Alamat9']}/pesanan/{$kiriman['Uuid']}")->assertNotFound();
    });
});
