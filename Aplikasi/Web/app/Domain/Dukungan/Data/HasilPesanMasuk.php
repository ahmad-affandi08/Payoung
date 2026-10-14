<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Data;

/**
 * Hasil memproses pesan masuk: `hasil` = TiketBaru, Balasan, atau Ditolak; `duplikat` = pesan ini sudah pernah diproses
 * (hasil sebelumnya dikembalikan apa adanya). `kode` hanya terisi bila ditolak.
 */
final readonly class HasilPesanMasuk
{
    public function __construct(
        public string $hasil,
        public ?string $nomorTiket = null,
        public ?string $kode = null,
        public bool $duplikat = false,
    ) {}

    /**
     * @return array{Hasil: string, NomorTiket: string|null, Kode: string|null, Duplikat: bool}
     */
    public function KeLarik(): array
    {
        return ['Hasil' => $this->hasil, 'NomorTiket' => $this->nomorTiket, 'Kode' => $this->kode, 'Duplikat' => $this->duplikat];
    }
}
