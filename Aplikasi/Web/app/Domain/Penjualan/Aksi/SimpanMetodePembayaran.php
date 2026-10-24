<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Penjualan\Data\DataMetodePembayaran;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Layanan\PemeriksaQris;
use App\Domain\Penjualan\Layanan\PenyimpanGambarQris;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Referensi\Kueri\ReferensiBankAktif;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F-01 langkah 5: menambah QRIS statis (unggah gambar QR dari penerbit), QRIS dinamis (F-08: tagihan dibuat lewat
 * gerbang pembayaran yang diaktifkan platform; tanpa gambar/bank), EDC per bank, atau transfer bank.
 * - QRIS statis wajib gambar; disimpan di disk privat dan dihapus lagi bila transaksi gagal. D-189: teks payload QRIS
 *   (hasil baca gambar di peramban) opsional; bila ada, diperiksa (`PemeriksaQris`) dan kasir menyusun QRIS bernominal.
 * - EDC wajib bank/jaringan EDC aktif; transfer wajib bank/dompet digital aktif + nomor & nama pemilik rekening.
 * - Biaya (MDR) 0–10 persen, string desimal (tidak pernah float).
 * - F-16d: deposit pelanggan (satu per tenant, tanpa biaya; akun Saldo Deposit Pelanggan dari pemetaan akun).
 * - CRM-06 (D-153): gift card (satu per tenant, tanpa biaya; akun Saldo Gift Card dari pemetaan akun).
 * - X8: platform ojol/marketplace wajib satu kanal platform (GoFood/GrabFood/ShopeeFood/Marketplace), satu metode per
 *   kanal; komisi platform 0–40 persen; dana masuk lewat pencairan platform (Piutang Pencairan).
 * - Tunai dipastikan ada lebih dulu (idempoten). Nama unik per tenant (tanpa beda huruf besar/kecil): kirim ganda ditolak.
 */
