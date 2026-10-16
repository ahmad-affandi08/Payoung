<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use App\Domain\Penjualan\Enum\StatusKonfirmasiPembayaran;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use App\Domain\Penjualan\Model\KonfirmasiPembayaranInvoice;
use App\Domain\Penjualan\Model\Penawaran;

/**
 * Kotak Tindakan domain Invoice (F-21, D-23 C, izin `invoice.kelola`, dibatasi outlet akses). Butir pengingat yang selesai
 * sendiri begitu masalahnya beres, bukan butir "sudah dicek":
 * - invoice terbit yang sudah lewat jatuh tempo dan masih bersisa (Penting);
 * - templat invoice berulang yang siklus terakhirnya gagal terbit (Penting): tagihan otomatis yang diam-diam berhenti
 *   adalah pendapatan yang tidak tertagih;
 * - draf invoice yang menggantung lebih dari `HARI_DRAF_LAMA` hari (Perhatian);
 * - invoice terbit lebih dari sehari yang belum pernah dikirim ke pelanggan (Perhatian);
 * - klaim "sudah bayar" dari pelanggan yang menunggu verifikasi pada invoice yang masih bersisa (Penting): uang mungkin
 *   sudah masuk tetapi belum dicatat, dan pelanggan menunggu kepastian;
 * - penawaran diterima yang belum dijadikan invoice aktif (Perhatian): pekerjaan sudah disetujui tetapi belum ditagih;
 * - penawaran terbit yang masih berlaku tetapi lebih dari `HARI_TANPA_TANGGAPAN` hari belum ditanggapi (Perhatian): saatnya
 *   menghubungi pelanggan sebelum masa berlakunya habis.
 */
final class PenyediaTindakanInvoice implements PenyediaTindakan
{
    public const HARI_DRAF_LAMA = 7;

