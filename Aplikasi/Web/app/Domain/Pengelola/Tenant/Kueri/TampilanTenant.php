<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Dukungan\Kueri\RiwayatAksesDukungan;
use App\Domain\Integrasi\Merchant\PendaftarMerchantPembayaran;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pengelola\Tenant\Aksi\AktifkanKembaliTenant;
use App\Domain\Pengelola\Tenant\Aksi\PerpanjangTrial;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\Tenant\Model\CatatanTenant;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Penjualan\Kueri\PemakaianTransaksi;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Kueri\SumberFiturTenant;
use App\Domain\Tenant\Layanan\EvaluatorFitur;
use App\Domain\Tenant\Layanan\PenjagaPenyimpanan;
use App\Domain\Tenant\Model\AtribusiMitra;
use App\Domain\Tenant\Model\DokumenLegal;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\Mitra;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\PersetujuanDokumenLegal;
use App\Domain\Tenant\Model\Tenant;

/**
 * Tampilan 360° dasar satu tenant (P-07). Data usaha tenant (outlet, gudang, merek) dibaca lewat
 * `KonteksPengelola::JalankanLintasTenant` sehingga setiap pembukaan tercatat di log audit (CLAUDE.md #11).
 * Bagian yang modulnya belum dibangun (tagihan, tiket, perangkat, skor kesehatan) tidak diisi di sini;
 * halaman menampilkannya sebagai keadaan kosong.
 */
final class TampilanTenant
{
    public const BATAS_RIWAYAT = 50;

