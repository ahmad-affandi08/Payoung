<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Dukungan\Layanan\PenyamarDataPribadi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menyamarkan data pribadi pada respons yang dilihat petugas dukungan bila Owner tidak membukanya (PGL-16, BR-P09.1).
 *
 * Yang dilewatkan tanpa diubah: pengalihan, galat (status >= 400), dan respons tanpa isi. Yang disamarkan: JSON (termasuk
 * jawaban XHR Inertia dan data tabel) dan data halaman Inertia di HTML pertama. Yang DITOLAK karena isinya tidak bisa
 * disamarkan per kunci: halaman HTML biasa (cetak struk, faktur, laporan), berkas unduhan (CSV, Excel, PDF, ZIP), dan
 * gambar kecuali gambar produk dan logo usaha. Aturan "tolak selain yang dikenal" menjaga agar jalur baru tidak
 * membocorkan data tanpa disadari.
 */
final class PenyamarResponsDukungan
{
    /** Gambar yang tidak memuat data pribadi: foto produk dan logo usaha. */
    private const RUTE_GAMBAR_AMAN = [
        'kelola.produk.gambar',
        'kelola.pengaturan.profil-usaha.logo',
        'kelola.panduan-awal.profil-usaha.logo',
        'kelola.kasir.struk.logo',
    ];

    public const PESAN_DITOLAK = 'Cetak dan unduhan tidak tersedia pada akses dukungan, kecuali Owner membuka data pribadi pelanggan.';

    public function __construct(private readonly PenyamarDataPribadi $penyamar) {}

    /**
     * @return Response|null Respons yang sudah aman ditampilkan, atau null bila harus ditolak
     */
    public function Terapkan(Request $permintaan, Response $respons): ?Response
    {
        if ($respons->isRedirection() || $respons->getStatusCode() >= 400 || $respons->getStatusCode() === 204) {
            return $respons;
        }

        if ($respons instanceof JsonResponse) {
            $respons->setData($this->SamarkanJson($permintaan, $respons->getData(true)));

            return $respons;
        }

        if ($respons instanceof BinaryFileResponse || $respons instanceof StreamedResponse) {
            return $this->CekGambarAman($permintaan, $respons) ? $respons : null;
        }

        $tipe = (string) $respons->headers->get('Content-Type', '');
        $isi = $respons->getContent();

        if ($isi === false || $isi === '') {
            return $respons;
        }

        if (str_starts_with($tipe, 'text/html')) {
            return $this->SamarkanHalamanInertia($respons, $isi);
        }

        return $this->CekGambarAman($permintaan, $respons) ? $respons : null;
    }

    /**
     * Jawaban JSON halaman pelanggan (data tabel `{Data, Meta}`) berisi baris pelanggan, jadi `Nama` di barisnya adalah nama
     * orang. Pada jawaban Inertia (data halaman), `Nama` hanya disamarkan di dalam objek berkunci `Pelanggan*` supaya nama
     * pengguna, paket, dan menu yang ikut dikirim tidak ikut rusak.
     */
    private function SamarkanJson(Request $permintaan, mixed $data): mixed
    {
        $tabelPelanggan = ! $permintaan->headers->has('X-Inertia')
            && $permintaan->routeIs('kelola.pelanggan', 'kelola.pelanggan.*')
            && is_array($data)
            && array_key_exists('Data', $data);

        if (! $tabelPelanggan) {
            return $this->penyamar->Samarkan($data);
        }

        /** @var array<string, mixed> $data */
        $baris = $data['Data'];
        unset($data['Data']);

        /** @var array<string, mixed> $hasil */
        $hasil = $this->penyamar->Samarkan($data);
        $hasil['Data'] = $this->penyamar->Samarkan($baris, true);

        return $hasil;
    }

    private function CekGambarAman(Request $permintaan, Response $respons): bool
    {
        $disposisi = (string) $respons->headers->get('Content-Disposition', '');

        return str_starts_with((string) $respons->headers->get('Content-Type', ''), 'image/')
            && ! str_contains($disposisi, 'attachment')
            && $permintaan->routeIs(...self::RUTE_GAMBAR_AMAN);
    }

    /** HTML pertama halaman Inertia membawa datanya di `<script data-page type="application/json">`; selain itu ditolak. */
    private function SamarkanHalamanInertia(Response $respons, string $isi): ?Response
    {
        $ditemukan = false;
        $baru = preg_replace_callback(
            '#(<script data-page="[^"]*" type="application/json">)(.*?)(</script>)#s',
            function (array $cocok) use (&$ditemukan): string {
                $halaman = json_decode($cocok[2], true);

                if (! is_array($halaman)) {
                    return $cocok[0];
                }

                $ditemukan = true;

                return $cocok[1].json_encode($this->penyamar->Samarkan($halaman), JSON_HEX_TAG | JSON_THROW_ON_ERROR).$cocok[3];
            },
            $isi,
        );

        if (! $ditemukan || ! is_string($baru)) {
            return null;
        }

        $respons->setContent($baru);

        return $respons;
    }
}
