<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tagihan\Surel\PembayaranLanggananDiterima;
use App\Domain\Pengelola\Tagihan\Surel\RefundLanggananDisetujui;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusKomisiMitra;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusRefundLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\AtribusiMitra;
use App\Domain\Tenant\Model\KomisiMitra;
use App\Domain\Tenant\Model\KuponLangganan;
use App\Domain\Tenant\Model\KuponLanggananPemakaian;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Mitra;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\RefundLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * D-112 (P-08, audit E4/E6): tagihan manual paket harga negosiasi, pembayaran dicatat langsung, bukti transfer oleh
 * Owner, hapus-buku tagihan lewat jatuh tempo, dan refund (BR-P08.2: di atas batas diputuskan Super Admin).
 */

function MasukManual(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    $tes->flushSession();

    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** @return array<string, string> */
function IsianTagihanManual(array $timpa = []): array
{
    return [
        'KodePaket' => 'ENTERPRISE',
        'Siklus' => 'Tahunan',
        'Subtotal' => '12000000',
        'Keterangan' => 'Kontrak Enterprise 12 bulan, 5 outlet',
        ...$timpa,
    ];
}

function TerbitkanManualUji(TestCase $tes, PenggunaPengelola $pelaku, Tenant $tenant, array $timpa = []): TagihanLangganan
{
    MasukManual($tes, $pelaku)
        ->post(BantuanPengelola::Url("/tenant/{$tenant->Uuid}/tagihan-manual"), IsianTagihanManual($timpa))
        ->assertSessionHasNoErrors();

    return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
}

/** Tagihan biasa (PRO) dari Owner, dibayar lewat bukti dan diterima Keuangan → Lunas. */
function LunasiTagihanBiasaUji(TestCase $tes, PenggunaPengelola $keuangan, Pengguna $pemilik, Tenant $tenant, string $siklus = 'Bulanan'): TagihanLangganan
{
    BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => $siklus])
        ->assertSessionHasNoErrors();
    $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
    $pembayaran = BantuanTagihan::UnggahBuktiLangsung($tenant, $pemilik, $tagihan);
    MasukManual($tes, $keuangan)
        ->post(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/terima"), ['JumlahDiterima' => $tagihan->Total])
        ->assertSessionHasNoErrors();

    return $tagihan->refresh();
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
    $this->keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
    $this->superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
});

describe('Hak akses', function (): void {
    it('Keuangan menerbitkan, mencatat, dan mengajukan refund tetapi tidak memutuskan refund besar. Peran lain tidak punya izin tagihan', function (): void {
        expect($this->keuangan->PunyaIzin(IzinPengelola::TagihanTerbitkan))->toBeTrue()
            ->and($this->keuangan->PunyaIzin(IzinPengelola::TagihanRefund))->toBeTrue()
            ->and($this->keuangan->PunyaIzin(IzinPengelola::TagihanRefundSetujui))->toBeFalse()
            ->and($this->superAdmin->PunyaIzin(IzinPengelola::TagihanRefundSetujui))->toBeTrue();

        foreach ([PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::KontenLegal, PeranPengelolaBawaan::MitraPenjualan, PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Analis] as $peran) {
            expect($peran->AmbilIzin())->not->toContain(IzinPengelola::TagihanTerbitkan)->not->toContain(IzinPengelola::TagihanRefund);
        }

        $dukungan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        MasukManual($this, $dukungan)->post(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}/tagihan-manual"), IsianTagihanManual())->assertForbidden();
    });
});