final class SimpanMetodePembayaran
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly SiapkanMetodePembayaranBawaan $siapkanBawaan,
        private readonly ReferensiBankAktif $referensiBank,
        private readonly PenyimpanGambarQris $penyimpanGambar,
        private readonly PemeriksaQris $pemeriksaQris,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataMetodePembayaran $data, ?UploadedFile $gambarQris): MetodePembayaran
    {
        $idTenant = $this->konteks->Wajib();
        $isian = $this->SusunIsian($data, $gambarQris);
        // D-189: teks QRIS (opsional) diperiksa sebelum berkas apa pun disimpan; yang cacat ditolak dengan alasan jelas.
        $teksQris = $data->jenis === JenisMetodePembayaran::QrisStatis && $data->teksQris !== null && trim($data->teksQris) !== '' ? trim($data->teksQris) : null;

        if ($teksQris !== null) {
            $this->pemeriksaQris->Periksa($teksQris);
        }

        $path = $data->jenis === JenisMetodePembayaran::QrisStatis && $gambarQris !== null ? $this->penyimpanGambar->Simpan($idTenant, $gambarQris) : null;

        try {
            return DB::transaction(function () use ($idTenant, $isian, $path, $data): MetodePembayaran {
                $this->penguncian->Kunci($idTenant);
                $this->siapkanBawaan->Jalankan($idTenant);

                // Kirim ganda (klik dua kali, tab lain) tidak membuat metode kembar: nama unik per tenant, diperiksa di
                // bawah kunci Tenant. Berkas QRIS kiriman kedua dihapus lagi oleh blok catch.
                $namaKecil = mb_strtolower(trim($data->nama));
                $namaAda = MetodePembayaran::query()->pluck('Nama')->contains(fn (mixed $nama): bool => mb_strtolower(trim((string) $nama)) === $namaKecil);

                if ($namaAda) {
                    throw new PelanggaranAturanBisnis('NamaMetodeSudahAda', 'Metode pembayaran dengan nama ini sudah ada.', 'Nama');
                }

                // F-16d: satu metode deposit per tenant (saldo pelanggan hanya satu buku).
                if ($data->jenis === JenisMetodePembayaran::Deposit && MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Deposit->value)->exists()) {
                    throw new PelanggaranAturanBisnis('MetodeDepositSudahAda', 'Metode deposit pelanggan sudah ada. Aktifkan metode yang ada bila dinonaktifkan.', 'Jenis');
                }

                // CRM-06: satu metode gift card per tenant (kartu dipilih lewat kodenya, bukan lewat metode).
                if ($data->jenis === JenisMetodePembayaran::GiftCard && MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::GiftCard->value)->exists()) {
                    throw new PelanggaranAturanBisnis('MetodeGiftCardSudahAda', 'Metode gift card sudah ada. Aktifkan metode yang ada bila dinonaktifkan.', 'Jenis');
                }

                // X8: kasir memilih metode platform menurut kanal penjualan, jadi satu metode per kanal.
                if ($isian['Kanal'] instanceof KanalPenjualan && MetodePembayaran::query()->where('Kanal', $isian['Kanal']->value)->exists()) {
                    throw new PelanggaranAturanBisnis('MetodeKanalSudahAda', "Metode pembayaran untuk {$isian['Kanal']->AmbilLabel()} sudah ada. Aktifkan metode yang ada bila dinonaktifkan.", 'Kanal');
                }

                $urutan = (int) MetodePembayaran::query()->max('Urutan');

                $metode = MetodePembayaran::query()->create([...$isian, 'PathGambarQris' => $path, 'TeksQris' => $teksQris, 'Urutan' => $urutan + 1, 'Aktif' => true]);
                $this->audit->Catat('metode-pembayaran.buat', $metode, nilaiBaru: [
                    'Jenis' => $metode->Jenis->value,
                    'Nama' => $metode->Nama,
                    'KodeBank' => $data->kodeBank,
                    'NomorRekening' => $metode->NomorRekening,
                    'Kanal' => $metode->Kanal?->value,
                    'PersenBiaya' => $metode->PersenBiaya,
                    'NominalOtomatis' => $teksQris !== null,
                ]);

                return $metode;
            });
        } catch (Throwable $galat) {
            $this->penyimpanGambar->Hapus($path);

            throw $galat;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function SusunIsian(DataMetodePembayaran $data, ?UploadedFile $gambarQris): array
    {
        $jenis = $data->jenis;

        if (! $jenis->CekBisaDibuatPanduan()) {
            throw new PelanggaranAturanBisnis('JenisTidakDidukung', 'Pilih QRIS statis, QRIS dinamis, kartu (EDC), transfer bank, deposit pelanggan, gift card, atau platform ojol/marketplace.', 'Jenis');
        }

        $kanal = $jenis === JenisMetodePembayaran::Marketplace ? $data->kanal : null;

        if ($jenis === JenisMetodePembayaran::Marketplace && ($kanal === null || ! $kanal->CekPlatform())) {
            throw new PelanggaranAturanBisnis('KanalPlatformWajib', 'Pilih platform: GoFood, GrabFood, ShopeeFood, atau Marketplace.', 'Kanal');
        }

        if ($jenis === JenisMetodePembayaran::QrisStatis && $gambarQris === null) {
            throw new PelanggaranAturanBisnis('GambarQrisWajib', 'Unggah gambar QRIS dari bank atau penyedia QRIS Anda.', 'GambarQris');
        }

        $idBank = null;

        if ($jenis->AmbilJenisBankBoleh() !== []) {
            $bank = $data->kodeBank === null ? null : $this->referensiBank->CariKode($data->kodeBank);
            $jenisBoleh = array_map(fn ($satu) => $satu->value, $jenis->AmbilJenisBankBoleh());

            if ($bank === null || ! in_array($bank['Jenis'], $jenisBoleh, true)) {
                throw new PelanggaranAturanBisnis('BankTidakDikenal', 'Pilih bank dari daftar.', 'KodeBank');
            }

            $idBank = $bank['Id'];
        }

        $nomorRekening = $data->nomorRekening === null ? null : trim($data->nomorRekening);
        $namaPemilik = $data->namaPemilikRekening === null ? null : trim($data->namaPemilikRekening);

        if ($jenis === JenisMetodePembayaran::Transfer && ($nomorRekening === null || $nomorRekening === '')) {
            throw new PelanggaranAturanBisnis('NomorRekeningWajib', 'Isi nomor rekening tujuan transfer.', 'NomorRekening');
        }

        if ($jenis === JenisMetodePembayaran::Transfer && ($namaPemilik === null || $namaPemilik === '')) {
            throw new PelanggaranAturanBisnis('NamaPemilikRekeningWajib', 'Isi nama pemilik rekening.', 'NamaPemilikRekening');
        }

        return [
            'Jenis' => $jenis,
            'Nama' => trim($data->nama),
            'IdReferensiBank' => $idBank,
            'NomorRekening' => $jenis === JenisMetodePembayaran::Transfer ? $nomorRekening : null,
            'NamaPemilikRekening' => $jenis === JenisMetodePembayaran::Transfer ? $namaPemilik : null,
            'Kanal' => $kanal,
            // F-16d: deposit bukan layanan penyedia pembayaran, jadi tanpa biaya MDR.
            'PersenBiaya' => in_array($jenis, [JenisMetodePembayaran::Deposit, JenisMetodePembayaran::GiftCard], true) ? '0' : $this->AmbilPersenBiaya($data->persenBiaya, self::AmbilPersenMaksimal($jenis)),
        ];
    }

    /** Batas biaya: MDR penyedia pembayaran, atau (X8) komisi platform ojol/marketplace yang lebih besar. */
    public static function AmbilPersenMaksimal(JenisMetodePembayaran $jenis): string
    {
        return (string) config($jenis === JenisMetodePembayaran::Marketplace ? 'pembayaran.PersenBiayaPlatformMaksimal' : 'pembayaran.PersenBiayaMaksimal');
    }

    private function AmbilPersenBiaya(?string $persen, string $maksimal): string
    {
        if ($persen === null || trim($persen) === '') {
            return '0';
        }

        try {
            $nilai = BigDecimal::of(trim($persen));
        } catch (MathException) {
            $nilai = null;
        }

        if ($nilai === null || $nilai->isNegative() || $nilai->isGreaterThan($maksimal) || $nilai->getScale() > 4) {
            throw new PelanggaranAturanBisnis('PersenBiayaTidakSah', "Biaya 0 sampai {$maksimal} persen, maksimal 4 angka di belakang titik.", 'PersenBiaya');
        }

        return (string) $nilai;
    }
}
