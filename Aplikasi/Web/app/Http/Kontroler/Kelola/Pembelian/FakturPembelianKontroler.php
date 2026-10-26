<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Pembelian\Aksi\BatalkanFakturPembelian;
use App\Domain\Pembelian\Aksi\SimpanFakturPembelian;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Kueri\DaftarDokumenPembelian;
use App\Domain\Pembelian\Kueri\DaftarNotaDebit;
use App\Domain\Pembelian\Kueri\DaftarPemasok;
use App\Domain\Pembelian\Kueri\DetailPembelian;
use App\Domain\Pembelian\Layanan\PenyimpanLampiranPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Permintaan\Kelola\Pembelian\SimpanFakturPembelianPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Faktur pembelian (F-04 fase 1, `/kelola/pembelian/faktur`): daftar, form 3-way matching (pilih pemasok → GRN belum
 * difakturkan), posting, detail, pembatalan, lampiran.
 */
final class FakturPembelianKontroler extends DasarPembelianKontroler
{
    public function Daftar(Request $permintaan, DaftarDokumenPembelian $daftar, DaftarPemasok $pemasok): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarDokumenPembelian::KOLOM_URUT, DaftarDokumenPembelian::URUT_BAWAAN, DaftarDokumenPembelian::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pembelian/Faktur/Daftar', 'Faktur', fn (): array => $daftar->Faktur($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiStatus' => self::Opsi(StatusFakturPembelian::class),
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    public function Buat(Request $permintaan, DaftarPemasok $pemasok, DetailPembelian $detail): Response
    {
        $uuidPemasok = $permintaan->query('pemasok');
        $uuidPenerimaan = $permintaan->query('penerimaan');

        if ((! is_string($uuidPemasok) || $uuidPemasok === '') && is_string($uuidPenerimaan) && $uuidPenerimaan !== '') {
            $grn = $this->CariDokumen(PenerimaanBarang::class, $uuidPenerimaan);
            $uuidPemasok = $grn->IdPemasok === null ? null : $this->PemasokDariId($grn->IdPemasok);
        }

        $terpilih = is_string($uuidPemasok) && $uuidPemasok !== '' ? $this->CariPemasok($uuidPemasok) : null;

        return Inertia::render('Kelola/Pembelian/Faktur/Form', [
            'OpsiPemasok' => $pemasok->AmbilPilihan(),
            'UuidPemasok' => $terpilih?->Uuid,
            'TerminHari' => $terpilih?->TerminHari,
            'UuidPenerimaanAwal' => is_string($uuidPenerimaan) ? $uuidPenerimaan : null,
            'Penerimaan' => $terpilih === null ? [] : $detail->PenerimaanBelumDifakturkan($terpilih->Id, $this->IdOutletBoleh()),
            'HariIni' => $this->HariIni(),
            'Lampiran' => PenerimaanBarangKontroler::AturanLampiran(),
        ]);
    }

    public function Simpan(SimpanFakturPembelianPermintaan $permintaan, SimpanFakturPembelian $simpan): RedirectResponse
    {
        foreach ((array) $permintaan->validated('UuidPenerimaan') as $uuid) {
            $this->CariDokumen(PenerimaanBarang::class, (string) $uuid);
        }

        $faktur = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id));

        return to_route('kelola.pembelian.faktur.detail', ['faktur' => $faktur->Uuid])->with('Kilat', "{$faktur->Nomor} dicatat. Hutang usaha tercatat, jatuh tempo {$faktur->JatuhTempo->format('d/m/Y')}.");
    }

    public function Detail(string $faktur, DetailPembelian $detail, DaftarNotaDebit $notaDebit): Response
    {
        $f = $this->CariDokumen(FakturPembelian::class, $faktur);
        $izin = $this->AmbilIzinPembelian();
        $terbuka = in_array($f->Status, [StatusFakturPembelian::BelumDibayar, StatusFakturPembelian::DibayarSebagian], true);

        return Inertia::render('Kelola/Pembelian/Faktur/Detail', [
            ...$detail->Faktur($f),
            'NotaDebit' => $notaDebit->UntukFaktur($f),
            'Izin' => $izin,
            'Tindakan' => [
                'Bayar' => $izin['Kelola'] && $terbuka && ! $f->BelanjaStok,
                'Batalkan' => $izin['Kelola'] && $f->Status === StatusFakturPembelian::BelumDibayar && ! $f->BelanjaStok,
                'NotaDebit' => $izin['Kelola'] && $f->Status !== StatusFakturPembelian::Dibatalkan,
            ],
        ]);
    }

    public function Batalkan(AlasanPembelianPermintaan $permintaan, string $faktur, BatalkanFakturPembelian $batalkan): RedirectResponse
    {
        $f = $batalkan->Jalankan($this->CariDokumen(FakturPembelian::class, $faktur), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.pembelian.faktur.detail', ['faktur' => $f->Uuid])->with('Kilat', "{$f->Nomor} dibatalkan. Penerimaan barangnya bisa difakturkan ulang.");
    }

    public function Lampiran(string $faktur, PenyimpanLampiranPembelian $penyimpan): StreamedResponse
    {
        $f = $this->CariDokumen(FakturPembelian::class, $faktur);

        return $penyimpan->Unduh($f->PathLampiran, $f->NamaLampiran, $f->MimeLampiran);
    }

    private function PemasokDariId(int $id): ?string
    {
        return Pemasok::query()->withTrashed()->whereKey($id)->value('Uuid');
    }
}