describe('Tagihan manual paket harga negosiasi (E4)', function (): void {
    it('menerbitkan tagihan Enterprise: harga kesepakatan + PPN, tanpa HargaPaket, bertanda Manual, tercatat di audit', function (): void {
        $tagihan = TerbitkanManualUji($this, $this->keuangan, $this->tenant);

        // 12.000.000 → DPP ⌊× 11/12⌋ = 11.000.000, PPN 12% = 1.320.000, total 13.320.000.
        expect($tagihan->Manual)->toBeTrue()
            ->and($tagihan->IdHargaPaket)->toBeNull()
            ->and($tagihan->Status)->toBe(StatusTagihanLangganan::Terbit)
            ->and($tagihan->Jenis)->toBe(JenisTagihanLangganan::Aktivasi)
            ->and($tagihan->Subtotal)->toBe('12000000.00')
            ->and($tagihan->DasarPengenaanPajak)->toBe('11000000.00')
            ->and($tagihan->JumlahPpn)->toBe('1320000.00')
            ->and($tagihan->Total)->toBe('13320000.00')
            ->and($tagihan->Keterangan)->toBe('Kontrak Enterprise 12 bulan, 5 outlet')
            ->and($tagihan->IdPenggunaPengelolaPembuat)->toBe($this->keuangan->Id)
            ->and($tagihan->Nomor)->toBe('INV/2026/09/000001');

        $log = LogAuditPengelola::query()->where('Aksi', 'tagihan.manual.terbit')->sole();
        expect($log->IdTenant)->toBe($this->tenant->Id);

        MasukManual($this, $this->keuangan)->get(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Tagihan/Detail')
                ->where('Tagihan.Manual', true)
                ->where('Izin.Terbitkan', true)
                ->where('Izin.SetujuiRefund', false));
    });

    it('menolak paket yang bukan harga negosiasi, harga tidak sah, dan tagihan kedua selagi yang pertama terbuka', function (): void {
        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}/tagihan-manual"), IsianTagihanManual(['KodePaket' => 'PRO']))
            ->assertSessionHasErrors('KodePaket');
        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}/tagihan-manual"), IsianTagihanManual(['Subtotal' => '0']))
            ->assertSessionHasErrors('Subtotal');
        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}/tagihan-manual"), IsianTagihanManual(['Keterangan' => '']))
            ->assertSessionHasErrors('Keterangan');

        TerbitkanManualUji($this, $this->keuangan, $this->tenant);
        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}/tagihan-manual"), IsianTagihanManual())
            ->assertSessionHasErrors('Umum');

        expect(TagihanLangganan::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('Owner mengunggah bukti transfer tagihan manual, Keuangan menerima: Lunas dan langganan Enterprise aktif 12 bulan', function (): void {
        $tagihan = TerbitkanManualUji($this, $this->keuangan, $this->tenant);
        BantuanTagihan::AturRekeningTujuan();

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->get(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Tagihan.Manual', true)
                ->where('BolehUnggahBukti', true)
                ->where('BolehBayarOnline', false)
                ->has('RekeningTujuan', 1));

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}/bukti"), [
                'Bukti' => UploadedFile::fake()->image('mutasi.png'),
                'Jumlah' => '13320000',
                'TanggalTransfer' => '2026-09-23',
                'BankPengirim' => 'Bank Mandiri',
                'NamaPengirim' => 'Rina Wulandari',
                'KodeRekeningTujuan' => 'UTAMA',
            ])
            ->assertSessionHasNoErrors();

        $pembayaran = PembayaranLangganan::query()->withoutGlobalScopes()->sole();
        expect($pembayaran->Status)->toBe(StatusPembayaranLangganan::Menunggu)->and($pembayaran->Metode)->toBe(MetodePembayaranLangganan::TransferManual);

        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/terima"), ['JumlahDiterima' => '13320000'])
            ->assertSessionHasNoErrors();

        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($langganan->Status)->toBe(StatusLangganan::Aktif)
            ->and($langganan->IdPaket)->toBe($tagihan->IdPaket)
            ->and($langganan->PeriodeMulai?->diffInMonths($langganan->PeriodeSelesai))->toBe(12.0);
    });

    it('tagihan biasa tidak menerima unggahan bukti lewat halaman Owner (dibayar lewat gerbang, D-82)', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasNoErrors();
        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->sole();
        BantuanTagihan::AturRekeningTujuan();

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}/bukti"), [
                'Bukti' => UploadedFile::fake()->image('mutasi.png'),
                'Jumlah' => $tagihan->Total,
                'TanggalTransfer' => '2026-09-23',
                'BankPengirim' => 'Bank Mandiri',
                'NamaPengirim' => 'Rina Wulandari',
                'KodeRekeningTujuan' => 'UTAMA',
            ])
            ->assertSessionHasErrors('Umum');

        expect(PembayaranLangganan::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('Keuangan mencatat pembayaran yang sudah masuk rekening tanpa bukti: Lunas, audit, Owner diberi email', function (): void {
        $tagihan = TerbitkanManualUji($this, $this->keuangan, $this->tenant);

        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/catat-pembayaran"), [
                'JumlahDiterima' => '13000000',
                'TanggalTransfer' => '2026-09-23',
                'NamaPengirim' => 'PT Kopi Nusantara',
            ])
            ->assertSessionHasErrors('JumlahDiterima');
        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);

        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/catat-pembayaran"), [
                'JumlahDiterima' => '13320000',
                'TanggalTransfer' => '2026-09-23',
                'NamaPengirim' => 'PT Kopi Nusantara',
                'BankPengirim' => 'BCA',
                'Catatan' => 'Transfer tercatat di mutasi 23 September',
            ])
            ->assertSessionHasNoErrors();

        $pembayaran = PembayaranLangganan::query()->withoutGlobalScopes()->sole();
        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and($pembayaran->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($pembayaran->PathBukti)->toBeNull()
            ->and($pembayaran->IdPenggunaPengelolaVerifikator)->toBe($this->keuangan->Id)
            ->and(Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->Status)->toBe(StatusLangganan::Aktif)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tagihan.pembayaran.catat-langsung')->sole()->IdTenant)->toBe($this->tenant->Id);
        Mail::assertQueued(PembayaranLanggananDiterima::class);

        // Sudah lunas: tidak bisa dicatat dua kali.
        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/catat-pembayaran"), ['JumlahDiterima' => '13320000', 'TanggalTransfer' => '2026-09-23', 'NamaPengirim' => 'PT Kopi Nusantara'])
            ->assertSessionHasErrors('Umum');
        expect(PembayaranLangganan::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('pembayaran tidak bisa dicatat langsung untuk tagihan biasa, atau selagi ada bukti yang menunggu verifikasi', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasNoErrors();
        $biasa = TagihanLangganan::query()->withoutGlobalScopes()->sole();
        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/{$biasa->Uuid}/catat-pembayaran"), ['JumlahDiterima' => $biasa->Total, 'TanggalTransfer' => '2026-09-23', 'NamaPengirim' => 'Rina'])
            ->assertSessionHasErrors('Umum');

        $biasa->update(['Status' => StatusTagihanLangganan::Dibatalkan, 'DibatalkanPada' => now(), 'AlasanBatal' => 'Uji']);
        $manual = TerbitkanManualUji($this, $this->keuangan, $this->tenant);
        BantuanTagihan::UnggahBuktiLangsung($this->tenant, $this->pemilik, $manual);
        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/{$manual->Uuid}/catat-pembayaran"), ['JumlahDiterima' => $manual->Total, 'TanggalTransfer' => '2026-09-23', 'NamaPengirim' => 'Rina'])
            ->assertSessionHasErrors('Umum');
        expect($manual->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });
});

