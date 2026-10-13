<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Layanan\PembuatNomorTiket;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Dukungan\Model\TiketDukunganPesan;
use App\Domain\Pengelola\Dukungan\Aksi\BalasTiketDukunganPengelola;
use App\Domain\Pengelola\Dukungan\Surel\BalasanPelaporTiketDukungan;
use App\Domain\Pengelola\Dukungan\Surel\TiketDukunganBaru;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Dukungan\BantuanDukungan;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function IsianTiketUji(array $ubah = []): array
{
    return [
        'Kategori' => 'Perangkat',
        'Prioritas' => 'Normal',
        'Judul' => 'Printer struk Outlet Kemang tidak mencetak',
        'Isi' => "Sejak pukul 07.00 printer Bluetooth di kasir 2 tidak mencetak struk.\r\nSudah restart tablet dan printer.",
        'HalamanAsal' => '/kelola',
        ...$ubah,
    ];
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 09:00:00', 'Asia/Jakarta'));
    Mail::fake();
    Storage::fake('local');
});

describe('Membuat tiket dari back-office (P-09)', function (): void {
    it('membuat tiket bernomor unik, pesan pertama, batas SLA paket, dan memberi tahu tim Dukungan', function (): void {
        $dukungan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = BantuanDukungan::BuatTenant();

        $respons = BantuanDukungan::MasukSebagaiTenant($this, $pengguna, $tenant)->post('/kelola/bantuan', IsianTiketUji());

        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket = TiketDukungan::query()->sole();
        $respons->assertRedirect("/kelola/bantuan/{$tiket->Uuid}")->assertSessionHas('Kilat');

        expect($tiket->Nomor)->toBe('TKT-2026-000001')
            ->and($tiket->Status)->toBe(StatusTiketDukungan::Baru)
            ->and($tiket->IdPelapor)->toBe($pengguna->Id)
            // Trial bawaan paket PRO, prioritas Normal = 8 jam (config dukungan.SlaResponsPertamaJam).
            ->and($tiket->JamSla)->toBe(8)
            ->and($tiket->BatasSlaPada->toIso8601String())->toBe(Carbon::parse('2026-09-24 17:00:00', 'Asia/Jakarta')->utc()->toIso8601String())
            ->and($tiket->Konteks)->toMatchArray(['HalamanAsal' => '/kelola']);

        $pesan = TiketDukunganPesan::query()->sole();
        expect($pesan->Isi)->toBe("Sejak pukul 07.00 printer Bluetooth di kasir 2 tidak mencetak struk.\nSudah restart tablet dan printer.")
            ->and($pesan->NamaPengirim)->toBe('Rina Wulandari')
            ->and($pesan->IdTenant)->toBe($tenant->Id);

        Mail::assertSent(TiketDukunganBaru::class, fn (TiketDukunganBaru $surel) => $surel->hasTo($dukungan->Email)
            && $surel->nomor === 'TKT-2026-000001'
            && $surel->namaTenant === 'Kopi Nusantara');
    });

    it('SLA per paket & prioritas: Gratis tetap 2 hari kerja (18 jam kerja) walau mendesak, Pro mendesak 4 jam', function (): void {
        ['Tenant' => $gratis, 'Pengguna' => $pemilikGratis] = BantuanDukungan::BuatTenant('Warung Bu Sri', 'sri@warung.id', 'GRATIS');
        ['Tenant' => $pro, 'Pengguna' => $pemilikPro] = BantuanDukungan::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id', 'PRO');

        expect(BantuanDukungan::BuatTiket($gratis, $pemilikGratis, prioritas: PrioritasTiketDukungan::Mendesak)->JamSla)->toBe(18)
            ->and(BantuanDukungan::BuatTiket($pro, $pemilikPro, prioritas: PrioritasTiketDukungan::Mendesak)->JamSla)->toBe(4)
            ->and(BantuanDukungan::BuatTiket($pro, $pemilikPro, prioritas: PrioritasTiketDukungan::Rendah)->JamSla)->toBe(8);
    });

    it('nomor tiket berurut untuk seluruh tenant dan mulai lagi dari 1 di tahun baru (WIB)', function (): void {
        ['Tenant' => $a, 'Pengguna' => $pa] = BantuanDukungan::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
        ['Tenant' => $b, 'Pengguna' => $pb] = BantuanDukungan::BuatTenant('Laundry Bersih', 'budi@laundry.id');

        expect(BantuanDukungan::BuatTiket($a, $pa)->Nomor)->toBe('TKT-2026-000001')
            ->and(BantuanDukungan::BuatTiket($b, $pb)->Nomor)->toBe('TKT-2026-000002');

        // 31 Des 2026 23.30 WIB masih tahun 2026; 1 Jan 2027 00.10 WIB (masih 31 Des UTC) sudah 2027.
        expect(DB::transaction(fn () => app(PembuatNomorTiket::class)->Buat(Carbon::parse('2026-12-31 23:30', 'Asia/Jakarta'))))->toBe('TKT-2026-000003')
            ->and(DB::transaction(fn () => app(PembuatNomorTiket::class)->Buat(Carbon::parse('2027-01-01 00:10', 'Asia/Jakarta')->utc())))->toBe('TKT-2027-000001');
    });

    it('menolak isian tidak lengkap dan lampiran berbahaya atau terlalu besar', function (array $ubah, string $bidang): void {
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = BantuanDukungan::BuatTenant();

        BantuanDukungan::MasukSebagaiTenant($this, $pengguna, $tenant)->post('/kelola/bantuan', IsianTiketUji($ubah))->assertSessionHasErrors($bidang);

        expect(DB::table('TiketDukungan')->count())->toBe(0);
    })->with([
        'judul kosong' => [['Judul' => ''], 'Judul'],
        'isi terlalu pendek' => [['Isi' => 'rusak'], 'Isi'],
        'kategori asing' => [['Kategori' => 'Hacking'], 'Kategori'],
        'lampiran exe' => [fn () => ['Lampiran' => [UploadedFile::fake()->create('pembaruan.exe', 10, 'application/x-msdownload')]], 'Lampiran.0'],
        'lampiran > 5 MB' => [fn () => ['Lampiran' => [UploadedFile::fake()->create('rekaman.pdf', 6000, 'application/pdf')]], 'Lampiran.0'],
        'lampiran > 3 berkas' => [fn () => ['Lampiran' => array_map(fn ($i) => UploadedFile::fake()->image("layar{$i}.png"), range(1, 4))], 'Lampiran'],
    ]);

    it('menyimpan lampiran di disk privat dan hanya bisa diunduh lewat tiketnya', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = BantuanDukungan::BuatTenant();
        BantuanDukungan::MasukSebagaiTenant($this, $pengguna, $tenant)
            ->post('/kelola/bantuan', IsianTiketUji(['Lampiran' => [UploadedFile::fake()->image('../../struk rusak.png')]]))
            ->assertSessionHasNoErrors();

        $tiket = DB::table('TiketDukungan')->sole();
        $lampiran = json_decode((string) DB::table('TiketDukunganPesan')->value('Lampiran'), true)[0];

        expect($lampiran['NamaAsli'])->toBe('struk rusak.png')
            ->and($lampiran['Path'])->toStartWith("dukungan/{$tenant->Id}/{$tiket->Uuid}/")
            ->and($lampiran['Path'])->not->toContain('struk');
        Storage::disk('local')->assertExists($lampiran['Path']);

        $this->get("/kelola/bantuan/{$tiket->Uuid}/lampiran/{$lampiran['Uuid']}")->assertOk()->assertDownload('struk rusak.png');
        $this->get("/kelola/bantuan/{$tiket->Uuid}/lampiran/01JTIDAKADA0000000000000000")->assertNotFound();
    });

    it('wajib masuk dan punya tenant aktif', function (): void {
        $this->get('/kelola/bantuan')->assertRedirect('/masuk');
        $this->post('/kelola/bantuan', IsianTiketUji())->assertRedirect('/masuk');
    });
});

