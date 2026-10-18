<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\GalatPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\PendaftarMerchantPembayaran;
use App\Domain\Integrasi\Merchant\PenyimpanBerkasKyc;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use App\Domain\Referensi\Enum\JenisReferensiBank;
use App\Domain\Referensi\Kueri\ReferensiBankAktif;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Mengirim pendaftaran berstatus `Dikirim` ke penyedia pembayaran lewat `PendaftarMerchantPembayaran` (DOKU: Generate
 * Token, Upload File KTP + swafoto + foto tempat usaha, lalu Business Registration). Konteks tenant harus sudah diatur
 * pemanggil (tugas antrean).
 *
 * - Idempoten dan dapat dilanjutkan: adaptor mencatat kemajuan (id berkas) di baris pendaftaran; bila `IdBisnisDoku`
 *   sudah ada, penyedia tidak didaftari ulang.
 * - Galat 4xx pasti = `Gagal` dengan pesan tersaring (berkas lokal yang tersisa dihapus, data diperbaiki lalu simpan
 *   ulang). Galat tidak pasti (jaringan, 5xx, kredensial partner, batas laju) = tetap `Dikirim` dan mengembalikan
 *   true supaya pemanggil mengulang; `Menyerah()` mengembalikan ke `Draf` bila percobaan habis.
 * - Satu pengiriman per tenant sekaligus (kunci cache). Rahasia, NIK, dan nomor rekening tidak masuk pesan galat.
 */
final class KirimPendaftaranMerchant
{
    public const AKSI_AUDIT = 'merchant-pembayaran.terkirim';

    private const DETIK_KUNCI = 180;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PendaftarMerchantPembayaran $penyedia,
        private readonly PenyimpanBerkasKyc $berkas,
        private readonly ReferensiBankAktif $bank,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return bool true = galat tidak pasti, pemanggil sebaiknya mengulang nanti
     */
    public function Jalankan(): bool
    {
        $idTenant = $this->konteks->Wajib();

        try {
            return Cache::lock("pendaftaran-merchant:{$idTenant}", self::DETIK_KUNCI)->block(5, fn (): bool => $this->Proses());
        } catch (LockTimeoutException) {
            return true;
        }
    }

    /** Percobaan habis: kembali ke `Draf` (foto yang belum terunggah tetap tersimpan sampai kedaluwarsa) agar tenant bisa mengirim lagi. */
    public function Menyerah(): void
    {
        $pendaftaran = PendaftaranMerchantPembayaran::query()->where('Status', StatusPendaftaranMerchant::Dikirim->value)->first();

        if ($pendaftaran !== null && ! $pendaftaran->CekSudahTerdaftar()) {
            $pendaftaran->update(['Status' => StatusPendaftaranMerchant::Draf, 'PesanGalat' => $this->penyedia->AmbilLabel().' belum bisa dihubungi, jadi pengiriman belum berhasil. Coba kirim lagi sebentar lagi.']);
        }
    }

    private function Proses(): bool
    {
        $pendaftaran = PendaftaranMerchantPembayaran::query()->where('Status', StatusPendaftaranMerchant::Dikirim->value)->first();

        if ($pendaftaran === null || $pendaftaran->CekSudahTerdaftar()) {
            return false;
        }

        if (! $this->penyedia->CekAktif()) {
            $pendaftaran->update(['Status' => StatusPendaftaranMerchant::Draf, 'PesanGalat' => 'Layanan aktivasi QRIS belum tersedia. Hubungi dukungan Payoung.']);

            return false;
        }

        try {
            $this->DaftarkanBisnis($pendaftaran);

            return false;
        } catch (GalatPendaftaranMerchant $galat) {
            $pesan = $this->Saring($galat->getMessage(), $pendaftaran);

            if ($galat->tidakPasti) {
                $pendaftaran->update(['PesanGalat' => $pesan]);

                return true;
            }

            $this->HapusSisaBerkas($pendaftaran);
            $pendaftaran->update(['Status' => StatusPendaftaranMerchant::Gagal, 'PesanGalat' => $pesan]);
            $this->audit->Catat(self::AKSI_AUDIT, $pendaftaran, ['Status' => 'Dikirim'], ['Status' => 'Gagal', 'PesanGalat' => $pesan]);

            return false;
        }
    }

    private function DaftarkanBisnis(PendaftaranMerchantPembayaran $pendaftaran): void
    {
        $bank = null;

        foreach ($this->bank->Ambil([JenisReferensiBank::Bank]) as $kandidat) {
            if ($kandidat['Id'] === $pendaftaran->IdReferensiBank) {
                $bank = ['Kode' => $kandidat['Kode'], 'Nama' => $kandidat['Nama']];
            }
        }

        if ($bank === null) {
            throw new GalatPendaftaranMerchant('Bank yang dipilih sudah tidak tersedia. Simpan ulang data dan pilih bank lagi.');
        }

        $hasil = $this->penyedia->Kirim($pendaftaran, $bank);
        $status = $hasil->status;

        $pendaftaran->update([
            'IdBisnisDoku' => $hasil->idBisnis,
            'IdBrandDoku' => $hasil->idBrand,
            'KunciBersama' => $hasil->kunciBersama,
            'StatusDoku' => $hasil->statusPenyedia,
            'Status' => $status,
            'PesanGalat' => null,
            'AlasanPenolakan' => $status === StatusPendaftaranMerchant::Ditolak ? ($hasil->alasanPenolakan ?? $this->penyedia->AmbilLabel().' menolak pendaftaran ini.') : null,
            'DikirimPada' => now(),
            'DiperiksaPada' => now(),
            'DisetujuiPada' => $status === StatusPendaftaranMerchant::Aktif ? now() : null,
        ]);
        $this->HapusSisaBerkas($pendaftaran);

        $this->audit->Catat(self::AKSI_AUDIT, $pendaftaran, ['Status' => 'Dikirim'], ['Status' => $status->value, 'IdBisnisDoku' => $hasil->idBisnis]);
    }

    private function HapusSisaBerkas(PendaftaranMerchantPembayaran $pendaftaran): void
    {
        foreach (['PathKtp', 'PathSwafoto', 'PathBuktiUsaha'] as $kolomPath) {
            $this->berkas->Hapus($pendaftaran->{$kolomPath});
        }

        $pendaftaran->update(['PathKtp' => null, 'PathSwafoto' => null, 'PathBuktiUsaha' => null, 'BerkasDiunggahPada' => null]);
    }

    /** Pesan dari DOKU bisa memantulkan isian; NIK dan nomor rekening dibuang sebelum disimpan. */
    private function Saring(string $pesan, PendaftaranMerchantPembayaran $pendaftaran): string
    {
        $rahasia = array_filter([(string) $pendaftaran->Nik, (string) $pendaftaran->NomorRekening], static fn (string $s): bool => $s !== '');

        return mb_substr(str_replace($rahasia, '••••', $pesan), 0, 300);
    }
}
