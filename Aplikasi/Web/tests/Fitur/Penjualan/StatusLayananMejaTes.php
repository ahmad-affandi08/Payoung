<?php

declare(strict_types=1);

use App\Domain\Penjualan\Model\PesananTerbuka;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-12 (§9.1): status layanan meja. "Minta bill" lewat `PesananTerbuka.Ubah {MintaBill}` (waktu pertama dipertahankan,
 * false menghapus) dan "perlu dibersihkan" otomatis saat pesanan meja dibayar, dihapus oleh `Meja.Bersih` yang tidak
 * lebih tua dari pembayaran. Keduanya ada di snapshot `GET /api/pos/v1/pesanan-terbuka` (ikut ETag).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemMejaBersih(array $k, string $uuidMeja, CarbonImmutable $waktu): array
{
    return ['Jenis' => 'Meja.Bersih', 'Uuid' => BantuanKasir::Uuid(), 'Data' => [
        'UuidMeja' => $uuidMeja,
        'UuidPengguna' => $k['Kasir']->Uuid,
        'DibersihkanPada' => $waktu->utc()->toIso8601ZuluString(),
    ]];
}

describe('K-12 status layanan meja', function (): void {
    it('minta bill: ditandai, waktu pertama dipertahankan, dihapus dengan false, tampil di snapshot', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        BantuanPesananTerbuka::Kirim($this, $k, [$buka]);
        $uuid = $buka['Uuid'];
        $pertama = CarbonImmutable::now()->subMinutes(6)->startOfSecond();

        expect(BantuanPesananTerbuka::Kirim($this, $k, [
            BantuanPesananTerbuka::Item($k, 'Ubah', $uuid, ['MintaBill' => true], 'DiubahPada', waktu: $pertama),
            BantuanPesananTerbuka::Item($k, 'Ubah', $uuid, ['MintaBill' => true], 'DiubahPada', waktu: $pertama->addMinute()),
        ]))->toBe([['Diterima', null], ['Diterima', null]]);
        expect(PesananTerbuka::query()->where('Uuid', $uuid)->sole()->MintaBillPada?->equalTo($pertama))->toBeTrue();

        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()
            ->assertJsonPath('Pesanan.0.MintaBillPada', $pertama->utc()->toIso8601ZuluString());

        // Ubah lain tanpa MintaBill tidak menyentuh tanda; false menghapusnya.
        BantuanPesananTerbuka::Kirim($this, $k, [BantuanPesananTerbuka::Item($k, 'Ubah', $uuid, ['JumlahTamu' => 3], 'DiubahPada', waktu: $pertama->addMinutes(2))]);
        expect(PesananTerbuka::query()->where('Uuid', $uuid)->sole()->MintaBillPada)->not->toBeNull();
        BantuanPesananTerbuka::Kirim($this, $k, [BantuanPesananTerbuka::Item($k, 'Ubah', $uuid, ['MintaBill' => false], 'DiubahPada', waktu: $pertama->addMinutes(3))]);
        expect(PesananTerbuka::query()->where('Uuid', $uuid)->sole()->MintaBillPada)->toBeNull();

        expect(BantuanPesananTerbuka::Kirim($this, $k, [BantuanPesananTerbuka::Item($k, 'Ubah', $uuid, ['MintaBill' => 'ya'], 'DiubahPada')]))
            ->toBe([['Ditolak', 'DataTidakValid']]);
    });

    it('perlu dibersihkan setelah pesanan meja dibayar. Tanda bersih lama diabaikan, yang baru menghapus. Isolasi outlet', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        BantuanPesananTerbuka::Kirim($this, $k, [$buka, BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00']])]);
        $dibayar = CarbonImmutable::now()->subMinutes(4)->startOfSecond();

        $bayar = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']], 'DibuatPada' => $dibayar], ['UuidPesananTerbuka' => $buka['Uuid']]);
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$bayar]))->toBe([['Diterima', null]])
            ->and($k['Meja']->refresh()->PerluDibersihkanSejak?->equalTo($dibayar))->toBeTrue();

        $etag = (string) $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()
            ->assertJsonPath('MejaPerluDibersihkan', [['UuidMeja' => $k['Meja']->Uuid, 'Sejak' => $dibayar->utc()->toIso8601ZuluString()]])
            ->headers->get('ETag');

        // Perangkat lain menandai bersih sebelum pembayaran tercatat: diterima tanpa efek.
        expect(BantuanPesananTerbuka::Kirim($this, $k, [ItemMejaBersih($k, $k['Meja']->Uuid, $dibayar->subMinute())]))->toBe([['Diterima', null]])
            ->and($k['Meja']->refresh()->PerluDibersihkanSejak)->not->toBeNull();

        // Meja tak dikenal tetap diterima (outbox tidak macet), pengguna tak dikenal ditolak.
        expect(BantuanPesananTerbuka::Kirim($this, $k, [ItemMejaBersih($k, BantuanKasir::Uuid(), $dibayar->addMinute())]))->toBe([['Diterima', null]]);
        $asing = ItemMejaBersih($k, $k['Meja']->Uuid, $dibayar->addMinute());
        $asing['Data']['UuidPengguna'] = BantuanKasir::Uuid();
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$asing]))->toBe([['Ditolak', 'KasirTidakDitemukan']]);

        $bersih = ItemMejaBersih($k, $k['Meja']->Uuid, $dibayar->addMinute());
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$bersih]))->toBe([['Diterima', null]])
            ->and($k['Meja']->refresh()->PerluDibersihkanSejak)->toBeNull();
        // Kirim ulang aman: idempoten secara alami (seperti `PesananTerbuka.Ubah`), tidak ada yang berubah.
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$bersih]))->toBe([['Diterima', null]])
            ->and($k['Meja']->refresh()->PerluDibersihkanSejak)->toBeNull();
        $this->withToken($k['Token'])->withHeader('If-None-Match', $etag)->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()
            ->assertJsonPath('MejaPerluDibersihkan', []);

        // Tenant lain tidak bisa membersihkan meja ini.
        $k['Meja']->update(['PerluDibersihkanSejak' => $dibayar]);
        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        expect(BantuanPesananTerbuka::Kirim($this, $b, [ItemMejaBersih($b, $k['Meja']->Uuid, $dibayar->addMinutes(2))]))->toBe([['Diterima', null]])
            ->and($k['Meja']->refresh()->PerluDibersihkanSejak)->not->toBeNull();
    });
});

describe('K-17 jawaban sinkron', function (): void {
    it('PerluTinjauan memuat Uuid penjualan di batch yang diterima dengan tanda tinjauan; WaktuServer ada', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $biasa = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]]);
        $tinjau = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]], ['UuidPesananTerbuka' => BantuanKasir::Uuid()]);

        $jawaban = BantuanKasir::Kirim($this, $k['Token'], [$biasa, $tinjau])->assertOk();
        expect($jawaban->json('PerluTinjauan'))->toBe([$tinjau['Uuid']])
            ->and($jawaban->json('WaktuServer'))->toBeString();

        // Kirim ulang (Duplikat) tetap melaporkan tandanya.
        expect(BantuanKasir::Kirim($this, $k['Token'], [$tinjau])->assertOk()->json('PerluTinjauan'))->toBe([$tinjau['Uuid']]);
    });
});