describe('Hapus-buku (E6)', function (): void {
    it('hanya tagihan lewat jatuh tempo. Alasan wajib. Kupon dilepas. Tidak boleh selagi ada pembayaran menunggu', function (): void {
        KuponLangganan::query()->create(['Kode' => 'HEMAT10', 'Jenis' => 'Persen', 'Nilai' => '10', 'DurasiBulan' => 1]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan', 'KodeKupon' => 'HEMAT10'])
            ->assertSessionHasNoErrors();
        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->sole();

        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/hapus-buku"), ['Alasan' => 'Tenant tidak merespons'])->assertSessionHasErrors('Umum');
        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);

        $tagihan->update(['Status' => StatusTagihanLangganan::JatuhTempo]);
        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/hapus-buku"), ['Alasan' => 'pendek'])->assertSessionHasErrors('Alasan');

        $pembayaran = BantuanTagihan::UnggahBuktiLangsung($this->tenant, $this->pemilik, $tagihan);
        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/hapus-buku"), ['Alasan' => 'Tenant tidak merespons'])->assertSessionHasErrors('Umum');
        $pembayaran->update(['Status' => StatusPembayaranLangganan::Ditolak, 'AlasanTolak' => 'Bukti buram']);

        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/hapus-buku"), ['Alasan' => 'Tenant tidak merespons 60 hari'])->assertSessionHasNoErrors();

        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Dihapuskan)
            ->and($tagihan->DihapusBukuPada)->not->toBeNull()
            ->and($tagihan->AlasanHapusBuku)->toBe('Tenant tidak merespons 60 hari')
            ->and(KuponLanggananPemakaian::query()->sole()->DibatalkanPada)->not->toBeNull()
            ->and(LogAuditPengelola::query()->where('Aksi', 'tagihan.hapus-buku')->sole()->IdTenant)->toBe($this->tenant->Id);

        // Nomor tetap tercatat dan tagihan baru boleh dibuat.
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasNoErrors();
        expect(TagihanLangganan::query()->withoutGlobalScopes()->count())->toBe(2);
    });
});

