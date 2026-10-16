<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Data\HasilHitungInvoice;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceDetail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Menghitung ulang seluruh angka satu invoice dari baris yang tersimpan, lalu menulisnya kembali ke baris dan kepala
 * invoice (F-21, D-166). Dipakai saat draf disimpan **dan** sekali lagi saat diterbitkan, supaya angka yang dijurnal selalu
 * hasil perhitungan pada saat itu (mis. tarif PPN baru yang terbit setelah draf disimpan), bukan angka lama yang basi.
 *
 * Yang dihitung di sini: angka dokumen & snapshot tarif PPN (mesin kalkulasi bersama), pendapatan/pajak per baris, PPh
 * yang diharapkan dipotong pelanggan (dasar = DPP baris Jasa × tarif yang **diisi pengguna**; sistem tidak menebak tarif),
 * dan potongan uang muka dari invoice DP yang tertaut (`IdInvoiceAkhir`). Draf saja yang boleh dihitung ulang.
 */
final class PenyusunAngkaInvoice
{
    public function __construct(private readonly PenghitungInvoice $penghitung) {}

    /**
     * @throws PelanggaranAturanBisnis UangMukaMelebihiInvoice bila DP yang dipotong lebih besar dari DPP atau pajak invoice
     */
    public function Terapkan(Invoice $invoice): HasilHitungInvoice
    {
        $outlet = Outlet::query()->whereKey($invoice->IdOutlet)->first()
            ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet invoice ini tidak ditemukan.', 'IdOutlet');
        $detail = InvoiceDetail::query()->where('IdInvoice', $invoice->Id)->orderBy('Urutan')->get()->values();
        $hasil = $this->penghitung->Hitung($outlet->Id, $outlet->KodeKota, array_values(array_map(
            fn (InvoiceDetail $d): array => [
                'Jumlah' => Kuantitas::Dari($d->Jumlah),
                'HargaSatuan' => Uang::Dari($d->Harga),
                'Diskon' => Uang::Dari($d->Diskon),
                'IdKelompokPajak' => $d->IdKelompokPajak,
                // Selalu eksplisit: `HargaTermasukPajak` tingkat invoice menggantikan pengaturan outlet, supaya invoice
                // B2B (harga biasanya belum termasuk PPN) tidak ikut berubah bila outlet dipakai kasir inklusif.
                'HargaTermasukPajak' => $d->HargaTermasukPajak ?? $invoice->HargaTermasukPajak,
            ],
            $detail->all(),
        )), CarbonImmutable::parse($invoice->Tanggal->format('Y-m-d')));

        $dasarPph = Uang::Nol();

        foreach ($detail as $indeks => $d) {
            $baris = $hasil->baris[$indeks];
            $d->fill(['Pajak' => $baris['Pajak']->KeString(), 'Pendapatan' => $baris['Pendapatan']->KeString()])->save();

            if ($d->JenisBaris === JenisBarisInvoice::Jasa) {
                $dasarPph = $dasarPph->Tambah($baris['Dpp']);
            }
        }

        $uangMuka = $this->HitungUangMuka($invoice, $hasil);
        $nilaiPph = Uang::Nol();

        if ($invoice->Jenis === JenisInvoice::Penjualan && $invoice->KodePph !== null && $invoice->TarifPph !== null) {
            $nilaiPph = $dasarPph->Kali(BigDecimal::of($invoice->TarifPph)->dividedBy(100, 12, RoundingMode::HalfUp));
        } else {
            $dasarPph = Uang::Nol();
        }

        $dokumen = $hasil->dokumen;
        $invoice->fill([
            'Subtotal' => $dokumen->subtotal->KeString(),
            'Diskon' => $dokumen->diskon->KeString(),
            'DasarPengenaanPajak' => $dokumen->dasarPengenaanPajak->KeString(),
            'Pajak' => $dokumen->pajak->KeString(),
            'Total' => $dokumen->total->KeString(),
            'TarifPpn' => $dokumen->tarifPpn,
            'PengaliDppPembilang' => $dokumen->pengaliDppPembilang,
            'PengaliDppPenyebut' => $dokumen->pengaliDppPenyebut,
            'RincianPajak' => array_map(fn (Uang $n): string => $n->KeString(), $dokumen->rincianPajak),
            'DasarPph' => $dasarPph->KeString(),
            'NilaiPph' => $nilaiPph->KeString(),
            'PotonganUangMukaDpp' => $uangMuka['Dpp']->KeString(),
            'PotonganUangMukaPajak' => $uangMuka['Pajak']->KeString(),
            'Tagihan' => $dokumen->total->Kurangi($uangMuka['Dpp'])->Kurangi($uangMuka['Pajak'])->KeString(),
        ])->save();

        return $hasil;
    }