    public const HARI_TANPA_TANGGAPAN = 7;

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('invoice.kelola')) {
            return [];
        }

        $idOutlet = $konteks->idOutletBoleh;
        $hari = $konteks->hariIni->toDateString();
        $batasOutlet = fn ($kueri) => $kueri->when($idOutlet !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutlet ?? []));

        $terbuka = [StatusPiutang::BelumLunas->value, StatusPiutang::DibayarSebagian->value];
        $piutangTerbuka = Piutang::query()->whereNotNull('IdInvoice')->whereIn('Status', $terbuka)->get();
        $piutangLewat = Piutang::query()->whereNotNull('IdInvoice')->whereIn('Status', $terbuka)->where('JatuhTempo', '<', $hari)->get();
        $lewat = $batasOutlet(Invoice::query())->where('Status', StatusInvoice::Terbit->value)->whereIn('Id', $piutangLewat->pluck('IdInvoice')->all())->orderBy('JatuhTempo')->get();
        $sisaTotal = Uang::Nol();

        foreach ($piutangLewat as $p) {
            if ($lewat->contains('Id', $p->IdInvoice)) {
                $sisaTotal = $sisaTotal->Tambah($p->AmbilSisa());
            }
        }

        $gagal = $batasOutlet(InvoiceBerulang::query())->where('Status', StatusInvoiceBerulang::Aktif->value)->whereNotNull('GalatTerakhir')->orderBy('GalatPada')->get();
        $draf = $batasOutlet(Invoice::query())->where('Status', StatusInvoice::Draf->value)->where('DibuatPada', '<', $konteks->hariIni->subDays(self::HARI_DRAF_LAMA)->startOfDay())->orderBy('Id')->get();
        $belumKirim = $batasOutlet(Invoice::query())->where('Status', StatusInvoice::Terbit->value)->whereNull('TerkirimPada')->where('Tanggal', '<', $hari)->orderBy('Tanggal')->get();

        $konfirmasi = KonfirmasiPembayaranInvoice::query()->where('Status', StatusKonfirmasiPembayaran::Menunggu->value)
            ->whereIn('IdInvoice', $batasOutlet(Invoice::query())->where('Status', StatusInvoice::Terbit->value)->whereIn('Id', $piutangTerbuka->pluck('IdInvoice')->all())->select('Id'))
            ->orderBy('Id')->get();
        $uuidInvoiceKonfirmasi = $konfirmasi->isEmpty() ? [] : Invoice::query()->whereIn('Id', $konfirmasi->pluck('IdInvoice')->all())->pluck('Uuid', 'Id')->all();
        $invoiceAktif = Invoice::query()->where('Status', '!=', StatusInvoice::Dibatalkan->value)->select('Id');
        $diterima = $batasOutlet(Penawaran::query())->where('Status', StatusPenawaran::Diterima->value)
            ->where(fn ($k) => $k->whereNull('IdInvoice')->orWhereNotIn('IdInvoice', $invoiceAktif))->orderBy('DiputuskanPada')->get();
        $tanpaTanggapan = $batasOutlet(Penawaran::query())->where('Status', StatusPenawaran::Terbit->value)->where('BerlakuSampai', '>=', $hari)
            ->where('DiterbitkanPada', '<', $konteks->hariIni->subDays(self::HARI_TANPA_TANGGAPAN)->startOfDay())->orderBy('BerlakuSampai')->get();

        return [
            new DataButirTindakan(
                'invoice.lewat-tempo',
                'Invoice',
                TingkatTindakan::Penting,
                'Invoice lewat jatuh tempo',
                'Total sisa tagihan '.$sisaTotal->FormatRupiah().'. Kirim pengingat atau catat pembayarannya.',
                $lewat->count(),
                '/kelola/invoice?saring[Pembayaran]=LewatTempo',
                'Tagih sekarang',
                array_values($lewat->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (Invoice $i): DataRincianTindakan => new DataRincianTindakan(
                    $i->Uuid,
                    (string) $i->Nomor,
                    'Jatuh tempo '.$i->JatuhTempo->toDateString(),
                    $i->JatuhTempo->toDateString(),
                    '/kelola/invoice/'.$i->Uuid,
                ))->all()),
            ),
            new DataButirTindakan(
                'invoice.konfirmasi-bayar',
                'Invoice',
                TingkatTindakan::Penting,
                'Pelanggan mengaku sudah membayar',
                'Cocokkan dengan mutasi bank, lalu catat pembayarannya (diterima) atau tolak bila uangnya tidak ditemukan.',
                $konfirmasi->count(),
                '/kelola/invoice?saring[Pembayaran]=Terbuka',
                'Verifikasi',
                array_values($konfirmasi->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (KonfirmasiPembayaranInvoice $k): DataRincianTindakan => new DataRincianTindakan(
                    $k->Uuid,
                    $k->NamaPengirim,
                    Uang::Dari($k->Jumlah)->FormatRupiah().' pada '.$k->TanggalBayar->toDateString(),
                    $k->TanggalBayar->toDateString(),
                    '/kelola/invoice/'.($uuidInvoiceKonfirmasi[$k->IdInvoice] ?? ''),
                ))->all()),
            ),
            new DataButirTindakan(
                'invoice-berulang.gagal',
                'Invoice',
                TingkatTindakan::Penting,
                'Invoice berulang gagal terbit',
                'Tagihan otomatis berikut tidak terbit dan akan dicoba lagi besok pagi. Perbaiki masalahnya agar tagihan tidak terlewat.',
                $gagal->count(),
                '/kelola/invoice/berulang?saring[Status]=Aktif',
                'Periksa templat',
                array_values($gagal->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (InvoiceBerulang $t): DataRincianTindakan => new DataRincianTindakan(
                    $t->Uuid,
                    $t->Nama,
                    $t->GalatTerakhir,
                    $t->GalatPada?->toDateString(),
                    '/kelola/invoice/berulang/'.$t->Uuid,
                ))->all()),
            ),
            new DataButirTindakan(
                'invoice.draf-lama',
                'Invoice',
                TingkatTindakan::Perhatian,
                'Draf invoice belum diterbitkan',
                'Draf yang lebih dari '.self::HARI_DRAF_LAMA.' hari belum diterbitkan atau dibatalkan. Tagihan yang tertahan di draf tidak tercatat sebagai pendapatan.',
                $draf->count(),
                '/kelola/invoice?saring[Status]=Draf',
                'Periksa draf',
                array_values($draf->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (Invoice $i): DataRincianTindakan => new DataRincianTindakan(
                    $i->Uuid,
                    'Draf '.($i->Perihal ?? 'invoice'),
                    'Dibuat '.$i->DibuatPada?->toDateString(),
                    $i->DibuatPada?->toDateString(),
                    '/kelola/invoice/'.$i->Uuid,
                ))->all()),
            ),
            new DataButirTindakan(
                'invoice.belum-dikirim',
                'Invoice',
                TingkatTindakan::Perhatian,
                'Invoice belum dikirim ke pelanggan',
                'Sudah terbit lebih dari sehari tetapi belum pernah dikirim. Pelanggan yang belum menerima tagihan belum bisa membayar.',
                $belumKirim->count(),
                '/kelola/invoice?saring[Status]=Terbit',
                'Kirim invoice',
                array_values($belumKirim->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (Invoice $i): DataRincianTindakan => new DataRincianTindakan(
                    $i->Uuid,
                    (string) $i->Nomor,
                    'Terbit '.$i->Tanggal->toDateString(),
                    $i->Tanggal->toDateString(),
                    '/kelola/invoice/'.$i->Uuid,
                ))->all()),
            ),
            new DataButirTindakan(
                'penawaran.diterima-belum-invoice',
                'Invoice',
                TingkatTindakan::Perhatian,
                'Penawaran diterima belum ditagih',
                'Pelanggan sudah menyetujui penawaran berikut, tetapi belum dijadikan invoice. Jadikan invoice agar tagihannya tidak terlewat.',
                $diterima->count(),
                '/kelola/penawaran?saring[Status]=Diterima',
                'Jadikan invoice',
                array_values($diterima->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (Penawaran $p): DataRincianTindakan => new DataRincianTindakan(
                    $p->Uuid,
                    (string) $p->Nomor,
                    'Diterima '.($p->DiputuskanPada?->toDateString() ?? ''),
                    $p->DiputuskanPada?->toDateString(),
                    '/kelola/penawaran/'.$p->Uuid,
                ))->all()),
            ),
            new DataButirTindakan(
                'penawaran.belum-ditanggapi',
                'Invoice',
                TingkatTindakan::Perhatian,
                'Penawaran belum ditanggapi',
                'Sudah lebih dari '.self::HARI_TANPA_TANGGAPAN.' hari sejak terbit dan masih berlaku. Hubungi pelanggan sebelum masa berlakunya habis.',
                $tanpaTanggapan->count(),
                '/kelola/penawaran?saring[Status]=MenungguKeputusan',
                'Tindak lanjuti',
                array_values($tanpaTanggapan->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (Penawaran $p): DataRincianTindakan => new DataRincianTindakan(
                    $p->Uuid,
                    (string) $p->Nomor,
                    'Berlaku sampai '.$p->BerlakuSampai->toDateString(),
                    $p->BerlakuSampai->toDateString(),
                    '/kelola/penawaran/'.$p->Uuid,
                ))->all()),
            ),
        ];
    }

    public function AmbilJenisDokumen(): array
    {
        return [];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return [];
    }
}
