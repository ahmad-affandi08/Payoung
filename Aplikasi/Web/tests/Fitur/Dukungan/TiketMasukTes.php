<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Layanan\PembersihPesanMasuk;
use App\Domain\Dukungan\Model\PesanMasukDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pengelola\Dukungan\Surel\TiketDukunganBaru;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Dukungan\BantuanDukungan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/*
 * PGL-15 (PRD v6.28, D-149): pesan email/WhatsApp masuk menjadi tiket baru atau balasan lewat `POST /webhook/dukungan/masuk`.
 * Dibuktikan: tanda tangan HMAC + tanda waktu (tanpa rahasia = 404), pengirim harus akun yang dikenal (email hanya bila
 * SPF/DKIM lolos), idempoten per id pesan, balasan hanya ke tiket tenant tempat pengirim menjadi anggota, anggota banyak
 * usaha diminta menyebut nomor tiket, kutipan balasan dipangkas, batas harian, dan isolasi tenant.
 */

const RAHASIA_MASUK = 'rahasia-uji-dukungan-masuk-0123456789abcdef';

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    Mail::fake();
    Cache::flush();
    config(['dukungan.MasukRahasia' => RAHASIA_MASUK]);
});

/**
 * Kirim pesan masuk bertanda tangan sah ke pintu masuk.
 *
 * @param  array<string, mixed>  $badan
 */
function KirimPesanMasuk($tes, array $badan, ?string $rahasia = RAHASIA_MASUK, ?int $waktu = null): TestResponse
{
    $mentah = json_encode($badan, JSON_THROW_ON_ERROR);
    $waktu = (string) ($waktu ?? time());
    $header = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Waktu-Kirim' => $waktu];

    if ($rahasia !== null) {
        $header['X-Tanda-Tangan'] = 'sha256='.hash_hmac('sha256', $waktu.'.'.$mentah, $rahasia);
    }

    return $tes->call('POST', '/webhook/dukungan/masuk', [], [], [], collect($header)->mapWithKeys(fn ($nilai, $nama) => ['HTTP_'.strtoupper(str_replace('-', '_', $nama)) => $nilai])->all() + ['CONTENT_TYPE' => 'application/json'], $mentah);
}

/** @return array<string, mixed> */
function PesanEmail(string $dari, array $ubah = []): array
{
    return [
        'Kanal' => 'Email',
        'Dari' => $dari,
        'IdPesan' => '<'.bin2hex(random_bytes(6)).'@mail.contoh.id>',
        'Subjek' => 'Printer struk tidak mencetak',
        'Isi' => "Selamat pagi,\n\nSejak pagi printer Bluetooth di kasir tidak mencetak struk.\nMohon bantuannya.",
        'Terverifikasi' => true,
        ...$ubah,
    ];
}

