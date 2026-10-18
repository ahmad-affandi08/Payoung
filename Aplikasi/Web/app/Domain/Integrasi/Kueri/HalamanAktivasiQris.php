<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Kueri;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\PendaftarMerchantPembayaran;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use App\Domain\Referensi\Enum\JenisReferensiBank;
use App\Domain\Referensi\Kueri\ReferensiBankAktif;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Data halaman "Aktivasi QRIS" tenant: status pendaftaran merchant dan isian tersimpan. NIK dan nomor rekening hanya
 * keluar dalam bentuk tersamar (`••••1234`); foto tidak pernah dikirim (hanya penanda sudah tersimpan atau belum);
 * ID bisnis/brand, shared key, dan token callback tidak ikut.
 */
final class HalamanAktivasiQris
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PendaftarMerchantPembayaran $penyedia,
        private readonly ReferensiBankAktif $bank,
        private readonly ProfilTenant $profil,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(?string $namaPengguna, ?string $emailPengguna): array
    {
        $pendaftaran = PendaftaranMerchantPembayaran::UntukPenyediaAktif()->first();
        $daftarBank = $this->bank->Ambil([JenisReferensiBank::Bank]);
        $namaBank = array_column($daftarBank, 'Nama', 'Id');
        // Gagal/Ditolak = mulai lagi: foto lama sudah dihapus dan wajib diambil ulang.
        $fotoMasihAda = $pendaftaran !== null && ! in_array($pendaftaran->Status, [StatusPendaftaranMerchant::Gagal, StatusPendaftaranMerchant::Ditolak], true);

        return [
            'LayananTersedia' => $this->penyedia->CekAktif(),
            'BatasFotoMb' => (int) config('merchant.UkuranMaksimalFotoKb') / 1024,
            'DaftarBank' => array_map(fn (array $b): array => ['Id' => $b['Id'], 'Nama' => $b['Nama']], $daftarBank),
            'Awal' => [
                'NamaPemilik' => $namaPengguna ?? '',
                'Email' => $emailPengguna ?? '',
                'NamaUsaha' => $this->profil->Ambil($this->konteks->Wajib())['Nama'],
            ],
            'Pendaftaran' => $pendaftaran === null ? null : [
                'Uuid' => $pendaftaran->Uuid,
                'Status' => $pendaftaran->Status->value,
                'LabelStatus' => $pendaftaran->Status->AmbilLabel(),
                'BisaDiubah' => $pendaftaran->Status->CekBisaDiubah(),
                'NamaPemilik' => $pendaftaran->NamaPemilik,
                'NikTersamar' => $pendaftaran->NikTersamar(),
                'Email' => $pendaftaran->Email,
                'NomorHp' => $pendaftaran->NomorHp,
                'NamaUsaha' => $pendaftaran->NamaUsaha,
                'AlamatUsaha' => $pendaftaran->AlamatUsaha,
                'IdReferensiBank' => $pendaftaran->IdReferensiBank,
                'NamaBank' => $pendaftaran->IdReferensiBank === null ? null : ($namaBank[$pendaftaran->IdReferensiBank] ?? null),
                'NamaPemilikRekening' => $pendaftaran->NamaPemilikRekening,
                'RekeningTersamar' => $pendaftaran->RekeningTersamar(),
                'FotoTersimpan' => [
                    'Ktp' => $fotoMasihAda && ($pendaftaran->PathKtp !== null || $pendaftaran->IdFileKtp !== null),
                    'Swafoto' => $fotoMasihAda && ($pendaftaran->PathSwafoto !== null || $pendaftaran->IdFileSwafoto !== null),
                    'BuktiUsaha' => $fotoMasihAda && ($pendaftaran->PathBuktiUsaha !== null || $pendaftaran->IdFileBuktiUsaha !== null),
                ],
                'PesanGalat' => $pendaftaran->PesanGalat,
                'AlasanPenolakan' => $pendaftaran->AlasanPenolakan,
                'DikirimPada' => $pendaftaran->DikirimPada?->toIso8601String(),
                'DisetujuiPada' => $pendaftaran->DisetujuiPada?->toIso8601String(),
            ],
        ];
    }
}
