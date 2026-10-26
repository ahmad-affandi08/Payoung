<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Referensi\Enum\JenisReferensiBank;
use App\Domain\Referensi\Model\ReferensiBank;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ReferensiBank::query()->create(['Kode' => 'BCA', 'Nama' => 'Bank Central Asia', 'Jenis' => JenisReferensiBank::Bank]);
    ReferensiBank::query()->create(['Kode' => 'BRI', 'Nama' => 'Bank Rakyat Indonesia', 'Jenis' => JenisReferensiBank::Bank, 'Aktif' => false]);
    ReferensiBank::query()->create(['Kode' => 'GOPAY', 'Nama' => 'GoPay', 'Jenis' => JenisReferensiBank::Ewallet]);
});

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function IsianMetodePembayaranUji(string $jenis, array $ubah = []): array
{
    return [
        'Jenis' => $jenis,
        'Nama' => match ($jenis) {
            'QrisStatis' => 'QRIS Toko',
            'Edc' => 'EDC BCA',
            default => 'Transfer BCA',
        },
        'KodeBank' => $jenis === 'QrisStatis' ? null : 'BCA',
        'GambarQris' => $jenis === 'QrisStatis' ? UploadedFile::fake()->image('qris.png', 400, 400) : null,
        'NomorRekening' => $jenis === 'Transfer' ? '8730123456' : null,
        'NamaPemilikRekening' => $jenis === 'Transfer' ? 'CV Kopi Nusantara Sejahtera' : null,
        'PersenBiaya' => $jenis === 'Edc' ? '0.7' : null,
        ...$ubah,
    ];
}