    /**
     * Pajak invoice yang masih harus dikreditkan ke PPN keluaran setelah PPN yang sudah diakui di invoice DP dikurangkan
     * (per kode pajak). Tidak pernah negatif: DP yang pajaknya melebihi pajak invoice ditolak di `HitungUangMuka`.
     *
     * @return array<string, Uang>
     */
    public function AmbilPajakNeto(Invoice $invoice): array
    {
        $neto = [];

        foreach ($invoice->RincianPajak ?? [] as $kode => $nilai) {
            $neto[(string) $kode] = Uang::Dari($nilai);
        }

        foreach ($invoice->UangMukaTerpakai()->get() as $dp) {
            foreach ($dp->RincianPajak ?? [] as $kode => $nilai) {
                $neto[(string) $kode] = ($neto[(string) $kode] ?? Uang::Nol())->Kurangi(Uang::Dari($nilai));
            }
        }

        return array_filter($neto, fn (Uang $n): bool => ! $n->BernilaiNol());
    }

    /**
     * @return array{Dpp: Uang, Pajak: Uang}
     */
    private function HitungUangMuka(Invoice $invoice, HasilHitungInvoice $hasil): array
    {
        $dpp = Uang::Nol();
        $pajak = Uang::Nol();

        if ($invoice->Jenis !== JenisInvoice::Penjualan) {
            return ['Dpp' => $dpp, 'Pajak' => $pajak];
        }

        $pajakPerKode = [];

        foreach ($invoice->UangMukaTerpakai()->get() as $dp) {
            $dpp = $dpp->Tambah(Uang::Dari($dp->DasarPengenaanPajak));
            $pajak = $pajak->Tambah(Uang::Dari($dp->Pajak));

            foreach ($dp->RincianPajak ?? [] as $kode => $nilai) {
                $pajakPerKode[(string) $kode] = ($pajakPerKode[(string) $kode] ?? Uang::Nol())->Tambah(Uang::Dari($nilai));
            }
        }

        if ($dpp->Bandingkan($hasil->dokumen->dasarPengenaanPajak) > 0) {
            throw new PelanggaranAturanBisnis(
                'UangMukaMelebihiInvoice',
                "Uang muka yang dipotong ({$dpp->FormatRupiah()}) melebihi nilai invoice sebelum pajak ({$hasil->dokumen->dasarPengenaanPajak->FormatRupiah()}). Kurangi uang muka yang dipakai atau tambah baris invoice.",
                'UuidUangMuka',
            );
        }

        foreach ($pajakPerKode as $kode => $nilai) {
            if ($nilai->Bandingkan($hasil->dokumen->rincianPajak[$kode] ?? Uang::Nol()) > 0) {
                throw new PelanggaranAturanBisnis(
                    'UangMukaMelebihiInvoice',
                    "Pajak pada uang muka yang dipotong melebihi pajak invoice ini (pajak {$kode}). Pastikan baris invoice memakai kelompok pajak yang sama dengan invoice uang muka.",
                    'UuidUangMuka',
                );
            }
        }

        return ['Dpp' => $dpp, 'Pajak' => $pajak];
    }
}
