<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusKonfirmasiPembayaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\KonfirmasiPembayaranInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pelanggan menyatakan "sudah bayar" dari halaman publik invoice (F-21 bagian 4, D-169). Hanya **klaim** yang menunggu
 * verifikasi penagih: tidak ada jurnal, pelunasan, atau perubahan sisa tagihan.
 *
 * Syarat: invoice terbit dengan sisa tagihan; jumlah lebih dari 0 dan tidak melebihi sisa; tanggal bayar tidak di masa
 * depan dan tidak lebih dari `MAKS_HARI_LALU` hari ke belakang; nama 2–100 karakter. Dibatasi `MAKS_MENUNGGU` klaim
 * menunggu per invoice supaya halaman publik tidak bisa dipakai membanjiri penagih; klaim identik (tanggal, jumlah, nama)
 * yang masih menunggu dikembalikan apa adanya (klik ganda tidak menggandakan).
 */
final class KirimKonfirmasiPembayaranInvoice
{
    public const MAKS_MENUNGGU = 3;

    public const MAKS_HARI_LALU = 60;

    public function __construct(
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis NamaWajib, InvoiceTidakBisaDikonfirmasi, JumlahTidakValid, TanggalTidakValid, TerlaluBanyakKonfirmasi
     */
    public function Jalankan(int $idInvoice, string $nama, CarbonImmutable $tanggalBayar, Uang $jumlah, ?string $catatan): KonfirmasiPembayaranInvoice
    {
        $nama = trim($nama);
        $catatan = $catatan === null ? null : trim($catatan);

        if (mb_strlen($nama) < 2 || mb_strlen($nama) > 100) {
            throw new PelanggaranAturanBisnis('NamaWajib', 'Tulis nama pengirim (2 sampai 100 huruf).', 'Nama');
        }

        if ($catatan !== null && mb_strlen($catatan) > 300) {
            throw new PelanggaranAturanBisnis('CatatanTerlaluPanjang', 'Catatan paling banyak 300 karakter.', 'Catatan');
        }

        return DB::transaction(function () use ($idInvoice, $nama, $tanggalBayar, $jumlah, $catatan): KonfirmasiPembayaranInvoice {
            $invoice = Invoice::query()->whereKey($idInvoice)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('InvoiceTidakBisaDikonfirmasi', 'Invoice tidak ditemukan.', 'Umum', 404);
            $piutang = $invoice->Status === StatusInvoice::Terbit ? Piutang::query()->where('IdInvoice', $invoice->Id)->first() : null;

            if ($piutang === null || ! $piutang->Status->CekTerbuka()) {
                throw new PelanggaranAturanBisnis('InvoiceTidakBisaDikonfirmasi', 'Invoice ini tidak punya sisa tagihan yang perlu dikonfirmasi.', 'Umum', 409);
            }

            $sisa = $piutang->AmbilSisa();

            if ($jumlah->BernilaiNol() || $jumlah->BernilaiNegatif() || $jumlah->Bandingkan($sisa) > 0) {
                throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah harus lebih dari 0 dan paling banyak sisa tagihan ('.$sisa->FormatRupiah().').', 'Jumlah');
            }

            $hariIni = $this->tanggalBisnis->Hitung($invoice->IdOutlet);

            // Dibandingkan sebagai tanggal kalender (string `YYYY-MM-DD`), bukan instan waktu: tanggal bayar dari formulir tidak
            // membawa zona, sedangkan hari bisnis outlet jatuh pada zona usaha.
            if ($tanggalBayar->toDateString() > $hariIni->toDateString() || $tanggalBayar->toDateString() < $hariIni->subDays(self::MAKS_HARI_LALU)->toDateString()) {
                throw new PelanggaranAturanBisnis('TanggalTidakValid', 'Tanggal bayar tidak boleh di masa depan dan paling lama '.self::MAKS_HARI_LALU.' hari ke belakang.', 'TanggalBayar');
            }

            $menunggu = KonfirmasiPembayaranInvoice::query()->where('IdInvoice', $invoice->Id)->where('Status', StatusKonfirmasiPembayaran::Menunggu->value)->orderBy('Id')->get();
            $sama = $menunggu->first(fn (KonfirmasiPembayaranInvoice $k): bool => $k->TanggalBayar->toDateString() === $tanggalBayar->toDateString()
                && $k->Jumlah === $jumlah->KeString() && $k->NamaPengirim === $nama);

            if ($sama !== null) {
                return $sama;
            }

            if ($menunggu->count() >= self::MAKS_MENUNGGU) {
                throw new PelanggaranAturanBisnis('TerlaluBanyakKonfirmasi', 'Sudah ada '.self::MAKS_MENUNGGU.' konfirmasi yang menunggu verifikasi penjual. Mohon tunggu sampai diperiksa.', 'Umum', 429);
            }

            $konfirmasi = KonfirmasiPembayaranInvoice::query()->create([
                'IdInvoice' => $invoice->Id,
                'Status' => StatusKonfirmasiPembayaran::Menunggu,
                'NamaPengirim' => $nama,
                'TanggalBayar' => $tanggalBayar->toDateString(),
                'Jumlah' => $jumlah->KeString(),
                'Catatan' => $catatan === '' ? null : $catatan,
            ]);
            $this->audit->Catat('invoice.konfirmasi-bayar', $invoice, nilaiBaru: [
                'Nomor' => $invoice->Nomor,
                'Jumlah' => $konfirmasi->Jumlah,
                'TanggalBayar' => $tanggalBayar->toDateString(),
            ], idPengguna: null);

            return $konfirmasi;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