describe('F-01 langkah 5: metode pembayaran', function (): void {
    it('Tunai selalu ada sejak pendaftaran. Perintah panduan-awal:siapkan-bawaan idempoten', function (): void {
        ['Tenant' => $tenant] = BantuanPanduanAwal::BuatTenant();
        expect(MetodePembayaran::query()->sole()->Jenis)->toBe(JenisMetodePembayaran::Tunai);

        // Tenant lama (sebelum F-01) belum punya Tunai.
        MetodePembayaran::query()->delete();
        $this->artisan('panduan-awal:siapkan-bawaan')->assertSuccessful();
        $this->artisan('panduan-awal:siapkan-bawaan')->assertSuccessful();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(MetodePembayaran::query()->where('Jenis', 'Tunai')->count())->toBe(1);
    });

    it('Tunai tidak bisa dinonaktifkan (Umum: TunaiWajib)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tunai = MetodePembayaran::query()->sole();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post("/kelola/panduan-awal/metode-pembayaran/{$tunai->Uuid}/nonaktifkan")
            ->assertSessionHasErrors(['Umum' => 'Tunai selalu tersedia di kasir dan tidak bisa dinonaktifkan.']);
        expect($tunai->refresh()->Aktif)->toBeTrue();
    });

    it('QRIS statis: gambar wajib & harus gambar. Disimpan di disk privat dan hanya bisa diunduh tenant sendiri', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        ['Tenant' => $lain, 'Pemilik' => $pemilikLain] = BantuanPanduanAwal::BuatTenant('Toko Roti Harum');
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('QrisStatis', ['GambarQris' => null]))->assertSessionHasErrors('GambarQris');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('QrisStatis', ['GambarQris' => UploadedFile::fake()->create('qris.pdf', 50, 'application/pdf')]))->assertSessionHasErrors('GambarQris');

        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('QrisStatis'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', 'Metode pembayaran QRIS Toko ditambahkan.');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $qris = MetodePembayaran::query()->where('Jenis', 'QrisStatis')->sole();
        expect($qris->PathGambarQris)->toStartWith("metode-pembayaran/{$tenant->Id}/")
            ->and($qris->Urutan)->toBe(1);
        Storage::disk('local')->assertExists((string) $qris->PathGambarQris);

        $tes()->get("/kelola/panduan-awal/metode-pembayaran/{$qris->Uuid}/gambar-qris")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        BantuanPanduanAwal::Masuk($this, $pemilikLain, $lain)->get("/kelola/panduan-awal/metode-pembayaran/{$qris->Uuid}/gambar-qris")->assertNotFound();

        $tes()->get('/kelola/panduan-awal/metode-pembayaran')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/PanduanAwal/MetodePembayaran')
                ->where('MetodePembayaran.0.Jenis', 'Tunai')
                ->where('MetodePembayaran.0.Wajib', true)
                ->where('MetodePembayaran.0.TautanGambarQris', null)
                ->where('MetodePembayaran.1.LabelJenis', 'QRIS statis')
                ->where('MetodePembayaran.1.TautanGambarQris', route('kelola.panduan-awal.metode-pembayaran.gambar-qris', ['metodePembayaran' => $qris->Uuid]))
                ->where('MetodePembayaran.1.PersenBiaya', '0.000000')
                ->missing('MetodePembayaran.1.PathGambarQris')
                // F-08: QRIS dinamis bisa ditambahkan dari back-office (butuh gerbang aktif platform); X8: platform ojol.
                ->where('JenisTersedia', [['Nilai' => 'QrisStatis', 'Label' => 'QRIS statis'], ['Nilai' => 'QrisDinamis', 'Label' => 'QRIS dinamis'], ['Nilai' => 'Edc', 'Label' => 'Kartu (EDC)'], ['Nilai' => 'Transfer', 'Label' => 'Transfer bank'], ['Nilai' => 'Deposit', 'Label' => 'Deposit pelanggan'], ['Nilai' => 'GiftCard', 'Label' => 'Gift card'], ['Nilai' => 'Marketplace', 'Label' => 'Platform ojol / marketplace']])
                ->where('Bank', [['Kode' => 'BCA', 'Nama' => 'Bank Central Asia', 'Jenis' => 'Bank'], ['Kode' => 'GOPAY', 'Nama' => 'GoPay', 'Jenis' => 'Ewallet']])
                ->where('BatasGambarQris', ['UkuranMaksimalKb' => 2048, 'Ekstensi' => ['png', 'jpg', 'jpeg', 'webp']]));
    });

    it('EDC: bank tidak aktif, tidak dikenal, atau jenis salah ditolak (KodeBank). Biaya > 10 persen ditolak', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Edc', ['KodeBank' => 'BRI']))->assertSessionHasErrors(['KodeBank' => 'Pilih bank dari daftar.']);
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Edc', ['KodeBank' => 'TIDAKADA']))->assertSessionHasErrors('KodeBank');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Edc', ['KodeBank' => 'GOPAY']))->assertSessionHasErrors('KodeBank');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Edc', ['KodeBank' => null]))->assertSessionHasErrors('KodeBank');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Edc', ['PersenBiaya' => '12.5']))->assertSessionHasErrors('PersenBiaya');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Edc'))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $edc = MetodePembayaran::query()->where('Jenis', 'Edc')->sole();
        expect($edc->PersenBiaya)->toBe('0.700000')
            ->and($edc->IdReferensiBank)->toBe(ReferensiBank::query()->where('Kode', 'BCA')->value('Id'))
            ->and($edc->NomorRekening)->toBeNull()
            ->and($edc->IdAkun)->toBeNull();
    });

    it('transfer wajib nomor & nama pemilik rekening. Dompet digital boleh. Nonaktif/aktif tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Transfer', ['NomorRekening' => null]))->assertSessionHasErrors('NomorRekening');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Transfer', ['NomorRekening' => '873-012-3456']))->assertSessionHasErrors('NomorRekening');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Transfer', ['NamaPemilikRekening' => null]))->assertSessionHasErrors('NamaPemilikRekening');
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Transfer'))->assertSessionHasNoErrors();
        $tes()->post('/kelola/panduan-awal/metode-pembayaran', IsianMetodePembayaranUji('Transfer', ['Nama' => 'GoPay Toko', 'KodeBank' => 'GOPAY']))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $transfer = MetodePembayaran::query()->where('Nama', 'Transfer BCA')->sole();
        expect($transfer->NomorRekening)->toBe('8730123456')
            ->and($transfer->NamaPemilikRekening)->toBe('CV Kopi Nusantara Sejahtera')
            ->and(MetodePembayaran::query()->orderBy('Urutan')->pluck('Nama')->all())->toBe(['Tunai', 'Transfer BCA', 'GoPay Toko']);

        $tes()->post("/kelola/panduan-awal/metode-pembayaran/{$transfer->Uuid}/nonaktifkan")->assertSessionHasNoErrors();
        expect($transfer->refresh()->Aktif)->toBeFalse();
        $tes()->post("/kelola/panduan-awal/metode-pembayaran/{$transfer->Uuid}/aktifkan")->assertSessionHasNoErrors();
        expect($transfer->refresh()->Aktif)->toBeTrue();

        $peristiwa = LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'like', 'metode-pembayaran.%')->orderBy('Id')->pluck('Peristiwa')->all();
        expect($peristiwa)->toBe(['metode-pembayaran.buat', 'metode-pembayaran.buat', 'metode-pembayaran.buat', 'metode-pembayaran.nonaktifkan', 'metode-pembayaran.aktifkan']);
    });
});
