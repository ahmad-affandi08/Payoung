<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Data\DataBarisInvoice;
use App\Domain\Penjualan\Data\DataInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\ModeInvoiceBerulang;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use App\Domain\Penjualan\Model\InvoiceBerulangDetail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menerbitkan invoice dari templat berulang yang sudah jatuh tempo (F-21), dipanggil perintah harian
 * `invoice:jalankan-berulang`. Tiap siklus satu transaksi: invoice dibuat (dan diterbitkan sesuai `Mode`), ditandai
 * dengan `(IdInvoiceBerulang, TanggalSiklus)` yang unik, lalu jadwal maju. Hasilnya idempoten: perintah yang berjalan dua
 * kali, bertumpuk, atau diulang setelah galat tidak pernah menerbitkan dua invoice untuk siklus yang sama.
 *
 * - Invoice bertanggal siklusnya; bila periodenya sudah dikunci, bertanggal hari ini (tidak ada invoice yang ditolak
 *   hanya karena servernya telat berjalan).
 * - Bila server lama tidak berjalan, siklus yang tertinggal dikejar paling banyak `MAKS_SIKLUS_PER_JALAN` per putaran.
 * - Galat (pelanggan diarsipkan, produk hilang, periode...) tidak menggugurkan templat lain: dicatat di
 *   `GalatTerakhir` dan siklus yang sama dicoba lagi di putaran berikutnya. Templat tidak pernah maju melewati siklus
 *   yang belum berhasil.
 * - Invoice atas nama pembuat templat (`DibuatOleh`), jadi jejak audit dan jurnalnya menunjuk orang yang menyetujui jadwalnya.
 * - Pengiriman WhatsApp (mode `TerbitkanDanKirim`) berjalan setelah transaksi; kegagalannya hanya dicatat sebagai
 *   peringatan di templat, invoice tetap terbit.
 */
final class JalankanInvoiceBerulang
{
    public const MAKS_SIKLUS_PER_JALAN = 3;

    public function __construct(
        private readonly SimpanInvoice $simpan,
        private readonly TerbitkanInvoice $terbitkan,
        private readonly AntrekanPengirimanInvoice $antrekan,
        private readonly DaftarKelompokPajak $kelompokPajak,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly PencatatAudit $audit,
    ) {}

    /** @return int jumlah invoice yang dihasilkan */
    public function Jalankan(int $idTenant, string $uuidTemplat, CarbonImmutable $hariIni): int
    {
        $dibuat = 0;

        for ($putaran = 0; $putaran < self::MAKS_SIKLUS_PER_JALAN; $putaran++) {
            try {
                $invoice = DB::transaction(fn (): ?Invoice => $this->SatuSiklus($uuidTemplat, $hariIni), 1);
            } catch (PelanggaranAturanBisnis $galat) {
                $this->CatatGalat($uuidTemplat, $galat->getMessage());

                return $dibuat;
            } catch (Throwable $galat) {
                $this->CatatGalat($uuidTemplat, 'Masalah sistem saat menerbitkan invoice berulang.');
                report($galat);

                return $dibuat;
            }

            if ($invoice === null) {
                return $dibuat;
            }

            $dibuat++;
            $this->KirimBilaPerlu($idTenant, $uuidTemplat, $invoice);
        }

        return $dibuat;
    }

    /** Menerbitkan satu siklus bila ada yang jatuh tempo; null bila tidak ada lagi. */
    private function SatuSiklus(string $uuidTemplat, CarbonImmutable $hariIni): ?Invoice
    {
        $templat = InvoiceBerulang::query()->where('Uuid', $uuidTemplat)->lockForUpdate()->first();

        if (! $templat instanceof InvoiceBerulang
            || $templat->Status !== StatusInvoiceBerulang::Aktif
            || $templat->BerikutnyaPada === null
            || $templat->BerikutnyaPada->toDateString() > $hariIni->toDateString()) {
            return null;
        }

        $siklus = CarbonImmutable::parse($templat->BerikutnyaPada->toDateString());
        $invoice = Invoice::query()->where('IdInvoiceBerulang', $templat->Id)->whereDate('TanggalSiklus', $siklus->toDateString())->first();

        if ($invoice === null) {
            $tanggal = $this->penjagaPeriode->CekTerkunci($siklus->format('Y-m')) ? $hariIni : $siklus;
            $invoice = $this->simpan->Jalankan($this->SusunData($templat, $tanggal), $templat->DibuatOleh);
            Invoice::query()->whereKey($invoice->Id)->toBase()->update(['IdInvoiceBerulang' => $templat->Id, 'TanggalSiklus' => $siklus->toDateString()]);

            if ($templat->Mode->Menerbitkan()) {
                $invoice = $this->terbitkan->Jalankan($invoice->Uuid, $templat->DibuatOleh);
            }

            $invoice->refresh();
            $this->audit->Catat('invoice-berulang.terbit', $templat, nilaiBaru: [
                'Nomor' => $invoice->Nomor,
                'TanggalSiklus' => $siklus->toDateString(),
                'Mode' => $templat->Mode->value,
            ], idPengguna: $templat->DibuatOleh);
        }

        $this->MajukanJadwal($templat);

        return $invoice;
    }