describe('Isolasi tenant (PRD §13.4)', function (): void {
    it('tenant lain tidak bisa melihat, membalas, menyelesaikan, atau mengunduh lampiran tiket walau Uuid diketahui', function (): void {
        ['Tenant' => $a, 'Pengguna' => $pa] = BantuanDukungan::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
        ['Tenant' => $b, 'Pengguna' => $pb] = BantuanDukungan::BuatTenant('Laundry Bersih', 'budi@laundry.id');
        BantuanDukungan::MasukSebagaiTenant($this, $pa, $a)
            ->post('/kelola/bantuan', IsianTiketUji(['Judul' => 'Rahasia tenant A', 'Lampiran' => [UploadedFile::fake()->image('a.png')]]));
        $tiket = DB::table('TiketDukungan')->sole();
        $lampiran = json_decode((string) DB::table('TiketDukunganPesan')->value('Lampiran'), true)[0];

        BantuanDukungan::MasukSebagaiTenant($this, $pb, $b);
        $this->get('/kelola/bantuan?saring[Keadaan]=Semua')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Bantuan/Daftar')->where('Tiket.Meta.Total', 0));
        $this->get("/kelola/bantuan/{$tiket->Uuid}")->assertNotFound();
        $this->post("/kelola/bantuan/{$tiket->Uuid}/balasan", ['Isi' => 'Coba masuk'])->assertNotFound();
        $this->post("/kelola/bantuan/{$tiket->Uuid}/selesaikan")->assertNotFound();
        $this->get("/kelola/bantuan/{$tiket->Uuid}/lampiran/{$lampiran['Uuid']}")->assertNotFound();

        expect(DB::table('TiketDukunganPesan')->count())->toBe(1)
            ->and(DB::table('TiketDukungan')->value('Status'))->toBe('Baru');
    });

    it('menampilkan daftar & percakapan tanpa catatan internal tim', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = BantuanDukungan::BuatTenant();
        $tiket = BantuanDukungan::BuatTiket($tenant, $pengguna);
        app(BalasTiketDukunganPengelola::class)->Jalankan($petugas, $tiket->Uuid, 'Catatan internal: kemungkinan firmware printer lama.', true);
        app(BalasTiketDukunganPengelola::class)->Jalankan($petugas, $tiket->Uuid, 'Mohon kirim foto layar pengaturan printer.', false);

        BantuanDukungan::MasukSebagaiTenant($this, $pengguna, $tenant);
        $this->get('/kelola/bantuan')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Kelola/Bantuan/Daftar')
            ->where('Tiket.Meta.Total', 1)
            ->where('Tiket.Data.0.Nomor', $tiket->Nomor)
            ->where('Tiket.Data.0.Status', 'Ditangani'));

        $this->get("/kelola/bantuan/{$tiket->Uuid}")
            ->assertDontSee('firmware printer lama')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Bantuan/Tiket')
                ->has('Tiket.Pesan', 2)
                ->where('Tiket.Pesan.1.Isi', 'Mohon kirim foto layar pengaturan printer.')
                ->where('Tiket.Pesan.1.NamaPengirim', "{$petugas->Nama} | Tim Dukungan"));
    });
});

