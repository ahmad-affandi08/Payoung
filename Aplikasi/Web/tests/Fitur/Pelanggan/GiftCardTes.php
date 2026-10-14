<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Enum\JenisMutasiGiftCard;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Layanan\KodeGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use App\Domain\Pelanggan\Model\MutasiGiftCard;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Kueri\RingkasanPenjualanShift;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\PenjualanPembayaran;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * CRM-06 gift card (D-153): kartu bernilai berkode dijual dari back-office (J-16.10 Dr kas/bank Cr Saldo Gift Card),
 * dicek online di POS, dipakai membayar di semua outlet (satu kartu per penjualan, saldo kurang = diterima + tinjauan),
 * void mengembalikan saldo, kartu tidak bisa diuangkan lewat retur, kedaluwarsa dihanguskan tiap malam, dan invarian
 * Σ GiftCard.Saldo = Σ MutasiGiftCard = −saldo akun Saldo Gift Card.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant kasir + produk Rp 38.500 + metode Gift card.
 *
 * @return array<string, mixed>
 */
function SiapkanGiftCard(TestCase $tes, string $namaUsaha = 'Toko Hadiah Kartu Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);

    return $k + [
        'Produk' => $produk,
        'GiftCard' => BantuanPenjualan::BuatMetode(JenisMetodePembayaran::GiftCard, 'Gift card'),
    ];
}

/**
 * Jual kartu dari back-office sebagai Pemilik; hasilnya kartu dengan kode lengkap.
 *
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $timpa
 */
function JualKartu(TestCase $tes, array $k, string $nilai = '100000', array $timpa = []): GiftCard
{
    $uuid = BantuanKasir::Uuid();
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);
    $tes->post('/kelola/pelanggan/gift-card', array_replace([
        'Uuid' => $uuid,
        'Nilai' => $nilai,
        'UuidAkun' => BantuanPembelian::AkunKas()->Uuid,
        'NamaPemegang' => 'Bu Ratna',
        'MasaBerlakuHari' => 90,
    ], $timpa))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return GiftCard::query()->where('Uuid', $uuid)->firstOrFail();
}

/**
 * Jual 2 × Rp 38.500 dibayar gift card [gift] (sisa tunai), dengan kode [kode].
 *
 * @param  array<string, mixed>  $k
 */
function JualDenganGiftCard(TestCase $tes, array $k, string $kode, string $gift = '30000.00', string $status = 'Diterima'): ?Penjualan
{
    $item = BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']],
        'Pembayaran' => [['Metode' => $k['GiftCard'], 'Jumlah' => $gift, 'Referensi' => $kode], ['Metode' => $k['Tunai'], 'Jumlah' => null]],
    ]);
    $hasil = BantuanKasir::KirimRingkas($tes, $k['Token'], [$item]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($hasil[0][0])->toBe($status);

    return Penjualan::query()->where('Uuid', $item['Uuid'])->first();
}

function SaldoKartu(GiftCard $g): string
{
    return (string) GiftCard::query()->whereKey($g->Id)->value('Saldo');
}

/**
 * Invarian gift card: Σ `GiftCard.Saldo` = Σ `MutasiGiftCard.Jumlah` = −saldo akun Saldo Gift Card; `SaldoSetelah` =
 * jumlah berjalan per kartu; jurnal seimbang.
 *
 * @return list<string>
 */
