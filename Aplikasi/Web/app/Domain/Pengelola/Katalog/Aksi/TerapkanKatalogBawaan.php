<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Katalog\Aksi;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * D-86: menyelaraskan katalog server yang sudah berjalan dengan berkas data rilis (`database/Data/Katalog*.json`)
 * dengan SATU perintah (`php artisan katalog:terapkan`), tanpa lewat konsol satu per satu:
 *
 * 1. Fitur katalog & fitur paket: `LengkapiFiturPaketBawaan` (hanya menambah).
 * 2. Harga paket: berkas memuat harga NORMAL (`Harga`) dan, bila ada, harga peluncuran (`Promo` + `BerlakuSampai`).
 *    Selama promo berjalan dibuat DUA versi harga terbit: versi promo (berlaku hari ini sampai `BerlakuSampai`;
 *    `TerapkanKePelangganLama` = true sehingga seluruh tenant yang sudah ada langsung ikut) dan versi normal yang
 *    terbit sehari sesudah promo berakhir (`TerapkanKePelangganLama` = false: langganan yang dimulai sebelumnya, termasuk
 *    semua yang daftar selama promo, tetap di harga promo; BR-P04.1). Tanpa promo (atau promo sudah lewat) hanya versi
 *    normal. Versi lama tidak diubah selain `BerlakuSampai` (BR-P04.5). Harga baru berlaku pada TAGIHAN BERIKUTNYA:
 *    tagihan yang sudah terbit menyimpan salinan harganya. Paket tanpa harga tetap (Gratis tanpa baris, Enterprise
 *    negosiasi) dilewati.
 * 3. Add-on: dibuat bila belum ada; yang sudah ada diselaraskan nama, harga, fitur, dan tambahan batasnya (harga
 *    add-on berlaku di tagihan berikutnya, termasuk langganan add-on yang sedang berjalan). Add-on bertanda
 *    `Status: Aktif` di berkas ikut diaktifkan bila `$aktifkanAddon`.
 *
 * Langsung terbit tanpa tinjauan dua orang: perintah ini dijalankan operator server (setara Super Admin, D-34) dan
 * isinya adalah berkas data yang sudah lewat tinjauan kode; setiap perubahan tetap tercatat di `LogAuditPengelola`
 * (pelaku kosong = sistem) dan alasan menyebut perintahnya. Idempoten: dijalankan ulang tanpa perubahan berkas = tidak
 * ada yang berubah. Tidak pernah menghapus, mengarsipkan, atau menurunkan status apa pun.
 */
final class TerapkanKatalogBawaan
{
    public const ALASAN = 'Penyelarasan katalog rilis lewat `php artisan katalog:terapkan` (D-86).';

