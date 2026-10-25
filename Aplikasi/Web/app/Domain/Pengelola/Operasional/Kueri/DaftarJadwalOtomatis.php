<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Pengelola\Operasional\Layanan\PencatatRiwayatJadwal;
use App\Domain\Pengelola\Operasional\Model\RiwayatJadwal;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daftar perintah terjadwal beserta jalan terakhirnya (D-209). Sumber daftar adalah jadwal yang benar-benar terdaftar di
 * `routes/console.php`, jadi tombol "Jalankan sekarang" tidak pernah menerima perintah di luar jadwal.
 */
final class DaftarJadwalOtomatis
{
    /** Jalan yang belum selesai lebih lama dari ini dianggap tidak selesai (proses mati di tengah jalan). */
    private const BATAS_BERJALAN_MENIT = 120;

    private const RIWAYAT_DITAMPILKAN = 5;

    public function __construct(
        private readonly Kernel $kernel,
        private readonly Schedule $jadwal,
    ) {}

    /**
     * @return array{Ringkasan: array{Total: int, Gagal: int, Berjalan: int, BelumPernah: int}, Data: list<array<string, mixed>>}
     */
    public function Ambil(): array
    {
        $acara = $this->AmbilAcara();
        $riwayat = RiwayatJadwal::query()
            ->whereIn('Perintah', array_keys($acara))
            ->orderByDesc('Id')
            ->get()
            ->groupBy('Perintah');

        $data = [];

        foreach ($acara as $perintah => $satu) {
            /** @var Collection<int, RiwayatJadwal> $baris */
            $baris = $riwayat->get($perintah, collect());
            $terakhir = $baris->first();

            $data[] = [
                'Perintah' => $perintah,
                'Ekspresi' => $satu->expression,
                'Zona' => $satu->timezone === null ? 'UTC' : (string) (is_string($satu->timezone) ? $satu->timezone : $satu->timezone->getName()),
                'Berikutnya' => $satu->nextRunDate()->toIso8601String(),
                'Status' => $this->Status($terakhir),
                'Terakhir' => $terakhir === null ? null : self::Petakan($terakhir),
                'Riwayat' => array_values($baris->take(self::RIWAYAT_DITAMPILKAN)->map(fn (RiwayatJadwal $r): array => self::Petakan($r))->all()),
            ];
        }

        $status = array_column($data, 'Status');

        return [
            'Ringkasan' => [
                'Total' => count($data),
                'Gagal' => count(array_filter($status, fn (string $s): bool => in_array($s, ['Gagal', 'TidakSelesai'], true))),
                'Berjalan' => count(array_filter($status, fn (string $s): bool => $s === 'Berjalan')),
                'BelumPernah' => count(array_filter($status, fn (string $s): bool => $s === 'BelumPernah')),
            ],
            'Data' => $data,
        ];
    }

    /** Apakah perintah ini ada di jadwal (dan boleh dijalankan manual). */
    public function Dikenal(string $perintah): bool
    {
        return array_key_exists($perintah, $this->AmbilAcara());
    }

    /**
     * @return array<string, Event>
     */
    private function AmbilAcara(): array
    {
        // Di permintaan web `routes/console.php` belum dimuat; `bootstrap()` memuatnya sekali (idempoten).
        $this->kernel->bootstrap();

        $hasil = [];

        foreach ($this->jadwal->events() as $acara) {
            $perintah = PencatatRiwayatJadwal::NamaPerintah($acara);

            if ($perintah !== null) {
                $hasil[$perintah] = $acara;
            }
        }

        ksort($hasil);

        return $hasil;
    }

    private function Status(?RiwayatJadwal $terakhir): string
    {
        if ($terakhir === null) {
            return 'BelumPernah';
        }

        if ($terakhir->SelesaiPada === null) {
            return $terakhir->MulaiPada->greaterThan(Carbon::now()->subMinutes(self::BATAS_BERJALAN_MENIT)) ? 'Berjalan' : 'TidakSelesai';
        }

        return $terakhir->Berhasil === true ? 'Berhasil' : 'Gagal';
    }

    /**
     * @return array<string, mixed>
     */
    private static function Petakan(RiwayatJadwal $baris): array
    {
        return [
            'MulaiPada' => $baris->MulaiPada->toIso8601String(),
            'SelesaiPada' => $baris->SelesaiPada?->toIso8601String(),
            'Berhasil' => $baris->Berhasil,
            'DurasiMs' => $baris->DurasiMs,
            'Keterangan' => $baris->Keterangan,
            'Sumber' => $baris->Sumber,
        ];
    }
}