function PeriksaInvarianGiftCard(int $idTenant): array
{
    $galat = PemeriksaInvarian::PeriksaJurnalSeimbang($idTenant);
    $cache = (string) DB::selectOne('SELECT CAST(COALESCE(SUM(`Saldo`), 0) AS DECIMAL(18,2)) AS S FROM `GiftCard` WHERE `IdTenant` = ?', [$idTenant])->S;
    $ledger = (string) DB::selectOne('SELECT CAST(COALESCE(SUM(`Jumlah`), 0) AS DECIMAL(18,2)) AS S FROM `MutasiGiftCard` WHERE `IdTenant` = ?', [$idTenant])->S;
    $akun = BantuanPembelian::SaldoPeran($idTenant, PeranAkun::SaldoGiftCard);

    if (! BigDecimal::of($cache)->isEqualTo(BigDecimal::of($ledger))) {
        $galat[] = "Σ Saldo {$cache} ≠ Σ MutasiGiftCard {$ledger}";
    }

    if (! BigDecimal::of($akun)->isEqualTo(BigDecimal::of($ledger)->negated())) {
        $galat[] = "Saldo akun SaldoGiftCard {$akun} ≠ −Σ MutasiGiftCard {$ledger}";
    }

    $berjalan = [];

    foreach (DB::table('MutasiGiftCard')->where('IdTenant', $idTenant)->orderBy('Id')->get() as $m) {
        $berjalan[$m->IdGiftCard] = BigDecimal::of($berjalan[$m->IdGiftCard] ?? '0')->plus((string) $m->Jumlah);

        if (! $berjalan[$m->IdGiftCard]->isEqualTo(BigDecimal::of((string) $m->SaldoSetelah))) {
            $galat[] = "MutasiGiftCard {$m->Id} SaldoSetelah {$m->SaldoSetelah} ≠ jumlah berjalan {$berjalan[$m->IdGiftCard]}";
        }
    }

    return $galat;
}

describe('CRM-06 jual gift card di back-office', function (): void {
    it('kartu terbit berkode, jurnal J-16.10 Dr Kas Cr Saldo Gift Card, riwayat Jual, audit menyamarkan kode, kirim ulang Uuid sama = satu kartu', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '100000');

        expect($kartu->Kode)->toMatch('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/')
            ->and($kartu->NilaiAwal)->toBe('100000.00')
            ->and($kartu->Saldo)->toBe('100000.00')
            ->and($kartu->Status)->toBe(StatusGiftCard::Aktif)
            ->and($kartu->BerlakuSampai)->not->toBeNull()
            ->and(MutasiGiftCard::query()->sole()->Jenis)->toBe(JenisMutasiGiftCard::Jual)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::SaldoGiftCard))->toBe('-100000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KasOutlet))->toBe('100000.00');
        expect(PeriksaInvarianGiftCard($k['Tenant']->Id))->toBe([]);

        // Flash kartu baru menampilkan kode lengkap sekali; audit hanya menyimpan samaran.
        $audit = DB::table('LogAudit')->where('IdTenant', $k['Tenant']->Id)->where('Peristiwa', 'pelanggan.gift-card-jual')->sole();
        expect((string) $audit->NilaiBaru)->not->toContain($kartu->Kode)->and((string) $audit->NilaiBaru)->toContain(KodeGiftCard::Samarkan($kartu->Kode));

        // Uuid sama dikirim lagi (klik dua kali): tidak membuat kartu kedua dan tidak menggandakan jurnal.
        $this->post('/kelola/pelanggan/gift-card', ['Uuid' => $kartu->Uuid, 'Nilai' => '100000', 'UuidAkun' => BantuanPembelian::AkunKas()->Uuid])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(GiftCard::query()->count())->toBe(1)->and(MutasiGiftCard::query()->count())->toBe(1);
    });

    it('menolak nilai bukan Rupiah bulat atau di luar batas, akun bukan kas/bank, masa berlaku di luar rentang, dan kasir tanpa izin', function (): void {
        $k = SiapkanGiftCard($this);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $kas = BantuanPembelian::AkunKas()->Uuid;
        $kirim = fn (array $isi): TestResponse => $this->post('/kelola/pelanggan/gift-card', array_replace(['Uuid' => BantuanKasir::Uuid(), 'Nilai' => '50000', 'UuidAkun' => $kas], $isi));

        $kirim(['Nilai' => '50000.50'])->assertSessionHasErrors('Nilai');
        $kirim(['Nilai' => '5000'])->assertSessionHasErrors('Nilai');
        $kirim(['Nilai' => '5000001'])->assertSessionHasErrors('Nilai');
        $kirim(['UuidAkun' => BantuanKasir::Uuid()])->assertSessionHasErrors('UuidAkun');
        $kirim(['MasaBerlakuHari' => 0])->assertSessionHasErrors('MasaBerlakuHari');
        $kirim(['MasaBerlakuHari' => 5000])->assertSessionHasErrors('MasaBerlakuHari');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(GiftCard::query()->count())->toBe(0);

        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id);
        $kirim([])->assertForbidden();
    });

    it('paket tanpa fitur pelanggan.deposit: penjualan kartu ditolak', function (): void {
        $k = SiapkanGiftCard($this);
        Langganan::query()->where('IdTenant', $k['Tenant']->Id)->update(['IdPaket' => Paket::query()->where('Kode', 'STARTER')->value('Id')]);
        cache()->flush();
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        // Tenant yang turun paket: halaman & tulis ditolak penegak fitur (D-111) dan tidak ada kartu terbit.
        $this->post('/kelola/pelanggan/gift-card', ['Uuid' => BantuanKasir::Uuid(), 'Nilai' => '50000', 'UuidAkun' => BantuanPembelian::AkunKas()->Uuid])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(GiftCard::query()->count())->toBe(0);
        $this->withToken($k['Token'])->postJson('/api/pos/v1/gift-card/cek', ['Kode' => 'AAAA-BBBB-CCCC'])->assertForbidden()->assertJsonPath('Galat.Kode', 'FiturTidakAktif');
    });
});

