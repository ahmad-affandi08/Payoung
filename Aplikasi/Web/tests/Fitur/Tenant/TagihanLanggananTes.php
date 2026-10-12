<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Tenant\Aksi\BuatTagihanLangganan;
use App\Domain\Tenant\Enum\JenisKupon;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\KuponLangganan;
use App\Domain\Tenant\Model\KuponLanggananPemakaian;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/** Buat tagihan lewat HTTP sebagai Owner dan kembalikan tagihan terbarunya. */
function BuatTagihanUji(TestCase $tes, Pengguna $pengguna, Tenant $tenant, string $paket = 'PRO', string $siklus = 'Bulanan', ?string $kupon = null): TagihanLangganan
{
    BantuanTagihan::Masuk($tes, $pengguna, $tenant)
        ->post('/kelola/langganan/tagihan', ['KodePaket' => $paket, 'Siklus' => $siklus, 'KodeKupon' => $kupon])
        ->assertSessionHasNoErrors();

    return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
}

function BuatKuponUji(string $kode, string $jenis, string $nilai, int $durasi, ?int $kuota = null, ?array $paket = null, ?string $berlakuSampai = null): KuponLangganan
{
    return KuponLangganan::query()->create([
        'Kode' => $kode,
        'Jenis' => JenisKupon::from($jenis),
        'Nilai' => $nilai,
        'DurasiBulan' => $durasi,
        'Kuota' => $kuota,
        'DaftarKodePaket' => $paket,
        'BerlakuSampai' => $berlakuSampai,
    ]);
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
});

describe('Halaman langganan Owner (P-08, F-19 Fase 0)', function (): void {
    it('menampilkan status trial dan harga paket berlaku (tanpa Gratis & Enterprise), belum termasuk PPN', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola/langganan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Langganan/Indeks')
                ->where('Langganan.Status', 'Trial')
                ->where('Langganan.KodePaket', 'PRO')
                ->has('PilihanPaket', 3)
                ->where('PilihanPaket.1.Kode', 'PRO')
                ->where('PilihanPaket.1.HargaBulanan', '249000.00')
                ->where('PilihanPaket.1.HargaTahunan', '2390400.00')
                ->where('PilihanPaket.1.BisaDipilih', true));
    });

    it('§19.1: izin langganan.kelola khusus Pemilik; Admin & anggota tanpa peran mendapat halaman Tanpa izin', function (): void {
        $admin = BantuanOrganisasi::TambahAnggota($this->tenant->Id, PeranTenantBawaan::Admin);
        $staf = Pengguna::factory()->createOne();
        TenantPengguna::query()->create(['IdTenant' => $this->tenant->Id, 'IdPengguna' => $staf->Id, 'Pemilik' => false]);

        foreach ([$admin, $staf] as $pengguna) {
            BantuanTagihan::Masuk($this, $pengguna, $this->tenant)->get('/kelola/langganan')
                ->assertForbidden()
                ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/TanpaIzin'));
            $this->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])->assertForbidden();
        }

        expect(TagihanLangganan::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('Log audit tenant langganan (§25 no. 17)', function (): void {
    it('membuat tagihan dan membatalkan tercatat dengan pelaku & IP', function (): void {
        $pertama = BuatTagihanUji($this, $this->pemilik, $this->tenant);
        $this->post("/kelola/langganan/tagihan/{$pertama->Uuid}/batalkan", ['Alasan' => 'Salah pilih siklus'])->assertSessionHasNoErrors();

        $log = LogAudit::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)
            ->where('Peristiwa', 'like', 'langganan.%')->orderBy('Id')->get();

        expect($log->pluck('Peristiwa')->all())->toBe([
            'langganan.tagihan-buat', 'langganan.tagihan-batal',
        ])
            ->and($log->every(fn (LogAudit $baris) => $baris->IdPengguna === $this->pemilik->Id && $baris->Ip === '127.0.0.1'))->toBeTrue()
            ->and($log[0]->JenisObjek)->toBe('TagihanLangganan')
            ->and($log[0]->NilaiBaru)->toMatchArray(['Nomor' => $pertama->Nomor, 'Paket' => 'PRO', 'Siklus' => 'Bulanan', 'Total' => $pertama->Total])
            ->and($log[1]->NilaiLama)->toBe(['Status' => 'Terbit'])
            ->and($log[1]->NilaiBaru)->toMatchArray(['Status' => 'Dibatalkan', 'Alasan' => 'Salah pilih siklus']);
    });
});