describe('Refund tagihan lunas (BR-P08.2)', function (): void {
    it('di bawah batas langsung disetujui: tagihan Dikembalikan, layanan diakhiri, Owner diberi email, audit', function (): void {
        $tagihan = LunasiTagihanBiasaUji($this, $this->keuangan, $this->pemilik, $this->tenant);
        expect($tagihan->Status)->toBe(StatusTagihanLangganan::Lunas);

        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Salah paket, minta dikembalikan', 'CatatanPengembalian' => 'Transfer balik ke BCA 1234'])
            ->assertSessionHasNoErrors();

        $refund = RefundLangganan::query()->withoutGlobalScopes()->sole();
        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Dikembalikan)
            ->and($tagihan->DikembalikanPada)->not->toBeNull()
            ->and($refund->Status)->toBe(StatusRefundLangganan::Disetujui)
            ->and($refund->Jumlah)->toBe($tagihan->Total)
            ->and($refund->LayananDiakhiri)->toBeTrue()
            ->and($refund->IdPenggunaPengelolaPemutus)->toBe($this->keuangan->Id)
            ->and($langganan->PeriodeSelesai?->lessThanOrEqualTo(now()))->toBeTrue()
            ->and(LogAuditPengelola::query()->where('Aksi', 'tagihan.refund.setuju')->sole()->IdTenant)->toBe($this->tenant->Id);
        Mail::assertQueued(RefundLanggananDisetujui::class);

        // Sudah dikembalikan: tidak bisa diajukan lagi.
        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Coba lagi untuk kedua kali'])
            ->assertSessionHasErrors('Umum');
        expect(RefundLangganan::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('di atas batas diajukan Keuangan lalu diputuskan Super Admin; Keuangan tidak bisa menyetujui', function (): void {
        $tagihan = LunasiTagihanBiasaUji($this, $this->keuangan, $this->pemilik, $this->tenant, 'Tahunan');
        expect(Uang::Dari($tagihan->Total)->Bandingkan(Uang::Dari('1000000')))->toBeGreaterThan(0);

        MasukManual($this, $this->keuangan)
            ->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Tenant tutup usaha, minta refund'])
            ->assertSessionHasNoErrors();
        $refund = RefundLangganan::query()->withoutGlobalScopes()->sole();
        expect($refund->Status)->toBe(StatusRefundLangganan::Diajukan)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas);
        Mail::assertNotQueued(RefundLanggananDisetujui::class);

        // Pengajuan kedua selagi menunggu ditolak.
        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Ajukan lagi pengajuan yang sama'])->assertSessionHasErrors('Umum');

        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/refund/{$refund->Uuid}/setujui"))->assertForbidden();
        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/refund/{$refund->Uuid}/tolak"), ['Alasan' => 'Tidak berwenang menolak'])->assertForbidden();

        MasukManual($this, $this->superAdmin)->get(BantuanPengelola::Url('/tagihan'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->has('RefundMenunggu', 1)
                ->where('RefundMenunggu.0.Uuid', $refund->Uuid)
                ->where('RefundMenunggu.0.NamaTenant', 'Kopi Nusantara')
                ->where('BolehSetujuiRefund', true));

        MasukManual($this, $this->superAdmin)->post(BantuanPengelola::Url("/tagihan/refund/{$refund->Uuid}/setujui"))->assertSessionHasNoErrors();

        expect($refund->refresh()->Status)->toBe(StatusRefundLangganan::Disetujui)
            ->and($refund->IdPenggunaPengelolaPemutus)->toBe($this->superAdmin->Id)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Dikembalikan);
        Mail::assertQueued(RefundLanggananDisetujui::class);

        // Keputusan final: tidak bisa diputuskan dua kali.
        MasukManual($this, $this->superAdmin)->post(BantuanPengelola::Url("/tagihan/refund/{$refund->Uuid}/setujui"))->assertSessionHasErrors('Umum');
    });

    it('Super Admin menolak pengajuan dengan alasan: tagihan tetap Lunas dan layanan tidak berubah; Super Admin yang mengajukan langsung disetujui', function (): void {
        $tagihan = LunasiTagihanBiasaUji($this, $this->keuangan, $this->pemilik, $this->tenant, 'Tahunan');
        $periodeSelesai = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->PeriodeSelesai;

        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Tenant minta refund tahunan'])->assertSessionHasNoErrors();
        $refund = RefundLangganan::query()->withoutGlobalScopes()->sole();

        MasukManual($this, $this->superAdmin)->post(BantuanPengelola::Url("/tagihan/refund/{$refund->Uuid}/tolak"), ['Alasan' => 'pendek'])->assertSessionHasErrors('Alasan');
        MasukManual($this, $this->superAdmin)->post(BantuanPengelola::Url("/tagihan/refund/{$refund->Uuid}/tolak"), ['Alasan' => 'Layanan sudah dipakai penuh 8 bulan'])->assertSessionHasNoErrors();

        expect($refund->refresh()->Status)->toBe(StatusRefundLangganan::Ditolak)
            ->and($refund->AlasanTolak)->toBe('Layanan sudah dipakai penuh 8 bulan')
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and(Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->PeriodeSelesai?->equalTo($periodeSelesai))->toBeTrue();

        // Pengajuan baru setelah ditolak diperbolehkan; Super Admin tidak terikat persetujuan kedua (D-34).
        MasukManual($this, $this->superAdmin)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Kebijakan khusus pemilik produk'])->assertSessionHasNoErrors();
        expect($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Dikembalikan);
    });

    it('refund perpanjangan mengembalikan akhir periode ke awal perpanjangan. Refund tagihan lama yang sudah disusul perpanjangan tidak menyentuh layanan', function (): void {
        $pertama = LunasiTagihanBiasaUji($this, $this->keuangan, $this->pemilik, $this->tenant);
        $akhirPertama = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->PeriodeSelesai;
        $kedua = LunasiTagihanBiasaUji($this, $this->keuangan, $this->pemilik, $this->tenant);
        expect($kedua->Jenis)->toBe(JenisTagihanLangganan::Perpanjangan);

        // Refund tagihan pertama: periodenya sudah disusul perpanjangan, jadi layanan dibiarkan.
        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$pertama->Uuid}/refund"), ['Alasan' => 'Koreksi tagihan pertama'])->assertSessionHasNoErrors();
        expect(RefundLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $pertama->Id)->sole()->LayananDiakhiri)->toBeFalse();

        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$kedua->Uuid}/refund"), ['Alasan' => 'Koreksi perpanjangan salah bayar'])->assertSessionHasNoErrors();
        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect(RefundLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $kedua->Id)->sole()->LayananDiakhiri)->toBeTrue()
            ->and($langganan->PeriodeSelesai?->equalTo($akhirPertama))->toBeTrue();
    });

    it('refund membatalkan komisi mitra yang masih tertunda atas tagihan itu (clawback BR-P12.1)', function (): void {
        $mitra = Mitra::query()->create(['Kode' => 'AGEN-SOLO', 'Nama' => 'Agen Solo', 'Jenis' => 'Reseller', 'PersenKomisi' => '20.00', 'KomisiBerulang' => true]);
        AtribusiMitra::query()->create(['IdMitra' => $mitra->Id, 'IdTenant' => $this->tenant->Id, 'Sumber' => 'Tautan', 'MulaiPada' => now()]);
        $tagihan = LunasiTagihanBiasaUji($this, $this->keuangan, $this->pemilik, $this->tenant);
        $komisi = KomisiMitra::query()->where('IdTagihanLangganan', $tagihan->Id)->sole();
        expect($komisi->Status)->toBe(StatusKomisiMitra::Tertunda);

        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Salah paket, minta dikembalikan'])->assertSessionHasNoErrors();

        expect($komisi->refresh()->Status)->toBe(StatusKomisiMitra::Dibatalkan)
            ->and($komisi->AlasanBatal)->toContain($tagihan->Nomor);
    });

    it('D-113: batas refund diubah Super Admin dari konsol: bawaan Rp 1 juta, berlaku untuk pengajuan berikutnya, teraudit', function (): void {
        $tagihan = LunasiTagihanBiasaUji($this, $this->keuangan, $this->pemilik, $this->tenant, 'Tahunan');
        MasukManual($this, $this->superAdmin)->get(BantuanPengelola::Url('/tagihan'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('BatasRefund', '1000000.00')->where('BolehSetujuiRefund', true));

        // Keuangan tidak boleh mengubahnya.
        MasukManual($this, $this->keuangan)
            ->put(BantuanPengelola::Url('/tagihan/pengaturan/batas-refund'), ['Batas' => '9000000', 'Alasan' => 'Coba ubah sendiri'])
            ->assertForbidden();

        // Nilai tidak sah ditolak.
        MasukManual($this, $this->superAdmin)->put(BantuanPengelola::Url('/tagihan/pengaturan/batas-refund'), ['Batas' => '-5', 'Alasan' => 'Nilai negatif'])->assertSessionHasErrors('Batas');
        MasukManual($this, $this->superAdmin)->put(BantuanPengelola::Url('/tagihan/pengaturan/batas-refund'), ['Batas' => '2000000000', 'Alasan' => 'Terlalu besar sekali'])->assertSessionHasErrors('Batas');
        MasukManual($this, $this->superAdmin)->put(BantuanPengelola::Url('/tagihan/pengaturan/batas-refund'), ['Batas' => '5000000', 'Alasan' => ''])->assertSessionHasErrors('Alasan');

        MasukManual($this, $this->superAdmin)
            ->put(BantuanPengelola::Url('/tagihan/pengaturan/batas-refund'), ['Batas' => '5000000', 'Alasan' => 'Keuangan sering menangani refund tahunan'])
            ->assertSessionHasNoErrors();

        $log = LogAuditPengelola::query()->where('Aksi', 'tagihan.refund.batas-ubah')->sole();
        expect($log->NilaiLama)->toBe(['Batas' => '1000000.00'])->and($log->NilaiBaru)->toBe(['Batas' => '5000000.00']);

        // Tagihan tahunan ±Rp 2,65 juta kini di bawah batas: Keuangan langsung menyetujui.
        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Tenant tutup usaha, minta refund'])->assertSessionHasNoErrors();
        expect(RefundLangganan::query()->withoutGlobalScopes()->sole()->Status)->toBe(StatusRefundLangganan::Disetujui);
    });

    it('D-113: batas 0 berarti semua refund Keuangan menunggu persetujuan Super Admin', function (): void {
        $tagihan = LunasiTagihanBiasaUji($this, $this->keuangan, $this->pemilik, $this->tenant);
        MasukManual($this, $this->superAdmin)->put(BantuanPengelola::Url('/tagihan/pengaturan/batas-refund'), ['Batas' => '0', 'Alasan' => 'Semua refund harus dicek'])->assertSessionHasNoErrors();

        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$tagihan->Uuid}/refund"), ['Alasan' => 'Salah paket, minta dikembalikan'])->assertSessionHasNoErrors();

        expect(RefundLangganan::query()->withoutGlobalScopes()->sole()->Status)->toBe(StatusRefundLangganan::Diajukan);
    });

    it('D-114: rekening tujuan transfer diatur Super Admin dari konsol: kode otomatis, maksimal 5, validasi nomor, teraudit, tampil di halaman Owner', function (): void {
        $rekening = [
            ['NamaBank' => 'Bank Central Asia', 'NomorRekening' => '1234 567 890', 'AtasNama' => 'PT Kasir Nusantara Digital'],
            ['NamaBank' => 'Bank Mandiri', 'NomorRekening' => '137-00-1234567-8', 'AtasNama' => 'PT Kasir Nusantara Digital'],
        ];

        // Sebelum disimpan dari konsol, nilai awal server (env) dipakai: di sini kosong, jadi tagihan manual belum bisa dibayar.
        MasukManual($this, $this->superAdmin)->get(BantuanPengelola::Url('/tagihan'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('PengaturanPenagihan.Boleh', true)
                ->where('PengaturanPenagihan.RekeningDariKonsol', false)
                ->has('PengaturanPenagihan.Rekening', 0));

        // Keuangan dan peran lain tidak boleh mengubah rekening tujuan.
        MasukManual($this, $this->keuangan)
            ->put(BantuanPengelola::Url('/tagihan/pengaturan/rekening-tujuan'), ['Rekening' => $rekening, 'Alasan' => 'Coba ubah sendiri'])
            ->assertForbidden();
        MasukManual($this, $this->keuangan)->get(BantuanPengelola::Url('/tagihan'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PengaturanPenagihan.Boleh', false));

        // Validasi: nomor harus angka, atas nama wajib, alasan wajib, maksimal 5 baris.
        $ubah = fn (array $isian) => MasukManual($this, $this->superAdmin)->put(BantuanPengelola::Url('/tagihan/pengaturan/rekening-tujuan'), $isian);
        $ubah(['Rekening' => [['NamaBank' => 'BCA', 'NomorRekening' => 'abc', 'AtasNama' => 'PT Kasir']], 'Alasan' => 'Rekening baru'])->assertSessionHasErrors('Rekening.0.NomorRekening');
        $ubah(['Rekening' => [['NamaBank' => 'BCA', 'NomorRekening' => '1234567890', 'AtasNama' => '']], 'Alasan' => 'Rekening baru'])->assertSessionHasErrors('Rekening.0.AtasNama');
        $ubah(['Rekening' => $rekening, 'Alasan' => ''])->assertSessionHasErrors('Alasan');
        $ubah(['Rekening' => array_fill(0, 6, $rekening[0]), 'Alasan' => 'Terlalu banyak'])->assertSessionHasErrors('Rekening');

        $ubah(['Rekening' => $rekening, 'Alasan' => 'Rekening operasional resmi perusahaan'])->assertSessionHasNoErrors();

        $log = LogAuditPengelola::query()->where('Aksi', 'tagihan.rekening-tujuan.ubah')->sole();
        expect($log->NilaiLama)->toBe(['Rekening' => []])
            // MySQL menormalkan urutan kunci JSON (kunci pendek dulu), jadi urutan tidak ikut dibandingkan.
            ->and($log->NilaiBaru['Rekening'][0])->toEqual(['Kode' => 'UTAMA', 'NamaBank' => 'Bank Central Asia', 'NomorRekening' => '1234 567 890', 'AtasNama' => 'PT Kasir Nusantara Digital'])
            ->and($log->NilaiBaru['Rekening'][1]['Kode'])->toBe('REK2')
            ->and($log->Alasan)->toBe('Rekening operasional resmi perusahaan');

        MasukManual($this, $this->superAdmin)->get(BantuanPengelola::Url('/tagihan'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('PengaturanPenagihan.RekeningDariKonsol', true)
                ->has('PengaturanPenagihan.Rekening', 2));

        // Owner melihat rekening dari konsol di halaman tagihan manual.
        $tagihan = TerbitkanManualUji($this, $this->keuangan, $this->tenant);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->get(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}"))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('RekeningTujuan', 2)->where('RekeningTujuan.1.Kode', 'REK2'));
    });

    it('D-114: status PKP platform diatur Super Admin dari konsol. Berlaku untuk tagihan berikutnya saja dan teraudit', function (): void {
        $ubah = fn (array $isian) => MasukManual($this, $this->superAdmin)->put(BantuanPengelola::Url('/tagihan/pengaturan/pkp'), $isian);

        // Nilai awal server (env) dipakai selama belum disimpan: PKP, tagihan memuat PPN.
        $sebelum = TerbitkanManualUji($this, $this->keuangan, $this->tenant);
        expect($sebelum->JumlahPpn)->not->toBe('0.00');
        MasukManual($this, $this->superAdmin)->get(BantuanPengelola::Url('/tagihan'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PengaturanPenagihan.PlatformPkp', true)->where('PengaturanPenagihan.PkpDariKonsol', false));

        MasukManual($this, $this->keuangan)->put(BantuanPengelola::Url('/tagihan/pengaturan/pkp'), ['Pkp' => false, 'Alasan' => 'Coba ubah sendiri'])->assertForbidden();
        $ubah(['Pkp' => false, 'Alasan' => ''])->assertSessionHasErrors('Alasan');
        $ubah(['Pkp' => false, 'Alasan' => 'SPPKP belum terbit, jangan tagih PPN'])->assertSessionHasNoErrors();

        $log = LogAuditPengelola::query()->where('Aksi', 'tagihan.pkp.ubah')->sole();
        expect($log->NilaiLama)->toBe(['Pkp' => true])->and($log->NilaiBaru)->toBe(['Pkp' => false]);

        // Tagihan lama tidak berubah; tagihan baru tanpa PPN.
        $sebelum->Status = StatusTagihanLangganan::Dibatalkan;
        $sebelum->save();
        $sesudah = TerbitkanManualUji($this, $this->keuangan, $this->tenant);
        expect($sebelum->refresh()->JumlahPpn)->not->toBe('0.00')
            ->and($sesudah->JumlahPpn)->toBe('0.00')
            ->and($sesudah->IdTarifPajak)->toBeNull()
            ->and($sesudah->Total)->toBe('12000000.00');

        MasukManual($this, $this->superAdmin)->get(BantuanPengelola::Url('/tagihan'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PengaturanPenagihan.PlatformPkp', false)->where('PengaturanPenagihan.PkpDariKonsol', true));

        // Dinyalakan lagi: tagihan berikutnya memuat PPN.
        $ubah(['Pkp' => true, 'Alasan' => 'SPPKP sudah terbit'])->assertSessionHasNoErrors();
        $sesudah->Status = StatusTagihanLangganan::Dibatalkan;
        $sesudah->save();
        expect(TerbitkanManualUji($this, $this->keuangan, $this->tenant)->JumlahPpn)->not->toBe('0.00');
    });

    it('tagihan Rp 0 (kupon 100%) dan tagihan yang belum lunas tidak bisa di-refund', function (): void {
        KuponLangganan::query()->create(['Kode' => 'GRATIS100', 'Jenis' => 'Persen', 'Nilai' => '100', 'DurasiBulan' => 1]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan', 'KodeKupon' => 'GRATIS100'])
            ->assertSessionHasNoErrors();
        $gratis = TagihanLangganan::query()->withoutGlobalScopes()->sole();
        expect($gratis->Status)->toBe(StatusTagihanLangganan::Lunas);

        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$gratis->Uuid}/refund"), ['Alasan' => 'Mau coba refund tagihan nol'])->assertSessionHasErrors('Umum');

        $manual = TerbitkanManualUji($this, $this->keuangan, $this->tenant, ['KodePaket' => 'ENTERPRISE']);
        MasukManual($this, $this->keuangan)->post(BantuanPengelola::Url("/tagihan/{$manual->Uuid}/refund"), ['Alasan' => 'Belum dibayar tetapi minta refund'])->assertSessionHasErrors('Umum');
        expect(RefundLangganan::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});
