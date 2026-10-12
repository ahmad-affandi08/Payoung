<?php

declare(strict_types=1);

namespace App\Domain\Pajak\Kueri;

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pajak\Data\DataTarifBerlaku;
use App\Domain\Pajak\Enum\CakupanPajak;
use App\Domain\Pajak\Enum\KategoriJenisPajak;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Pajak\Model\TarifPajak;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;

/**
 * Tarif pajak master yang berlaku pada satu tanggal (dipakai kalkulasi F-07 dan sinkron POS). Hanya status Terbit.
 * F-07b: tarif untuk outlet (jenis daerah memakai kota outlet, nasional/kustom tanpa wilayah) dan daftar tarif untuk
 * `data-awal` POS.
 */
final class TarifPajakBerlaku
{
    public function Cari(string $kodeJenisPajak, ?string $kodeWilayah, CarbonInterface $tanggal): ?TarifPajak
    {
        $hari = $tanggal->toDateString();

        return TarifPajak::query()
            ->whereHas('JenisPajak', fn ($jenis) => $jenis->where('Kode', $kodeJenisPajak))
            ->where('Status', StatusDataMaster::Terbit->value)
            ->where('KodeWilayah', $kodeWilayah)
            ->whereDate('BerlakuMulai', '<=', $hari)
            ->where(fn ($kueri) => $kueri->whereNull('BerlakuSampai')->orWhereDate('BerlakuSampai', '>=', $hari))
            ->orderByDesc('BerlakuMulai')
            ->first();
    }

    /** Sama dengan `Cari`, dalam bentuk DTO untuk domain lain. */
    public function CariData(string $kodeJenisPajak, ?string $kodeWilayah, CarbonInterface $tanggal): ?DataTarifBerlaku
    {
        $tarif = $this->Cari($kodeJenisPajak, $kodeWilayah, $tanggal);

        return $tarif === null ? null : new DataTarifBerlaku(
            tarif: $tarif->Tarif,
            berlakuMulai: $tarif->BerlakuMulai->toDateString(),
            nomorDasarHukum: $tarif->NomorDasarHukum,
            biayaLayananMasukDpp: $tarif->BiayaLayananMasukDpp,
            pengaliDppPembilang: $tarif->PengaliDppPembilang,
            pengaliDppPenyebut: $tarif->PengaliDppPenyebut,
        );
    }

    /**
     * F-04 fase 1: tarif nasional terbit yang berlaku untuk jenis pajak pertama (Id terkecil) berkategori `kategori`
     * berlingkup Nasional, misal PPN masukan pembelian, tanpa kode jenis pajak di kode pemanggil (CLAUDE.md #12).
     * Null bila jenisnya tidak ada atau belum ada tarif terbit yang berlaku.
     */
    public function CariDataKategoriNasional(KategoriJenisPajak $kategori, CarbonInterface $tanggal): ?DataTarifBerlaku
    {
        $kode = JenisPajak::query()
            ->where('Kategori', $kategori->value)
            ->where('Cakupan', CakupanPajak::Nasional->value)
            ->orderBy('Id')
            ->value('Kode');

        return is_string($kode) ? $this->CariData($kode, null, $tanggal) : null;
    }

    /**
     * Tarif yang berlaku untuk outlet: jenis pajak berlingkup `Daerah` memakai kota outlet (tanpa kota = tidak ada
     * tarif), selain itu tarif tanpa wilayah (nasional). Null bila jenis pajak tidak dikenal atau belum ada tarif.
     */
    public function CariDataOutlet(string $kodeJenisPajak, ?string $kodeKotaOutlet, CarbonInterface $tanggal): ?DataTarifBerlaku
    {
        $jenis = JenisPajak::query()->where('Kode', $kodeJenisPajak)->first();

        if ($jenis === null) {
            return null;
        }

        if ($jenis->Cakupan === CakupanPajak::Daerah) {
            return $kodeKotaOutlet === null ? null : $this->CariData($kodeJenisPajak, $kodeKotaOutlet, $tanggal);
        }

        return $this->CariData($kodeJenisPajak, null, $tanggal);
    }