describe('CRM-06 cek gift card di POS', function (): void {
    it('kode benar (huruf kecil, tanpa tanda hubung) mengembalikan saldo; salah/tak ada/tenant lain = 404 yang sama', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '100000');
        $tulis = strtolower(str_replace('-', ' ', $kartu->Kode));

        $this->withToken($k['Token'])->postJson('/api/pos/v1/gift-card/cek', ['Kode' => $tulis])->assertOk()
            ->assertJsonPath('Kode', $kartu->Kode)
            ->assertJsonPath('Saldo', '100000.00')
            ->assertJsonPath('Status', 'Aktif')
            ->assertJsonPath('BisaDipakai', true)
            ->assertJsonPath('Alasan', null);
        $this->withToken($k['Token'])->postJson('/api/pos/v1/gift-card/cek', ['Kode' => '2222-3333-4444'])->assertNotFound()->assertJsonPath('Galat.Kode', 'GiftCardTidakDitemukan');
        $this->withToken($k['Token'])->postJson('/api/pos/v1/gift-card/cek', ['Kode' => 'bukan kode'])->assertNotFound()->assertJsonPath('Galat.Kode', 'GiftCardTidakDitemukan');
        $this->withToken($k['Token'])->postJson('/api/pos/v1/gift-card/cek', [])->assertUnprocessable();

        // Tenant lain tidak bisa membaca kartu ini.
        $lain = SiapkanGiftCard($this, 'Toko Lain Kartu Madiun');
        $this->withToken($lain['Token'])->postJson('/api/pos/v1/gift-card/cek', ['Kode' => $kartu->Kode])->assertNotFound();
    });

    it('kartu hangus atau lewat masa berlaku tampil BisaDipakai=false dengan alasan', function (): void {
        $k = SiapkanGiftCard($this);
        $kadaluarsa = JualKartu($this, $k, '50000');
        $kadaluarsa->forceFill(['BerlakuSampai' => now()->subDays(3)->toDateString()])->save();

        $this->withToken($k['Token'])->postJson('/api/pos/v1/gift-card/cek', ['Kode' => $kadaluarsa->Kode])->assertOk()
            ->assertJsonPath('BisaDipakai', false)
            ->assertJsonPath('Status', 'Aktif');
    });
});

