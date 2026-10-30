<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Snap\PembuatQrSnap;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Layanan\PengaturanLamaLangganan;
use App\Domain\Tenant\Layanan\PenghitungHargaPeriode;
use App\Domain\Tenant\Layanan\PenghitungNaikPaket;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\TagihanLanggananAddon;
use Carbon\CarbonImmutable;

/**
 * Data halaman Langganan di back-office tenant (/kelola/langganan). Tagihan & pembayaran memakai `MilikTenant`,
 * sehingga tenant lain tidak pernah terlihat; langganan disaring `IdTenant` tenant aktif.
 */
final class TagihanLanggananTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly HargaPaketBerlaku $hargaBerlaku,
        private readonly PenghitungNaikPaket $naikPaket,
        private readonly PengaturanLamaLangganan $lamaLangganan,
        private readonly PenghitungHargaPeriode $hargaPeriode,
    ) {}

    /**
     * D-249: aturan lama langganan yang boleh dipilih (dari konsol, bukan angka di kode) untuk input di halaman Langganan.
     *
     * @return array{MinimalBulan: int, MaksimalBulan: int, TanggaDiskon: list<array{DariBulan: int, Persen: string}>}
     */
    public function AmbilAturanLama(): array
    {
        $atur = $this->lamaLangganan->Ambil();

        return ['MinimalBulan' => $atur['MinimalBulan'], 'MaksimalBulan' => $atur['MaksimalBulan'], 'TanggaDiskon' => $atur['TanggaDiskon']];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function AmbilLangganan(): ?array
    {
        $langganan = Langganan::query()->with('Paket')->where('IdTenant', $this->konteks->Wajib())->first();

        if ($langganan === null) {
            return null;
        }

        return [
            'Status' => $langganan->Status->value,
            'LabelStatus' => $langganan->Status->AmbilLabel(),
            'KodePaket' => $langganan->Paket->Kode,
            'NamaPaket' => $langganan->Paket->Nama,
            'SiklusTagihan' => $langganan->SiklusTagihan->value,
            'JumlahBulanSiklus' => $langganan->AmbilJumlahBulanSiklus(),
            'TrialBerakhirPada' => $langganan->TrialBerakhirPada?->toIso8601ZuluString(),
            'PeriodeMulai' => $langganan->PeriodeMulai?->toIso8601ZuluString(),
            'PeriodeSelesai' => $langganan->PeriodeSelesai?->toIso8601ZuluString(),
            'BatasTenggangPada' => $langganan->Status === StatusLangganan::Tertunggak && $langganan->PeriodeSelesai !== null
                ? $langganan->PeriodeSelesai->copy()->addDays((int) config('tagihan.HariMasaTenggang'))->toIso8601ZuluString()
                : null,
        ];
    }

    /**
     * Paket yang bisa ditagihkan beserta harga berlaku untuk tenant ini (grandfathering paket berjalan, BR-P04.1).
     *
     * @return list<array<string, mixed>>
     */
    public function AmbilPilihanPaket(): array
    {
        $langganan = Langganan::query()->where('IdTenant', $this->konteks->Wajib())->first();
        $berjalan = $langganan !== null && in_array($langganan->Status, [StatusLangganan::Aktif, StatusLangganan::Tertunggak], true);
        $sekarang = CarbonImmutable::now();

        $paket = Paket::query()
            ->where('HargaNegosiasi', false)
            ->where('Kode', '!=', (string) config('tenant.KodePaketGratis'))
            ->where(fn ($kueri) => $kueri->where('Status', StatusPaket::Aktif->value)
                ->when($berjalan, fn ($lanjutan) => $lanjutan->orWhere('Id', $langganan?->IdPaket)))
            ->orderBy('Urutan')
            ->get();

        $hasil = [];
        $aktif = $langganan !== null && $langganan->Status === StatusLangganan::Aktif;
        $paketAsal = $aktif ? $paket->firstWhere('Id', $langganan->IdPaket) : null;

        foreach ($paket as $item) {
            $paketBerjalan = $berjalan && $item->Id === $langganan?->IdPaket;
            $harga = $this->hargaBerlaku->Cari($item->Id, $sekarang, $paketBerjalan ? $this->AmbilMulaiLanggananPaket($item->Id) : null);

            if ($harga === null || $harga->AmbilHargaBulanan()->BernilaiNol()) {
                continue;
            }

            // D-98: saat langganan Aktif, paket yang lebih mahal bisa dipilih (naik paket, selisih prorata); paket yang
            // lebih murah tidak (turun paket berlaku periode berikutnya, lewat tim kami).
            $naik = null;

            if ($aktif && ! $paketBerjalan && $paketAsal !== null && $langganan !== null) {
                try {
                    $hitung = $this->naikPaket->Hitung($langganan, $paketAsal, $item, $sekarang, $this->AmbilMulaiLanggananPaket($paketAsal->Id));
                    $naik = [
                        'Selisih' => $hitung['Selisih']->KeString(),
                        'Subtotal' => $hitung['Subtotal']->KeString(),
                        'HariDitagih' => $hitung['HariDitagih'],
                        'HariPeriode' => $hitung['HariPeriode'],
                    ];
                } catch (PelanggaranAturanBisnis) {
                    $naik = null;
                }
            }

            $atur = $this->lamaLangganan->Ambil();
            $hargaPerLama = [];

            for ($bulan = $atur['MinimalBulan']; $bulan <= $atur['MaksimalBulan']; $bulan++) {
                $hitungLama = $this->hargaPeriode->Hitung($harga->AmbilHargaBulanan(), $harga->AmbilHargaTahunan(), $bulan, $atur['TanggaDiskon']);
                $hargaPerLama[] = [
                    'JumlahBulan' => $bulan,
                    'Subtotal' => $hitungLama['Subtotal']->KeString(),
                    'HargaPenuh' => $hitungLama['HargaPenuh']->KeString(),
                    'DiskonPersen' => $hitungLama['DiskonPersen'],
                ];
            }

            $hasil[] = [
                'Kode' => $item->Kode,
                'Nama' => $item->Nama,
                'Keterangan' => $item->Keterangan,
                'HargaBulanan' => $harga->AmbilHargaBulanan()->KeString(),
                'HargaTahunan' => $harga->AmbilHargaTahunan()->KeString(),
                'HargaPerLama' => $hargaPerLama,
                'PaketBerjalan' => $paketBerjalan,
                'NaikPaket' => $naik,
                'BisaDipilih' => $paketBerjalan || ! $aktif || $naik !== null,
            ];
        }

        return $hasil;
    }

    /**
     * Jangkar grandfathering (BR-P04.1): tanggal mulai berlangganan paket ini dari tagihan lunas terakhir tenant,
     * bila tagihan lunas terakhir memang untuk paket yang sama.
     */
    public function AmbilMulaiLanggananPaket(int $idPaket): ?CarbonImmutable
    {
        $terakhir = TagihanLangganan::query()
            ->where('Status', StatusTagihanLangganan::Lunas->value)
            ->where('Jenis', '!=', JenisTagihanLangganan::Addon->value)
            ->orderByDesc('DibayarPada')
            ->orderByDesc('Id')
            ->first();

        return $terakhir !== null && $terakhir->IdPaket === $idPaket && $terakhir->MulaiLanggananPaket !== null
            ? CarbonImmutable::instance($terakhir->MulaiLanggananPaket)
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function DaftarTagihan(): array
    {
        return array_values(TagihanLangganan::query()
            ->with(['Paket', 'RincianAddon'])
            ->orderByDesc('TerbitPada')
            ->orderByDesc('Id')
            ->limit(50)
            ->get()
            ->map(fn (TagihanLangganan $tagihan): array => self::PetakanTagihan($tagihan))
            ->all());
    }

    public function CariTagihan(string $uuid): ?TagihanLangganan
    {
        return TagihanLangganan::query()->with(['Paket', 'Pembayaran', 'RincianAddon'])->where('Uuid', $uuid)->first();
    }

    public function CariPembayaran(string $uuid): ?PembayaranLangganan
    {
        return PembayaranLangganan::query()->where('Uuid', $uuid)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function PetakanTagihan(TagihanLangganan $tagihan): array
    {
        // D-49: rincian add-on (prorata pada tagihan Addon, per add-on pada Perpanjangan). Subtotal paket = Subtotal − Σ add-on.
        $rincian = $tagihan->relationLoaded('RincianAddon') ? $tagihan->RincianAddon : $tagihan->RincianAddon()->get();
        $subtotalPaket = Uang::Dari($tagihan->Subtotal);

        foreach ($rincian as $baris) {
            $subtotalPaket = $subtotalPaket->Kurangi(Uang::Dari($baris->Subtotal));
        }

        return [
            'RincianAddon' => array_values($rincian->map(fn (TagihanLanggananAddon $baris): array => [
                'KodeAddon' => $baris->KodeAddon,
                'NamaAddon' => $baris->NamaAddon,
                'Jumlah' => $baris->Jumlah,
                'HargaBulanan' => $baris->HargaBulanan,
                'JumlahBulan' => $baris->JumlahBulan,
                'Prorata' => $baris->Prorata,
                'HariDitagih' => $baris->HariDitagih,
                'HariPeriode' => $baris->HariPeriode,
                'Subtotal' => $baris->Subtotal,
            ])->all()),
            'SubtotalPaket' => $subtotalPaket->KeString(),
            // D-98: rincian naik paket (kosong untuk jenis lain).
            'NaikPaket' => $tagihan->Jenis === JenisTagihanLangganan::NaikPaket ? [
                'NamaPaketAsal' => $tagihan->PaketAsal()->first()?->Nama,
                'HariDitagih' => $tagihan->HariDitagih,
                'HariPeriode' => $tagihan->HariPeriode,
            ] : null,
            'Uuid' => $tagihan->Uuid,
            'Nomor' => $tagihan->Nomor,
            'Jenis' => $tagihan->Jenis->value,
            'LabelJenis' => $tagihan->Jenis->AmbilLabel(),
            'Status' => $tagihan->Status->value,
            'LabelStatus' => $tagihan->Status->AmbilLabel(),
            'Manual' => $tagihan->Manual,
            'Keterangan' => $tagihan->Keterangan,
            'KodePaket' => $tagihan->Paket->Kode,
            'NamaPaket' => $tagihan->Paket->Nama,
            'Siklus' => $tagihan->Siklus->value,
            'JumlahBulan' => $tagihan->JumlahBulan,
            'Subtotal' => $tagihan->Subtotal,
            'KodeKupon' => $tagihan->KodeKupon,
            'Diskon' => $tagihan->Diskon,
            'TarifPpn' => $tagihan->TarifPpn,
            'PengaliDppPembilang' => $tagihan->PengaliDppPembilang,
            'PengaliDppPenyebut' => $tagihan->PengaliDppPenyebut,
            'DasarPengenaanPajak' => $tagihan->DasarPengenaanPajak,
            'JumlahPpn' => $tagihan->JumlahPpn,
            'Total' => $tagihan->Total,
            'TerbitPada' => $tagihan->TerbitPada->toIso8601ZuluString(),
            'JatuhTempoPada' => $tagihan->JatuhTempoPada->toIso8601ZuluString(),
            'DibayarPada' => $tagihan->DibayarPada?->toIso8601ZuluString(),
            'DibatalkanPada' => $tagihan->DibatalkanPada?->toIso8601ZuluString(),
            'AlasanBatal' => $tagihan->AlasanBatal,
            'DihapusBukuPada' => $tagihan->DihapusBukuPada?->toIso8601ZuluString(),
            'AlasanHapusBuku' => $tagihan->AlasanHapusBuku,
            'DikembalikanPada' => $tagihan->DikembalikanPada?->toIso8601ZuluString(),
            'PeriodeMulai' => $tagihan->PeriodeMulai?->toIso8601ZuluString(),
            'PeriodeSelesai' => $tagihan->PeriodeSelesai?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function PetakanPembayaran(PembayaranLangganan $pembayaran): array
    {
        return [
            'Uuid' => $pembayaran->Uuid,
            'Metode' => $pembayaran->Metode->value,
            'LabelMetode' => self::AmbilLabelCaraBayar($pembayaran),
            'Status' => $pembayaran->Status->value,
            'LabelStatus' => $pembayaran->Status->AmbilLabel(),
            'Jumlah' => $pembayaran->Jumlah,
            'TanggalTransfer' => $pembayaran->TanggalTransfer?->toDateString(),
            'BankPengirim' => $pembayaran->BankPengirim,
            'NamaPengirim' => $pembayaran->NamaPengirim,
            'BankTujuan' => $pembayaran->BankTujuan,
            'NomorRekeningTujuan' => $pembayaran->NomorRekeningTujuan,
            'NamaFileBukti' => $pembayaran->NamaFileBukti,
            'MimeBukti' => $pembayaran->MimeBukti,
            'DiunggahPada' => $pembayaran->DibuatPada?->toIso8601ZuluString(),
            'DiverifikasiPada' => $pembayaran->DiverifikasiPada?->toIso8601ZuluString(),
            'AlasanTolak' => $pembayaran->AlasanTolak,
            'TampilanBayar' => self::PetakanTampilanBayar($pembayaran),
        ];
    }

    /** "QRIS", "Virtual account BNI", "DANA"; selain SNAP memakai label metode biasa. */
    private static function AmbilLabelCaraBayar(PembayaranLangganan $pembayaran): string
    {
        $snap = $pembayaran->AmbilMetodeSnap();

        if ($snap === null) {
            return $pembayaran->Metode->AmbilLabel();
        }

        $bank = $pembayaran->RincianBayar['NamaBank'] ?? null;

        return $snap->AmbilLabel().($bank !== null && $bank !== '' ? ' '.$bank : '');
    }

    /**
     * D-159: isi halaman bayar Payoung untuk pembayaran SNAP yang masih menunggu (gambar QR, nomor VA, atau tautan
     * e-wallet). null untuk pembayaran lain atau yang sudah selesai: kode bayar yang sudah tidak berlaku tidak ditampilkan.
     *
     * @return array<string, mixed>|null
     */
    public static function PetakanTampilanBayar(PembayaranLangganan $pembayaran): ?array
    {
        $snap = $pembayaran->AmbilMetodeSnap();
        $rincian = $pembayaran->RincianBayar;

        if ($snap === null || $rincian === null || $pembayaran->Status !== StatusPembayaranLangganan::Menunggu) {
            return null;
        }

        return [
            'Metode' => $snap->value,
            'Tampilan' => $snap->AmbilTampilan(),
            'Label' => self::AmbilLabelCaraBayar($pembayaran),
            'QrSvg' => isset($rincian['IsiQr']) ? app(PembuatQrSnap::class)->BuatSvg($rincian['IsiQr']) : null,
            'NomorVa' => $rincian['NomorVa'] ?? null,
            'NamaBank' => $rincian['NamaBank'] ?? null,
            'UrlAlih' => $rincian['UrlAlih'] ?? null,
            'Jumlah' => $pembayaran->Jumlah,
            'KedaluwarsaPada' => $pembayaran->KedaluwarsaPada?->toIso8601ZuluString(),
        ];
    }
}
