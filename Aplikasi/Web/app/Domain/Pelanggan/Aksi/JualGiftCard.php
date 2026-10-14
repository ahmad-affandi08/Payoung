<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Layanan\PenyediaAkunPeran;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\JenisMutasiGiftCard;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Enum\SumberMutasiGiftCard;
use App\Domain\Pelanggan\Kueri\PengaturanGiftCardTenant;
use App\Domain\Pelanggan\Layanan\BukuGiftCard;
use App\Domain\Pelanggan\Layanan\KodeGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use App\Domain\Pelanggan\Model\Pelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Jual gift card dari back-office (CRM-06, D-153, izin `pelanggan.deposit.kelola`, J-16.10): kartu baru berkode acak
 * dengan nilai Rupiah bulat dalam batas `config/pelanggan.php`, uangnya diterima di akun kas/bank yang dipilih
 * (Dr kas/bank, Cr Saldo Gift Card — kewajiban, bukan pendapatan; PPN/PBJT dipungut saat kartu dipakai berbelanja).
 * Masa berlaku opsional per kartu (`masaBerlakuHari`; kosong = tanpa kedaluwarsa). Idempoten per `uuid` dari klien.
 * Kode kartu hanya dikembalikan di sini (untuk ditampilkan sekali); audit mencatat kode yang disamarkan.
 */
final class JualGiftCard
{
    public function __construct(
        private readonly BukuGiftCard $buku,
        private readonly DaftarAkunPilihan $akun,
        private readonly PostingJurnal $postingJurnal,
        private readonly PenyediaAkunPeran $penyediaAkun,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(
        string $uuid,
        Uang $nilai,
        string $uuidAkunKasBank,
        ?string $uuidPelanggan,
        ?string $namaPemegang,
        ?int $masaBerlakuHari,
        ?string $catatan,
        int $idPengguna,
        CarbonImmutable $tanggal,
    ): GiftCard {
        $ada = GiftCard::query()->where('Uuid', $uuid)->first();

        if ($ada !== null) {
            return $ada;
        }

        $nilai = self::PeriksaNilai($nilai);

        if ($masaBerlakuHari !== null && ($masaBerlakuHari < 1 || $masaBerlakuHari > PengaturanGiftCardTenant::MASA_BERLAKU_MAKSIMAL_HARI)) {
            throw new PelanggaranAturanBisnis('MasaBerlakuTidakValid', 'Masa berlaku antara 1 dan '.PengaturanGiftCardTenant::MASA_BERLAKU_MAKSIMAL_HARI.' hari, atau kosongkan untuk tanpa kedaluwarsa.', 'MasaBerlakuHari');
        }

        $akun = $this->akun->CariKasBankDariUuid($uuidAkunKasBank)
            ?? throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas/bank aktif tempat uang kartu diterima.', 'UuidAkun');
        $idPelanggan = null;

        if ($uuidPelanggan !== null && $uuidPelanggan !== '') {
            $idPelanggan = Pelanggan::query()->where('Uuid', $uuidPelanggan)->value('Id')
                ?? throw new PelanggaranAturanBisnis('PelangganTidakDitemukan', 'Pembeli tidak ditemukan.', 'UuidPelanggan');
        }

        return DB::transaction(function () use ($uuid, $nilai, $akun, $idPelanggan, $namaPemegang, $masaBerlakuHari, $catatan, $idPengguna, $tanggal): GiftCard {
            $kartu = $this->BuatKartu($uuid, $nilai, $idPelanggan, $namaPemegang, $masaBerlakuHari, $catatan, $idPengguna, $tanggal);
            $mutasi = $this->buku->Catat($kartu->Id, $nilai, JenisMutasiGiftCard::Jual, SumberMutasiGiftCard::Kartu, $kartu->Id, null, $tanggal, 'Dijual dari back-office', $idPengguna, $akun['Id']);
            $this->penyediaAkun->Pastikan(PeranAkun::SaldoGiftCard, '2-1550', 'Saldo Gift Card');
            $mutasi->IdJurnal = $this->postingJurnal->Jalankan(new DataJurnal(
                jenisSumber: JenisSumberJurnal::MutasiGiftCard,
                idSumber: $mutasi->Id,
                uuidSumber: $mutasi->Uuid,
                nomorSumber: null,
                tanggal: $tanggal,
                keterangan: 'Penjualan gift card '.KodeGiftCard::Samarkan($kartu->Kode),
                baris: [
                    new DataBarisJurnal(null, $akun['Id'], null, $nilai, Uang::Nol(), 'Penjualan gift card'),
                    DataBarisJurnal::Kredit(PeranAkun::SaldoGiftCard, $nilai, null, 'Penjualan gift card'),
                ],
                idPengguna: $idPengguna,
            ))->idJurnal;
            $mutasi->save();
            $this->audit->Catat('pelanggan.gift-card-jual', $kartu, null, [
                'Kartu' => KodeGiftCard::Samarkan($kartu->Kode),
                'Nilai' => $nilai->KeString(),
                'Akun' => $akun['Kode'],
                'BerlakuSampai' => $kartu->BerlakuSampai?->toDateString(),
            ], idPengguna: $idPengguna);

            return $kartu;
        });
    }

    private function BuatKartu(string $uuid, Uang $nilai, ?int $idPelanggan, ?string $namaPemegang, ?int $masaBerlakuHari, ?string $catatan, int $idPengguna, CarbonImmutable $tanggal): GiftCard
    {
        // Tabrakan kode (peluang ±1 : 10^17) ditangani dengan mengundi ulang; savepoint menjaga transaksi tetap hidup.
        for ($percobaan = 0; $percobaan < 5; $percobaan++) {
            try {
                return DB::transaction(fn (): GiftCard => GiftCard::query()->create([
                    'Uuid' => $uuid,
                    'Kode' => KodeGiftCard::Buat(),
                    'NilaiAwal' => $nilai->KeString(),
                    'Saldo' => '0.00',
                    'BerlakuSampai' => $masaBerlakuHari === null ? null : $tanggal->addDays($masaBerlakuHari)->toDateString(),
                    'Status' => StatusGiftCard::Aktif,
                    'IdPelanggan' => $idPelanggan,
                    'NamaPemegang' => $namaPemegang === null || trim($namaPemegang) === '' ? null : mb_substr(trim($namaPemegang), 0, 100),
                    'Catatan' => $catatan === null || trim($catatan) === '' ? null : mb_substr(trim($catatan), 0, 255),
                    'IdPenerbit' => $idPengguna,
                    'DiterbitkanPada' => now(),
                ]));
            } catch (UniqueConstraintViolationException $galat) {
                if ($percobaan === 4) {
                    throw $galat;
                }
            }
        }

        throw new PelanggaranAturanBisnis('KodeGiftCardGagal', 'Kode gift card gagal dibuat. Coba lagi.');
    }

    private static function PeriksaNilai(Uang $nilai): Uang
    {
        $minimal = PengaturanGiftCardTenant::AmbilMinimalNilai();
        $maksimal = PengaturanGiftCardTenant::AmbilMaksimalNilai();

        if (! str_ends_with($nilai->KeString(), '.00') || $nilai->Bandingkan($minimal) < 0 || $nilai->Bandingkan($maksimal) > 0) {
            throw new PelanggaranAturanBisnis('NilaiGiftCardTidakValid', "Nilai gift card Rupiah bulat antara {$minimal->FormatRupiah()} dan {$maksimal->FormatRupiah()}.", 'Nilai');
        }

        return $nilai;
    }
}