describe('Membalas & menyelesaikan tiket', function (): void {
    it('balasan saat menunggu pelanggan mengembalikan tiket ke Ditangani dan memberi tahu penanggung jawab', function (): void {
        $petugas = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = BantuanDukungan::BuatTenant();
        $tiket = BantuanDukungan::BuatTiket($tenant, $pengguna);
        app(BalasTiketDukunganPengelola::class)->Jalankan($petugas, $tiket->Uuid, 'Mohon kirim foto.', false, statusBaru: StatusTiketDukungan::MenungguPelanggan);

        BantuanDukungan::MasukSebagaiTenant($this, $pengguna, $tenant)
            ->post("/kelola/bantuan/{$tiket->Uuid}/balasan", ['Isi' => 'Foto terlampir.', 'Lampiran' => [UploadedFile::fake()->image('pengaturan.jpg')]])
            ->assertSessionHasNoErrors();

        expect(BantuanDukungan::MuatUlang($tiket)->Status)->toBe(StatusTiketDukungan::Ditangani);
        Mail::assertSent(BalasanPelaporTiketDukungan::class, fn (BalasanPelaporTiketDukungan $surel) => $surel->hasTo($petugas->Email) && ! $surel->dibukaLagi);
    });

    it('tiket selesai bisa dibuka lagi dalam 7 hari, setelah itu dan saat ditutup ditolak', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pengguna] = BantuanDukungan::BuatTenant();
        $tiket = BantuanDukungan::BuatTiket($tenant, $pengguna);
        BantuanDukungan::MasukSebagaiTenant($this, $pengguna, $tenant);

        $this->post("/kelola/bantuan/{$tiket->Uuid}/selesaikan")->assertSessionHasNoErrors();
        expect(BantuanDukungan::MuatUlang($tiket)->Status)->toBe(StatusTiketDukungan::Selesai);

        $this->travel(6)->days();
        $this->post("/kelola/bantuan/{$tiket->Uuid}/balasan", ['Isi' => 'Masalahnya muncul lagi.'])->assertSessionHasNoErrors();
        expect(BantuanDukungan::MuatUlang($tiket)->Status)->toBe(StatusTiketDukungan::Ditangani);

        $this->post("/kelola/bantuan/{$tiket->Uuid}/selesaikan");
        $this->travel(8)->days();
        $this->post("/kelola/bantuan/{$tiket->Uuid}/balasan", ['Isi' => 'Muncul lagi minggu ini.'])->assertSessionHasErrors('Isi');
        expect(BantuanDukungan::MuatUlang($tiket)->Status)->toBe(StatusTiketDukungan::Selesai);

        $this->artisan('pengelola:tutup-tiket-selesai')->assertSuccessful();
        expect(BantuanDukungan::MuatUlang($tiket)->Status)->toBe(StatusTiketDukungan::Ditutup);
        $this->post("/kelola/bantuan/{$tiket->Uuid}/balasan", ['Isi' => 'Halo?'])->assertSessionHasErrors('Isi');
        $this->post("/kelola/bantuan/{$tiket->Uuid}/selesaikan")->assertSessionHasErrors('Umum');
    });
});