describe('CRM-06 bayar dengan gift card', function (): void {
    it('memotong saldo kartu di transaksi penjualan; bukan uang masuk laci; Referensi tersimpan samar; void mengembalikan; invarian terjaga', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '100000');

        $jual = JualDenganGiftCard($this, $k, $kartu->Kode, '30000.00');
        expect($jual?->PerluTinjauan)->toBeFalse()
            ->and(SaldoKartu($kartu))->toBe('70000.00')
            ->and(MutasiGiftCard::query()->where('Jenis', JenisMutasiGiftCard::Pakai->value)->sole()->Jumlah)->toBe('-30000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::SaldoGiftCard))->toBe('-70000.00');

        $bayar = PenjualanPembayaran::query()->where('IdPenjualan', $jual?->Id)->where('JenisMetode', JenisMetodePembayaran::GiftCard->value)->sole();
        expect($bayar->Referensi)->toBe(KodeGiftCard::Samarkan($kartu->Kode))->and($bayar->Referensi)->not->toContain($kartu->Kode);

        // Laci hanya menerima tunai 47.000 (77.000 − 30.000 dibayar kartu); penjualan kartu 100.000 dicatat di back-office, bukan laci.
        $ringkasan = app(RingkasanPenjualanShift::class)->Ambil((int) Shift::query()->where('Uuid', $k['UuidShift'])->value('Id'));
        expect($ringkasan->tunaiMasukBersih->KeString())->toBe('47000.00');
        expect(PeriksaInvarianGiftCard($k['Tenant']->Id))->toBe([]);

        $void = BantuanPenjualan::ItemVoid($k, $jual);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$void]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoKartu($kartu))->toBe('100000.00')
            ->and(MutasiGiftCard::query()->where('Jenis', JenisMutasiGiftCard::BatalPakai->value)->sole()->Jumlah)->toBe('30000.00');
        expect(PeriksaInvarianGiftCard($k['Tenant']->Id))->toBe([]);

        // Kirim ulang item void yang sama tidak mengembalikan dua kali.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$void]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoKartu($kartu))->toBe('100000.00');
    });

    it('kartu habis dipakai berstatus Habis lalu Aktif lagi setelah void; seluruh nilai bisa dipakai', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '77000');

        $jual = JualDenganGiftCard($this, $k, $kartu->Kode, '77000.00');
        expect(GiftCard::query()->whereKey($kartu->Id)->value('Status'))->toBe(StatusGiftCard::Habis)
            ->and(SaldoKartu($kartu))->toBe('0.00');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(GiftCard::query()->whereKey($kartu->Id)->value('Status'))->toBe(StatusGiftCard::Aktif)
            ->and(SaldoKartu($kartu))->toBe('77000.00');
        expect(PeriksaInvarianGiftCard($k['Tenant']->Id))->toBe([]);
    });

    it('saldo kurang: tetap diterima, saldo minus, tinjauan GiftCardBermasalah; kode tak dikenal: diterima + tinjauan, tanpa mutasi', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '20000');

        $jual = JualDenganGiftCard($this, $k, $kartu->Kode, '30000.00');
        expect($jual?->PerluTinjauan)->toBeTrue()
            ->and($jual?->AlasanTinjauan)->toContain('GiftCardBermasalah')
            ->and(SaldoKartu($kartu))->toBe('-10000.00');
        expect(PeriksaInvarianGiftCard($k['Tenant']->Id))->toBe([]);

        $tidakDikenal = JualDenganGiftCard($this, $k, 'ZZZZ-ZZZZ-ZZZZ', '10000.00');
        expect($tidakDikenal?->PerluTinjauan)->toBeTrue()
            ->and($tidakDikenal?->AlasanTinjauan)->toContain('tidak dikenal')
            ->and(MutasiGiftCard::query()->where('Jenis', JenisMutasiGiftCard::Pakai->value)->count())->toBe(1);
    });

    it('kartu hangus atau lewat masa berlaku tetap diterima dengan tinjauan; kode tanpa format sah atau dua pembayaran gift card ditolak', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '100000');
        $kartu->forceFill(['BerlakuSampai' => now()->subDays(2)->toDateString()])->save();

        $jual = JualDenganGiftCard($this, $k, $kartu->Kode, '30000.00');
        expect($jual?->PerluTinjauan)->toBeTrue()->and($jual?->AlasanTinjauan)->toContain('lewat masa berlaku');

        $tanpaKode = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['GiftCard'], 'Jumlah' => '38500.00', 'Referensi' => 'abc']],
        ]);
        $duaKartu = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['GiftCard'], 'Jumlah' => '20000.00', 'Referensi' => $kartu->Kode], ['Metode' => $k['GiftCard'], 'Jumlah' => '18500.00', 'Referensi' => $kartu->Kode]],
        ]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpaKode]))->toBe([['Ditolak', 'GiftCardTanpaKode']])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$duaKartu]))->toBe([['Ditolak', 'PembayaranTidakValid']]);
    });

    it('retur penjualan yang sebagian dibayar gift card: refund tunai paling banyak bagian non-kartu; bagian kartu tidak bisa diuangkan', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '100000');
        // Total 77.000: gift card 50.000 + tunai 27.000.
        $jual = JualDenganGiftCard($this, $k, $kartu->Kode, '50000.00');
        $detail = PenjualanDetail::query()->where('IdPenjualan', $jual?->Id)->firstOrFail();

        // Retur 1 unit = 38.500 > batas tunai 27.000: ditolak.
        $retur = BantuanPenjualan::ItemRetur($k, $jual, [['Detail' => $detail, 'Jumlah' => '1']], ['Refund' => [['Metode' => $k['Tunai'], 'Jumlah' => null]]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur]))->toBe([['Ditolak', 'GiftCardTidakBisaDiuangkan']]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoKartu($kartu))->toBe('50000.00');
    });
});

