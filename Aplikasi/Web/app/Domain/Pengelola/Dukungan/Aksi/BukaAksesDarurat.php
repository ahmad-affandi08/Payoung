<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use App\Domain\Dukungan\Enum\KategoriAksesDarurat;
use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Pengelola\Dukungan\Surel\AksesDaruratDibuka;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Akses darurat tanpa izin Owner (PGL-16, P-09 langkah 5, izin `dukungan.akses.darurat` = hanya Super Admin): wajib
 * kategori (insiden keamanan atau permintaan hukum) dan alasan tertulis, maksimal 4 jam, tidak membuka data pribadi
 * pelanggan. Owner diberi tahu lewat email segera setelah akses dibuka (di luar transaksi, lewat antrean), dan akses ini
 * hanya bisa dipakai Super Admin yang membukanya. Tidak dibuka bila tenant sudah punya izin akses yang berlaku.
 */
final class BukaAksesDarurat
{
    public const MAKS_JAM = 4;

    public const MIN_PANJANG_ALASAN = 20;

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PemilikTenant $pemilik,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis TanpaIzin, TenantTidakDitemukan, AlasanTidakValid, DurasiTidakValid, AksesSudahAktif
     */
    public function Jalankan(PenggunaPengelola $pelaku, int $idTenant, KategoriAksesDarurat $kategori, string $alasan, int $durasiJam, CakupanAksesDukungan $cakupan): AksesDukungan
    {
        if (! $pelaku->Aktif || ! $pelaku->PunyaIzin(IzinPengelola::DukunganAksesDarurat)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Akses darurat hanya untuk Super Admin.');
        }

        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::MIN_PANJANG_ALASAN || mb_strlen($alasan) > 500) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Tulis alasan akses darurat dengan jelas, '.self::MIN_PANJANG_ALASAN.' sampai 500 karakter.', 'Alasan');
        }

        if ($durasiJam < 1 || $durasiJam > self::MAKS_JAM) {
            throw new PelanggaranAturanBisnis('DurasiTidakValid', 'Durasi akses darurat 1 sampai '.self::MAKS_JAM.' jam.', 'DurasiJam');
        }

        $tenant = Tenant::query()->whereKey($idTenant)->first();

        if (! $tenant instanceof Tenant || $tenant->Status === StatusTenant::Ditutup) {
            throw new PelanggaranAturanBisnis('TenantTidakDitemukan', 'Tenant tidak ditemukan atau akunnya sudah ditutup.');
        }

        $akses = $this->konteks->JalankanLintasTenant("Akses darurat ke tenant {$tenant->Nama}: {$kategori->AmbilLabel()}", fn (): AksesDukungan => DB::transaction(function () use ($pelaku, $idTenant, $kategori, $alasan, $durasiJam, $cakupan): AksesDukungan {
            $aktif = AksesDukungan::query()->whereNull('DicabutPada')->where('BerakhirPada', '>', now())->lockForUpdate()->exists();

            if ($aktif) {
                throw new PelanggaranAturanBisnis('AksesSudahAktif', 'Tenant ini sudah punya izin akses dukungan yang berlaku. Pakai izin itu, atau cabut dulu.');
            }

            $akses = AksesDukungan::query()->create([
                'Jenis' => JenisAksesDukungan::Darurat,
                'IdPenggunaPengelola' => $pelaku->Id,
                'Cakupan' => $cakupan,
                'DataPribadiTerbuka' => false,
                'Alasan' => $alasan,
                'KategoriDarurat' => $kategori,
                'MulaiPada' => now(),
                'BerakhirPada' => now()->addHours($durasiJam),
            ]);

            $this->audit->Catat('dukungan.akses.darurat', $akses, nilaiBaru: [
                'Kategori' => $kategori->value,
                'Cakupan' => $cakupan->value,
                'DurasiJam' => $durasiJam,
            ], alasan: $alasan, idPelaku: $pelaku->Id, idTenant: $idTenant);

            return $akses;
        }), $idTenant);

        $this->BeritahuPemilik($akses, $tenant, $kategori, $alasan, $cakupan);

        return $akses;
    }

    /** Email ke setiap Owner aktif; dikirim lewat antrean setelah akses tersimpan, jadi kegagalan email tidak membatalkan akses. */
    private function BeritahuPemilik(AksesDukungan $akses, Tenant $tenant, KategoriAksesDarurat $kategori, string $alasan, CakupanAksesDukungan $cakupan): void
    {
        $kontak = $this->konteks->JalankanLintasTenant('Memberi tahu Owner tentang akses darurat', fn (): array => $this->pemilik->AmbilKontak($tenant->Id), $tenant->Id);
        $terkirim = false;

        foreach ($kontak as $orang) {
            if ($orang['Email'] === null) {
                continue;
            }

            Mail::to($orang['Email'])->queue(new AksesDaruratDibuka(
                $orang['Nama'],
                $tenant->Nama,
                $kategori->AmbilLabel(),
                $alasan,
                $cakupan->AmbilLabel(),
                $akses->BerakhirPada->timezone('Asia/Jakarta')->format('d/m/Y H.i').' WIB',
                AlamatDomain::BuatUrlAbsolutTenant('/kelola/pengaturan/akses-dukungan'),
            ));
            $terkirim = true;
        }

        if ($terkirim) {
            $this->konteks->JalankanLintasTenant('Menandai Owner sudah diberi tahu', function () use ($akses): void {
                $segar = AksesDukungan::query()->whereKey($akses->Id)->first();
                $segar?->forceFill(['PemilikDiberitahuPada' => now()])->save();
            }, $tenant->Id);
        }
    }
}