describe('Membuat tagihan (BR-P08.1, BR-P04.1, BR-P04.7, §12.2)', function (): void {
    it('tagihan PRO bulanan: nomor INV/2026/09/000001, PPN dari TarifPajak terbit dengan DPP 11/12, jatuh tempo 7 hari', function (): void {
        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant);

        expect($tagihan->Nomor)->toBe('INV/2026/09/000001')
            ->and($tagihan->Status)->toBe(StatusTagihanLangganan::Terbit)
            ->and($tagihan->Jenis)->toBe(JenisTagihanLangganan::Aktivasi)
            ->and($tagihan->Siklus)->toBe(SiklusTagihan::Bulanan)
            ->and($tagihan->Subtotal)->toBe('249000.00')
            ->and($tagihan->Diskon)->toBe('0.00')
            ->and($tagihan->TarifPpn)->toBe('12.000000')
            ->and([$tagihan->PengaliDppPembilang, $tagihan->PengaliDppPenyebut])->toBe([11, 12])
            // DPP = ⌊249.000 × 11/12⌋ = 228.250; PPN = ⌊228.250 × 12%⌋ = 27.390.
            ->and($tagihan->DasarPengenaanPajak)->toBe('228250.00')
            ->and($tagihan->JumlahPpn)->toBe('27390.00')
            ->and($tagihan->Total)->toBe('276390.00')
            ->and($tagihan->IdTarifPajak)->not->toBeNull()
            ->and($tagihan->JatuhTempoPada->equalTo(now()->addDays(7)))->toBeTrue();

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get("/kelola/langganan/tagihan/{$tagihan->Uuid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Langganan/Tagihan')
                ->where('Tagihan.Nomor', 'INV/2026/09/000001')
                ->where('Tagihan.Total', '276390.00')
                ->where('BolehBayarOnline', true));
    });

    it('tagihan tahunan dengan kupon 50% selama 3 bulan: diskon 3/12 bagian, pemakaian kupon tercatat', function (): void {
        BuatKuponUji('HEMAT50', 'Persen', '50', 3);
        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant, siklus: 'Tahunan', kupon: 'hemat50');

        // Diskon = 2.390.400 × 50% × 3/12 = 298.800; DPP = 2.091.600 × 11/12 = 1.917.300; PPN = 230.076.
        expect($tagihan->Subtotal)->toBe('2390400.00')
            ->and($tagihan->JumlahBulan)->toBe(12)
            ->and($tagihan->KodeKupon)->toBe('HEMAT50')
            ->and($tagihan->Diskon)->toBe('298800.00')
            ->and($tagihan->DasarPengenaanPajak)->toBe('1917300.00')
            ->and($tagihan->JumlahPpn)->toBe('230076.00')
            ->and($tagihan->Total)->toBe('2321676.00');

        $pemakaian = KuponLanggananPemakaian::query()->sole();
        expect($pemakaian->IdTagihanLangganan)->toBe($tagihan->Id)
            ->and($pemakaian->IdTenant)->toBe($this->tenant->Id)
            ->and($pemakaian->BulanDiskon)->toBe(3)
            ->and($pemakaian->Diskon)->toBe('298800.00');
    });

    it('D-112: kupon 100% menerbitkan tagihan Rp 0 yang langsung lunas, tanpa gerbang, dan mengaktifkan paket', function (): void {
        // Tagihan Rp 0 tidak butuh gerbang pembayaran: tetap bisa walau gerbang billing belum dikonfigurasi.
        config()->set('integrasi.GerbangBilling', null);
        BuatKuponUji('GRATIS100', 'Persen', '100', 1);
        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant, kupon: 'GRATIS100');

        expect($tagihan->Total)->toBe('0.00')
            ->and($tagihan->Diskon)->toBe($tagihan->Subtotal)
            ->and($tagihan->JumlahPpn)->toBe('0.00')
            ->and($tagihan->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($tagihan->DibayarPada)->not->toBeNull();

        $pembayaran = PembayaranLangganan::query()->withoutGlobalScopes()->sole();
        expect($pembayaran->Metode)->toBe(MetodePembayaranLangganan::Kupon)
            ->and($pembayaran->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($pembayaran->JumlahDiterima)->toBe('0.00');

        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect($langganan->Status)->toBe(StatusLangganan::Aktif)
            ->and($langganan->PeriodeMulai)->not->toBeNull()
            ->and($langganan->PeriodeSelesai?->isAfter($langganan->PeriodeMulai))->toBeTrue()
            ->and(KuponLanggananPemakaian::query()->sole()->BulanDiskon)->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'langganan.tagihan-lunas-kupon')->exists())->toBeTrue();
    });

    it('invariant: Total = Subtotal − Diskon + PPN dan DPP = ⌊(Subtotal − Diskon) × 11/12⌋ untuk semua paket & siklus', function (string $paket, string $siklus): void {
        BuatKuponUji('POTONG25', 'Nominal', '25000.50', 2);
        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant, $paket, $siklus, 'POTONG25');
        $bersih = Uang::Dari($tagihan->Subtotal)->Kurangi(Uang::Dari($tagihan->Diskon));

        expect(Uang::Dari($tagihan->Total)->SamaDengan($bersih->Tambah(Uang::Dari($tagihan->JumlahPpn))))->toBeTrue()
            ->and(Uang::Dari($tagihan->DasarPengenaanPajak)->Kali('12')->Bandingkan($bersih->Kali('11')))->toBeLessThanOrEqual(0)
            ->and(Uang::Dari($tagihan->DasarPengenaanPajak)->Tambah(Uang::Dari('1'))->Kali('12')->Bandingkan($bersih->Kali('11')))->toBeGreaterThan(0)
            ->and(Uang::Dari($tagihan->JumlahPpn)->Kali('100')->Bandingkan(Uang::Dari($tagihan->DasarPengenaanPajak)->Kali('12')))->toBeLessThanOrEqual(0)
            ->and(Uang::Dari($tagihan->JumlahPpn)->Tambah(Uang::Dari('1'))->Kali('100')->Bandingkan(Uang::Dari($tagihan->DasarPengenaanPajak)->Kali('12')))->toBeGreaterThan(0)
            ->and(Uang::Dari($tagihan->Diskon)->SamaDengan(Uang::Dari(match ($siklus) {
                'Bulanan' => '25001',
                default => '50001',
            })))->toBeTrue();
    })->with([
        ['STARTER', 'Bulanan'], ['STARTER', 'Tahunan'], ['PRO', 'Bulanan'], ['PRO', 'Tahunan'], ['BISNIS', 'Bulanan'], ['BISNIS', 'Tahunan'],
    ]);

    it('hanya satu tagihan terbuka per tenant: klik ganda ditolak dengan pesan yang menyebut nomornya', function (): void {
        $pertama = BuatTagihanUji($this, $this->pemilik, $this->tenant);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'STARTER', 'Siklus' => 'Bulanan'])
            ->assertSessionHasErrors(['Umum' => "Masih ada tagihan {$pertama->Nomor} yang belum dibayar. Bayar atau batalkan tagihan itu dulu."]);
        expect(TagihanLangganan::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('menolak paket Gratis, Enterprise (negosiasi), paket tidak dikenal, dan siklus tidak valid', function (array $isian, string $bidang): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/tagihan', $isian)->assertSessionHasErrors($bidang);
        expect(TagihanLangganan::query()->withoutGlobalScopes()->count())->toBe(0);
    })->with([
        'gratis' => [['KodePaket' => 'GRATIS', 'Siklus' => 'Bulanan'], 'KodePaket'],
        'enterprise' => [['KodePaket' => 'ENTERPRISE', 'Siklus' => 'Bulanan'], 'KodePaket'],
        'tak dikenal' => [['KodePaket' => 'EMAS', 'Siklus' => 'Bulanan'], 'KodePaket'],
        'siklus' => [['KodePaket' => 'PRO', 'Siklus' => 'Mingguan'], 'Siklus'],
    ]);

    it('CLAUDE.md #12: tagihan ditolak bila tarif PPN belum terbit; tanpa PPN bila platform non-PKP', function (): void {
        DB::table('TarifPajak')->update(['Status' => 'Draf']);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasErrors(['Umum' => 'Tagihan belum bisa dibuat karena tarif PPN belum diterbitkan. Hubungi tim kami.']);
        expect(DB::table('NomorUrutTagihanLangganan')->count())->toBe(0);

        config()->set('tagihan.PlatformPkp', false);
        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant);
        expect($tagihan->JumlahPpn)->toBe('0.00')
            ->and($tagihan->IdTarifPajak)->toBeNull()
            ->and($tagihan->Total)->toBe('249000.00')
            // Percobaan yang gagal tidak memakan nomor (BR-P08.1).
            ->and($tagihan->Nomor)->toBe('INV/2026/09/000001');
    });

    it('menolak bila gerbang pembayaran platform belum aktif', function (): void {
        config()->set('integrasi.GerbangBilling', null);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasErrors('Umum');
    });

    it('BR-P08.1: nomor urut tanpa celah lintas tenant dan bulan, mulai lagi dari 1 di tahun baru; tagihan batal tetap memegang nomornya', function (): void {
        ['Tenant' => $tenantB, 'Pengguna' => $pemilikB] = BantuanTagihan::DaftarTenant('budi@tokobudi.id', '081298765432', 'Toko Budi');
        $a1 = BuatTagihanUji($this, $this->pemilik, $this->tenant);
        $this->post("/kelola/langganan/tagihan/{$a1->Uuid}/batalkan")->assertSessionHasNoErrors();
        $b1 = BuatTagihanUji($this, $pemilikB, $tenantB);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00', 'Asia/Jakarta'));
        $a2 = BuatTagihanUji($this, $this->pemilik, $this->tenant, 'STARTER');

        $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Asia/Jakarta'));
        BantuanTagihan::Masuk($this, $pemilikB, $tenantB)->post("/kelola/langganan/tagihan/{$b1->Uuid}/batalkan")->assertSessionHasNoErrors();
        $b2 = BuatTagihanUji($this, $pemilikB, $tenantB);

        expect([$a1->Nomor, $b1->Nomor, $a2->Nomor, $b2->Nomor])
            ->toBe(['INV/2026/09/000001', 'INV/2026/09/000002', 'INV/2026/10/000003', 'INV/2027/01/000001'])
            ->and($a1->refresh()->Status)->toBe(StatusTagihanLangganan::Dibatalkan);
    });

    it('angka tagihan tidak bisa diubah dan tagihan tidak bisa dihapus (CLAUDE.md #8)', function (): void {
        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant);
        BantuanTagihan::AturTenant($this->tenant);

        expect(fn () => $tagihan->update(['Total' => '1.00']))->toThrow(LogicException::class)
            ->and(fn () => $tagihan->refresh()->delete())->toThrow(LogicException::class)
            ->and(fn () => $tagihan->refresh()->update(['Status' => StatusTagihanLangganan::Draf]))->toThrow(LogicException::class);
    });
});