describe('CRM-06 kedaluwarsa dan pengelolaan kartu', function (): void {
    it('perintah malam menghanguskan kartu lewat masa berlaku: Dr Saldo Gift Card Cr Pendapatan Lain, idempoten', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '100000');
        $aman = JualKartu($this, $k, '50000', ['MasaBerlakuHari' => 365]);
        $tanpaBatas = JualKartu($this, $k, '25000', ['MasaBerlakuHari' => null]);
        JualDenganGiftCard($this, $k, $kartu->Kode, '30000.00');
        $kartu->forceFill(['BerlakuSampai' => now()->subDays(2)->toDateString()])->save();

        $this->artisan('pelanggan:proses-loyalti', ['--tenant' => [(string) $k['Tenant']->Id]])->assertSuccessful();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(GiftCard::query()->whereKey($kartu->Id)->value('Status'))->toBe(StatusGiftCard::Hangus)
            ->and(SaldoKartu($kartu))->toBe('0.00')
            ->and(GiftCard::query()->whereKey($aman->Id)->value('Status'))->toBe(StatusGiftCard::Aktif)
            ->and(GiftCard::query()->whereKey($tanpaBatas->Id)->value('Status'))->toBe(StatusGiftCard::Aktif)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('-70000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::SaldoGiftCard))->toBe('-75000.00');
        expect(PeriksaInvarianGiftCard($k['Tenant']->Id))->toBe([]);

        $this->artisan('pelanggan:proses-loyalti', ['--tenant' => [(string) $k['Tenant']->Id]])->assertSuccessful();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('-70000.00')
            ->and(MutasiGiftCard::query()->where('Jenis', JenisMutasiGiftCard::Hangus->value)->count())->toBe(1);
    });

    it('daftar & rincian riwayat; perpanjang masa berlaku; hanguskan manual; kembalikan uang hanya untuk kartu belum dipakai', function (): void {
        $k = SiapkanGiftCard($this);
        $utuh = JualKartu($this, $k, '100000');
        $terpakai = JualKartu($this, $k, '100000');
        JualDenganGiftCard($this, $k, $terpakai->Kode, '30000.00');
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $kas = BantuanPembelian::AkunKas()->Uuid;

        $this->get('/kelola/pelanggan/gift-card')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pelanggan/GiftCard')
            ->where('Izin.Kelola', true)->where('Pengaturan.Berlaku', true)
            ->where('Ringkasan.KartuAktif', 2)->where('Ringkasan.SaldoBeredar', '170000.00')
            ->has('AkunKasBank'));
        $this->get('/kelola/pelanggan/gift-card', ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('Meta.Total', 2)
            ->assertJsonMissing(['Kode' => $utuh->Kode]);
        $this->get('/kelola/pelanggan/gift-card?cari='.urlencode($utuh->Kode), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('Meta.Total', 1);
        $this->get("/kelola/pelanggan/gift-card/{$terpakai->Uuid}")->assertOk()
            ->assertJsonPath('Kartu.Saldo', '70000.00')->assertJsonCount(2, 'Riwayat')->assertJsonPath('Riwayat.1.Jenis', 'Pakai');

        $this->post("/kelola/pelanggan/gift-card/{$terpakai->Uuid}/kembalikan", ['UuidAkun' => $kas, 'Alasan' => 'Pelanggan minta uang kembali'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pelanggan/gift-card/{$utuh->Uuid}/kembalikan", ['UuidAkun' => $kas, 'Alasan' => 'abc'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pelanggan/gift-card/{$utuh->Uuid}/kembalikan", ['UuidAkun' => $kas, 'Alasan' => 'Salah input nominal'])->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/gift-card/{$utuh->Uuid}/kembalikan", ['UuidAkun' => $kas, 'Alasan' => 'Salah input nominal'])->assertSessionHasErrors('Alasan');

        $this->post("/kelola/pelanggan/gift-card/{$terpakai->Uuid}/perpanjang", ['BerlakuSampai' => now()->addDays(200)->toDateString()])->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/gift-card/{$terpakai->Uuid}/perpanjang", ['BerlakuSampai' => now()->subDay()->toDateString()])->assertSessionHasErrors('BerlakuSampai');
        $this->post("/kelola/pelanggan/gift-card/{$terpakai->Uuid}/perpanjang", [])->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/gift-card/{$terpakai->Uuid}/hanguskan", ['Alasan' => 'Kartu dilaporkan hilang'])->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/gift-card/{$terpakai->Uuid}/hanguskan", ['Alasan' => 'Kartu dilaporkan hilang'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pelanggan/gift-card/{$terpakai->Uuid}/perpanjang", [])->assertSessionHasErrors('BerlakuSampai');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(GiftCard::query()->whereKey($utuh->Id)->value('Status'))->toBe(StatusGiftCard::Dibatalkan)
            ->and(GiftCard::query()->whereKey($terpakai->Id)->value('Status'))->toBe(StatusGiftCard::Hangus)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::SaldoGiftCard))->toBe('0.00')
            ->and(DB::table('LogAudit')->where('IdTenant', $k['Tenant']->Id)->whereIn('Peristiwa', ['pelanggan.gift-card-kembali', 'pelanggan.gift-card-hangus', 'pelanggan.gift-card-perpanjang'])->count())->toBe(4);
        expect(PeriksaInvarianGiftCard($k['Tenant']->Id))->toBe([]);
    });

    it('tautan sumber jurnal membuka daftar kartu dengan rincian; tenant lain tidak bisa membaca atau mengubah kartu ini', function (): void {
        $k = SiapkanGiftCard($this);
        $kartu = JualKartu($this, $k, '100000');
        $mutasi = MutasiGiftCard::query()->sole();

        $this->get("/kelola/pelanggan/mutasi-gift-card/{$mutasi->Uuid}")->assertRedirect(route('kelola.pelanggan.gift-card.daftar', ['rincian' => $kartu->Uuid]));

        $lain = SiapkanGiftCard($this, 'Toko Lain Kartu Madiun');
        BantuanOrganisasi::Masuk($this, $lain['Pemilik'], $lain['Tenant']->Id);
        $this->get("/kelola/pelanggan/gift-card/{$kartu->Uuid}")->assertNotFound();
        $this->post("/kelola/pelanggan/gift-card/{$kartu->Uuid}/hanguskan", ['Alasan' => 'Percobaan lintas tenant'])->assertNotFound();
        $this->get('/kelola/pelanggan/gift-card', ['Accept' => 'application/json'])->assertOk()->assertJsonPath('Meta.Total', 0);
    });
});