    public function __construct(
        private readonly LengkapiFiturPaketBawaan $lengkapiFitur,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @return array{
     *     FiturBaru: list<string>,
     *     PenambahanFitur: list<array{Paket: string, Fitur: string}>,
     *     Harga: list<array{Paket: string, Jenis: string, HargaBulananLama: string|null, HargaBulanan: string, HargaTahunan: string, BerlakuMulai: string, BerlakuSampai: string|null}>,
     *     Addon: list<array{Kode: string, Tindakan: string, Perubahan: list<string>}>
     * }
     */
    public function Jalankan(
        bool $terapkan = true,
        bool $pelangganLama = true,
        bool $aktifkanAddon = true,
        ?string $pathFitur = null,
        ?string $pathPaket = null,
        ?string $pathAddon = null,
    ): array {
        $dataFitur = SiapkanKatalogBawaan::BacaJson($pathFitur ?? database_path('Data/KatalogFitur.json'), 'Fitur');
        $dataPaket = SiapkanKatalogBawaan::BacaJson($pathPaket ?? database_path('Data/KatalogPaket.json'), 'Paket');
        $dataAddon = SiapkanKatalogBawaan::BacaJson($pathAddon ?? database_path('Data/KatalogAddon.json'), 'Addon');

        return DB::transaction(function () use ($terapkan, $pelangganLama, $aktifkanAddon, $pathFitur, $pathPaket, $dataFitur, $dataPaket, $dataAddon): array {
            $fitur = $this->lengkapiFitur->Jalankan($terapkan, $pathFitur, $pathPaket);
            $kunciDikenal = [];

            foreach (Fitur::query()->pluck('Kunci') as $kunci) {
                $kunciDikenal[(string) $kunci] = true;
            }

            foreach ($dataFitur as $baris) {
                $kunciDikenal[SiapkanKatalogBawaan::AmbilTeks($baris, 'Kunci')] = true;
            }

            return [
                'FiturBaru' => $fitur['FiturBaru'],
                'PenambahanFitur' => $fitur['Penambahan'],
                'Harga' => $this->SelaraskanHarga($dataPaket, $terapkan, $pelangganLama),
                'Addon' => $this->SelaraskanAddon($dataAddon, $kunciDikenal, $terapkan, $aktifkanAddon),
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $dataPaket
     * @return list<array{Paket: string, Jenis: string, HargaBulananLama: string|null, HargaBulanan: string, HargaTahunan: string, BerlakuMulai: string, BerlakuSampai: string|null}>
     */
    private function SelaraskanHarga(array $dataPaket, bool $terapkan, bool $pelangganLama): array
    {
        $hasil = [];
        $hariIni = now('Asia/Jakarta')->startOfDay();

        foreach ($dataPaket as $data) {
            $harga = $data['Harga'] ?? null;

            if (! is_array($harga) || ($data['HargaNegosiasi'] ?? false) === true) {
                continue;
            }

            $kode = SiapkanKatalogBawaan::AmbilTeks($data, 'Kode');
            $paket = Paket::query()->where('Kode', $kode)->first();

            if ($paket === null) {
                continue;
            }

            $normalBulanan = Uang::Dari(SiapkanKatalogBawaan::AmbilTeks($harga, 'HargaBulanan'));
            $normalTahunan = Uang::Dari(SiapkanKatalogBawaan::AmbilTeks($harga, 'HargaTahunan'));
            $terbit = HargaPaket::query()
                ->where('IdPaket', $paket->Id)
                ->where('Status', StatusDataMaster::Terbit->value)
                ->orderByDesc('BerlakuMulai')
                ->lockForUpdate()
                ->get();
            $berlaku = $terbit->first(fn (HargaPaket $versi): bool => $versi->BerlakuMulai->toDateString() <= $hariIni->toDateString());

            /** @var list<array{Jenis: string, Bulanan: Uang, Tahunan: Uang, Mulai: Carbon, Sampai: Carbon|null, Lama: bool}> $rencana */
            $rencana = [];
            $promo = is_array($data['Promo'] ?? null) ? $data['Promo'] : null;
            $akhirPromo = $promo === null ? null : Carbon::parse(SiapkanKatalogBawaan::AmbilTeks($promo, 'BerlakuSampai'), 'Asia/Jakarta')->startOfDay();
            $mulaiPromo = $hariIni->copy();
            $versiTerakhir = $terbit->first();
            // Awal promo hanya digeser oleh versi yang mulai sebelum promo berakhir. Versi harga normal yang sudah dijadwalkan
            // sesudah promo (mulai sehari setelah promo berakhir) bukan bagian promo: bila ikut dihitung, menjalankan ulang
            // perintah di tengah promo menggeser awal promo melewati akhirnya, promo dilewati, dan harga normal terbit lagi
            // sebagai versi untuk pelanggan lama yang membuka kunci harga promo seluruh tenant.
            $versiDalamPromo = $akhirPromo === null
                ? $versiTerakhir
                : $terbit->first(fn (HargaPaket $versi): bool => $versi->BerlakuMulai->toDateString() <= $akhirPromo->toDateString());

            if ($versiDalamPromo !== null && $versiDalamPromo->BerlakuMulai->greaterThanOrEqualTo($mulaiPromo)) {
                $mulaiPromo = Carbon::parse($versiDalamPromo->BerlakuMulai->toDateString(), 'Asia/Jakarta')->addDay();
            }

            if ($promo !== null && $akhirPromo !== null && $mulaiPromo->lessThanOrEqualTo($akhirPromo)) {
                $promoBulanan = Uang::Dari(SiapkanKatalogBawaan::AmbilTeks($promo, 'HargaBulanan'));
                $promoTahunan = Uang::Dari(SiapkanKatalogBawaan::AmbilTeks($promo, 'HargaTahunan'));
                $mulaiNormal = $akhirPromo->copy()->addDay();
                $adaPromo = $terbit->contains(fn (HargaPaket $versi): bool => $versi->AmbilHargaBulanan()->SamaDengan($promoBulanan)
                    && $versi->AmbilHargaTahunan()->SamaDengan($promoTahunan)
                    && $versi->BerlakuSampai?->toDateString() === $akhirPromo->toDateString());
                $adaNormal = $terbit->contains(fn (HargaPaket $versi): bool => $versi->AmbilHargaBulanan()->SamaDengan($normalBulanan)
                    && $versi->AmbilHargaTahunan()->SamaDengan($normalTahunan)
                    && $versi->BerlakuMulai->toDateString() === $mulaiNormal->toDateString());

                if (! $adaPromo) {
                    $rencana[] = ['Jenis' => 'promo', 'Bulanan' => $promoBulanan, 'Tahunan' => $promoTahunan, 'Mulai' => $mulaiPromo, 'Sampai' => $akhirPromo, 'Lama' => $pelangganLama];
                }

                // Harga normal terbit sehari setelah promo; langganan yang dimulai sebelumnya tetap di harga promo (BR-P04.1).
                // Versi terbit tidak pernah diubah (BR-P04.5): bila sudah ada versi bertanggal sama dengan harga lain, perubahan
                // harga normal masuk lewat alur usulan di konsol, bukan perintah ini.
                $adaVersiTanggalNormal = $terbit->contains(fn (HargaPaket $versi): bool => $versi->BerlakuMulai->toDateString() === $mulaiNormal->toDateString());

                if (! $adaNormal && ! $adaVersiTanggalNormal) {
                    $rencana[] = ['Jenis' => 'normal', 'Bulanan' => $normalBulanan, 'Tahunan' => $normalTahunan, 'Mulai' => $mulaiNormal, 'Sampai' => null, 'Lama' => false];
                }
            } elseif ($versiTerakhir === null || ! ($versiTerakhir->AmbilHargaBulanan()->SamaDengan($normalBulanan) && $versiTerakhir->AmbilHargaTahunan()->SamaDengan($normalTahunan))) {
                $rencana[] = ['Jenis' => 'normal', 'Bulanan' => $normalBulanan, 'Tahunan' => $normalTahunan, 'Mulai' => $mulaiPromo, 'Sampai' => null, 'Lama' => $pelangganLama];
            }

            foreach ($rencana as $langkah) {
                $hasil[] = [
                    'Paket' => $kode,
                    'Jenis' => $langkah['Jenis'],
                    'HargaBulananLama' => $berlaku?->AmbilHargaBulanan()->KeString(),
                    'HargaBulanan' => $langkah['Bulanan']->KeString(),
                    'HargaTahunan' => $langkah['Tahunan']->KeString(),
                    'BerlakuMulai' => $langkah['Mulai']->toDateString(),
                    'BerlakuSampai' => $langkah['Sampai']?->toDateString(),
                ];

                if ($terapkan) {
                    $this->TerbitkanVersi($paket, $kode, $terbit, $berlaku, $langkah);
                }
            }
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, HargaPaket>  $terbit
     * @param  array{Jenis: string, Bulanan: Uang, Tahunan: Uang, Mulai: Carbon, Sampai: Carbon|null, Lama: bool}  $langkah
     */
    private function TerbitkanVersi(Paket $paket, string $kode, Collection $terbit, ?HargaPaket $berlaku, array $langkah): void
    {
        $sampaiLama = $langkah['Mulai']->copy()->subDay();

        // Versi lama yang mulai sebelum versi baru diakhiri sehari sebelumnya (BR-P04.5: hanya BerlakuSampai yang boleh berubah).
        foreach ($terbit as $lama) {
            if ($lama->BerlakuMulai->lessThan($langkah['Mulai']) && ($lama->BerlakuSampai === null || $lama->BerlakuSampai->greaterThan($sampaiLama))) {
                $lama->update(['BerlakuSampai' => $sampaiLama->toDateString()]);
            }
        }

        $baru = HargaPaket::query()->create([
            'IdPaket' => $paket->Id,
            'HargaBulanan' => $langkah['Bulanan']->KeString(),
            'HargaTahunan' => $langkah['Tahunan']->KeString(),
            'BerlakuMulai' => $langkah['Mulai']->toDateString(),
            'BerlakuSampai' => $langkah['Sampai']?->toDateString(),
            'TerapkanKePelangganLama' => $langkah['Lama'],
            'Status' => StatusDataMaster::Terbit,
        ]);

        $this->audit->Catat(
            'katalog.harga.terapkan-rilis',
            $baru,
            nilaiLama: $berlaku === null ? null : ['HargaBulanan' => $berlaku->HargaBulanan, 'HargaTahunan' => $berlaku->HargaTahunan, 'BerlakuMulai' => $berlaku->BerlakuMulai->toDateString()],
            nilaiBaru: ['Paket' => $kode, 'Jenis' => $langkah['Jenis'], 'HargaBulanan' => $langkah['Bulanan']->KeString(), 'HargaTahunan' => $langkah['Tahunan']->KeString(), 'BerlakuMulai' => $langkah['Mulai']->toDateString(), 'BerlakuSampai' => $langkah['Sampai']?->toDateString(), 'TerapkanKePelangganLama' => $langkah['Lama']],
            alasan: self::ALASAN,
        );

        $terbit->push($baru);
    }

    /**
     * @param  list<array<string, mixed>>  $dataAddon
     * @param  array<string, true>  $kunciDikenal
     * @return list<array{Kode: string, Tindakan: string, Perubahan: list<string>}>
     */
    private function SelaraskanAddon(array $dataAddon, array $kunciDikenal, bool $terapkan, bool $aktifkanAddon): array
    {
        $hasil = [];

        foreach ($dataAddon as $data) {
            $kode = SiapkanKatalogBawaan::AmbilTeks($data, 'Kode');
            $nama = SiapkanKatalogBawaan::AmbilTeks($data, 'Nama');
            $harga = Uang::Dari(SiapkanKatalogBawaan::AmbilTeks($data, 'HargaBulanan'));
            $kunciFitur = is_string($data['KunciFitur'] ?? null) ? $data['KunciFitur'] : null;
            $tambahan = is_array($data['TambahanBatas'] ?? null)
                ? array_map('intval', array_intersect_key($data['TambahanBatas'], array_flip(Paket::KOLOM_BATAS)))
                : [];
            $inginAktif = $aktifkanAddon && ($data['Status'] ?? 'Aktif') === StatusPaket::Aktif->value;

            if ($kunciFitur !== null && ! isset($kunciDikenal[$kunciFitur])) {
                throw new RuntimeException("Add-on {$kode} memakai fitur {$kunciFitur} yang tidak ada di katalog fitur.");
            }

            $addon = Addon::query()->where('Kode', $kode)->lockForUpdate()->first();

            if ($addon === null) {
                $hasil[] = ['Kode' => $kode, 'Tindakan' => 'baru', 'Perubahan' => ['harga '.$harga->KeString(), $inginAktif ? 'aktif' : 'diarsipkan']];

                if ($terapkan) {
                    $addon = Addon::query()->create([
                        'Kode' => $kode,
                        'Nama' => $nama,
                        'HargaBulanan' => $harga->KeString(),
                        'KunciFitur' => $kunciFitur,
                        'TambahanBatas' => $tambahan === [] ? null : $tambahan,
                        'Status' => $inginAktif ? StatusPaket::Aktif : StatusPaket::Diarsipkan,
                    ]);
                    $this->audit->Catat('katalog.addon.terapkan-rilis', $addon, nilaiBaru: $addon->only(['Nama', 'HargaBulanan', 'KunciFitur', 'TambahanBatas', 'Status']), alasan: self::ALASAN);
                }

                continue;
            }

            $perubahan = [];
            $nilai = [];

            if ($addon->Nama !== $nama) {
                $perubahan[] = "nama {$addon->Nama} → {$nama}";
                $nilai['Nama'] = $nama;
            }

            if (! Uang::Dari($addon->HargaBulanan)->SamaDengan($harga)) {
                $perubahan[] = 'harga '.Uang::Dari($addon->HargaBulanan)->KeString().' → '.$harga->KeString();
                $nilai['HargaBulanan'] = $harga->KeString();
            }

            if ($addon->KunciFitur !== $kunciFitur) {
                $perubahan[] = 'fitur '.($addon->KunciFitur ?? '-').' → '.($kunciFitur ?? '-');
                $nilai['KunciFitur'] = $kunciFitur;
            }

            if (($addon->TambahanBatas ?? []) != $tambahan) {
                $perubahan[] = 'tambahan batas diselaraskan';
                $nilai['TambahanBatas'] = $tambahan === [] ? null : $tambahan;
            }

            if ($inginAktif && $addon->Status === StatusPaket::Diarsipkan) {
                $perubahan[] = 'diaktifkan';
                $nilai['Status'] = StatusPaket::Aktif;
            }

            if ($perubahan === []) {
                continue;
            }

            $hasil[] = ['Kode' => $kode, 'Tindakan' => 'ubah', 'Perubahan' => $perubahan];

            if ($terapkan) {
                $lama = $addon->only(['Nama', 'HargaBulanan', 'KunciFitur', 'TambahanBatas', 'Status']);
                $addon->update($nilai);
                $this->audit->Catat('katalog.addon.terapkan-rilis', $addon, nilaiLama: $lama, nilaiBaru: $addon->only(['Nama', 'HargaBulanan', 'KunciFitur', 'TambahanBatas', 'Status']), alasan: self::ALASAN);
            }
        }

        return $hasil;
    }
}
