<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Kueri\HargaPaketBerlaku;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use Carbon\CarbonImmutable;

/**
 * Harga naik paket di tengah periode (F-19, D-98), dipakai penerbitan tagihan (`PenerbitTagihanLangganan`) dan
 * pratinjau harga di halaman Langganan (`TagihanLanggananTenant`) supaya angka yang dilihat pemilik sama persis dengan
 * yang ditagihkan.
 *
 * Selisih = harga paket baru − harga paket berjalan untuk satu periode (lama langganan berjalan dalam bulan, D-249, dengan tangga diskon yang sama dengan penagihan).
 * Paket berjalan memakai harga jangkar grandfathering tenant (BR-P04.1), paket baru harga berlaku hari ini. Yang
 * ditagih = selisih × hari tersisa ÷ hari periode (`PenghitungProrataAddon`), sebelum PPN. Murni: tanpa tulis ke DB.
 */
final class PenghitungNaikPaket
{
    public function __construct(
        private readonly HargaPaketBerlaku $hargaBerlaku,
        private readonly PenghitungProrataAddon $prorata,
        private readonly PenghitungHargaPeriode $hargaPeriode,
    ) {}

    /**
     * @param  CarbonImmutable|null  $mulaiPaketLama  jangkar grandfathering paket berjalan (`AmbilMulaiLanggananPaket`)
     * @return array{HargaLama: HargaPaket, HargaBaru: HargaPaket, JumlahBulan: int, Selisih: Uang, Subtotal: Uang, Prorata: bool, HariDitagih: int, HariPeriode: int}
     *
     * @throws PelanggaranAturanBisnis
     */
    public function Hitung(Langganan $langganan, Paket $paketLama, Paket $paketBaru, CarbonImmutable $sekarang, ?CarbonImmutable $mulaiPaketLama): array
    {
        if (
            $langganan->Status !== StatusLangganan::Aktif
            || $langganan->PeriodeMulai === null
            || $langganan->PeriodeSelesai === null
            || ! $langganan->PeriodeSelesai->greaterThan($sekarang)
        ) {
            throw new PelanggaranAturanBisnis('NaikPaketTidakBisa', 'Naik paket hanya bisa saat langganan berbayar masih aktif. Perpanjang atau aktifkan langganan Anda dulu.', 'KodePaket');
        }

        if ($paketLama->Id === $paketBaru->Id) {
            throw new PelanggaranAturanBisnis('PaketSama', "Anda sudah memakai paket {$paketBaru->Nama}.", 'KodePaket');
        }

        $hargaLama = $this->hargaBerlaku->Cari($paketLama->Id, $sekarang, $mulaiPaketLama)
            ?? throw new PelanggaranAturanBisnis('HargaBelumTersedia', "Harga paket {$paketLama->Nama} belum tersedia. Hubungi tim kami.", 'KodePaket');
        $hargaBaru = $this->hargaBerlaku->Cari($paketBaru->Id, $sekarang, null)
            ?? throw new PelanggaranAturanBisnis('HargaBelumTersedia', "Harga paket {$paketBaru->Nama} belum tersedia. Hubungi tim kami.", 'KodePaket');
        $jumlahBulan = $langganan->AmbilJumlahBulanSiklus();
        $penuhLama = $this->hargaPeriode->UntukHarga($hargaLama, $jumlahBulan);
        $penuhBaru = $this->hargaPeriode->UntukHarga($hargaBaru, $jumlahBulan);

        if ($penuhBaru->Bandingkan($penuhLama) <= 0) {
            throw new PelanggaranAturanBisnis(
                'BukanNaikPaket',
                "Paket {$paketBaru->Nama} tidak lebih mahal dari paket {$paketLama->Nama}. Turun paket berlaku mulai periode berikutnya. Hubungi tim kami untuk mengaturnya.",
                'KodePaket',
            );
        }

        $selisih = $penuhBaru->Kurangi($penuhLama);
        $hitung = $this->prorata->HitungDariJumlahPenuh($selisih, $sekarang, $langganan->PeriodeMulai, $langganan->PeriodeSelesai);

        return [
            'HargaLama' => $hargaLama,
            'HargaBaru' => $hargaBaru,
            'JumlahBulan' => $jumlahBulan,
            'Selisih' => $selisih,
            'Subtotal' => $hitung['Subtotal'],
            'Prorata' => $hitung['Prorata'],
            'HariDitagih' => $hitung['HariDitagih'],
            'HariPeriode' => $hitung['HariPeriode'],
        ];
    }
}
