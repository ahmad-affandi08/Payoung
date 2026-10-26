<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Organisasi\Tugas\KirimNotifikasiPushTugas;
use App\Domain\Tenant\Enum\JenisKupon;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Enum\TahapPengingatTagihan;
use App\Domain\Tenant\Model\KuponLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\TemplateKomunikasi;
use App\Domain\Tenant\Surel\PengingatTagihanLangganan;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanTagihan;

/*
 * P-08 langkah 1, 2 & 4 / F-19 (PRD v4.04): tagihan perpanjangan terbit otomatis H-7 sebelum periode berakhir, lalu
 * pengingat ke Owner H-7, H-3, H0, H+3 (email; WhatsApp bila penyedia aktif), masing-masing sekali per tagihan.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 08:20:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
    // Langganan PRO bulanan aktif yang berakhir 1 Oktober 2026 10.00 WIB.
    DB::table('Langganan')->where('IdTenant', $this->tenant->Id)->update([
        'Status' => StatusLangganan::Aktif->value,
        'SiklusTagihan' => SiklusTagihan::Bulanan->value,
        'PeriodeMulai' => Carbon::parse('2026-09-01 10:00:00', 'Asia/Jakarta')->utc(),
        'PeriodeSelesai' => Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta')->utc(),
    ]);
});

/** @return list<TagihanLangganan> */
function TagihanOtomatisUji(int $idTenant): array
{
    return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $idTenant)->orderBy('Id')->get()->all();
}

function JalankanTagihanOtomatisUji(object $tes, string $waktu, string $keluaran): void
{
    $tes->travelTo(Carbon::parse($waktu, 'Asia/Jakarta'));
    $tes->artisan('tagihan:terbitkan-perpanjangan')->expectsOutputToContain($keluaran)->assertSuccessful();
}

/** @return list<TahapPengingatTagihan> */
function TahapTerkirimUji(): array
{
    return Mail::sent(PengingatTagihanLangganan::class)->map(fn (PengingatTagihanLangganan $s): TahapPengingatTagihan => $s->tahap)->values()->all();
}

