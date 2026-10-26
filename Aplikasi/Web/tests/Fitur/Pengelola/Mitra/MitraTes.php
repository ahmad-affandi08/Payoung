<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tagihan\Aksi\TerimaPembayaranLangganan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Aksi\CatatAtribusiMitra;
use App\Domain\Tenant\Enum\StatusKomisiMitra;
use App\Domain\Tenant\Model\AtribusiMitra;
use App\Domain\Tenant\Model\KomisiMitra;
use App\Domain\Tenant\Model\Mitra;
use App\Domain\Tenant\Model\PencairanKomisi;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Autentikasi\PendaftaranKontroler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * P-12 (v3.50): mitra, reseller & referral. Konsol mengelola mitra & kode; tenant yang mendaftar lewat tautan mitra
 * (klik pertama ≤ 90 hari) teratribusi ke satu mitra (BR-P12.2); tagihan langganan lunas melahirkan komisi
 * (BR-P12.1: dasar tanpa PPN, referral sekali, reseller berulang); komisi tertunda bisa dibatalkan (clawback);
 * Keuangan mencatat pencairan bulanan dengan potongan pajak.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
});

function MasukMitraPengelola(TestCase $tes, PeranPengelolaBawaan $peran): PenggunaPengelola
{
    $anggota = BantuanPengelola::BuatAnggota($peran);
    $tes->actingAs($anggota, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());

    return $anggota;
}

/** @param array<string, mixed> $timpa */
function IsianMitraUji(array $timpa = []): array
{
    return [
        'Kode' => 'AGEN-SOLO', 'Nama' => 'CV Agen Kasir Solo', 'Jenis' => 'Reseller', 'Status' => 'Aktif',
        'Email' => 'agen@solo.id', 'NoHp' => '081255556666', 'Npwp' => '0123456789012345', 'NamaBank' => 'Bank Rakyat Indonesia',
        'NomorRekening' => '002301000123567', 'NamaPemilikRekening' => 'CV Agen Kasir Solo',
        'PersenKomisi' => '20', 'KomisiBerulang' => true, 'Catatan' => null, ...$timpa,
    ];
}

/** Tagihan PRO bulanan dibuat owner lalu diterima Keuangan; kembalikan tagihannya. */
function LunasiTagihanMitraUji(TestCase $tes, Tenant $tenant, Pengguna $pemilik): TagihanLangganan
{
    BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])->assertSessionHasNoErrors();
    $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
    $pembayaran = BantuanTagihan::UnggahBuktiLangsung($tenant, $pemilik, $tagihan);
    $tes->flushSession();
    app(TerimaPembayaranLangganan::class)->Jalankan(BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan), $pembayaran->Uuid, $tagihan->Total);

    return $tagihan->refresh();
}

