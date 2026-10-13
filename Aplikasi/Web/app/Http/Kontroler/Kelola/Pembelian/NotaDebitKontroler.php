<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pembelian;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Pembelian\Aksi\BatalkanNotaDebitPembelian;
use App\Domain\Pembelian\Aksi\SimpanNotaDebitPembelian;
use App\Domain\Pembelian\Data\DataNotaDebitPembelian;
use App\Domain\Pembelian\Enum\JenisNotaDebit;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Kueri\DaftarNotaDebit;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\NotaDebitPembelian;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Permintaan\Kelola\Pembelian\SimpanNotaDebitPembelianPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nota debit pembelian (v6.07, F-04 BR-04.10, `/kelola/pembelian/nota-debit`, izin `pembelian.kelola`): daftar, formulir
 * dari satu faktur (`?faktur=`), rincian, dan pembatalan. Nota di atas batas persetujuan pembelian butuh izin
 * `pembelian.po.setujui`.
 */
final class NotaDebitKontroler extends DasarPembelianKontroler
{
    public function Daftar(Request $permintaan, DaftarNotaDebit $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarNotaDebit::KOLOM_URUT, DaftarNotaDebit::URUT_BAWAAN, DaftarNotaDebit::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pembelian/NotaDebit/Daftar', 'Nota', fn (): array => $daftar->Ambil($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiJenis' => self::Opsi(JenisNotaDebit::class),
            'OpsiStatus' => self::Opsi(StatusDokumenTerposting::class),
            'Izin' => $this->AmbilIzinPembelian(),
        ]);
    }

    public function Buat(Request $permintaan, DaftarNotaDebit $daftar, DaftarAkunPilihan $akun): Response|RedirectResponse
    {
        $uuid = $permintaan->string('faktur')->toString();

        if ($uuid === '') {
            return to_route('kelola.pembelian.faktur.daftar')->withErrors(['Umum' => 'Buka fakturnya, lalu tekan "Buat nota debit".']);
        }

        /** @var FakturPembelian $faktur */
        $faktur = $this->CariDokumen(FakturPembelian::class, strtoupper($uuid));

        if ($faktur->Status === StatusFakturPembelian::Dibatalkan) {
            return to_route('kelola.pembelian.faktur.detail', ['faktur' => $faktur->Uuid])->withErrors(['Umum' => "{$faktur->Nomor} sudah dibatalkan, jadi tidak bisa dibuatkan nota debit."]);
        }

        return Inertia::render('Kelola/Pembelian/NotaDebit/Buat', $daftar->Isian($faktur) + [
            'OpsiJenis' => self::Opsi(JenisNotaDebit::class),
            'OpsiAkun' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $akun->AmbilKasBank()),
            'HariIni' => $this->HariIni(),
            'BolehMelebihiBatas' => $this->CekIzin(IzinTenant::PembelianPoSetujui),
        ]);
    }

    public function Simpan(SimpanNotaDebitPembelianPermintaan $permintaan, SimpanNotaDebitPembelian $simpan): RedirectResponse
    {
        /** @var FakturPembelian $faktur */
        $faktur = $this->CariDokumen(FakturPembelian::class, $permintaan->AmbilUuidFaktur());

        $nota = $simpan->Jalankan(new DataNotaDebitPembelian(
            idFakturPembelian: $faktur->Id,
            jenis: $permintaan->AmbilJenis(),
            tanggal: $permintaan->AmbilTanggal(),
            nilaiKlaim: Uang::Dari($permintaan->AmbilNilaiKlaim()),
            idFakturPembelianDetail: $permintaan->AmbilIdBaris(),
            alasan: $permintaan->AmbilAlasan(),
            nomorNotaPemasok: $permintaan->AmbilNomorNotaPemasok(),
            uuidAkunRefund: $permintaan->AmbilUuidAkunRefund(),
            bolehMelebihiBatas: $this->CekIzin(IzinTenant::PembelianPoSetujui),
            idPengguna: $this->Pelaku()->Id,
        ));

        return to_route('kelola.pembelian.nota-debit.detail', ['nota' => $nota->Uuid])->with('Kilat', "{$nota->Nomor} dicatat: hutang faktur berkurang ".Uang::Dari($nota->PotongHutang)->FormatRupiah().(Uang::Dari($nota->NilaiRefund)->BernilaiNol() ? '.' : ', pengembalian uang '.Uang::Dari($nota->NilaiRefund)->FormatRupiah().'.'));
    }

    public function Detail(string $nota, DaftarNotaDebit $daftar): Response
    {
        /** @var NotaDebitPembelian $n */
        $n = $this->CariDokumen(NotaDebitPembelian::class, strtoupper($nota));
        $izin = $this->AmbilIzinPembelian();

        return Inertia::render('Kelola/Pembelian/NotaDebit/Detail', $daftar->Detail($n) + [
            'Izin' => $izin,
            'Tindakan' => ['Batalkan' => $izin['Kelola'] && $n->Status === StatusDokumenTerposting::Diposting],
        ]);
    }

    public function Batalkan(AlasanPembelianPermintaan $permintaan, string $nota, BatalkanNotaDebitPembelian $batalkan): RedirectResponse
    {
        /** @var NotaDebitPembelian $n */
        $n = $batalkan->Jalankan($this->CariDokumen(NotaDebitPembelian::class, strtoupper($nota)), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.pembelian.nota-debit.detail', ['nota' => $n->Uuid])->with('Kilat', "{$n->Nomor} dibatalkan; hutang faktur, nilai stok, dan jurnalnya dikembalikan.");
    }
}
