<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\NotaDebitPembelian;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Persediaan\Kueri\SaldoStokPasangan;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan nota debit pembelian (v6.07, CLAUDE.md #8): mutasi penilaian ulang dibalik (masuk dulu, lalu keluar) hanya
 * selama stok yang dinilai ulang **belum bergerak** sejak itu (`StokSudahBergerak`; koreksi lewat jurnal umum), jurnalnya
 * dibalik bertanggal hari bisnis (termasuk pengembalian uang), dan sisa hutang faktur kembali bertambah sebesar bagian
 * pemotongnya. Alasan 5–255 karakter. Audit `nota-debit.batalkan`.
 *
 * Urutan kunci: L1 Tenant (S) → faktur → nota → buku stok → penghitung JU.
 */
final class BatalkanNotaDebitPembelian
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturanPersediaan,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly MutasiDokumen $mutasiDokumen,
        private readonly SaldoStokPasangan $saldo,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly BalikkanJurnal $balikkan,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, StokSudahBergerak
     */
    public function Jalankan(NotaDebitPembelian $nota, string $alasan, int $idPengguna): NotaDebitPembelian
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(fn (): NotaDebitPembelian => $this->Batalkan($nota, $alasan, $idPengguna), 3);
    }

    private function Batalkan(NotaDebitPembelian $awal, string $alasan, int $idPengguna): NotaDebitPembelian
    {
        $this->pengaturanPersediaan->AmbilDenganKunciBaca();
        $faktur = FakturPembelian::query()->whereKey($awal->IdFakturPembelian)->lockForUpdate()->firstOrFail();
        $nota = NotaDebitPembelian::query()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

        if ($nota->Status === StatusDokumenTerposting::Dibatalkan) {
            return $nota;
        }

        $mutasi = $this->mutasiDokumen->Ambil(JenisReferensiMutasi::NotaDebitPembelian, $nota->Id);
        $masuk = array_values(array_filter($mutasi, fn (MutasiStok $m): bool => str_starts_with($m->KunciBaris, 'M/')));
        $keluar = array_values(array_filter($mutasi, fn (MutasiStok $m): bool => str_starts_with($m->KunciBaris, 'K/')));
        $terakhir = $this->saldo->AmbilIdMutasiTerakhir(array_map(fn (MutasiStok $m): array => [$m->IdProduk, $m->IdGudang], $masuk));

        foreach ($masuk as $m) {
            if (($terakhir[SaldoStok::BuatKunciPasangan($m->IdProduk, $m->IdGudang)] ?? null) !== $m->Id) {
                throw new PelanggaranAturanBisnis('StokSudahBergerak', 'Barangnya sudah terjual atau berpindah sejak nota debit ini dicatat, jadi nilai stok tidak bisa dikembalikan. Koreksi selisihnya lewat jurnal umum.');
            }
        }

        $tanggal = $this->tanggalBisnis->Hitung($nota->IdOutlet);

        foreach ([$masuk, $keluar] as $kelompok) {
            if ($kelompok !== []) {
                $this->catatMutasi->Jalankan(new DataDokumenMutasi(
                    JenisReferensiMutasi::NotaDebitPembelian,
                    $nota->Id,
                    $nota->Uuid,
                    $nota->Nomor,
                    $tanggal,
                    $idPengguna,
                    null,
                    array_map(fn (MutasiStok $m): DataBarisMutasi => new DataBarisMutasi(
                        kunciBaris: 'X/'.$m->KunciBaris,
                        idProduk: $m->IdProduk,
                        idGudang: $m->IdGudang,
                        jenisMutasi: $m->JenisMutasi,
                        jumlah: Kuantitas::Dari($m->Jumlah)->Negasi(),
                        modeNilai: ModeNilaiMutasi::Ditentukan,
                        nilai: AritmetikaHpp::AmbilMutlak(Uang::Dari($m->TotalHpp)->Kurangi(Uang::Dari($m->SelisihHpp))),
                        hppSatuan: BigDecimal::of($m->HppSatuan),
                        idMutasiAsal: $m->Id,
                    ), $kelompok),
                ));
            }
        }

        $jurnal = $nota->IdJurnal === null ? null : $this->balikkan->Jalankan(
            $nota->IdJurnal,
            $tanggal,
            mb_substr("Pembatalan nota debit pembelian {$nota->Nomor}: {$alasan}", 0, 255),
            JenisSumberJurnal::NotaDebitPembelian,
            $nota->Id,
            'Pembatalan',
            $idPengguna,
        );

        $asal = $faktur->Status;
        $faktur->JumlahRetur = Uang::Dari($faktur->JumlahRetur)->Kurangi(Uang::Dari($nota->PotongHutang))->KeString();

        if ($faktur->Status !== StatusFakturPembelian::Dibatalkan) {
            $faktur->SelaraskanStatus();
        }

        $faktur->save();

        if ($asal !== $faktur->Status) {
            $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $faktur->Id, $asal->value, $faktur->Status->value, $idPengguna, $alasan);
        }

        $nota->UbahStatus(StatusDokumenTerposting::Dibatalkan);
        $nota->forceFill(['IdJurnalPembatalan' => $jurnal?->idJurnal, 'AlasanBatal' => $alasan, 'DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now()])->save();
        $this->riwayat->Catat(NotaDebitPembelian::JENIS_DOKUMEN, $nota->Id, StatusDokumenTerposting::Diposting->value, StatusDokumenTerposting::Dibatalkan->value, $idPengguna, $alasan);
        $this->audit->Catat('nota-debit.batalkan', $nota, ['Status' => StatusDokumenTerposting::Diposting->value], [
            'Status' => StatusDokumenTerposting::Dibatalkan->value,
            'Nomor' => $nota->Nomor,
            'Alasan' => $alasan,
            'NomorJurnalPembatalan' => $jurnal?->nomor,
        ], idPengguna: $idPengguna);

        return $nota;
    }
}