it('konsol: Mitra & Penjualan menambah mitra (kode unik, rahasia terenkripsi & tersamar), Keuangan & Analis dibatasi', function (): void {
    MasukMitraPengelola($this, PeranPengelolaBawaan::MitraPenjualan);
    $this->post(BantuanPengelola::Url('/mitra'), IsianMitraUji(['Kode' => 'a b']))->assertSessionHasErrors('Kode');
    $this->post(BantuanPengelola::Url('/mitra'), IsianMitraUji(['PersenKomisi' => '120']))->assertSessionHasErrors('PersenKomisi');
    $this->post(BantuanPengelola::Url('/mitra'), IsianMitraUji())->assertSessionHasNoErrors();
    $this->post(BantuanPengelola::Url('/mitra'), IsianMitraUji(['Nama' => 'Ganda']))->assertSessionHasErrors('Kode');

    $mitra = Mitra::query()->sole();
    expect($mitra->getRawOriginal('NomorRekening'))->not->toBe('002301000123567')
        ->and($mitra->NomorRekening)->toBe('002301000123567');

    // Ubah tanpa mengisi rekening = rekening tetap; kode tidak bisa diganti.
    $this->put(BantuanPengelola::Url("/mitra/{$mitra->Uuid}"), IsianMitraUji(['NomorRekening' => '', 'PersenKomisi' => '15']))->assertSessionHasNoErrors();
    $this->put(BantuanPengelola::Url("/mitra/{$mitra->Uuid}"), IsianMitraUji(['Kode' => 'LAIN']))->assertSessionHasErrors('Kode');
    expect($mitra->refresh()->NomorRekening)->toBe('002301000123567')->and($mitra->PersenKomisi)->toBe('15.00');
    $audit = LogAuditPengelola::query()->where('Aksi', 'mitra.ubah')->sole();
    expect(json_encode($audit->NilaiBaru))->not->toContain('002301000123567');

    $this->get(BantuanPengelola::Url("/mitra/{$mitra->Uuid}"))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Pengelola/Mitra/Tampil')
        ->where('Mitra.RekeningTersamar', '•••• 3567')
        ->where('Mitra.TautanPendaftaran', url('/daftar?mitra=AGEN-SOLO'))
        ->missing('Mitra.NomorRekening'));

    MasukMitraPengelola($this, PeranPengelolaBawaan::Keuangan);
    $this->get(BantuanPengelola::Url('/mitra'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Pengelola/Mitra/Daftar')->has('Mitra', 1));
    $this->post(BantuanPengelola::Url('/mitra'), IsianMitraUji(['Kode' => 'BARU']))->assertForbidden();
    MasukMitraPengelola($this, PeranPengelolaBawaan::Analis);
    $this->get(BantuanPengelola::Url('/mitra'))->assertForbidden();
});

it('tautan mitra: klik pertama dicatat di cookie, pendaftaran teratribusi sekali. Kode tak dikenal & klik > 90 hari diabaikan', function (): void {
    $agen = Mitra::query()->create(['Kode' => 'AGEN-SOLO', 'Nama' => 'Agen Solo', 'Jenis' => 'Reseller', 'PersenKomisi' => '20.00', 'KomisiBerulang' => true]);
    Mitra::query()->create(['Kode' => 'LAIN', 'Nama' => 'Mitra lain', 'Jenis' => 'Referral', 'PersenKomisi' => '10.00']);

    $this->get(BantuanTagihan::Url('/daftar?mitra=agen-solo'))->assertOk()->assertCookie(PendaftaranKontroler::COOKIE_MITRA);
    // Tautan mitra kedua tidak menimpa klik pertama. (Antrean cookie dikosongkan: di aplikasi nyata tiap request baru.)
    app('cookie')->flushQueuedCookies();
    $this->withCookie(PendaftaranKontroler::COOKIE_MITRA, 'AGEN-SOLO|'.now()->getTimestamp())
        ->get(BantuanTagihan::Url('/daftar?mitra=LAIN'))->assertCookieMissing(PendaftaranKontroler::COOKIE_MITRA);

    // Pendaftaran lewat web membawa cookie klik pertama → teratribusi; atribusi kedua untuk tenant yang sama diabaikan.
    app('cookie')->flushQueuedCookies();
    $this->withCookie(PendaftaranKontroler::COOKIE_MITRA, 'AGEN-SOLO|'.now()->subDays(3)->getTimestamp())->post(BantuanTagihan::Url('/daftar'), [
        'Nama' => 'Rina Wulandari', 'Email' => 'rina@kopinusantara.id', 'NoHp' => '081234567890', 'KataSandi' => 'kopisusu123',
        'KonfirmasiKataSandi' => 'kopisusu123', 'NamaUsaha' => 'Kopi Nusantara', 'Paket' => 'PRO', 'Setuju' => true, 'TokenCaptcha' => 'token-uji',
    ])->assertRedirect(route('kelola.panduan-awal'));
    $tenant = Tenant::query()->sole();
    app(CatatAtribusiMitra::class)->Jalankan($tenant->Id, 'LAIN', now()->toImmutable());
    $atribusi = AtribusiMitra::query()->sole();
    expect($atribusi->IdMitra)->toBe($agen->Id)->and($atribusi->Sumber)->toBe('Tautan');

    ['Tenant' => $lama] = BantuanTagihan::DaftarTenant('budi@toko.id', '081211112222', 'Toko Budi');
    app(CatatAtribusiMitra::class)->Jalankan($lama->Id, 'AGEN-SOLO', now()->toImmutable()->subDays(91));
    app(CatatAtribusiMitra::class)->Jalankan($lama->Id, 'TIDAK-ADA', now()->toImmutable());
    expect(AtribusiMitra::query()->count())->toBe(1);
});

it('komisi dari tagihan lunas (tanpa PPN), referral sekali vs reseller berulang, clawback, lalu pencairan bulanan', function (): void {
    $agen = Mitra::query()->create(['Kode' => 'AGEN-SOLO', 'Nama' => 'Agen Solo', 'Jenis' => 'Reseller', 'PersenKomisi' => '20.00', 'KomisiBerulang' => true]);
    $rujuk = Mitra::query()->create(['Kode' => 'RUJUK', 'Nama' => 'Perujuk', 'Jenis' => 'Referral', 'PersenKomisi' => '10.00', 'KomisiBerulang' => false]);
    ['Tenant' => $tenantA, 'Pengguna' => $pemilikA] = BantuanTagihan::DaftarTenant();
    ['Tenant' => $tenantB, 'Pengguna' => $pemilikB] = BantuanTagihan::DaftarTenant('budi@toko.id', '081211112222', 'Toko Budi');
    AtribusiMitra::query()->create(['IdMitra' => $agen->Id, 'IdTenant' => $tenantA->Id, 'Sumber' => 'Tautan', 'MulaiPada' => now()]);
    AtribusiMitra::query()->create(['IdMitra' => $rujuk->Id, 'IdTenant' => $tenantB->Id, 'Sumber' => 'Tautan', 'MulaiPada' => now()]);

    $tagihanA1 = LunasiTagihanMitraUji($this, $tenantA, $pemilikA);
    $tagihanB1 = LunasiTagihanMitraUji($this, $tenantB, $pemilikB);
    $this->travel(32)->days();
    $tagihanA2 = LunasiTagihanMitraUji($this, $tenantA, $pemilikA);
    LunasiTagihanMitraUji($this, $tenantB, $pemilikB);

    $komisiA1 = KomisiMitra::query()->where('IdTagihanLangganan', $tagihanA1->Id)->sole();
    $dasar = Uang::Dari((string) $tagihanA1->Subtotal)->Kurangi(Uang::Dari((string) $tagihanA1->Diskon));
    expect($komisiA1->DasarKomisi)->toBe($dasar->KeString())
        ->and($komisiA1->Jumlah)->toBe($dasar->Kali('0.2')->KeString())
        ->and(Uang::Dari((string) $tagihanA1->Total)->Bandingkan($dasar))->toBe(1, 'PPN tidak ikut dasar komisi.')
        ->and(KomisiMitra::query()->where('IdMitra', $agen->Id)->count())->toBe(2)
        ->and(KomisiMitra::query()->where('IdMitra', $rujuk->Id)->count())->toBe(1, 'Referral hanya dari tagihan lunas pertama.')
        ->and(KomisiMitra::query()->where('IdTagihanLangganan', $tagihanB1->Id)->value('Jumlah'))->not->toBeNull();

    // Clawback komisi tagihan kedua A oleh Mitra & Penjualan.
    MasukMitraPengelola($this, PeranPengelolaBawaan::MitraPenjualan);
    $komisiA2 = KomisiMitra::query()->where('IdTagihanLangganan', $tagihanA2->Id)->sole();
    $this->post(BantuanPengelola::Url("/mitra/komisi/{$komisiA2->Uuid}/batal"), ['Alasan' => 'x'])->assertSessionHasErrors('Alasan');
    $this->post(BantuanPengelola::Url("/mitra/komisi/{$komisiA2->Uuid}/batal"), ['Alasan' => 'Tagihan dikembalikan ke tenant'])->assertSessionHasNoErrors();
    expect($komisiA2->refresh()->Status)->toBe(StatusKomisiMitra::Dibatalkan);
    $this->post(BantuanPengelola::Url("/mitra/{$agen->Uuid}/pencairan"), ['Periode' => '2026-09', 'PotonganPajak' => '0', 'DibayarPada' => '2026-10-24'])->assertForbidden();

    // Keuangan mencairkan periode September: hanya komisi A1 (tertunda, tercatat ≤ akhir September).
    MasukMitraPengelola($this, PeranPengelolaBawaan::Keuangan);
    $this->post(BantuanPengelola::Url("/mitra/{$agen->Uuid}/pencairan"), ['Periode' => '2026-09', 'PotonganPajak' => '999999999', 'DibayarPada' => '2026-10-24'])
        ->assertSessionHasErrors('PotonganPajak');
    $this->post(BantuanPengelola::Url("/mitra/{$agen->Uuid}/pencairan"), ['Periode' => '2026-09', 'PotonganPajak' => '1000', 'DibayarPada' => '2026-10-24', 'Catatan' => 'Transfer BRI'])
        ->assertSessionHasNoErrors();
    $pencairan = PencairanKomisi::query()->sole();
    expect($pencairan->Total)->toBe($komisiA1->Jumlah)
        ->and($pencairan->JumlahBersih)->toBe(Uang::Dari($komisiA1->Jumlah)->Kurangi(Uang::Dari('1000'))->KeString())
        ->and($komisiA1->refresh()->Status)->toBe(StatusKomisiMitra::Dibayar)
        ->and($komisiA1->IdPencairanKomisi)->toBe($pencairan->Id);
    $this->post(BantuanPengelola::Url("/mitra/{$agen->Uuid}/pencairan"), ['Periode' => '2026-09', 'PotonganPajak' => '0', 'DibayarPada' => '2026-10-24'])
        ->assertSessionHasErrors('Periode');
    $this->post(BantuanPengelola::Url("/mitra/{$agen->Uuid}/pencairan"), ['Periode' => '2026-10', 'PotonganPajak' => '0', 'DibayarPada' => '2026-10-24'])
        ->assertSessionHasErrors('Periode');

    $this->get(BantuanPengelola::Url("/tenant/{$tenantA->Uuid}"))->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Tenant.MitraPerujuk.Kode', 'AGEN-SOLO'));
    $this->get(BantuanPengelola::Url('/mitra'))->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Mitra.0.Kode', 'AGEN-SOLO')->where('Mitra.0.JumlahTenant', 1)->where('Mitra.0.KomisiTertunda', '0.00'));
});