describe('Kupon langganan (BR-P04.7)', function (): void {
    it('kuota dihitung per tenant: tenant kedua ditolak saat kuota habis, pembatalan melepas kuota', function (): void {
        BuatKuponUji('PERDANA', 'Persen', '20', 1, kuota: 1);
        ['Tenant' => $tenantB, 'Pengguna' => $pemilikB] = BantuanTagihan::DaftarTenant('budi@tokobudi.id', '081298765432', 'Toko Budi');
        $tagihanA = BuatTagihanUji($this, $this->pemilik, $this->tenant, kupon: 'PERDANA');

        BantuanTagihan::Masuk($this, $pemilikB, $tenantB)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan', 'KodeKupon' => 'PERDANA'])
            ->assertSessionHasErrors(['KodeKupon' => 'Kuota kupon ini sudah habis.']);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post("/kelola/langganan/tagihan/{$tagihanA->Uuid}/batalkan");
        $tagihanB = BuatTagihanUji($this, $pemilikB, $tenantB, kupon: 'PERDANA');

        expect($tagihanB->Diskon)->toBe('49800.00')
            ->and(KuponLanggananPemakaian::query()->whereNull('DibatalkanPada')->pluck('IdTenant')->all())->toBe([$tenantB->Id]);
    });

    it('menolak kupon nonaktif, kedaluwarsa, bukan untuk paket, dan yang sudah habis dipakai tenant', function (): void {
        BuatKuponUji('LAMA', 'Persen', '10', 1, berlakuSampai: '2026-09-22');
        BuatKuponUji('BISNISSAJA', 'Persen', '10', 1, paket: ['BISNIS']);
        BuatKuponUji('SEKALI', 'Persen', '10', 1);
        KuponLangganan::query()->create(['Kode' => 'MATI', 'Jenis' => JenisKupon::Persen, 'Nilai' => '10', 'DurasiBulan' => 1, 'Aktif' => false]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant);

        foreach (['LAMA' => 'Kode kupon tidak berlaku.', 'MATI' => 'Kode kupon tidak berlaku.', 'BISNISSAJA' => 'Kupon ini tidak berlaku untuk paket Pro.'] as $kode => $pesan) {
            $this->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan', 'KodeKupon' => $kode])
                ->assertSessionHasErrors(['KodeKupon' => $pesan]);
        }

        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant, kupon: 'SEKALI');
        $this->post("/kelola/langganan/tagihan/{$tagihan->Uuid}/batalkan");
        // Tagihan batal melepas bulan kupon; dipakai lagi lalu habis.
        BuatTagihanUji($this, $this->pemilik, $this->tenant, kupon: 'SEKALI');
        TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->update(['Status' => 'Lunas']);
        $this->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan', 'KodeKupon' => 'SEKALI'])
            ->assertSessionHasErrors(['KodeKupon' => 'Kupon ini sudah Anda pakai sampai habis.']);
    });
});

