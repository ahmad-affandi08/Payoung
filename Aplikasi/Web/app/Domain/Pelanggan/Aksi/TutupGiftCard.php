<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\JenisMutasiGiftCard;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Enum\SumberMutasiGiftCard;
use App\Domain\Pelanggan\Layanan\BukuGiftCard;
use App\Domain\Pelanggan\Layanan\KodeGiftCard;
use App\Domain\Pelanggan\Layanan\PenutupSaldoGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use App\Domain\Pelanggan\Model\MutasiGiftCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menutup gift card dari back-office (CRM-06, D-153, izin `pelanggan.deposit.kelola`), alasan ≥ 5 karakter:
 * - [Hanguskan]: sisa saldo diakui pendapatan lain (Dr Saldo Gift Card, Cr Pendapatan Lain); kartu `Hangus`.
 * - [Kembalikan]: kartu yang belum pernah dipakai dibatalkan dan uang pembelian dikembalikan dari akun kas/bank
 *   (Dr Saldo Gift Card, Cr kas/bank); kartu `Dibatalkan`. Kartu yang sudah terpakai tidak bisa diuangkan
 *   (`GiftCardSudahDipakai`): sisanya hanya bisa dihanguskan.
 * Audit `pelanggan.gift-card-hangus` / `pelanggan.gift-card-kembali` (kode disamarkan).
 */
final class TutupGiftCard
{
    public function __construct(
        private readonly BukuGiftCard $buku,
        private readonly PenutupSaldoGiftCard $penutup,
        private readonly DaftarAkunPilihan $akun,
        private readonly PencatatAudit $audit,
    ) {}

    public function Hanguskan(GiftCard $kartu, string $alasan, int $idPengguna, CarbonImmutable $tanggal): MutasiGiftCard
    {
        $alasan = self::PeriksaAlasan($alasan);

        return DB::transaction(function () use ($kartu, $alasan, $idPengguna, $tanggal): MutasiGiftCard {
            $terkunci = $this->buku->AmbilKartuTerkunci($kartu->Id);
            $this->PeriksaMasihTerbuka($terkunci);

            if (Uang::Dari($terkunci->Saldo)->Bandingkan(Uang::Nol()) <= 0) {
                throw new PelanggaranAturanBisnis('SaldoGiftCardKosong', 'Kartu ini sudah tidak punya sisa saldo untuk dihanguskan.', 'Alasan');
            }

            $sisa = $terkunci->Saldo;
            $mutasi = $this->penutup->Tutup($terkunci, JenisMutasiGiftCard::Hangus, SumberMutasiGiftCard::Kartu, $tanggal, $alasan, $idPengguna);
            $this->audit->Catat('pelanggan.gift-card-hangus', $terkunci, ['Saldo' => $sisa], ['Kartu' => KodeGiftCard::Samarkan($terkunci->Kode), 'Alasan' => $alasan], idPengguna: $idPengguna);

            return $mutasi;
        });
    }

    public function Kembalikan(GiftCard $kartu, string $uuidAkunKasBank, string $alasan, int $idPengguna, CarbonImmutable $tanggal): MutasiGiftCard
    {
        $alasan = self::PeriksaAlasan($alasan);
        $akun = $this->akun->CariKasBankDariUuid($uuidAkunKasBank)
            ?? throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas/bank aktif sumber pengembalian.', 'UuidAkun');

        return DB::transaction(function () use ($kartu, $akun, $alasan, $idPengguna, $tanggal): MutasiGiftCard {
            $terkunci = $this->buku->AmbilKartuTerkunci($kartu->Id);
            $this->PeriksaMasihTerbuka($terkunci);

            $dipakai = MutasiGiftCard::query()->where('IdGiftCard', $terkunci->Id)->whereIn('Jenis', [JenisMutasiGiftCard::Pakai->value, JenisMutasiGiftCard::BatalPakai->value])->exists();

            if ($dipakai || Uang::Dari($terkunci->Saldo)->Bandingkan(Uang::Dari($terkunci->NilaiAwal)) !== 0) {
                throw new PelanggaranAturanBisnis('GiftCardSudahDipakai', 'Kartu yang sudah pernah dipakai tidak bisa diuangkan. Sisa saldonya hanya bisa dihanguskan.', 'Alasan');
            }

            $mutasi = $this->penutup->Tutup($terkunci, JenisMutasiGiftCard::Kembali, SumberMutasiGiftCard::Kartu, $tanggal, $alasan, $idPengguna, $akun['Id']);
            $this->audit->Catat('pelanggan.gift-card-kembali', $terkunci, null, [
                'Kartu' => KodeGiftCard::Samarkan($terkunci->Kode),
                'Nilai' => $terkunci->NilaiAwal,
                'Akun' => $akun['Kode'],
                'Alasan' => $alasan,
            ], idPengguna: $idPengguna);

            return $mutasi;
        });
    }

    private function PeriksaMasihTerbuka(GiftCard $kartu): void
    {
        if ($kartu->Status === StatusGiftCard::Hangus || $kartu->Status === StatusGiftCard::Dibatalkan) {
            throw new PelanggaranAturanBisnis('GiftCardSudahDitutup', "Kartu ini sudah {$kartu->Status->AmbilLabel()}.", 'Alasan');
        }
    }

    private static function PeriksaAlasan(string $alasan): string
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5) {
            throw new PelanggaranAturanBisnis('AlasanWajib', 'Tulis alasan minimal 5 karakter.', 'Alasan');
        }

        return mb_substr($alasan, 0, 255);
    }
}
