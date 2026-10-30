<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Kueri\PermintaanTurunPaketMenunggu;
use App\Domain\Tenant\Layanan\PenerbitTagihanLangganan;
use App\Domain\Tenant\Model\KuponLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\TagihanLangganan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P-08 langkah 1 (PRD v4.04): tagihan perpanjangan terbit otomatis H-7 sebelum `PeriodeSelesai` untuk langganan
 * berbayar `Aktif`/`Tertunggak`, dengan paket & siklus yang sedang berjalan, lewat `PenerbitTagihanLangganan` yang sama
 * dengan tagihan buatan Owner (angka, nomor BR-P08.5, PPN, jatuh tempo = akhir periode).
 *
 * - Dilewati: tenant berpenanda Uji/Demo/Internal (P-07), ditangguhkan manual (BR-P07.4), paket harga negosiasi
 *   (ditagih Keuangan), sudah ada tagihan terbuka (BR-P08.4), atau **sudah ada tagihan apa pun sejak jendela H-7
 *   periode ini** — tagihan yang dibatalkan Owner tidak diterbitkan ulang setiap hari (nomor tagihan tidak boleh
 *   terbuang, BR-P08.1); Owner yang ingin siklus/kupon lain membuat tagihannya sendiri.
 * - Kupon berdurasi (BR-P08.7) dilanjutkan dari tagihan terakhir bila masih aktif, berlaku untuk paketnya, dan bulan
 *   diskonnya belum habis; bila tidak, tagihan terbit tanpa kupon.
 * - Galat aturan bisnis per tenant (gerbang belum aktif, harga/tarif PPN belum terbit) tidak menghentikan tenant lain:
 *   dicatat di log dan dicoba lagi pada putaran berikutnya.
 * - Idempoten: baris langganan dikunci dan diperiksa ulang di dalam transaksi.
 *
 * @phpstan-type Hasil array{Diterbitkan: int, Dilewati: int}
 */
final class TerbitkanTagihanPerpanjanganOtomatis
{
    public const HARI_SEBELUM_PERIODE_SELESAI = 7;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenerbitTagihanLangganan $penerbit,
        private readonly PermintaanTurunPaketMenunggu $turunPaket,
    ) {}

    /**
     * @return Hasil
     */
    public function Jalankan(): array
    {
        // H-7 dihitung per tanggal kalender WIB: periode yang berakhir 1 Oktober pukul berapa pun ditagih sejak 24 September.
        $batas = CarbonImmutable::now('Asia/Jakarta')->addDays(self::HARI_SEBELUM_PERIODE_SELESAI)->endOfDay();
        $hasil = ['Diterbitkan' => 0, 'Dilewati' => 0];

        $daftar = Langganan::query()
            ->whereHas('Tenant', fn ($kueri) => $kueri->whereNull('Penanda'))
            ->whereIn('Status', [StatusLangganan::Aktif->value, StatusLangganan::Tertunggak->value])
            ->whereNotNull('PeriodeSelesai')
            ->where('PeriodeSelesai', '<=', $batas)
            ->orderBy('Id')
            ->pluck('IdTenant', 'Id');

        foreach ($daftar as $idLangganan => $idTenant) {
            $sebelumnya = $this->konteks->Ambil();
            $this->konteks->Atur((int) $idTenant);

            try {
                $hasil['Diterbitkan'] += $this->Proses((int) $idLangganan, $batas);
            } catch (PelanggaranAturanBisnis $galat) {
                $hasil['Dilewati']++;
                Log::warning('Tagihan perpanjangan otomatis dilewati.', ['IdTenant' => $idTenant, 'Kode' => $galat->kode]);
            } finally {
                $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
            }
        }

        return $hasil;
    }

    private function Proses(int $idLangganan, CarbonImmutable $batas): int
    {
        return DB::transaction(function () use ($idLangganan, $batas): int {
            $langganan = Langganan::query()->whereKey($idLangganan)->lockForUpdate()->first();

            if ($langganan === null
                || ! in_array($langganan->Status, [StatusLangganan::Aktif, StatusLangganan::Tertunggak], true)
                || $langganan->PeriodeSelesai === null
                || $langganan->PeriodeSelesai->greaterThan($batas)
                || $langganan->CekDitangguhkanManual()) {
                return 0;
            }

            $awalJendela = CarbonImmutable::instance($langganan->PeriodeSelesai)->setTimezone('Asia/Jakarta')
                ->subDays(self::HARI_SEBELUM_PERIODE_SELESAI)->startOfDay();
            $sudahAda = TagihanLangganan::query()
                ->whereNotIn('Jenis', [JenisTagihanLangganan::Addon->value, JenisTagihanLangganan::NaikPaket->value])
                ->where(fn ($kueri) => $kueri->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->orWhere('TerbitPada', '>=', $awalJendela))
                ->exists();

            if ($sudahAda) {
                return 0;
            }

            $paketBerjalan = Paket::query()->whereKey($langganan->IdPaket)->first();

            if ($paketBerjalan === null || $paketBerjalan->HargaNegosiasi || $paketBerjalan->Kode === (string) config('tenant.KodePaketGratis')) {
                return 0;
            }

            // BR-P04.4: turun paket yang menunggu. Ke Gratis tidak ada tagihan (langganan turun saat periode berakhir);
            // ke paket lebih murah, perpanjangan ditagih dengan paket tujuan.
            $turun = $this->turunPaket->Cari($langganan->Id);

            if ($turun !== null && $turun->PaketTujuan->Kode === (string) config('tenant.KodePaketGratis')) {
                return 0;
            }

            $paket = $turun === null ? $paketBerjalan : $turun->PaketTujuan;

            // D-49/D-98: tagihan add-on & naik paket tidak dihitung sebagai tagihan paket; yang belum dibayar dibatalkan agar perpanjangan terbit.
            $this->penerbit->BatalkanTagihanTengahPeriodeTerbuka($langganan->IdTenant, 'Dibatalkan otomatis karena tagihan perpanjangan terbit.');

            $this->penerbit->Terbitkan(
                $langganan,
                $paket,
                JenisTagihanLangganan::Perpanjangan,
                $langganan->AmbilJumlahBulanSiklus(),
                $this->CariKuponLanjutan($langganan->IdTenant, $paket),
                null,
                $turun === null ? null : $paketBerjalan,
            );

            return 1;
        });
    }

    /** Kupon tagihan terakhir yang masih berlaku dan masih punya bulan diskon (BR-P08.7); null = tanpa kupon. */
    private function CariKuponLanjutan(int $idTenant, Paket $paket): ?KuponLangganan
    {
        $idKupon = TagihanLangganan::query()
            ->whereNotNull('IdKuponLangganan')
            ->where('Status', '!=', StatusTagihanLangganan::Dibatalkan->value)
            ->orderByDesc('Id')
            ->value('IdKuponLangganan');

        if (! is_int($idKupon)) {
            return null;
        }

        $kupon = KuponLangganan::query()->whereKey($idKupon)->lockForUpdate()->first();
        $hariIni = now('Asia/Jakarta')->toDateString();

        if ($kupon === null
            || ! $kupon->Aktif
            || ($kupon->BerlakuSampai !== null && $kupon->BerlakuSampai->toDateString() < $hariIni)
            || ($kupon->DaftarKodePaket !== null && ! in_array($paket->Kode, $kupon->DaftarKodePaket, true))
            || $this->penerbit->HitungBulanTerpakai($idTenant, $kupon) >= $kupon->DurasiBulan) {
            return null;
        }

        return $kupon;
    }
}