describe('Isolasi tenant (CLAUDE.md #11)', function (): void {
    it('tenant lain tidak bisa melihat atau membatalkan tagihan milik tenant A', function (): void {
        $tagihanA = BuatTagihanUji($this, $this->pemilik, $this->tenant);
        ['Tenant' => $tenantB, 'Pengguna' => $pemilikB] = BantuanTagihan::DaftarTenant('budi@tokobudi.id', '081298765432', 'Toko Budi');

        BantuanTagihan::Masuk($this, $pemilikB, $tenantB);
        $this->get("/kelola/langganan/tagihan/{$tagihanA->Uuid}")->assertNotFound();
        $this->post("/kelola/langganan/tagihan/{$tagihanA->Uuid}/batalkan")->assertSessionHasErrors(['Umum' => 'Tagihan tidak ditemukan.']);
        $this->get('/kelola/langganan')->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Tagihan', 0));

        expect($tagihanA->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });
});

describe('Naik paket & perpanjangan (F-19, D-98)', function (): void {
    /** Langganan Pro Aktif, periode 30 hari dengan 10 hari tersisa (selisih prorata 10/30). */
    function AktifkanPaketProUntukUji(object $tes): void
    {
        Langganan::query()->where('IdTenant', $tes->tenant->Id)->sole()->update([
            'Status' => StatusLangganan::Aktif,
            'SiklusTagihan' => 'Bulanan',
            'PeriodeMulai' => now()->subDays(20),
            'PeriodeSelesai' => now()->addDays(10),
        ]);
    }

    it('saat Aktif, paket yang sama = perpanjangan dengan jatuh tempo di akhir periode', function (): void {
        AktifkanPaketProUntukUji($this);

        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant);
        expect($tagihan->Jenis)->toBe(JenisTagihanLangganan::Perpanjangan)
            ->and($tagihan->JatuhTempoPada->equalTo(now()->addDays(10)))->toBeTrue();
    });

    it('D-98: Pro ke Bisnis saat Aktif menerbitkan tagihan naik paket berisi selisih prorata; paket belum berganti sebelum lunas', function (): void {
        AktifkanPaketProUntukUji($this);
        $halaman = BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola/langganan')->assertOk();
        $pilihan = collect($halaman->viewData('page')['props']['PilihanPaket'])->keyBy('Kode');
        $selisih = (int) $pilihan['BISNIS']['HargaBulanan'] - (int) $pilihan['PRO']['HargaBulanan'];
        $diharapkan = (int) round($selisih * 10 / 30);

        // Pratinjau di pilihan paket = angka yang nanti ditagihkan.
        expect($pilihan['BISNIS']['BisaDipilih'])->toBeTrue()
            ->and((int) $pilihan['BISNIS']['NaikPaket']['Subtotal'])->toBe($diharapkan)
            ->and($pilihan['BISNIS']['NaikPaket']['HariDitagih'])->toBe(10)
            ->and($pilihan['BISNIS']['NaikPaket']['HariPeriode'])->toBe(30)
            ->and($pilihan['PRO']['NaikPaket'])->toBeNull()
            ->and($pilihan['STARTER']['BisaDipilih'])->toBeFalse();

        $tagihan = BuatTagihanUji($this, $this->pemilik, $this->tenant, 'BISNIS');

        expect($tagihan->Jenis)->toBe(JenisTagihanLangganan::NaikPaket)
            ->and($tagihan->Paket->Kode)->toBe('BISNIS')
            ->and($tagihan->PaketAsal()->first()?->Kode)->toBe('PRO')
            ->and((int) $tagihan->Subtotal)->toBe($diharapkan)
            ->and($tagihan->HariDitagih)->toBe(10)
            ->and($tagihan->HariPeriode)->toBe(30)
            ->and($tagihan->Diskon)->toBe('0.00')
            ->and($tagihan->JatuhTempoPada->lessThanOrEqualTo(now()->addDays(10)))->toBeTrue();
        expect(Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->Paket->Kode)->toBe('PRO');
        expect(LogAudit::query()->where('Peristiwa', 'langganan.tagihan-naik-paket')->count())->toBe(1);
    });

    it('D-98: turun paket, paket sama dengan siklus beda kupon, dan langganan tidak aktif ditolak dengan alasan jelas', function (): void {
        AktifkanPaketProUntukUji($this);
        $masuk = fn () => BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant);

        $masuk()->post('/kelola/langganan/tagihan', ['KodePaket' => 'STARTER', 'Siklus' => 'Bulanan'])->assertSessionHasErrors('KodePaket');
        expect(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->count())->toBe(0);

        $kupon = BuatKuponUji('NAIK10', 'Persen', '10', 1);
        $masuk()->post('/kelola/langganan/tagihan', ['KodePaket' => 'BISNIS', 'Siklus' => 'Bulanan', 'KodeKupon' => $kupon->Kode])->assertSessionHasErrors('KodeKupon');
        expect(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->count())->toBe(0);

        // Tertunggak: bukan naik paket (jalur aktivasi biasa), jadi tidak diblokir oleh aturan naik paket.
        Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->update(['Status' => StatusLangganan::Tertunggak]);
        expect(BuatTagihanUji($this, $this->pemilik, $this->tenant, 'BISNIS')->Jenis)->toBe(JenisTagihanLangganan::Aktivasi);
    });

    it('langganan Berhenti tidak bisa membuat tagihan', function (): void {
        BantuanTagihan::AturTenant($this->tenant);

        DB::table('Langganan')->where('IdTenant', $this->tenant->Id)->update(['Status' => 'Berhenti']);
        expect(fn () => app(BuatTagihanLangganan::class)->Jalankan($this->pemilik->Id, 'PRO', SiklusTagihan::Bulanan))
            ->toThrow(PelanggaranAturanBisnis::class, 'Langganan usaha ini sudah berhenti.');
    });
});