    private function MajukanJadwal(InvoiceBerulang $templat): void
    {
        $templat->JumlahTerbit++;
        $templat->SiklusBerikutnya++;
        $templat->TerakhirTerbitPada = now();
        $templat->GalatTerakhir = null;
        $templat->GalatPada = null;
        $berikutnya = $templat->Frekuensi->HitungTanggal($templat->TanggalMulai->toImmutable(), $templat->Interval, $templat->SiklusBerikutnya);
        $habis = ($templat->BatasKali !== null && $templat->JumlahTerbit >= $templat->BatasKali)
            || ($templat->TanggalBerakhir !== null && $berikutnya->toDateString() > $templat->TanggalBerakhir->toDateString());

        if ($habis) {
            $templat->UbahStatus(StatusInvoiceBerulang::Selesai);
            $templat->BerikutnyaPada = null;
        } else {
            $templat->BerikutnyaPada = Carbon::parse($berikutnya->toDateString());
        }

        $templat->save();
    }

    private function SusunData(InvoiceBerulang $templat, CarbonImmutable $tanggal): DataInvoice
    {
        $pelanggan = Pelanggan::query()->whereKey($templat->IdPelanggan)->first()
            ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan templat ini tidak ditemukan.');
        $detail = InvoiceBerulangDetail::query()->where('IdInvoiceBerulang', $templat->Id)->orderBy('Urutan')->get();
        $uuidProduk = Produk::query()->whereIn('Id', array_values(array_filter($detail->pluck('IdProduk')->all(), 'is_int')))->pluck('Uuid', 'Id');
        $uuidKelompok = [];

        foreach ($this->kelompokPajak->AmbilOpsi() as $opsi) {
            $uuidKelompok[$opsi['Id']] = $opsi['Uuid'];
        }

        $tarif = $templat->TarifPph === null ? null : rtrim(rtrim($templat->TarifPph, '0'), '.');

        return new DataInvoice(
            jenis: JenisInvoice::Penjualan,
            uuidPelanggan: $pelanggan->Uuid,
            idOutlet: $templat->IdOutlet,
            tanggal: $tanggal,
            terminHari: $templat->TerminHari,
            baris: array_values($detail->map(fn (InvoiceBerulangDetail $d): DataBarisInvoice => new DataBarisInvoice(
                uuidProduk: $d->IdProduk === null ? null : ($uuidProduk[$d->IdProduk] ?? throw new PelanggaranAturanBisnis('ProdukTidakDikenal', "Produk baris \"{$d->Nama}\" sudah tidak ada.")),
                nama: $d->Nama,
                keterangan: $d->Keterangan,
                jenisBaris: $d->JenisBaris,
                simbolSatuan: $d->SimbolSatuan,
                jumlah: Kuantitas::Dari($d->Jumlah),
                harga: Uang::Dari($d->Harga),
                diskon: Uang::Dari($d->Diskon),
                uuidKelompokPajak: $d->IdKelompokPajak === null ? null : ($uuidKelompok[$d->IdKelompokPajak] ?? null),
                hargaTermasukPajak: $d->HargaTermasukPajak,
            ))->all()),
            hargaTermasukPajak: $templat->HargaTermasukPajak,
            perihal: $this->IsiPlaceholder($templat->Perihal, $tanggal),
            nomorReferensi: $templat->NomorReferensi,
            kodePph: $templat->KodePph,
            tarifPph: $tarif === '' ? null : $tarif,
            catatan: $this->IsiPlaceholder($templat->Catatan, $tanggal),
            syarat: $templat->Syarat,
            infoPembayaran: $templat->InfoPembayaran,
        );
    }

    /** `{bulan}`, `{tahun}`, dan `{periode}` diganti sesuai tanggal invoice ("September", "2026", "September 2026"). */
    public static function IsiPlaceholder(?string $teks, CarbonImmutable $tanggal): ?string
    {
        if ($teks === null) {
            return null;
        }

        $bulan = $tanggal->translatedFormat('F');

        return str_replace(['{periode}', '{bulan}', '{tahun}'], ["{$bulan} {$tanggal->year}", $bulan, (string) $tanggal->year], $teks);
    }

    private function KirimBilaPerlu(int $idTenant, string $uuidTemplat, Invoice $invoice): void
    {
        $templat = InvoiceBerulang::query()->where('Uuid', $uuidTemplat)->first();

        if (! $templat instanceof InvoiceBerulang || $templat->Mode !== ModeInvoiceBerulang::TerbitkanDanKirim) {
            return;
        }

        try {
            $this->antrekan->Jalankan($idTenant, $invoice, $templat->DibuatOleh);
        } catch (PelanggaranAturanBisnis $galat) {
            $this->CatatGalat($uuidTemplat, "Invoice {$invoice->Nomor} terbit, tetapi belum terkirim lewat WhatsApp: {$galat->getMessage()}");
        }
    }

    private function CatatGalat(string $uuidTemplat, string $pesan): void
    {
        InvoiceBerulang::query()->where('Uuid', $uuidTemplat)->toBase()->update([
            'GalatTerakhir' => mb_substr($pesan, 0, 300),
            'GalatPada' => CarbonImmutable::now(),
        ]);
    }
}