    public const BATAS_CATATAN = 100;

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PencatatAuditPengelola $audit,
        private readonly SumberFiturTenant $sumberFitur,
        private readonly EvaluatorFitur $evaluator,
        private readonly PemakaianBatasOrganisasi $pemakaian,
        private readonly PendaftarMerchantPembayaran $pendaftarMerchant,
        private readonly PenjagaPenyimpanan $penyimpanan,
        private readonly PemakaianTransaksi $pemakaianTransaksi,
        private readonly PenghapusanDataTenant $penghapusanData,
        private readonly RiwayatAksesDukungan $aksesDukungan,
        private readonly RingkasanKesehatanTenant $kesehatan,
        private readonly RingkasanPerangkatTenant $perangkat,
        private readonly RingkasanTiketTenant $tiket,
        private readonly RingkasanTagihanTenant $tagihan,
    ) {}

    /**
     * `$denganTiket` dan `$denganTagihan` menandakan pelaku boleh melihat tab itu (`dukungan.tiket.lihat`, `tagihan.lihat`);
     * bila tidak, kuncinya `null` dan datanya tidak pernah dibaca.
     *
     * @return array<string, mixed>
     */
    public function Ambil(Tenant $tenant, bool $denganTiket = false, bool $denganTagihan = false): array
    {
        $tenant->loadMissing('Langganan.Paket');
        $organisasi = $this->konteks->JalankanLintasTenant(
            'Membuka tampilan 360° tenant',
            fn (): array => [
                'Outlet' => array_values(Outlet::query()->orderBy('Id')->get(['Id', 'Kode', 'Nama', 'TemplateSektor', 'KodeKota'])
                    ->map(fn (Outlet $outlet): array => [
                        'Kode' => $outlet->Kode,
                        'Nama' => $outlet->Nama,
                        'TemplateSektor' => $outlet->TemplateSektor,
                        'KodeKota' => $outlet->KodeKota,
                    ])->all()),
                'JumlahGudang' => Gudang::query()->count(),
                'JumlahMerek' => Merek::query()->count(),
                // Pemakaian dihitung dengan kueri yang sama dengan penegak batas F-02a, agar angka yang dilihat
                // Dukungan sama dengan yang menolak tenant (BR-02.1, BR-P04.3).
                'PakaiOutlet' => $this->pemakaian->HitungOutlet(),
                'PakaiPengguna' => $this->pemakaian->HitungPengguna($tenant->Id),
                'PakaiPenyimpananMb' => (int) ceil($this->penyimpanan->AmbilByte($tenant->Id) / 1048576),
                // P-04: transaksi penjualan bulan berjalan (kueri yang sama dengan penegak kuota).
                'PakaiTransaksiBulan' => $this->pemakaianTransaksi->HitungBulan($this->pemakaianTransaksi->AmbilPeriodeBulan()),
                'PakaiTransaksiHari' => $this->pemakaianTransaksi->HitungHari(),
                'PendaftaranMerchant' => self::AmbilPendaftaranMerchant(),
                // PGL-16: izin akses dukungan dan sesi petugas (nama, waktu, jumlah; tanpa data pribadi).
                'AksesDukungan' => $this->aksesDukungan->Ambil(10),
                // P-07 tab Perangkat & Tiket: dibaca dalam pembukaan yang sama (satu baris audit akses data tenant).
                'Perangkat' => $this->perangkat->Ambil(),
                'Tiket' => $denganTiket ? $this->tiket->Ambil() : null,
            ],
            $tenant->Id,
        );

        $anggota = TenantPengguna::query()->with('Pengguna')->where('IdTenant', $tenant->Id)->orderByDesc('Pemilik')->orderBy('Id')->get();
        $batas = $this->evaluator->HitungBatasEfektif($this->sumberFitur->Ambil($tenant->Id));

        return [
            'Profil' => [
                'Uuid' => $tenant->Uuid,
                'Nama' => $tenant->Nama,
                'Slug' => $tenant->Slug,
                'Npwp' => $tenant->Npwp,
                'Pkp' => $tenant->Pkp,
                'ZonaWaktu' => $tenant->ZonaWaktu,
                'Status' => $tenant->Status->value,
                'Penanda' => $tenant->Penanda?->value,
                'DibuatPada' => $tenant->DibuatPada->toIso8601String(),
                'TemplateSektor' => array_values(array_unique(array_filter(array_column($organisasi['Outlet'], 'TemplateSektor')))),
            ],
            'Langganan' => $this->PetakanLangganan($tenant),
            // D-49: add-on yang dimiliki tenant (aktif atau baru berakhir), untuk dukungan & penagihan.
            'Addon' => array_values(LanggananAddon::query()->with('Addon')->where('IdTenant', $tenant->Id)->orderByDesc('SelesaiPada')->get()->map(fn (LanggananAddon $milik): array => [
                'Kode' => $milik->Addon->Kode,
                'Nama' => $milik->Addon->Nama,
                'Jumlah' => $milik->Jumlah,
                'SelesaiPada' => $milik->SelesaiPada->toIso8601String(),
                'Aktif' => $milik->CekAktifPada(now()),
                'Berhenti' => $milik->BerhentiPada !== null,
            ])->all()),
            // P-12: mitra perujuk tenant ini (BR-P12.2: paling banyak satu), null bila mendaftar langsung.
            'MitraPerujuk' => self::AmbilMitraPerujuk($tenant->Id),
            // Pendaftaran merchant pembayaran DOKU Partner (KYB): status, ID bisnis/brand, dan penampung merchant QRIS.
            // NIK & rekening tersamar, foto sudah dihapus, shared key tidak pernah keluar.
            'PendaftaranMerchant' => [
                'Pendaftaran' => $organisasi['PendaftaranMerchant'],
                'PartnerAktif' => $this->pendaftarMerchant->CekAktif(),
            ],
            'Pemakaian' => [
                ['Label' => 'Outlet', 'Pakai' => $organisasi['PakaiOutlet'], 'Batas' => $batas['BatasOutlet'] ?? null],
                ['Label' => 'Pengguna', 'Pakai' => $organisasi['PakaiPengguna'], 'Batas' => $batas['BatasPengguna'] ?? null],
                ['Label' => 'Penyimpanan (MB)', 'Pakai' => $organisasi['PakaiPenyimpananMb'], 'Batas' => $batas['BatasPenyimpananMb'] ?? null],
                ['Label' => 'Transaksi bulan ini', 'Pakai' => $organisasi['PakaiTransaksiBulan'], 'Batas' => $batas['KuotaTransaksiBulanan'] ?? null],
                ['Label' => 'Transaksi hari ini', 'Pakai' => $organisasi['PakaiTransaksiHari'], 'Batas' => $batas['KuotaTransaksiHarian'] ?? null],
            ],
            'Organisasi' => [
                'Outlet' => $organisasi['Outlet'],
                'JumlahGudang' => $organisasi['JumlahGudang'],
                'JumlahMerek' => $organisasi['JumlahMerek'],
            ],
            'Anggota' => array_values($anggota->map(fn (TenantPengguna $baris): array => [
                'Nama' => $baris->Pengguna->Nama,
                'Email' => $baris->Pengguna->Email,
                'NoHp' => $baris->Pengguna->NoHp,
                'EmailTerverifikasi' => $baris->Pengguna->EmailDiverifikasiPada !== null,
                'Pemilik' => $baris->Pemilik,
                'Status' => $baris->Status->value,
            ])->all()),
            'PersetujuanLegal' => $this->AmbilPersetujuanLegal($tenant->Id),
            'Override' => $this->AmbilOverride($tenant->Id),
            'Catatan' => $this->AmbilCatatan($tenant->Id),
            'Kesehatan' => $this->kesehatan->Ambil($tenant),
            'Perangkat' => $organisasi['Perangkat'],
            'Tiket' => $organisasi['Tiket'],
            // Tagihan adalah catatan platform (bukan data usaha), jadi dibaca tanpa pembukaan konteks tenant.
            'Tagihan' => $denganTagihan ? $this->tagihan->Ambil($tenant) : null,
            'AksesDukungan' => $organisasi['AksesDukungan'],
            // UU PDP: permintaan penghapusan data dan penutupan akun (angka ringkasan saja, tanpa data pribadi).
            'PenghapusanData' => $this->penghapusanData->Ambil($tenant->Id),
            'Riwayat' => $this->audit->AmbilRiwayatTenant($tenant->Id, self::BATAS_RIWAYAT, [KonteksPengelola::AKSI_AUDIT]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function PetakanLangganan(Tenant $tenant): ?array
    {
        $langganan = $tenant->Langganan;

        if ($langganan === null) {
            return null;
        }

        $tujuan = $langganan->Status === StatusLangganan::Ditangguhkan ? AktifkanKembaliTenant::TentukanTujuan($langganan) : null;
        $perpanjangan = OverrideTenant::query()->where('IdTenant', $tenant->Id)->where('Jenis', JenisOverride::Trial->value)->count();

        return [
            'Status' => $langganan->Status->value,
            'StatusSebelumDitangguhkan' => $langganan->StatusSebelumDitangguhkan?->value,
            'StatusSetelahDiaktifkan' => $tujuan?->value,
            'BisaDiaktifkan' => $tujuan !== null,
            'KodePaket' => $langganan->Paket->Kode,
            'NamaPaket' => $langganan->Paket->Nama,
            'TrialBerakhirPada' => $langganan->TrialBerakhirPada?->toIso8601String(),
            'PeriodeMulai' => $langganan->PeriodeMulai?->toIso8601String(),
            'PeriodeSelesai' => $langganan->PeriodeSelesai?->toIso8601String(),
            'SiklusTagihan' => $langganan->SiklusTagihan->value,
            'PerpanjanganTrial' => $perpanjangan,
            'SisaPerpanjanganTrial' => max(0, PerpanjangTrial::MAKS_KALI - $perpanjangan),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function AmbilPersetujuanLegal(int $idTenant): array
    {
        $persetujuan = PersetujuanDokumenLegal::query()->where('IdTenant', $idTenant)->orderByDesc('DisetujuiPada')->get();
        $dokumen = DokumenLegal::query()->whereKey($persetujuan->pluck('IdDokumenLegal')->all())->get()->keyBy('Id');
        $pengguna = Pengguna::query()->whereKey($persetujuan->pluck('IdPengguna')->all())->pluck('Nama', 'Id');

        return array_values($persetujuan->map(function (PersetujuanDokumenLegal $baris) use ($dokumen, $pengguna): array {
            $dokumenLegal = $dokumen->get($baris->IdDokumenLegal);

            return [
                'Jenis' => $dokumenLegal?->Jenis->AmbilLabel() ?? '—',
                'Versi' => $dokumenLegal?->Versi,
                'Pengguna' => $pengguna->get($baris->IdPengguna, '—'),
                'DisetujuiPada' => $baris->DisetujuiPada->toIso8601String(),
            ];
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function AmbilOverride(int $idTenant): array
    {
        $override = OverrideTenant::query()->where('IdTenant', $idTenant)->orderByDesc('Id')->limit(self::BATAS_RIWAYAT)->get();
        $nama = PenggunaPengelola::query()->whereKey($override->pluck('DibuatOleh')->all())->pluck('Nama', 'Id');

        return array_values($override->map(fn (OverrideTenant $baris): array => [
            'Uuid' => $baris->Uuid,
            'Jenis' => $baris->Jenis->value,
            'Kunci' => $baris->Kunci,
            'Nilai' => $baris->Nilai,
            'BerakhirPada' => $baris->BerakhirPada->toIso8601String(),
            'Aktif' => $baris->CekAktif(),
            'Alasan' => $baris->Alasan,
            'DibuatOleh' => $nama->get($baris->DibuatOleh, '—'),
            'DibuatPada' => $baris->DibuatPada->toIso8601String(),
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function AmbilCatatan(int $idTenant): array
    {
        return array_values(CatatanTenant::query()
            ->with('Penulis:Id,Nama')
            ->where('IdTenant', $idTenant)
            ->orderByDesc('Id')
            ->limit(self::BATAS_CATATAN)
            ->get()
            ->map(fn (CatatanTenant $catatan): array => [
                'Uuid' => $catatan->Uuid,
                'Isi' => $catatan->Isi,
                'Penulis' => $catatan->Penulis->Nama,
                'DibuatPada' => $catatan->DibuatPada->toIso8601String(),
            ])->all());
    }

    /**
     * Dipanggil di dalam `JalankanLintasTenant`, jadi scope `MilikTenant` membatasi ke tenant yang dibuka.
     *
     * @return array<string, mixed>|null
     */
    private static function AmbilPendaftaranMerchant(): ?array
    {
        $p = PendaftaranMerchantPembayaran::UntukPenyediaAktif()->first();

        return $p === null ? null : [
            'Uuid' => $p->Uuid,
            'Status' => $p->Status->value,
            'LabelStatus' => $p->Status->AmbilLabel(),
            'NamaPemilik' => $p->NamaPemilik,
            'NamaUsaha' => $p->NamaUsaha,
            'NikTersamar' => $p->NikTersamar(),
            'RekeningTersamar' => $p->RekeningTersamar(),
            'IdBisnisDoku' => $p->IdBisnisDoku,
            'IdBrandDoku' => $p->IdBrandDoku,
            'StatusDoku' => $p->StatusDoku,
            'PesanGalat' => $p->PesanGalat,
            'AlasanPenolakan' => $p->AlasanPenolakan,
            'DikirimPada' => $p->DikirimPada?->toIso8601String(),
            'DisetujuiPada' => $p->DisetujuiPada?->toIso8601String(),
            'DiperiksaPada' => $p->DiperiksaPada?->toIso8601String(),
            'CallbackDiterimaPada' => $p->CallbackDiterimaPada?->toIso8601String(),
            'IdPedagangQris' => $p->IdPedagangQris,
            'IdTerminalQris' => $p->IdTerminalQris,
            'BisaDisegarkan' => $p->CekSudahTerdaftar(),
        ];
    }

    /** @return array{Uuid: string, Kode: string, Nama: string, MulaiPada: string}|null */
    private static function AmbilMitraPerujuk(int $idTenant): ?array
    {
        $atribusi = AtribusiMitra::query()->where('IdTenant', $idTenant)->first();
        $mitra = $atribusi === null ? null : Mitra::query()->whereKey($atribusi->IdMitra)->first();

        return $atribusi === null || $mitra === null ? null : [
            'Uuid' => $mitra->Uuid, 'Kode' => $mitra->Kode, 'Nama' => $mitra->Nama, 'MulaiPada' => $atribusi->MulaiPada->toIso8601String(),
        ];
    }
}
