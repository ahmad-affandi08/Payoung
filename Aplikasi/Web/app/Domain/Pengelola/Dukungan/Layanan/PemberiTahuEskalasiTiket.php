<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Layanan;

use App\Domain\Dukungan\Enum\TingkatEskalasiTiket;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Pengelola\Dukungan\Surel\TiketDukunganDieskalasi;
use App\Domain\Pengelola\Operasional\Kueri\PenerimaSurelPeran;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email eskalasi tiket (P-09). L2: semua anggota Dukungan dan Super Admin; Teknis: anggota Teknis (bila kosong,
 * Super Admin). Dikirim langsung, bukan lewat antrean: eskalasi lahir dari tiket yang sudah terlambat, dan antrean
 * yang macet adalah salah satu penyebab keterlambatan itu. Kegagalan kirim dicatat, tidak membatalkan eskalasinya.
 */
final class PemberiTahuEskalasiTiket
{
    public function __construct(
        private readonly PenerimaSurelPeran $penerima,
        private readonly RingkasanTenant $ringkasanTenant,
    ) {}

    public function Beritahu(TiketDukungan $tiket, TingkatEskalasiTiket $tingkat, string $alasan): int
    {
        $penerima = match ($tingkat) {
            TingkatEskalasiTiket::Teknis => $this->penerima->Ambil(PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::SuperAdmin),
            default => array_values(array_unique([
                ...$this->penerima->Ambil(PeranPengelolaBawaan::Dukungan),
                ...$this->penerima->Ambil(PeranPengelolaBawaan::SuperAdmin),
            ])),
        };
        $namaTenant = $this->ringkasanTenant->Ambil([$tiket->IdTenant])[0]['Nama'] ?? "Tenant #{$tiket->IdTenant}";
        $terkirim = 0;

        foreach ($penerima as $email) {
            try {
                Mail::to($email)->send(new TiketDukunganDieskalasi(
                    $tiket->Nomor,
                    $tiket->Judul,
                    $namaTenant,
                    $tingkat->AmbilLabel(),
                    $alasan,
                    $tiket->BatasSlaPada->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y H:i').' WIB',
                    route('pengelola.dukungan.tiket.tampil', $tiket->Uuid),
                ));
                $terkirim++;
            } catch (Throwable $galat) {
                Log::error('Email eskalasi tiket gagal terkirim.', ['Tiket' => $tiket->Nomor, 'Pesan' => Str::limit($galat->getMessage(), 300)]);
            }
        }

        return $terkirim;
    }
}