describe('P-08 langkah 1: tagihan perpanjangan otomatis H-7', function (): void {
    it('terbit tepat H-7 dengan paket & siklus berjalan, jatuh tempo = akhir periode, oleh sistem; idempoten', function (): void {
        JalankanTagihanOtomatisUji($this, '2026-09-23 08:20', '0 tagihan perpanjangan terbit, 0 dilewati, 0 tagihan diingatkan');
        expect(TagihanOtomatisUji($this->tenant->Id))->toBe([]);

        JalankanTagihanOtomatisUji($this, '2026-09-24 08:20', '1 tagihan perpanjangan terbit, 0 dilewati, 1 tagihan diingatkan');
        [$tagihan] = TagihanOtomatisUji($this->tenant->Id);

        expect($tagihan->Jenis)->toBe(JenisTagihanLangganan::Perpanjangan)
            ->and($tagihan->Status)->toBe(StatusTagihanLangganan::Terbit)
            ->and($tagihan->Nomor)->toBe('INV/2026/09/000001')
            ->and($tagihan->Siklus)->toBe(SiklusTagihan::Bulanan)
            ->and($tagihan->Subtotal)->toBe('249000.00')
            ->and($tagihan->JatuhTempoPada->equalTo(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta')))->toBeTrue()
            ->and($tagihan->IdPenggunaPembuat)->toBeNull()
            ->and($tagihan->PengingatTerakhir)->toBe(TahapPengingatTagihan::HMinus7->value);

        $audit = LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'langganan.tagihan-otomatis')->sole();
        expect($audit->IdTenant)->toBe($this->tenant->Id)->and($audit->IdPengguna)->toBeNull();

        // Email "tagihan terbit" (tahap H-7) ke Owner, sekali.
        Mail::assertSent(PengingatTagihanLangganan::class, fn (PengingatTagihanLangganan $s): bool => $s->hasTo('rina@kopinusantara.id')
            && $s->tahap === TahapPengingatTagihan::HMinus7
            && $s->nomorTagihan === 'INV/2026/09/000001'
            && $s->jatuhTempo === '1 Oktober 2026');

        JalankanTagihanOtomatisUji($this, '2026-09-24 15:00', '0 tagihan perpanjangan terbit, 0 dilewati, 0 tagihan diingatkan');
        expect(TagihanOtomatisUji($this->tenant->Id))->toHaveCount(1)->and(TahapTerkirimUji())->toBe([TahapPengingatTagihan::HMinus7]);
    });

    it('tagihan otomatis yang dibatalkan Owner tidak diterbitkan ulang setiap hari (nomor tidak terbuang, BR-P08.1)', function (): void {
        JalankanTagihanOtomatisUji($this, '2026-09-24 08:20', '1 tagihan perpanjangan terbit');
        [$tagihan] = TagihanOtomatisUji($this->tenant->Id);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post("/kelola/langganan/tagihan/{$tagihan->Uuid}/batalkan", ['Alasan' => 'Mau ganti ke tahunan'])
            ->assertSessionHasNoErrors();

        JalankanTagihanOtomatisUji($this, '2026-09-25 08:20', '0 tagihan perpanjangan terbit, 0 dilewati');
        expect(TagihanOtomatisUji($this->tenant->Id))->toHaveCount(1);
    });

    it('dilewati: tenant berpenanda Uji, paket harga negosiasi, dan gerbang billing belum aktif (dicoba lagi besok)', function (): void {
        DB::table('Tenant')->where('Id', $this->tenant->Id)->update(['Penanda' => PenandaTenant::Uji->value]);
        JalankanTagihanOtomatisUji($this, '2026-09-24 08:20', '0 tagihan perpanjangan terbit, 0 dilewati');

        DB::table('Tenant')->where('Id', $this->tenant->Id)->update(['Penanda' => null]);
        $idPaket = DB::table('Langganan')->where('IdTenant', $this->tenant->Id)->value('IdPaket');
        DB::table('Paket')->where('Id', $idPaket)->update(['HargaNegosiasi' => true]);
        JalankanTagihanOtomatisUji($this, '2026-09-24 09:00', '0 tagihan perpanjangan terbit, 0 dilewati');

        DB::table('Paket')->where('Id', $idPaket)->update(['HargaNegosiasi' => false]);
        config()->set('integrasi.GerbangBilling', null);
        JalankanTagihanOtomatisUji($this, '2026-09-24 10:00', '0 tagihan perpanjangan terbit, 1 dilewati');
        expect(TagihanOtomatisUji($this->tenant->Id))->toBe([]);
    });

    it('BR-P08.7: kupon berdurasi dari tagihan sebelumnya dilanjutkan selama bulan diskonnya belum habis', function (): void {
        KuponLangganan::query()->create([
            'Kode' => 'HEMAT3', 'Jenis' => JenisKupon::Persen, 'Nilai' => '20', 'DurasiBulan' => 3,
            'Kuota' => null, 'DaftarKodePaket' => null, 'BerlakuSampai' => null,
        ]);
        // Perpanjangan bulan lalu memakai kupon HEMAT3 (1 dari 3 bulan diskon), sudah lunas.
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan', 'KodeKupon' => 'HEMAT3'])
            ->assertSessionHasNoErrors();
        DB::table('TagihanLangganan')->where('IdTenant', $this->tenant->Id)->update(['Status' => StatusTagihanLangganan::Lunas->value, 'DibayarPada' => now()]);

        JalankanTagihanOtomatisUji($this, '2026-09-24 08:20', '1 tagihan perpanjangan terbit');
        $otomatis = TagihanOtomatisUji($this->tenant->Id)[1];

        expect($otomatis->KodeKupon)->toBe('HEMAT3')
            ->and($otomatis->Diskon)->toBe('49800.00')
            ->and(DB::table('KuponLanggananPemakaian')->where('IdTenant', $this->tenant->Id)->sum('BulanDiskon'))->toEqual(2);
    });
});

describe('P-08 langkah 4: pengingat H-7, H-3, H0, H+3', function (): void {
    it('setiap tahap sekali. Tahap terlewat tidak dikirim berurutan; H+3 menyebut tanggal penangguhan; WhatsApp ke nomor Owner', function (): void {
        config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
        Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9001']])]);

        JalankanTagihanOtomatisUji($this, '2026-09-24 08:20', '1 tagihan diingatkan');
        // Penjadwal mati 25–28 September: 29 September hanya tahap H-3, bukan rentetan.
        JalankanTagihanOtomatisUji($this, '2026-09-29 08:20', '1 tagihan diingatkan');
        JalankanTagihanOtomatisUji($this, '2026-09-30 08:20', '0 tagihan diingatkan');
        JalankanTagihanOtomatisUji($this, '2026-10-01 08:20', '1 tagihan diingatkan');
        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-10-01 10:05', 'Asia/Jakarta'));
        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();
        JalankanTagihanOtomatisUji($this, '2026-10-04 08:20', '1 tagihan diingatkan');
        JalankanTagihanOtomatisUji($this, '2026-10-05 08:20', '0 tagihan diingatkan');

        expect(TahapTerkirimUji())->toBe([
            TahapPengingatTagihan::HMinus7, TahapPengingatTagihan::HMinus3, TahapPengingatTagihan::HariH, TahapPengingatTagihan::HPlus3,
        ]);
        Mail::assertSent(PengingatTagihanLangganan::class, fn (PengingatTagihanLangganan $s): bool => $s->tahap === TahapPengingatTagihan::HPlus3
            && $s->tanggalDitangguhkan === '8 Oktober 2026'
            && str_contains((string) $s->subject, 'lewat jatuh tempo'));
        Http::assertSentCount(4);
        Http::assertSent(fn (PermintaanHttp $r): bool => $r['target'] === '6281234567890' && str_contains((string) $r['message'], 'INV/2026/09/000001'));
    });

    it('PGL-07: WhatsApp memakai versi terbit templat komunikasi dari konsol dan email memakai subjeknya', function (): void {
        config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
        Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9001']])]);
        foreach ([['WhatsApp', null, null, 'Pengingat {NomorTagihan} untuk {NamaPemilik}: {Total}. Bayar di {Tautan}'], ['Email', 'Segera bayar {NomorTagihan}', 'Halo {NamaPemilik}', '{Kalimat}']] as [$kanal, $subjek, $judul, $isi]) {
            (new TemplateKomunikasi)->forceFill([
                'KodePeristiwa' => 'tagihan.pengingat.h-7', 'Kanal' => $kanal, 'Bahasa' => 'id', 'Versi' => 1, 'Subjek' => $subjek, 'Judul' => $judul,
                'Isi' => $isi, 'Status' => 'Terbit', 'DiterbitkanPada' => now(),
            ])->save();
        }

        JalankanTagihanOtomatisUji($this, '2026-09-24 08:20', '1 tagihan diingatkan');

        Http::assertSent(fn (PermintaanHttp $r): bool => $r['target'] === '6281234567890'
            && $r['message'] === 'Pengingat INV/2026/09/000001 untuk Rina Wulandari: Rp 276.390. Bayar di '.rtrim((string) config('app.url'), '/').'/kelola/langganan');
        Mail::assertSent(PengingatTagihanLangganan::class, fn (PengingatTagihanLangganan $s): bool => $s->subject === 'Segera bayar INV/2026/09/000001');
    });

    it('PGL-07: Owner juga menerima notifikasi aplikasi bertemplat per tahap, sekali per tahap. Dibisukan = tidak dibuat', function (): void {
        $idPemilik = $this->pemilik->Id;
        Queue::fake();

        JalankanTagihanOtomatisUji($this, '2026-09-24 08:20', '1 tagihan diingatkan');
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        $notifikasi = NotifikasiPengguna::query()->where('IdPengguna', $idPemilik)->sole();

        expect($notifikasi->Jenis)->toBe(JenisNotifikasiPengguna::PesanPlatform)
            ->and($notifikasi->Judul)->toBe('Tagihan INV/2026/09/000001 sudah terbit')
            ->and($notifikasi->Isi)->toContain('Pro')->toContain('Rp 276.390')->toContain('1 Oktober 2026')
            ->and($notifikasi->Data['Href'])->toBe('/kelola/langganan')
            ->and($notifikasi->Data['Peristiwa'])->toBe('tagihan.pengingat.h-7');
        Queue::assertPushed(KirimNotifikasiPushTugas::class, 1);

        // Hari yang sama: tahap tidak diulang, jadi tidak ada notifikasi kedua.
        JalankanTagihanOtomatisUji($this, '2026-09-24 15:00', '0 tagihan diingatkan');
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        expect(NotifikasiPengguna::query()->count())->toBe(1);

        // Tahap H-3: notifikasi baru dengan teks tahapnya; Owner yang membisukan jenis ini tidak menerimanya.
        NotifikasiDibisukan::query()->create(['IdPengguna' => $idPemilik, 'Jenis' => JenisNotifikasiPengguna::PesanPlatform->value]);
        JalankanTagihanOtomatisUji($this, '2026-09-29 08:20', '1 tagihan diingatkan');
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        expect(NotifikasiPengguna::query()->count())->toBe(1);
        Mail::assertSent(PengingatTagihanLangganan::class, fn (PengingatTagihanLangganan $s): bool => $s->tahap === TahapPengingatTagihan::HMinus3);
    });

    it('PGL-07: notifikasi aplikasi memakai versi terbit templat kanal Notifikasi', function (): void {
        Queue::fake();
        (new TemplateKomunikasi)->forceFill([
            'KodePeristiwa' => 'tagihan.pengingat.h-7', 'Kanal' => 'Notifikasi', 'Bahasa' => 'id', 'Versi' => 1, 'Subjek' => null,
            'Judul' => 'Ada tagihan {NomorTagihan}', 'Isi' => 'Halo {NamaPemilik}, bayar {Total} ya.', 'Status' => 'Terbit', 'DiterbitkanPada' => now(),
        ])->save();

        JalankanTagihanOtomatisUji($this, '2026-09-24 08:20', '1 tagihan diingatkan');
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        $notifikasi = NotifikasiPengguna::query()->sole();

        expect($notifikasi->Judul)->toBe('Ada tagihan INV/2026/09/000001')->and($notifikasi->Isi)->toBe('Halo Rina Wulandari, bayar Rp 276.390 ya.');
    });

    it('tagihan buatan Owner tidak memicu email "tagihan terbit"; tahap berikutnya tetap diingatkan. Lunas berhenti diingatkan', function (): void {
        DB::table('Langganan')->where('IdTenant', $this->tenant->Id)->update(['Status' => StatusLangganan::Gratis->value, 'PeriodeMulai' => null, 'PeriodeSelesai' => null]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasNoErrors();
        [$tagihan] = TagihanOtomatisUji($this->tenant->Id);
        // Aktivasi: jatuh tempo 7 hari (30 September) → saat dibuat sudah tahap H-7.
        expect($tagihan->PengingatTerakhir)->toBe(TahapPengingatTagihan::HMinus7->value);

        JalankanTagihanOtomatisUji($this, '2026-09-23 09:00', '0 tagihan diingatkan');
        JalankanTagihanOtomatisUji($this, '2026-09-27 08:20', '1 tagihan diingatkan');
        DB::table('TagihanLangganan')->where('Id', $tagihan->Id)->update(['Status' => StatusTagihanLangganan::Lunas->value, 'DibayarPada' => now()]);
        JalankanTagihanOtomatisUji($this, '2026-09-30 08:20', '0 tagihan diingatkan');

        expect(TahapTerkirimUji())->toBe([TahapPengingatTagihan::HMinus3]);
    });
});

it('TahapPengingatTagihan memakai tanggal kalender WIB', function (): void {
    $jatuhTempo = Carbon::parse('2026-10-01 00:30', 'Asia/Jakarta');

    expect(TahapPengingatTagihan::Tentukan(Carbon::parse('2026-09-23 23:59', 'Asia/Jakarta'), $jatuhTempo))->toBeNull()
        ->and(TahapPengingatTagihan::Tentukan(Carbon::parse('2026-09-24 00:01', 'Asia/Jakarta'), $jatuhTempo))->toBe(TahapPengingatTagihan::HMinus7)
        ->and(TahapPengingatTagihan::Tentukan(Carbon::parse('2026-09-30 23:00', 'Asia/Jakarta'), $jatuhTempo))->toBe(TahapPengingatTagihan::HMinus3)
        ->and(TahapPengingatTagihan::Tentukan(Carbon::parse('2026-10-01 23:00', 'Asia/Jakarta'), $jatuhTempo))->toBe(TahapPengingatTagihan::HariH)
        ->and(TahapPengingatTagihan::Tentukan(Carbon::parse('2026-10-20 08:00', 'Asia/Jakarta'), $jatuhTempo))->toBe(TahapPengingatTagihan::HPlus3);
});
