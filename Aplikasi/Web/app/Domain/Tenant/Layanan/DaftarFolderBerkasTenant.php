<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Folder berkas per tenant di tiap disk, dikelompokkan menurut nasibnya saat akun ditutup (UU PDP, P-07). Pola: `{id}`
 * = Id tenant, `*` = satu tingkat folder bebas. Penyimpan berkas baru wajib ditambahkan ke salah satu kelompok;
 * `PengukurPenyimpanan::FOLDER` dicek terhadap daftar ini oleh test supaya tidak ada folder yang terlupa.
 */
final class DaftarFolderBerkasTenant
{
    /**
     * Berkas yang berisi atau bisa mengungkap data pribadi (foto, wajah, KYC, lampiran bantuan, hasil ekspor, berkas
     * impor): dihapus saat penghapusan data dilaksanakan.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const PRIBADI = [
        ['katalog.DiskGambar', 'produk/{id}'],
        ['karyawan.DiskSwafoto', 'karyawan/swafoto/{id}'],
        ['pemenuhan.DiskBukti', 'pemenuhan/bukti/{id}'],
        ['tenant.DiskLogo', 'tenant/{id}'],
        ['pembayaran.DiskGambarQris', 'metode-pembayaran/{id}'],
        ['dukungan.DiskLampiran', 'dukungan/{id}'],
        ['katalog.Impor.Disk', 'impor/{id}'],
        ['merchant.DiskBerkas', 'merchant/kyc/{id}'],
        ['tenant.DiskEkspor', 'ekspor-data/{id}'],
        ['tenant.DiskUnduhan', 'unduhan/{id}'],
    ];

    /**
     * Lampiran dokumen pembukuan (faktur pembelian, bukti kas dan bank): bukti pembukuan yang wajib disimpan sepanjang
     * masa retensi pajak (`tenant.TahunRetensiPembukuan`), lalu dihapus oleh `penghapusan-data:hapus-retensi`.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const PEMBUKUAN = [
        ['kasir.DiskBuktiKas', 'kasir/bukti-kas/{id}'],
        ['akuntansi.DiskLampiran', 'akuntansi/kas-bank/{id}'],
        ['akuntansi.DiskLampiran', 'akuntansi/jurnal-umum/{id}'],
        ['akuntansi.DiskLampiran', 'pembelian/*/{id}'],
    ];

    /**
     * Folder nyata di disk untuk satu pola: `{id}` sudah diganti, `*` diurai menjadi semua subfolder yang ada.
     *
     * @return list<string>
     */
    public static function UraiFolder(Filesystem $disk, string $pola, int $idTenant): array
    {
        $pola = str_replace('{id}', (string) $idTenant, $pola);

        if (! str_contains($pola, '*')) {
            return [$pola];
        }

        [$awal, $akhir] = explode('*', $pola, 2);
        $hasil = [];

        foreach ($disk->directories(rtrim($awal, '/')) as $anak) {
            $hasil[] = $anak.$akhir;
        }

        return $hasil;
    }
}
