<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Merchant;

use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;

/**
 * Adaptor DOKU untuk `PendaftarMerchantPembayaran`: Generate Token, Upload File (KTP, swafoto, foto tempat usaha), lalu
 * Business Registration / Get Business Data lewat `KlienPartnerDoku`. Bentuk badan permintaan tetap di
 * `PembentukBadanPendaftaranDoku`.
 */
final class AdaptorPendaftarDoku implements PendaftarMerchantPembayaran
{
    public const KODE = 'Doku';

    /**
     * Kolom path lokal, kolom id berkas DOKU, kategori Upload File, kode dokumen, nama untuk pesan galat.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: ?string, 4: string}>
     */
    private const BERKAS = [
        ['PathKtp', 'IdFileKtp', 'DOCUMENT', 'KTP', 'ktp'],
        ['PathSwafoto', 'IdFileSwafoto', 'OWNER_LIVENESS', null, 'swafoto'],
        ['PathBuktiUsaha', 'IdFileBuktiUsaha', 'PHOTO_PROOF', null, 'bukti-usaha'],
    ];

    public function __construct(
        private readonly KlienPartnerDoku $klien,
        private readonly PenyimpanBerkasKyc $berkas,
    ) {}

    public function AmbilKode(): string
    {
        return self::KODE;
    }

    public function AmbilLabel(): string
    {
        return 'DOKU';
    }

    public function CekAktif(): bool
    {
        return $this->klien->CekAktif();
    }

    public function Kirim(PendaftaranMerchantPembayaran $pendaftaran, array $bank): HasilPendaftaranMerchant
    {
        $this->UnggahBerkas($pendaftaran);

        $badan = PembentukBadanPendaftaranDoku::Bentuk(
            $pendaftaran,
            ['Ktp' => (string) $pendaftaran->IdFileKtp, 'Swafoto' => (string) $pendaftaran->IdFileSwafoto, 'BuktiUsaha' => (string) $pendaftaran->IdFileBuktiUsaha],
            route('webhook.pembayaran.doku-partner', ['ref' => $pendaftaran->TokenCallback]),
            $bank,
        );

        return self::Petakan($this->klien->DaftarkanBisnis($badan));
    }

    public function AmbilStatus(PendaftaranMerchantPembayaran $pendaftaran): HasilPendaftaranMerchant
    {
        return self::Petakan($this->klien->AmbilDataBisnis($pendaftaran->IdBrandDoku ?? (string) $pendaftaran->IdBisnisDoku));
    }

    private static function Petakan(HasilBisnisDoku $hasil): HasilPendaftaranMerchant
    {
        return new HasilPendaftaranMerchant(
            status: $hasil->AmbilStatusPendaftaran(),
            idBisnis: $hasil->idBisnis,
            idBrand: $hasil->idBrand,
            kunciBersama: $hasil->kunciBersama,
            statusPenyedia: $hasil->statusBisnis === '' ? null : $hasil->statusBisnis,
            alasanPenolakan: $hasil->alasanPenolakan,
        );
    }

    /**
     * Idempoten dan dapat dilanjutkan: id berkas disimpan segera setelah tiap unggahan dan berkas lokalnya langsung
     * dihapus; berkas yang sudah punya id dilewati.
     */
    private function UnggahBerkas(PendaftaranMerchantPembayaran $pendaftaran): void
    {
        $token = null;

        foreach (self::BERKAS as [$kolomPath, $kolomId, $kategori, $kode, $nama]) {
            if ($pendaftaran->{$kolomId} !== null) {
                continue;
            }

            $path = $pendaftaran->{$kolomPath};
            $isi = $path === null ? null : $this->berkas->Baca($path, $nama);

            if ($isi === null) {
                throw new GalatPartnerDoku('Salah satu foto sudah kedaluwarsa atau rusak. Simpan ulang data dan unggah fotonya lagi.');
            }

            $token ??= $this->klien->AmbilToken();
            $id = $this->klien->UnggahBerkas($token, $kategori, $kode, $isi['Isi'], $isi['Nama']);

            // Kemajuan disimpan dulu, baru berkas lokal dihapus: foto tidak menunggu lebih lama dari yang perlu.
            $pendaftaran->update([$kolomId => $id]);
            $this->berkas->Hapus($path);
            $pendaftaran->update([$kolomPath => null]);
        }
    }
}
