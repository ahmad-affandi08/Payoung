<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Sinkron\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Sinkron\Data\DataKonteksSinkron;
use App\Domain\Bersama\Sinkron\Data\HasilItemSinkron;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Bersama\Sinkron\Kontrak\PenanganItemSinkron;
use App\Domain\Bersama\Sinkron\Kontrak\PenjagaAsalItemSinkron;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DetectsConcurrencyErrors;
use Throwable;

/**
 * Memproses batch outbox POS berurutan (PRD §18: FIFO per perangkat, shift sebelum transaksinya). Setiap item
 * diteruskan ke penangan sesuai `Jenis`; pelanggaran aturan bisnis menjadi `Ditolak` tanpa menghentikan item
 * berikutnya. Galat tak terduga dibiarkan naik (HTTP 500): perangkat mengirim ulang seluruh batch dan item yang sudah
 * diterima kembali sebagai `Duplikat`, sehingga aman.
 *
 * Audit P0 F-01: tiap item dikreditkan ke perangkat asalnya (`PenjagaAsalItemSinkron`): item boleh membawa
 * `UuidPerangkatAsal` (outbox perangkat lama yang dikirim setelah aktivasi ulang) dan perangkat dicabut hanya boleh
 * mengirim item yang dibuat sebelum dicabut. Item jalur pemulihan yang diterima ditandai untuk ditinjau.
 *
 * Audit F-07: item yang sama dikirim dua perangkat/permintaan bersamaan bisa membuat MySQL memilih salah satu transaksi
 * sebagai korban deadlock/lock wait. Item itu diulang (maks. [PERCOBAAN_KONKURENSI] kali, jeda singkat acak); karena
 * setiap item berjalan di transaksinya sendiri dan idempoten per Uuid, ulangan menghasilkan `Duplikat`, bukan HTTP 500.
 */
final class PemrosesSinkron
{
    use DetectsConcurrencyErrors;

    public const PERCOBAAN_KONKURENSI = 5;

    /** @var array<string, PenanganItemSinkron>|null */
    private ?array $penangan = null;

    public function __construct(
        private readonly Container $container,
        private readonly PenjagaAsalItemSinkron $penjagaAsal,
        private readonly PencatatAudit $audit,
        private readonly PencatatGalatSinkron $pencatatGalat,
    ) {}

    /**
     * @param  list<array{Jenis: string, Uuid: string, Data: array<string, mixed>, UuidPerangkatAsal?: string|null}>  $item
     * @return list<HasilItemSinkron>
     */
    public function Proses(array $item, DataKonteksSinkron $konteks): array
    {
        $hasil = [];

        foreach ($item as $satu) {
            $hasil[] = $this->ProsesDenganUlangan($satu, $konteks);
        }

        return $hasil;
    }

    /**
     * @param  array{Jenis: string, Uuid: string, Data: array<string, mixed>, UuidPerangkatAsal?: string|null}  $item
     */
    private function ProsesDenganUlangan(array $item, DataKonteksSinkron $konteks): HasilItemSinkron
    {
        for ($percobaan = 1; ; $percobaan++) {
            try {
                return $this->ProsesSatu($item, $konteks);
            } catch (Throwable $galat) {
                if ($percobaan >= self::PERCOBAAN_KONKURENSI || ! $this->causedByConcurrencyError($galat)) {
                    throw $galat;
                }

                usleep(random_int(20_000, 120_000) * $percobaan);
            }
        }
    }

    /**
     * @param  array{Jenis: string, Uuid: string, Data: array<string, mixed>, UuidPerangkatAsal?: string|null}  $item
     */
    private function ProsesSatu(array $item, DataKonteksSinkron $konteks): HasilItemSinkron
    {
        $penangan = $this->AmbilPenangan()[$item['Jenis']] ?? null;

        try {
            if ($penangan === null) {
                throw new PelanggaranAturanBisnis('JenisItemTidakDikenal', "Jenis data \"{$item['Jenis']}\" tidak dikenal server. Perbarui aplikasi kasir.", 'Jenis');
            }

            $asal = $this->penjagaAsal->Tentukan($item['Uuid'], $item['UuidPerangkatAsal'] ?? null, $konteks);
            $this->audit->AturPerangkat($asal->idPerangkat);

            try {
                // F-15/§18: item POS diproses dalam mode sinkron (periode terkunci tidak menolak transaksi offline).
                $status = $this->container->make(PenandaSinkronPos::class)->Jalankan(fn (): StatusItemSinkron => $penangan->Proses($item['Uuid'], $item['Data'], $asal));
            } finally {
                $this->audit->AturPerangkat($konteks->idPerangkat);
            }

            if ($status !== StatusItemSinkron::Ditolak) {
                $this->penjagaAsal->CatatPemulihan($item['Uuid'], $item['Jenis'], $asal, $konteks);
            }

            return new HasilItemSinkron($item['Uuid'], $item['Jenis'], $status);
        } catch (PelanggaranAturanBisnis $galat) {
            // PGL-20: hitung penolakan per jenis item dan kode galat untuk dasbor operasional (hanya penghitung cache).
            $this->pencatatGalat->Catat($item['Jenis'], $galat->kode);

            return new HasilItemSinkron($item['Uuid'], $item['Jenis'], StatusItemSinkron::Ditolak, [
                'Kode' => $galat->kode,
                'Pesan' => $galat->getMessage(),
                'Bidang' => $galat->bidang,
                'Detail' => $galat->detail,
            ]);
        }
    }

    /**
     * @return array<string, PenanganItemSinkron>
     */
    private function AmbilPenangan(): array
    {
        if ($this->penangan === null) {
            $this->penangan = [];

            foreach ($this->container->tagged(PenanganItemSinkron::TAG) as $penangan) {
                if ($penangan instanceof PenanganItemSinkron) {
                    $this->penangan[$penangan->AmbilJenis()] = $penangan;
                }
            }
        }

        return $this->penangan;
    }
}
