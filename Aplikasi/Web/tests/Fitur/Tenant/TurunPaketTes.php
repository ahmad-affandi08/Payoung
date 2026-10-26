<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PermintaanTurunPaket;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * BR-P04.4 (audit E5): turun paket mandiri. Berlaku pada perpanjangan berikutnya (atau akhir periode untuk Gratis);
 * kelebihan outlet/pengguna dipilih Owner, sisanya diarsipkan/dinonaktifkan tanpa menghapus data.
 */

function AktifkanProUntukTurunPaket(object $tes): void
{
    Langganan::query()->where('IdTenant', $tes->tenant->Id)->sole()->update([
        'Status' => StatusLangganan::Aktif,
        'SiklusTagihan' => 'Bulanan',
        'PeriodeMulai' => now()->subDays(20),
        'PeriodeSelesai' => now()->addDays(10),
    ]);
}

function AjukanTurunPaketUji(TestCase $tes, string $kode, array $tambahan = []): TestResponse
{
    return BantuanTagihan::Masuk($tes, $tes->pemilik, $tes->tenant)->post('/kelola/langganan/turun-paket', ['KodePaket' => $kode] + $tambahan);
}

function LunasiTagihanTurunUji(TestCase $tes, TagihanLangganan $tagihan): void
{
    $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
    $pembayaran = BantuanTagihan::UnggahBuktiLangsung($tes->tenant, $tes->pemilik, $tagihan);
    $tes->flushSession();
    $tes->actingAs($keuangan, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
        ->post(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/terima"), ['JumlahDiterima' => $tagihan->Total])
        ->assertSessionHasNoErrors();
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
    AktifkanProUntukTurunPaket($this);
});

describe('Turun paket mandiri (BR-P04.4)', function (): void {
    it('halaman Langganan menawarkan paket yang lebih murah dengan kelebihan kapasitasnya. Paket yang lebih mahal tidak', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola/langganan')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->where('TurunPaket.Bisa', true)
                ->where('TurunPaket.Menunggu', null)
                ->has('TurunPaket.Pilihan', 2)
                ->where('TurunPaket.Pilihan.0.Kode', 'GRATIS')
                ->where('TurunPaket.Pilihan.1.Kode', 'STARTER')
                ->where('TurunPaket.Pilihan.1.Kapasitas.Ada', false)
                ->has('TurunPaket.Outlet', 1)
                ->has('TurunPaket.Anggota', 1));
    });

    it('mengajukan turun ke Starter: tercatat Menunggu, paket belum berubah, tagihan perpanjangan berikutnya memakai Starter dan menyambung periode', function (): void {
        AjukanTurunPaketUji($this, 'STARTER')->assertSessionHasNoErrors();

        $permintaan = PermintaanTurunPaket::query()->sole();
        expect($permintaan->Status)->toBe(StatusPermintaanTurunPaket::Menunggu)
            ->and($permintaan->BerlakuPada->equalTo(now()->addDays(10)))->toBeTrue()
            ->and(Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->Paket->Kode)->toBe('PRO');
        expect(DB::table('LogAudit')->where('Peristiwa', 'langganan.turun-paket-ajukan')->count())->toBe(1);

        // Permintaan kedua ditolak; halaman menunjukkan permintaan yang menunggu.
        AjukanTurunPaketUji($this, 'GRATIS')->assertSessionHasErrors('KodePaket');
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola/langganan')
            ->assertInertia(fn (AssertableInertia $h) => $h->where('TurunPaket.Menunggu.KodePaket', 'STARTER')->where('TurunPaket.Bisa', false));

        // Owner memperpanjang "PRO": tagihan terbit untuk Starter, jenis Perpanjangan, menyebut PRO sebagai paket asal.
        $tagihan = (function () {
            BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])->assertSessionHasNoErrors();

            return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->latest('Id')->firstOrFail();
        })();
        expect($tagihan->Jenis)->toBe(JenisTagihanLangganan::Perpanjangan)
            ->and($tagihan->Paket->Kode)->toBe('STARTER')
            ->and($tagihan->PaketAsal()->first()?->Kode)->toBe('PRO');

        $akhirLama = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->PeriodeSelesai;
        LunasiTagihanTurunUji($this, $tagihan);
        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($langganan->Paket->Kode)->toBe('STARTER')
            ->and($langganan->Status)->toBe(StatusLangganan::Aktif)
            ->and($langganan->PeriodeMulai?->equalTo($akhirLama))->toBeTrue();

        $this->artisan('langganan:terapkan-turun-paket')->assertSuccessful();
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        expect(PermintaanTurunPaket::query()->sole()->Status)->toBe(StatusPermintaanTurunPaket::Diterapkan);
    });

    it('perpanjangan otomatis H-7 menagih paket tujuan, bukan paket berjalan', function (): void {
        AjukanTurunPaketUji($this, 'STARTER')->assertSessionHasNoErrors();
        Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->update(['PeriodeSelesai' => now()->addDays(5)]);

        $this->artisan('tagihan:terbitkan-perpanjangan')->assertSuccessful();

        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->sole();
        expect($tagihan->Paket->Kode)->toBe('STARTER')->and($tagihan->PaketAsal()->first()?->Kode)->toBe('PRO');
    });

    it('tagihan perpanjangan yang masih terbuka menghalangi pengajuan. Membatalkan permintaan mengembalikan perpanjangan ke paket berjalan', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])->assertSessionHasNoErrors();
        AjukanTurunPaketUji($this, 'STARTER')->assertSessionHasErrors('KodePaket');
        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->sole();
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post("/kelola/langganan/tagihan/{$tagihan->Uuid}/batalkan")->assertSessionHasNoErrors();

        AjukanTurunPaketUji($this, 'STARTER')->assertSessionHasNoErrors();
        $permintaan = PermintaanTurunPaket::query()->sole();
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post("/kelola/langganan/turun-paket/{$permintaan->Uuid}/batalkan")->assertSessionHasNoErrors();
        expect($permintaan->refresh()->Status)->toBe(StatusPermintaanTurunPaket::Dibatalkan);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])->assertSessionHasNoErrors();
        expect(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->latest('Id')->firstOrFail()->Paket->Kode)->toBe('PRO');
    });

    it('paket yang tidak lebih murah, paket sama, atau paket harga khusus ditolak', function (): void {
        AjukanTurunPaketUji($this, 'BISNIS')->assertSessionHasErrors('KodePaket');
        AjukanTurunPaketUji($this, 'PRO')->assertSessionHasErrors('KodePaket');
        AjukanTurunPaketUji($this, 'ENTERPRISE')->assertSessionHasErrors('KodePaket');
        AjukanTurunPaketUji($this, 'TIDAKADA')->assertSessionHasErrors('KodePaket');
        expect(PermintaanTurunPaket::query()->count())->toBe(0);
    });

    it('kelebihan outlet dan pengguna: wajib memilih yang tetap aktif. Saat berlaku sisanya diarsipkan/dinonaktifkan, Pemilik dan data tetap', function (): void {
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        $utama = Outlet::query()->orderBy('Id')->firstOrFail();
        $idMerek = Merek::query()->value('Id');
        $kedua = Outlet::query()->create(['IdMerek' => $idMerek, 'Kode' => 'SOLO', 'Nama' => 'Cabang Solo']);
        $ketiga = Outlet::query()->create(['IdMerek' => $idMerek, 'Kode' => 'KLTN', 'Nama' => 'Cabang Klaten']);
        $staf = [];

        for ($i = 0; $i < 5; $i++) {
            $staf[] = BantuanOrganisasi::TambahAnggota($this->tenant->Id, PeranTenantBawaan::Kasir);
        }

        BantuanOrganisasi::AturKonteks($this->tenant->Id);

        // Pratinjau memuat kelebihan: 3 outlet vs batas 1, 6 pengguna vs batas 5.
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola/langganan')
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->where('TurunPaket.Pilihan.1.Kode', 'STARTER')
                ->where('TurunPaket.Pilihan.1.Kapasitas.Lebih.Outlet', 2)
                ->where('TurunPaket.Pilihan.1.Kapasitas.Lebih.Pengguna', 1)
                ->where('TurunPaket.Pilihan.1.Kapasitas.Ada', true));

        // Tanpa pilihan, atau memilih terlalu banyak, ditolak.
        AjukanTurunPaketUji($this, 'STARTER')->assertSessionHasErrors('UuidOutletTetap');
        AjukanTurunPaketUji($this, 'STARTER', ['UuidOutletTetap' => [$kedua->Uuid, $ketiga->Uuid], 'UuidAnggotaTetap' => [$staf[0]->Uuid]])->assertSessionHasErrors('UuidOutletTetap');
        expect(PermintaanTurunPaket::query()->count())->toBe(0);

        AjukanTurunPaketUji($this, 'STARTER', [
            'UuidOutletTetap' => [$kedua->Uuid],
            'UuidAnggotaTetap' => [$staf[0]->Uuid, $staf[1]->Uuid, $staf[2]->Uuid, $staf[3]->Uuid],
        ])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        $permintaan = PermintaanTurunPaket::query()->sole();
        expect($permintaan->UuidOutletTetap)->toBe([$kedua->Uuid])
            ->and($permintaan->Kelebihan['Lebih']['Outlet'])->toBe(2);

        // Belum berlaku: semua masih aktif.
        $this->artisan('langganan:terapkan-turun-paket')->assertSuccessful();
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        expect(Outlet::query()->where('Status', StatusOrganisasi::Aktif->value)->count())->toBe(3);

        // Perpanjangan ke Starter lunas, lalu penerapan.
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])->assertSessionHasNoErrors();
        LunasiTagihanTurunUji($this, TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->latest('Id')->firstOrFail());
        $this->artisan('langganan:terapkan-turun-paket')->assertSuccessful();

        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        expect(Outlet::query()->where('Status', StatusOrganisasi::Aktif->value)->pluck('Uuid')->all())->toBe([$kedua->Uuid])
            ->and(Outlet::query()->whereKey($utama->Id)->value('Status'))->toBe(StatusOrganisasi::Diarsipkan)
            ->and(Outlet::query()->count())->toBe(3);
        $aktif = TenantPengguna::query()->where('IdTenant', $this->tenant->Id)->where('Status', StatusKeanggotaan::Aktif->value)->count();
        $pemilikAktif = TenantPengguna::query()->where('IdTenant', $this->tenant->Id)->where('Pemilik', true)->value('Status');
        expect($aktif)->toBe(5)
            ->and($pemilikAktif)->toBe(StatusKeanggotaan::Aktif)
            ->and(TenantPengguna::query()->where('IdPengguna', Pengguna::query()->where('Uuid', $staf[4]->Uuid)->value('Id'))->value('Status'))->toBe(StatusKeanggotaan::Nonaktif)
            ->and(PermintaanTurunPaket::query()->sole()->Status)->toBe(StatusPermintaanTurunPaket::Diterapkan)
            ->and(DB::table('LogAudit')->where('Peristiwa', 'langganan.kapasitas-dibatasi')->count())->toBe(1);
    });

    it('turun ke Gratis: tanpa tagihan perpanjangan, langganan turun saat periode berakhir dan kapasitas Gratis diterapkan', function (): void {
        AjukanTurunPaketUji($this, 'GRATIS')->assertSessionHasNoErrors();

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])->assertSessionHasErrors('KodePaket');
        Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->update(['PeriodeSelesai' => now()->addDays(5)]);
        $this->artisan('tagihan:terbitkan-perpanjangan')->assertSuccessful();
        expect(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->count())->toBe(0);

        // Sebelum periode berakhir tidak berubah.
        $this->artisan('langganan:terapkan-turun-paket')->assertSuccessful();
        expect(Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->Status)->toBe(StatusLangganan::Aktif);

        $this->travelTo(now()->addDays(6));
        $this->artisan('langganan:terapkan-turun-paket')->assertSuccessful();

        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect($langganan->Status)->toBe(StatusLangganan::Gratis)
            ->and($langganan->Paket->Kode)->toBe('GRATIS')
            ->and(PermintaanTurunPaket::query()->sole()->Status)->toBe(StatusPermintaanTurunPaket::Diterapkan);
    });

    it('naik paket menggantikan rencana turun paket yang menunggu', function (): void {
        AjukanTurunPaketUji($this, 'STARTER')->assertSessionHasNoErrors();

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/tagihan', ['KodePaket' => 'BISNIS', 'Siklus' => 'Bulanan'])->assertSessionHasNoErrors();

        expect(PermintaanTurunPaket::query()->sole()->Status)->toBe(StatusPermintaanTurunPaket::Dibatalkan)
            ->and(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->latest('Id')->firstOrFail()->Jenis)->toBe(JenisTagihanLangganan::NaikPaket);
    });

    it('permintaan menjadi basi (paket berganti lewat jalur lain) dibatalkan saat penerapan, tanpa mengubah apa pun', function (): void {
        AjukanTurunPaketUji($this, 'GRATIS')->assertSessionHasNoErrors();
        $bisnis = DB::table('Paket')->where('Kode', 'BISNIS')->value('Id');
        Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->update(['IdPaket' => $bisnis]);

        $this->artisan('langganan:terapkan-turun-paket')->assertSuccessful();

        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        expect(PermintaanTurunPaket::query()->sole()->Status)->toBe(StatusPermintaanTurunPaket::Dibatalkan)
            ->and(Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->Status)->toBe(StatusLangganan::Aktif);
    });

    it('ditangguhkan karena tunggakan: pindah ke Gratis seketika membatalkan tagihan terbuka dan menyesuaikan kapasitas. Penangguhan manual ditolak', function (): void {
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        $utama = Outlet::query()->orderBy('Id')->firstOrFail();
        $kedua = Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => 'SOLO', 'Nama' => 'Cabang Solo']);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])->assertSessionHasNoErrors();
        Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->update(['Status' => StatusLangganan::Tertunggak]);
        Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->update(['Status' => StatusLangganan::Ditangguhkan]);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola/langganan')
            ->assertInertia(fn (AssertableInertia $h) => $h->where('TurunPaket.PindahGratis.Kapasitas.Lebih.Outlet', 1)->has('TurunPaket.Outlet', 2));

        // Kelebihan outlet Gratis (batas 1) wajib dipilih.
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/pindah-gratis', [])->assertSessionHasErrors('UuidOutletTetap');
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/pindah-gratis', ['UuidOutletTetap' => [$kedua->Uuid]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect($langganan->Status)->toBe(StatusLangganan::Gratis)
            ->and($langganan->Paket->Kode)->toBe('GRATIS')
            ->and(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->sole()->Status)->toBe(StatusTagihanLangganan::Dibatalkan)
            ->and(Outlet::query()->where('Status', StatusOrganisasi::Aktif->value)->pluck('Uuid')->all())->toBe([$kedua->Uuid])
            ->and(Outlet::query()->whereKey($utama->Id)->value('Status'))->toBe(StatusOrganisasi::Diarsipkan)
            ->and(DB::table('LogAudit')->where('Peristiwa', 'langganan.pindah-gratis')->count())->toBe(1);

        // Penangguhan manual oleh Super Admin tidak bisa dilewati Owner.
        $langganan->update(['Status' => StatusLangganan::Ditangguhkan, 'StatusSebelumDitangguhkan' => StatusLangganan::Aktif]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post('/kelola/langganan/pindah-gratis', [])->assertSessionHasErrors('Umum');
    });

    it('hanya Pemilik (langganan.kelola) yang bisa mengajukan. Isolasi tenant', function (): void {
        $kasir = BantuanOrganisasi::TambahAnggota($this->tenant->Id, PeranTenantBawaan::Kasir);
        BantuanTagihan::Masuk($this, $kasir, $this->tenant)->post('/kelola/langganan/turun-paket', ['KodePaket' => 'STARTER'])->assertForbidden();

        ['Tenant' => $lain, 'Pengguna' => $pemilikLain] = BantuanTagihan::DaftarTenant('budi@tokobudi.id', '081298765432', 'Toko Budi');
        Langganan::query()->where('IdTenant', $lain->Id)->sole()->update(['Status' => StatusLangganan::Aktif, 'SiklusTagihan' => 'Bulanan', 'PeriodeMulai' => now()->subDays(20), 'PeriodeSelesai' => now()->addDays(10)]);
        BantuanTagihan::Masuk($this, $pemilikLain, $lain)->post('/kelola/langganan/turun-paket', ['KodePaket' => 'STARTER'])->assertSessionHasNoErrors();

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola/langganan')
            ->assertInertia(fn (AssertableInertia $h) => $h->where('TurunPaket.Menunggu', null));
    });
});
