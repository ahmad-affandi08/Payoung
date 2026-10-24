<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Perantara\BatasiTenantDitangguhkan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanTagihan;

/** Status diubah lewat query builder: test ini menguji akibat status, bukan transisinya (BR-00.7 diuji di P-07/P-08). */
function AturStatusLanggananUji(Tenant $tenant, StatusLangganan $status, ?string $periodeSelesai = null): void
{
    Langganan::query()->where('IdTenant', $tenant->Id)->toBase()->update([
        'Status' => $status->value,
        'PeriodeSelesai' => $periodeSelesai === null ? null : Carbon::parse($periodeSelesai, 'Asia/Jakarta')->utc(),
    ]);
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
});

describe('Banner status langganan (F-00, BR-00.7)', function (): void {
    it('Tertunggak: TenantAktif membawa status, akhir periode, dan batas masa tenggang', function (): void {
        AturStatusLanggananUji($this->tenant, StatusLangganan::Tertunggak, '2026-09-20 00:00:00');

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('TenantAktif.Nama', 'Kopi Nusantara')
                ->where('TenantAktif.StatusLangganan', 'Tertunggak')
                ->where('TenantAktif.PeriodeSelesai', '2026-09-19T17:00:00Z')
                // Masa tenggang 7 hari (config tagihan.HariMasaTenggang).
                ->where('TenantAktif.BatasTenggangPada', '2026-09-26T17:00:00Z'));
    });

    it('Ditangguhkan: status ikut terbagi; Admin tanpa langganan.kelola tidak mendapat izin bayar', function (): void {
        AturStatusLanggananUji($this->tenant, StatusLangganan::Ditangguhkan, '2026-09-01 00:00:00');
        $admin = BantuanOrganisasi::TambahAnggota($this->tenant->Id, PeranTenantBawaan::Admin);

        BantuanTagihan::Masuk($this, $admin, $this->tenant)->get('/kelola')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('TenantAktif.StatusLangganan', 'Ditangguhkan')
                ->where('TenantAktif.BatasTenggangPada', null)
                ->where('Akses.Izin', fn ($izin) => ! collect($izin)->contains('langganan.kelola')));
    });

    it('status normal (Trial) tidak memunculkan batas tenggang', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('TenantAktif.StatusLangganan', 'Trial')
                ->where('TenantAktif.BatasTenggangPada', null));
    });
});

describe('Pembatasan saat Ditangguhkan (F-00: hanya masuk, lihat, export, bayar)', function (): void {
    it('menolak perubahan data di back-office dengan masalah Umum, tetapi halaman tetap bisa dibuka', function (): void {
        AturStatusLanggananUji($this->tenant, StatusLangganan::Ditangguhkan);

        $tes = BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant);
        $tes->get('/kelola/outlet')->assertOk();
        $tes->from('/kelola/outlet')->post('/kelola/merek', ['Nama' => 'Kopi Senja'])
            ->assertRedirect('/kelola/outlet')
            ->assertSessionHasErrors(['Umum' => BatasiTenantDitangguhkan::PESAN]);

        app(KonteksTenant::class)->Atur($this->tenant->Id);
        expect(Merek::query()->where('Nama', 'Kopi Senja')->exists())->toBeFalse();
    });

    it('permintaan JSON ditolak 423 dengan format masalah seragam', function (): void {
        AturStatusLanggananUji($this->tenant, StatusLangganan::Ditangguhkan);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->postJson('/kelola/merek', ['Nama' => 'Kopi Senja'])
            ->assertStatus(423)
            ->assertJsonPath('Galat.Kode', 'TenantDitangguhkan')
            ->assertJsonPath('Galat.Pesan', BatasiTenantDitangguhkan::PESAN);
    });

    it('tetap bisa membayar: membuat tagihan langganan', function (): void {
        AturStatusLanggananUji($this->tenant, StatusLangganan::Ditangguhkan);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasNoErrors();

        expect(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->count())->toBe(1);
    });

    it('tetap bisa membuat tiket bantuan dan membuka keamanan akun', function (): void {
        AturStatusLanggananUji($this->tenant, StatusLangganan::Ditangguhkan);

        $tes = BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant);
        $tes->post('/kelola/bantuan', [
            'Kategori' => 'Tagihan',
            'Prioritas' => 'Tinggi',
            'Judul' => 'Sudah transfer tetapi masih ditangguhkan',
            'Isi' => 'Kami sudah transfer Rp 199.000 kemarin sore, mohon dicek.',
        ])->assertSessionHasNoErrors();
        // Kode salah ditolak oleh aksi 2FA (bidang Kode), bukan oleh pembatasan penangguhan.
        $tes->post('/kelola/keamanan/dua-faktor', ['Kode' => '000000'])->assertSessionDoesntHaveErrors('Umum');

        app(KonteksTenant::class)->Atur($this->tenant->Id);
        expect(TiketDukungan::query()->count())->toBe(1);
    });

    it('status lain (Tertunggak) tidak dibatasi', function (): void {
        AturStatusLanggananUji($this->tenant, StatusLangganan::Tertunggak, '2026-09-20 00:00:00');

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/merek', ['Nama' => 'Kopi Senja'])
            ->assertSessionHasNoErrors();

        app(KonteksTenant::class)->Atur($this->tenant->Id);
        expect(Merek::query()->where('Nama', 'Kopi Senja')->exists())->toBeTrue();
    });

    it('isolasi tenant: penangguhan tenant lain tidak membatasi tenant aktif', function (): void {
        $lain = BantuanTagihan::DaftarTenant('budi@tokobudi.id', '081299990000', 'Toko Budi')['Tenant'];
        AturStatusLanggananUji($lain, StatusLangganan::Ditangguhkan);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/merek', ['Nama' => 'Kopi Senja'])
            ->assertSessionHasNoErrors();
    });
});
