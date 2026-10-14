<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Tenant\Model\Tenant;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * OWN-03: kabar void, retur, dan diskon besar (di atas BatasNotifikasiTransaksi, bawaan Rp 500.000) serta pesanan
 * online masuk ke pengguna berizin; bisa dibisukan; satu kabar per kejadian.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** @return array<string, mixed> */
function SiapkanTokoTransaksiBesar(object $tes, ?string $batas = null): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Toko Elektronik Maju Jaya Solo');
    $k['Tv'] = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Televisi LED 32 Inci Full HD', '10', '1200000', '1500000.00');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    if ($batas !== null) {
        $tenant = Tenant::query()->whereKey($k['Tenant']->Id)->firstOrFail();
        $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'BatasNotifikasiTransaksi' => $batas];
        $tenant->save();
    }

    return $k;
}

/** @return list<NotifikasiPengguna> */
function KabarTransaksiBesar(): array
{
    return NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::TransaksiBesar->value)->orderBy('Id')->get()->all();
}

it('void bernilai di atas batas dikabarkan ke pemilik sekali; di bawah batas tidak', function (): void {
    $k = SiapkanTokoTransaksiBesar($this);
    $besar = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Tv'], 'Jumlah' => '1', 'Harga' => '1500000.00']]]);
    $kecil = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Tv'], 'Jumlah' => '1', 'Harga' => '300000.00']]]);
    // Diskon manual tidak ada: penjualan biasa tidak memicu kabar.
    expect(KabarTransaksiBesar())->toBe([]);

    expect(BantuanKasirKirimVoid($this, $k, $kecil))->toBe([['Diterima', null]]);
    expect(KabarTransaksiBesar())->toBe([]);

    expect(BantuanKasirKirimVoid($this, $k, $besar))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $kabar = KabarTransaksiBesar();
    expect($kabar)->toHaveCount(1)
        ->and($kabar[0]->IdPengguna)->toBe($k['Pemilik']->Id)
        ->and($kabar[0]->Kunci)->toBe('TransaksiBesar:Void:'.$besar->Uuid)
        ->and($kabar[0]->Judul)->toContain('di-void')->toContain('1.5')
        ->and($kabar[0]->Data['Href'])->toBe("/kelola/penjualan/{$besar->Uuid}");
});

it('batas 0 mengabarkan setiap void; dibisukan pengguna = tidak dibuat', function (): void {
    $k = SiapkanTokoTransaksiBesar($this, '0.00');
    $satu = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Tv'], 'Jumlah' => '1', 'Harga' => '300000.00']]]);
    $dua = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Tv'], 'Jumlah' => '1', 'Harga' => '310000.00']]]);

    BantuanKasirKirimVoid($this, $k, $satu);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(KabarTransaksiBesar())->toHaveCount(1);

    NotifikasiDibisukan::query()->create(['IdPengguna' => $k['Pemilik']->Id, 'Jenis' => JenisNotifikasiPengguna::TransaksiBesar->value]);
    BantuanKasirKirimVoid($this, $k, $dua);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(KabarTransaksiBesar())->toHaveCount(1);
});

it('retur dan penjualan berdiskon besar dikabarkan', function (): void {
    $k = SiapkanTokoTransaksiBesar($this);
    $dasar = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Tv'], 'Jumlah' => '1', 'Harga' => '1500000.00']]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $detail = PenjualanDetail::query()->where('IdPenjualan', $dasar->Id)->sole();
    $retur = BantuanPenjualan::ItemRetur($k, $dasar, [['Detail' => $detail, 'Jumlah' => '1']]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    $berdiskon = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Tv'], 'Jumlah' => '1', 'Harga' => '1500000.00']], 'DiskonManualPesanan' => ['Persen' => '40']]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    $kunci = array_map(fn (NotifikasiPengguna $n): string => $n->Kunci, KabarTransaksiBesar());
    expect($kunci)->toHaveCount(2)
        ->and(collect($kunci)->contains(fn (string $x): bool => str_starts_with($x, 'TransaksiBesar:Retur:')))->toBeTrue()
        ->and($kunci)->toContain('TransaksiBesar:Diskon:'.$berdiskon->Uuid);
});

it('pesanan online bayar-saat-ambil dikabarkan ke pemilik saat dibuat, sekali', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $kiriman = BantuanTokoOnline::Kiriman($k, 'AmbilSendiri');
    $this->postJson('/'.$k['Slug'].'/pesan', $kiriman)->assertCreated();
    $this->postJson('/'.$k['Slug'].'/pesan', $kiriman)->assertSuccessful();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    $kabar = NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::PesananOnline->value)->get();
    // Satu kabar per penerima berizin (pemilik, dan anggota lain yang memegang izin toko online), tidak ganda per kirim ulang.
    expect($kabar->pluck('IdPengguna')->all())->toContain($k['Pemilik']->Id)
        ->and($kabar->pluck('IdPengguna')->unique()->count())->toBe($kabar->count())
        ->and($kabar->pluck('Kunci')->unique()->count())->toBe(1)
        ->and($kabar[0]->Judul)->toContain('Pesanan online baru')
        ->and($kabar[0]->Data['Href'])->toBe('/kelola/toko-online');
});

/** @param array<string, mixed> $k */
function BantuanKasirKirimVoid(object $tes, array $k, object $penjualan): array
{
    return BantuanKasir::KirimRingkas($tes, $k['Token'], [BantuanPenjualan::ItemVoid($k, $penjualan)]);
}
