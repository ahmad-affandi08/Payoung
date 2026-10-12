<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Unduhan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Unduhan\Enum\StatusBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Layanan\BatasEksporLaporan;
use App\Domain\Bersama\Unduhan\Model\BerkasUnduhan;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Menyiapkan satu berkas Pusat Unduhan (D-124): menjalankan penangan ekspor laporan yang sama dengan unduhan langsung
 * (angka ekspor = angka di layar), menangkap keluarannya ke berkas sementara tanpa menampung semuanya di memori, lalu
 * memindahkannya ke disk privat. Dipanggil setelah respons dikirim ke peminta (fase `terminating`), dalam konteks tenant
 * dan pengguna yang sama dengan permintaan aslinya. Kegagalan apa pun menjadi status Gagal, tidak pernah melempar.
 */
final class ProsesBerkasUnduhan
{
    private const GALAT_UMUM = 'Berkas gagal disiapkan. Coba lagi dengan rentang tanggal atau saringan yang lebih sempit.';

    public function __construct(private readonly BatasEksporLaporan $batas) {}

    /**
     * @param  Closure(): Response  $hasilkan  menjalankan sisa pipeline permintaan ekspor (penangan kontroler)
     */
    public function Jalankan(BerkasUnduhan $berkas, Closure $hasilkan): void
    {
        $berkas->update(['Status' => StatusBerkasUnduhan::Diproses, 'MulaiPada' => now()]);
        @set_time_limit(0);
        ignore_user_abort(true);
        $sementara = tempnam(sys_get_temp_dir(), 'payoung-unduhan-');
        $nivel = ob_get_level();

        try {
            if ($sementara === false) {
                throw new PelanggaranAturanBisnis('UnduhanTidakDapatDibuat', self::GALAT_UMUM);
            }

            $this->batas->AktifkanLatarBelakang();
            $nama = $this->TangkapKeBerkas($hasilkan, $sementara, $nivel);
            $ukuran = (int) filesize($sementara);

            if ($ukuran === 0) {
                throw new PelanggaranAturanBisnis('UnduhanKosong', self::GALAT_UMUM);
            }

            $path = "unduhan/{$berkas->IdTenant}/{$berkas->Uuid}.{$berkas->Format}";
            $isi = fopen($sementara, 'rb');

            if ($isi === false) {
                throw new PelanggaranAturanBisnis('UnduhanTidakDapatDibuat', self::GALAT_UMUM);
            }

            try {
                Storage::disk((string) config('tenant.DiskUnduhan'))->writeStream($path, $isi);
            } finally {
                fclose($isi);
            }

            $berkas->update([
                'Status' => StatusBerkasUnduhan::Siap,
                'PathBerkas' => $path,
                'NamaBerkas' => $nama ?? "laporan.{$berkas->Format}",
                'Judul' => $nama === null ? $berkas->Judul : $this->JudulDariNama($nama, $berkas->Format),
                'UkuranByte' => $ukuran,
                'SelesaiPada' => now(),
                'KedaluwarsaPada' => now()->addDays((int) config('tenant.HariBerlakuUnduhan')),
                'Galat' => null,
            ]);
        } catch (Throwable $galat) {
            $pesan = match (true) {
                $galat instanceof PelanggaranAturanBisnis => $galat->getMessage(),
                $galat instanceof ValidationException => (string) collect($galat->errors())->flatten()->first(),
                default => self::GALAT_UMUM,
            };

            if (! $galat instanceof PelanggaranAturanBisnis && ! $galat instanceof ValidationException) {
                report($galat);
            }

            $berkas->update(['Status' => StatusBerkasUnduhan::Gagal, 'SelesaiPada' => now(), 'Galat' => mb_substr($pesan !== '' ? $pesan : self::GALAT_UMUM, 0, 300)]);
        } finally {
            $this->batas->NonaktifkanLatarBelakang();

            if (is_string($sementara)) {
                @unlink($sementara);
            }
        }
    }

    /**
     * Menjalankan penangan dan menulis semua keluarannya ke `$tujuan` per potongan (memori tetap kecil untuk puluhan
     * ribu baris). Mengembalikan nama berkas dari header unduhan penangan.
     *
     * @param  Closure(): Response  $hasilkan
     */
    private function TangkapKeBerkas(Closure $hasilkan, string $tujuan, int $nivel): ?string
    {
        $isi = fopen($tujuan, 'wb');

        if ($isi === false) {
            throw new PelanggaranAturanBisnis('UnduhanTidakDapatDibuat', self::GALAT_UMUM);
        }

        ob_start(static function (string $potongan) use ($isi): string {
            fwrite($isi, $potongan);

            return '';
        }, 65536);

        try {
            $respons = $hasilkan();

            if (! $respons instanceof StreamedResponse) {
                throw new PelanggaranAturanBisnis('UnduhanTidakDapatDibuat', self::GALAT_UMUM);
            }

            $respons->sendContent();
            $disposisi = (string) $respons->headers->get('Content-Disposition');
        } finally {
            while (ob_get_level() > $nivel) {
                ob_end_flush();
            }

            fclose($isi);
        }

        return preg_match('/filename="([^"]+)"/', $disposisi, $cocok) === 1 ? $cocok[1] : null;
    }

    private function JudulDariNama(string $nama, string $format): string
    {
        $tanpaEkstensi = preg_replace('/\.'.preg_quote($format, '/').'$/i', '', $nama) ?? $nama;

        return mb_substr(ucfirst(str_replace(['-', '_'], ' ', $tanpaEkstensi)), 0, 150);
    }
}
