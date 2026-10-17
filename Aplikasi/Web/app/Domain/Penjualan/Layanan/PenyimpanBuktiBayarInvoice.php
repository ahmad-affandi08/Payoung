<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Tenant\Layanan\PenjagaPenyimpanan;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto bukti transfer yang dilampirkan pelanggan pada konfirmasi pembayaran invoice (F-21 bagian 5, D-170). Pengunggahnya
 * pengunjung anonim, jadi berkas diperlakukan tidak tepercaya:
 * - ukuran berkas, jenis gambar (JPEG, PNG, WebP lewat isi, bukan nama), dan jumlah piksel diperiksa **sebelum** didekode;
 * - gambar didekode lalu **disimpan ulang sebagai JPEG** dengan sisi terpanjang dibatasi (EXIF termasuk lokasi GPS dan
 *   muatan tersembunyi tidak ikut tersimpan);
 * - disimpan di disk privat dengan nama acak per tenant, diunduh hanya lewat rute back-office berizin;
 * - ditolak bila penyimpanan paket penuh (`PenjagaPenyimpanan`); konfirmasi tanpa bukti tetap bisa dikirim.
 */
final class PenyimpanBuktiBayarInvoice
{
    private const KUALITAS_JPEG = 82;

    /** Jenis gambar yang diterima menurut isi berkas (`getimagesizefromstring`). */
    private const JENIS_DITERIMA = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private readonly PenjagaPenyimpanan $penjaga) {}

    /**
     * @return string jalur berkas di disk bukti
     *
     * @throws PelanggaranAturanBisnis BuktiTerlaluBesar, BuktiTidakValid, BuktiTidakBisaDisimpan
     */
    public function Simpan(int $idTenant, UploadedFile $berkas): string
    {
        $batasKb = (int) config('invoice.UkuranMaksimalBuktiKb');

        if ($berkas->getSize() > $batasKb * 1024) {
            throw new PelanggaranAturanBisnis('BuktiTerlaluBesar', 'Ukuran foto bukti melebihi '.intdiv($batasKb, 1024).' MB.', 'Bukti');
        }

        $isi = (string) file_get_contents($berkas->getRealPath());
        $info = $isi === '' ? false : @getimagesizefromstring($isi);

        if ($info === false || ! in_array($info[2], self::JENIS_DITERIMA, true) || $info[0] < 1 || $info[1] < 1
            || (int) config('invoice.PikselMaksimalBukti') < $info[0] * $info[1]) {
            throw new PelanggaranAturanBisnis('BuktiTidakValid', 'Bukti harus berupa foto JPEG, PNG, atau WebP yang wajar ukurannya.', 'Bukti');
        }

        $gambar = @imagecreatefromstring($isi);

        if (! $gambar instanceof GdImage) {
            throw new PelanggaranAturanBisnis('BuktiTidakValid', 'Bukti harus berupa foto JPEG, PNG, atau WebP yang wajar ukurannya.', 'Bukti');
        }

        $gambar = self::Kecilkan($gambar, (int) config('invoice.SisiMaksimalBuktiPx'));
        ob_start();
        imagejpeg($gambar, null, self::KUALITAS_JPEG);
        $jpeg = (string) ob_get_clean();

        if ($jpeg === '') {
            throw new PelanggaranAturanBisnis('BuktiTidakValid', 'Foto bukti tidak bisa diproses. Coba foto lain.', 'Bukti');
        }

        try {
            $this->penjaga->Pastikan($idTenant, strlen($jpeg));
        } catch (PelanggaranAturanBisnis) {
            throw new PelanggaranAturanBisnis('BuktiTidakBisaDisimpan', 'Bukti belum bisa dilampirkan saat ini. Kirim konfirmasi tanpa bukti, atau hubungi penjual.', 'Bukti');
        }

        $path = "invoice/bukti-bayar/{$idTenant}/".Str::ulid().'.jpg';

        if (! $this->AmbilDisk()->put($path, $jpeg)) {
            throw new RuntimeException('Foto bukti pembayaran gagal disimpan.');
        }

        $this->penjaga->Catat($idTenant, strlen($jpeg));

        return $path;
    }

    /** Membersihkan berkas bila transaksi yang menyimpannya gagal. */
    public function Hapus(?string $path): void
    {
        if ($path !== null) {
            $this->AmbilDisk()->delete($path);
        }
    }

    public function Unduh(?string $path): StreamedResponse
    {
        abort_if($path === null || ! $this->AmbilDisk()->exists($path), 404);

        return $this->AmbilDisk()->response($path, basename($path), [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="bukti-pembayaran.jpg"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private static function Kecilkan(GdImage $gambar, int $sisiMaksimal): GdImage
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $terpanjang = max($lebar, $tinggi);

        if ($terpanjang <= $sisiMaksimal) {
            return $gambar;
        }

        $hasil = imagescale($gambar, max(1, intdiv($lebar * $sisiMaksimal, $terpanjang)), max(1, intdiv($tinggi * $sisiMaksimal, $terpanjang)));

        return $hasil instanceof GdImage ? $hasil : $gambar;
    }

    private function AmbilDisk(): FilesystemAdapter
    {
        $disk = Storage::disk((string) config('invoice.DiskBukti'));

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('Disk bukti pembayaran invoice tidak dikenal.');
        }

        return $disk;
    }
}