describe('Pintu masuk dan tanda tangan', function (): void {
    it('tanpa rahasia terpasang pintu masuk mati (404). Tanda tangan salah, hilang, atau basi ditolak 401', function (): void {
        config(['dukungan.MasukRahasia' => '']);
        KirimPesanMasuk($this, PesanEmail('rina@kopinusantara.id'))->assertNotFound();

        config(['dukungan.MasukRahasia' => RAHASIA_MASUK]);
        KirimPesanMasuk($this, PesanEmail('rina@kopinusantara.id'), 'rahasia-lain')->assertUnauthorized()->assertJsonPath('Galat.Kode', 'TandaTanganTidakSah');
        KirimPesanMasuk($this, PesanEmail('rina@kopinusantara.id'), null)->assertUnauthorized();
        KirimPesanMasuk($this, PesanEmail('rina@kopinusantara.id'), waktu: time() - 301)->assertUnauthorized();
        KirimPesanMasuk($this, PesanEmail('rina@kopinusantara.id'), waktu: time() + 301)->assertUnauthorized();
        expect(PesanMasukDukungan::query()->count())->toBe(0);
    });

    it('badan cacat 422. Kanal selain Email/WhatsApp, nomor tiket aneh, dan isi kosong ditolak validasi', function (): void {
        KirimPesanMasuk($this, ['Kanal' => 'Telegram', 'Dari' => 'x', 'IdPesan' => '1', 'Isi' => 'halo'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ValidasiGagal');
        KirimPesanMasuk($this, PesanEmail('rina@kopinusantara.id', ['NomorTiket' => 'TKT-1']))->assertStatus(422);
        KirimPesanMasuk($this, PesanEmail('rina@kopinusantara.id', ['Isi' => '']))->assertStatus(422);
        KirimPesanMasuk($this, ['Kanal' => 'Email'])->assertStatus(422);
    });
});

describe('Tiket baru dari email', function (): void {
    it('anggota satu usaha: tiket Baru berkanal Email dengan judul dari subjek, SLA, dan tim Dukungan diberi tahu', function (): void {
        $dukungan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Kopi Masuk', 'rina@kopimasuk.id');

        $respons = KirimPesanMasuk($this, PesanEmail('Rina@KopiMasuk.id', ['Subjek' => 'Re: Fwd: Printer struk tidak mencetak']))->assertOk();
        $nomor = (string) $respons->json('NomorTiket');

        expect($respons->json('Hasil'))->toBe('TiketBaru')
            ->and($nomor)->toMatch('/^TKT-2026-\d{6}$/')
            ->and($respons->json('Duplikat'))->toBeFalse();

        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket = TiketDukungan::query()->where('Nomor', $nomor)->sole();
        expect($tiket->Kanal)->toBe(KanalTiketDukungan::Email)
            ->and($tiket->Status)->toBe(StatusTiketDukungan::Baru)
            ->and($tiket->IdPelapor)->toBe($pemilik->Id)
            ->and($tiket->Judul)->toBe('Printer struk tidak mencetak')
            ->and($tiket->Pesan()->count())->toBe(1)
            ->and($tiket->BatasSlaPada)->not->toBeNull()
            ->and($tiket->Konteks['PesanMasuk'] ?? null)->toBeTrue();
        Mail::assertSent(TiketDukunganBaru::class, fn (TiketDukunganBaru $surel) => $surel->hasTo($dukungan->Email));
    });

    it('pengirim tak dikenal, bukan SPF/DKIM-lolos, dan akun tanpa usaha ditolak dengan kode dan tidak membuat tiket', function (): void {
        ['Tenant' => $tenant] = BantuanDukungan::BuatTenant('Kopi Tolak', 'rina@kopitolak.id');

        KirimPesanMasuk($this, PesanEmail('orang.asing@contoh.id'))->assertOk()->assertJsonPath('Hasil', 'Ditolak')->assertJsonPath('Kode', 'PengirimTidakDikenal');
        KirimPesanMasuk($this, PesanEmail('rina@kopitolak.id', ['Terverifikasi' => false]))->assertOk()->assertJsonPath('Kode', 'PengirimTidakTerverifikasi');
        KirimPesanMasuk($this, PesanEmail('rina@kopitolak.id', ['Isi' => '   ']))->assertStatus(422);

        app(KonteksTenant::class)->Atur($tenant->Id);
        expect(TiketDukungan::query()->count())->toBe(0);
        expect(PesanMasukDukungan::query()->where('Hasil', 'Ditolak')->count())->toBe(2);
    });

    it('idempoten per id pesan: pengiriman ulang mengembalikan hasil yang sama dan menandai Duplikat tanpa tiket ganda', function (): void {
        ['Tenant' => $tenant] = BantuanDukungan::BuatTenant('Kopi Ulang', 'rina@kopiulang.id');
        $pesan = PesanEmail('rina@kopiulang.id', ['IdPesan' => '<tetap@mail.contoh.id>']);

        $pertama = KirimPesanMasuk($this, $pesan)->assertOk();
        $kedua = KirimPesanMasuk($this, $pesan)->assertOk();

        expect($kedua->json('Hasil'))->toBe('TiketBaru')
            ->and($kedua->json('NomorTiket'))->toBe($pertama->json('NomorTiket'))
            ->and($kedua->json('Duplikat'))->toBeTrue();

        app(KonteksTenant::class)->Atur($tenant->Id);
        expect(TiketDukungan::query()->count())->toBe(1);

        // Id pesan yang sama di kanal lain adalah pesan lain.
        expect(PesanMasukDukungan::query()->count())->toBe(1);
    });
});

describe('Balasan', function (): void {
    it('balasan email dengan nomor tiket di subjek masuk sebagai pesan pengguna. Kutipan riwayat dipangkas. Tiket Selesai dibuka lagi', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Kopi Balas', 'rina@kopibalas.id');
        $tiket = BantuanDukungan::BuatTiket($tenant, $pemilik);
        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket->forceFill(['Status' => StatusTiketDukungan::MenungguPelanggan])->save();

        $respons = KirimPesanMasuk($this, PesanEmail('rina@kopibalas.id', [
            'Subjek' => "Re: Balasan tiket {$tiket->Nomor}: Printer struk",
            'Isi' => "Sudah saya coba, masih belum jalan.\n\nPada Sel, 14 Okt 2026 09.00 Tim Dukungan menulis:\n> Coba matikan printer dulu\n> lalu nyalakan lagi",
        ]))->assertOk();

        expect($respons->json('Hasil'))->toBe('Balasan')->and($respons->json('NomorTiket'))->toBe($tiket->Nomor);
        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket->refresh();
        $pesan = $tiket->Pesan()->orderByDesc('Id')->first();
        expect($tiket->Status)->toBe(StatusTiketDukungan::Ditangani)
            ->and($pesan->Isi)->toBe('Sudah saya coba, masih belum jalan.')
            ->and(TiketDukungan::query()->count())->toBe(1);
    });

    it('nomor tiket di isi WhatsApp atau payload. Tiket tenant lain tidak ditemukan. Tiket ditutup tidak bisa dibalas', function (): void {
        ['Tenant' => $tenantA, 'Pengguna' => $pemilikA] = BantuanDukungan::BuatTenant('Kopi Alfa', 'alfa@kopialfa.id');
        ['Tenant' => $tenantB, 'Pengguna' => $pemilikB] = BantuanDukungan::BuatTenant('Kopi Beta', 'beta@kopibeta.id');
        $tiketA = BantuanDukungan::BuatTiket($tenantA, $pemilikA);
        $tiketB = BantuanDukungan::BuatTiket($tenantB, $pemilikB);

        // Pemilik A membalas lewat WhatsApp dengan nomor di isi.
        KirimPesanMasuk($this, ['Kanal' => 'WhatsApp', 'Dari' => (string) $pemilikA->NoHp, 'IdPesan' => 'wamid.1', 'Subjek' => '', 'Isi' => "Soal {$tiketA->Nomor}: printernya sudah dicoba ganti kabel."])
            ->assertOk()->assertJsonPath('Hasil', 'Balasan')->assertJsonPath('NomorTiket', $tiketA->Nomor);

        // Pemilik A menyebut nomor tiket milik tenant B: tidak terbaca.
        KirimPesanMasuk($this, PesanEmail('alfa@kopialfa.id', ['Subjek' => "Re: {$tiketB->Nomor}"]))
            ->assertOk()->assertJsonPath('Hasil', 'Ditolak')->assertJsonPath('Kode', 'TiketTidakDitemukan');

        app(KonteksTenant::class)->Atur($tenantB->Id);
        expect(TiketDukungan::query()->whereKey($tiketB->Id)->first()->Pesan()->count())->toBe(1);

        app(KonteksTenant::class)->Atur($tenantA->Id);
        TiketDukungan::query()->whereKey($tiketA->Id)->first()->forceFill(['Status' => StatusTiketDukungan::Ditutup])->save();
        KirimPesanMasuk($this, PesanEmail('alfa@kopialfa.id', ['NomorTiket' => $tiketA->Nomor]))
            ->assertOk()->assertJsonPath('Hasil', 'Ditolak')->assertJsonPath('Kode', 'TiketTidakBisaDibalas');
    });
});

describe('WhatsApp dan usaha ganda', function (): void {
    it('nomor WhatsApp anggota satu usaha membuat tiket berkanal WhatsApp dengan judul dari awal pesan', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Kopi Wa', 'rina@kopiwa.id');
        $nomor = (string) preg_replace('/^0/', '62', (string) $pemilik->NoHp);

        $respons = KirimPesanMasuk($this, ['Kanal' => 'WhatsApp', 'Dari' => $nomor, 'IdPesan' => 'wamid.77', 'Isi' => 'Halo, tagihan langganan saya bulan ini kok naik ya?'])->assertOk();
        expect($respons->json('Hasil'))->toBe('TiketBaru');

        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket = TiketDukungan::query()->sole();
        expect($tiket->Kanal)->toBe(KanalTiketDukungan::WhatsApp)->and($tiket->Judul)->toBe('Halo, tagihan langganan saya bulan ini kok naik ya?');
    });

    it('anggota beberapa usaha tanpa nomor tiket diminta lewat aplikasi (UsahaAmbigu), dengan nomor tiket tetap bisa membalas', function (): void {
        ['Tenant' => $tenantA, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Kopi Dua A', 'dua@kopidua.id');
        ['Tenant' => $tenantB] = BantuanDukungan::BuatTenant('Kopi Dua B', 'lain@kopiduab.id');
        TenantPengguna::query()->create([
            'IdTenant' => $tenantB->Id,
            'IdPengguna' => $pemilik->Id,
            'Pemilik' => false,
            'IdPeran' => BantuanOrganisasi::Peran($tenantB->Id, PeranTenantBawaan::Kasir)->Id,
            'SemuaOutlet' => true,
        ]);
        $tiket = BantuanDukungan::BuatTiket($tenantB, $pemilik);

        KirimPesanMasuk($this, PesanEmail('dua@kopidua.id'))->assertOk()->assertJsonPath('Hasil', 'Ditolak')->assertJsonPath('Kode', 'UsahaAmbigu');
        KirimPesanMasuk($this, PesanEmail('dua@kopidua.id', ['Subjek' => "Re: {$tiket->Nomor}"]))->assertOk()->assertJsonPath('Hasil', 'Balasan')->assertJsonPath('NomorTiket', $tiket->Nomor);

        app(KonteksTenant::class)->Atur($tenantA->Id);
        expect(TiketDukungan::query()->count())->toBe(0);
    });

    it('nomor WhatsApp yang dipakai dua akun dianggap ambigu dan ditolak', function (): void {
        ['Pengguna' => $pemilikA] = BantuanDukungan::BuatTenant('Kopi Kembar A', 'a@kopikembar.id');
        ['Pengguna' => $pemilikB] = BantuanDukungan::BuatTenant('Kopi Kembar B', 'b@kopikembarb.id');
        $nomor = (string) preg_replace('/^0/', '62', (string) $pemilikA->NoHp);
        // Nomor sama dalam penulisan lain (kunci unik hanya melihat teksnya): kecocokan menjadi tidak tunggal.
        $pemilikB->forceFill(['NoHp' => '+'.$nomor])->save();

        KirimPesanMasuk($this, ['Kanal' => 'WhatsApp', 'Dari' => $nomor, 'IdPesan' => 'wamid.kembar', 'Isi' => 'Tolong cek tagihan saya'])
            ->assertOk()->assertJsonPath('Hasil', 'Ditolak')->assertJsonPath('Kode', 'PengirimTidakDikenal');
    });
});

describe('Pembatasan', function (): void {
    it('pengirim yang melewati batas harian ditolak TerlaluBanyakPesan tanpa tiket tambahan', function (): void {
        config(['dukungan.MasukBatasPerPengirimHarian' => 2]);
        ['Tenant' => $tenant] = BantuanDukungan::BuatTenant('Kopi Banjir', 'rina@kopibanjir.id');

        KirimPesanMasuk($this, PesanEmail('rina@kopibanjir.id'))->assertJsonPath('Hasil', 'TiketBaru');
        KirimPesanMasuk($this, PesanEmail('rina@kopibanjir.id'))->assertJsonPath('Hasil', 'TiketBaru');
        KirimPesanMasuk($this, PesanEmail('rina@kopibanjir.id'))->assertJsonPath('Hasil', 'Ditolak')->assertJsonPath('Kode', 'TerlaluBanyakPesan');

        app(KonteksTenant::class)->Atur($tenant->Id);
        expect(TiketDukungan::query()->count())->toBe(2);
    });
});

describe('Pembersih pesan masuk', function (): void {
    it('memangkas kutipan, membuang karakter kendali, membatasi panjang. Judul dan nomor tiket', function (): void {
        $p = new PembersihPesanMasuk;

        expect($p->RapikanIsi("Baris satu\x00\r\nBaris dua\r\n> kutipan\r\n\r\n\r\n\r\nBaris tiga"))->toBe("Baris satu\nBaris dua\n\nBaris tiga")
            ->and($p->RapikanIsi("Halo\n\nOn Tue, Oct 14, 2026 at 9:00 AM Dukungan <d@payoung.id> wrote:\n> lama"))->toBe('Halo')
            ->and($p->RapikanIsi("Dari: toko Makmur\nmasalahnya begini"))->toBe("Dari: toko Makmur\nmasalahnya begini")
            ->and(mb_strlen($p->RapikanIsi(str_repeat('a', 9000))))->toBe(PembersihPesanMasuk::MAKS_ISI)
            ->and($p->BuatJudul('Re: RE: [TKT-2026-000123] Printer rusak', 'x'))->toBe('Printer rusak')
            ->and($p->BuatJudul('', 'Halo, tolong cek'))->toBe('Halo, tolong cek')
            ->and($p->BuatJudul('Hi', str_repeat('kata ', 40)))->toEndWith('...')
            ->and($p->CariNomorTiket('Re: TKT-2026-000123', 'x'))->toBe('TKT-2026-000123')
            ->and($p->CariNomorTiket('tanpa', 'lihat tkt-2026-000999'))->toBeNull()
            ->and($p->CariNomorTiket('tanpa', 'lihat TKT-2026-000999 ya'))->toBe('TKT-2026-000999');
    });
});
