<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Kueri;

use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pelanggan\Model\Pelanggan;
use Carbon\CarbonImmutable;

/**
 * Pelanggan aktif untuk **dropdown** formulir back-office (PRD v3.14, pesanan grosir F-12): berbeda dengan
 * `CariPelangganPos` (minimal 3 huruf, membawa tier, poin, dan pemakaian promo untuk kasir), di sini kata kosong
 * menampilkan daftar awal urut nama supaya pengguna bisa memilih tanpa mengetik, dan isinya ringan: nama, nomor HP
 * tersamar, serta limit kredit & sisa piutang (yang menentukan apakah pesanan besar lolos BR-12.6).
 */
final class DaftarPilihanPelanggan
{
    public const BATAS = 20;

    public function __construct(private readonly KreditPelanggan $kredit) {}

    /**
     * Cocok nama, atau nomor HP bila yang diketik berupa angka (sebagian nomor cukup).
     *
     * @return list<array{Uuid: string, Nama: string, NoHp: string, LimitKredit: string|null, SisaPiutang: string, TerminHari: int}>
     */
    public function Cari(string $kata, int $batas = self::BATAS): array
    {
        $kata = trim($kata);
        $angka = (string) preg_replace('/\D+/', '', $kata);
        $cariHp = $angka !== '' && strlen($angka) >= 3 && preg_match('/^[0-9+() .-]+$/', $kata) === 1;

        $daftar = Pelanggan::query()
            ->where('Status', StatusPelanggan::Aktif->value)
            ->when($kata !== '', fn ($kueri) => $kueri->where(
                $cariHp ? 'NoHp' : 'Nama',
                'like',
                PenerapKueriTabel::PolaCari($cariHp ? (NomorHp::Normalisasi($kata) ?? ltrim($angka, '0')) : $kata),
            ))
            ->orderBy('Nama')
            ->orderBy('Id')
            ->limit(max(1, min(50, $batas)))
            ->get(['Id', 'Uuid', 'Nama', 'NoHp', 'TerminHari']);
        $kredit = $this->kredit->AmbilRingkas(array_values(array_map('intval', $daftar->pluck('Id')->all())), CarbonImmutable::today());

        return array_values($daftar->map(fn (Pelanggan $p): array => [
            'Uuid' => $p->Uuid,
            'Nama' => $p->Nama,
            'NoHp' => NomorHp::Samarkan($p->NoHp),
            'LimitKredit' => $kredit[$p->Id]['LimitKredit'] ?? null,
            'SisaPiutang' => $kredit[$p->Id]['SisaPiutang'] ?? '0.00',
            'TerminHari' => $p->TerminHari,
        ])->all());
    }
}
