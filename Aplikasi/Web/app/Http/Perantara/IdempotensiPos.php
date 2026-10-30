<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Idempotensi\Layanan\PenyimpanIdempotensi;
use App\Http\Respons\GalatApi;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit F-12 (aturan emas #13, §16.1): header `Idempotency-Key` pada mutasi `/api/pos/v1/*` (setelah
 * `AutentikasiPerangkat`). Kunci sama + permintaan sama (metode, jalur, isi) = respons tersimpan diputar ulang
 * (`Idempotency-Replayed: true`); kunci sama + isi berbeda = 409 `KunciIdempotensiBentrok`; permintaan dengan kunci yang
 * masih diproses = 409 `PermintaanSedangDiproses`. Yang disimpan hanya jawaban JSON final selama 24 jam per perangkat:
 * 2xx, 400, dan 422 (ditentukan isi permintaannya sendiri). Jawaban yang bergantung keadaan di luar permintaan TIDAK
 * disimpan: 401/402/403 (langganan ditangguhkan lalu dibayar, fitur paket belum aktif, izin diberikan belakangan), 404 (dokumen
 * yang dirujuk belum sampai di server), 408/409/423/429 (sementara). Kalau disimpan, klien yang mengirim ulang dengan kunci
 * yang sama (Gudang memakai kunci tetap per draf) terus mendapat penolakan lama sampai 24 jam sesudah keadaannya pulih. Tanpa header = diproses seperti biasa (kompatibel mundur, aturan emas #16); data
 * tetap idempoten lewat `Uuid` klien.
 */
final class IdempotensiPos
{
    public const HEADER = 'Idempotency-Key';

    /** Penolakan yang bisa berubah tanpa isi permintaan berubah: tidak boleh diputar ulang. */
    private const STATUS_TIDAK_DISIMPAN = [401, 402, 403, 404, 408, 409, 423, 429];

    public function __construct(private readonly PenyimpanIdempotensi $penyimpan) {}

    public function handle(Request $request, Closure $next): Response
    {
        $kunci = $request->header(self::HEADER);

        if ($request->isMethodSafe() || ! is_string($kunci)) {
            return $next($request);
        }

        $kunci = trim($kunci);

        if (preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $kunci) !== 1) {
            return GalatApi::Buat('KunciIdempotensiTidakValid', 'Header Idempotency-Key harus 8–100 karakter huruf, angka, atau _-:.', 400);
        }

        $perangkat = AutentikasiPerangkat::AmbilPerangkat($request);
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        $kunciProses = Cache::lock("idempotensi-pos:{$perangkat->Id}:{$kunci}", 120);

        if (! $kunciProses->get()) {
            return GalatApi::Buat('PermintaanSedangDiproses', 'Permintaan dengan Idempotency-Key ini masih diproses. Coba lagi sebentar.', 409);
        }

        try {
            $ada = $this->penyimpan->Ambil($perangkat->Id, $kunci);

            if ($ada !== null) {
                if (! hash_equals($ada->HashPermintaan, $hash)) {
                    return GalatApi::Buat('KunciIdempotensiBentrok', 'Idempotency-Key ini sudah dipakai untuk permintaan lain. Buat kunci baru.', 409);
                }

                return new JsonResponse($ada->Respons, $ada->StatusHttp, ['Idempotency-Replayed' => 'true'], json: true);
            }

            $respons = $next($request);

            if ($respons instanceof JsonResponse && $respons->getStatusCode() < 500 && ! in_array($respons->getStatusCode(), self::STATUS_TIDAK_DISIMPAN, true)) {
                $this->penyimpan->Simpan($perangkat->IdTenant, $perangkat->Id, $kunci, $request->method(), $request->path(), $hash, $respons->getStatusCode(), (string) $respons->getContent());
            }

            return $respons;
        } finally {
            $kunciProses->release();
        }
    }
}