    /**
     * Apakah tarif ini (nilai dan pengali DPP sama) pernah terbit dan baru saja berakhir atau digantikan, paling lama
     * `toleransiHari` sebelum `tanggal`? Dipakai sinkron POS untuk perangkat yang belum memperbarui tarif (CLAUDE.md #12):
     * tarif yang benar-benar pernah ada diterima sebagai tinjauan, tarif karangan tetap ditolak.
     */
    public function CekBaruBerakhirDiOutlet(
        string $kodeJenisPajak,
        ?string $kodeKotaOutlet,
        CarbonInterface $tanggal,
        BigDecimal $tarif,
        int $pengaliDppPembilang,
        int $pengaliDppPenyebut,
        int $toleransiHari,
    ): bool {
        $jenis = JenisPajak::query()->where('Kode', $kodeJenisPajak)->first();

        if ($jenis === null) {
            return false;
        }

        $daerah = $jenis->Cakupan === CakupanPajak::Daerah;

        if ($daerah && $kodeKotaOutlet === null) {
            return false;
        }

        $hari = $tanggal->toDateString();
        $batasBawah = $tanggal->copy()->subDays($toleransiHari)->toDateString();

        return TarifPajak::query()
            ->where('IdJenisPajak', $jenis->Id)
            ->where('Status', StatusDataMaster::Terbit->value)
            ->where('KodeWilayah', $daerah ? $kodeKotaOutlet : null)
            ->where('Tarif', (string) $tarif)
            ->where('PengaliDppPembilang', $pengaliDppPembilang)
            ->where('PengaliDppPenyebut', $pengaliDppPenyebut)
            ->whereDate('BerlakuMulai', '<=', $hari)
            ->whereNotNull('BerlakuSampai')
            ->whereDate('BerlakuSampai', '>=', $batasBawah)
            ->exists();
    }

    /**
     * Tarif terbit nasional dan wilayah kota outlet yang belum berakhir pada `hari` (termasuk yang akan berlaku),
     * urut kode jenis lalu mulai berlaku (`data-awal` POS, F-07b). Tarif daerah wilayah lain tidak ikut. `Kategori`
     * (PRD v1.46, kunci tambahan) = kategori jenis pajak `Ppn`/`Pbjt`/`Lainnya`.
     *
     * @return list<array{KodeJenisPajak: string, Kategori: string, Tarif: string, PengaliDppPembilang: int, PengaliDppPenyebut: int, BerlakuMulai: string, BerlakuSampai: string|null}>
     */
    public function DaftarUntukOutlet(?string $kodeKotaOutlet, CarbonInterface $hari): array
    {
        $jenis = JenisPajak::query()->get()->keyBy('Id');
        $tarif = TarifPajak::query()
            ->where('Status', StatusDataMaster::Terbit->value)
            ->where(fn ($kueri) => $kueri->whereNull('KodeWilayah')->when($kodeKotaOutlet !== null, fn ($atau) => $atau->orWhere('KodeWilayah', $kodeKotaOutlet)))
            ->where(fn ($kueri) => $kueri->whereNull('BerlakuSampai')->orWhereDate('BerlakuSampai', '>=', $hari->toDateString()))
            ->orderBy('BerlakuMulai')
            ->orderBy('Id')
            ->get();
        $hasil = [];

        foreach ($tarif as $t) {
            $j = $jenis->get($t->IdJenisPajak);

            // Tarif daerah tanpa wilayah atau jenis nasional dengan wilayah bukan tarif outlet ini.
            if ($j === null || ($j->Cakupan === CakupanPajak::Daerah) !== ($t->KodeWilayah !== null)) {
                continue;
            }

            $hasil[] = [
                'KodeJenisPajak' => $j->Kode,
                'Kategori' => $j->Kategori->value,
                'Tarif' => $t->Tarif,
                'PengaliDppPembilang' => $t->PengaliDppPembilang,
                'PengaliDppPenyebut' => $t->PengaliDppPenyebut,
                'BerlakuMulai' => $t->BerlakuMulai->toDateString(),
                'BerlakuSampai' => $t->BerlakuSampai?->toDateString(),
            ];
        }

        usort($hasil, fn (array $a, array $b): int => [$a['KodeJenisPajak'], $a['BerlakuMulai']] <=> [$b['KodeJenisPajak'], $b['BerlakuMulai']]);

        return $hasil;
    }
}
