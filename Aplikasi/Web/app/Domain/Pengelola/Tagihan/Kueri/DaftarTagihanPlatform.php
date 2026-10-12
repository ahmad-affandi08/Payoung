<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusRefundLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Kueri\TagihanLanggananTenant;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\RefundLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tagihan & pembayaran langganan lintas tenant untuk Keuangan/Super Admin (P-08). Tagihan adalah data platform ke
 * tenant, bukan data operasional tenant; lingkup tenant dilepas lewat `KonteksPengelola::KueriDataPlatform`
 * (CLAUDE.md #11, PRD §13.4).
 * Data yang tampil terbatas pada nama usaha, angka tagihan, dan bukti transfer.
 */
final class DaftarTagihanPlatform
{
    public const KOLOM_URUT = ['TerbitPada', 'Total', 'JatuhTempoPada'];

    public const KOLOM_SARING = ['Status', 'TerbitPada'];

    /**
     * @return Builder<TagihanLangganan>
     */
    public static function KueriTagihan(): Builder
    {
        return app(KonteksPengelola::class)->KueriDataPlatform(TagihanLangganan::class);
    }

    /**
     * @return Builder<PembayaranLangganan>
     */
    public static function KueriPembayaran(): Builder
    {
        return app(KonteksPengelola::class)->KueriDataPlatform(PembayaranLangganan::class);
    }

    /**
     * @return Builder<RefundLangganan>
     */
    public static function KueriRefund(): Builder
    {
        return app(KonteksPengelola::class)->KueriDataPlatform(RefundLangganan::class);
    }

    /**
     * Antrean "Menunggu Verifikasi" (P-08 langkah 3), yang terlama di atas.
     *
     * @return list<array<string, mixed>>
     */
    public function AmbilAntrean(): array
    {
        $pembayaran = self::KueriPembayaran()
            ->where('Status', StatusPembayaranLangganan::Menunggu->value)
            ->orderBy('DibuatPada')
            ->orderBy('Id')
            ->limit(100)
            ->get();
        $tagihan = self::KueriTagihan()->with('Paket')->whereKey($pembayaran->pluck('IdTagihanLangganan')->all())->get()->keyBy('Id');
        $namaTenant = $this->AmbilNamaTenant($pembayaran->pluck('IdTenant')->all());

        return array_values($pembayaran->map(function (PembayaranLangganan $baris) use ($tagihan, $namaTenant): array {
            $induk = $tagihan->get($baris->IdTagihanLangganan);

            return [
                ...TagihanLanggananTenant::PetakanPembayaran($baris),
                'NamaTenant' => $namaTenant[$baris->IdTenant] ?? '—',
                'UuidTagihan' => $induk?->Uuid,
                'NomorTagihan' => $induk?->Nomor,
                'TotalTagihan' => $induk?->Total,
                'NamaPaket' => $induk?->Paket->Nama,
            ];
        })->all());
    }

    /**
     * Tagihan untuk `TabelData` (D-16): cari nomor tagihan/nama usaha; saring status (pilihan banyak) & tanggal terbit.
     *
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $permintaan): array
    {
        $pola = PenerapKueriTabel::PolaCari($permintaan->cari);
        $status = $permintaan->AmbilDaftar('Status', array_map(fn (StatusTagihanLangganan $s): string => $s->value, StatusTagihanLangganan::cases()));
        $tanggal = $permintaan->AmbilRentangTanggal('TerbitPada');
        $kueri = self::KueriTagihan()
            ->with(['Paket', 'RincianAddon'])
            ->when($status !== [], fn ($kueri) => $kueri->whereIn('Status', $status))
            ->when($tanggal['Dari'] !== null, fn ($kueri) => $kueri->where('TerbitPada', '>=', $tanggal['Dari'].' 00:00:00'))
            ->when($tanggal['Sampai'] !== null, fn ($kueri) => $kueri->where('TerbitPada', '<=', $tanggal['Sampai'].' 23:59:59'))
            ->when($permintaan->cari !== '', fn ($kueri) => $kueri->where(fn ($dalam) => $dalam
                ->where('Nomor', 'like', $pola)
                ->orWhereIn('IdTenant', Tenant::query()->select('Id')->where('Nama', 'like', $pola))));

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['TerbitPada' => 'TerbitPada', 'Total' => 'Total', 'JatuhTempoPada' => 'JatuhTempoPada'], function (Collection $tagihan): array {
            /** @var list<TagihanLangganan> $isi */
            $isi = array_values($tagihan->all());
            $namaTenant = $this->AmbilNamaTenant(array_map(fn (TagihanLangganan $t): int => $t->IdTenant, $isi));

            return array_map(fn (TagihanLangganan $t): array => [
                ...TagihanLanggananTenant::PetakanTagihan($t),
                'NamaTenant' => $namaTenant[$t->IdTenant] ?? '—',
            ], $isi);
        });
    }

    public function CariTagihan(string $uuid): ?TagihanLangganan
    {
        return self::KueriTagihan()->with(['Paket', 'RincianAddon'])->where('Uuid', $uuid)->first();
    }

    public function CariPembayaran(string $uuid): ?PembayaranLangganan
    {
        return self::KueriPembayaran()->where('Uuid', $uuid)->first();
    }

    /**
     * @return list<PembayaranLangganan>
     */
    public function AmbilPembayaranTagihan(TagihanLangganan $tagihan): array
    {
        return array_values(self::KueriPembayaran()
            ->where('IdTagihanLangganan', $tagihan->Id)
            ->orderByDesc('Id')
            ->get()
            ->all());
    }

    /**
     * Pengajuan refund satu tagihan, terbaru di atas, lengkap dengan nama pengaju dan pemutus.
     *
     * @return list<array<string, mixed>>
     */
    public function AmbilRefundTagihan(TagihanLangganan $tagihan): array
    {
        return $this->PetakanRefund(array_values(self::KueriRefund()->where('IdTagihanLangganan', $tagihan->Id)->orderByDesc('Id')->get()->all()));
    }

    /**
     * Antrean refund di atas batas yang menunggu keputusan Super Admin (P-08, BR-P08.2), terlama di atas.
     *
     * @return list<array<string, mixed>>
     */
    public function AmbilRefundMenunggu(): array
    {
        $refund = self::KueriRefund()->where('Status', StatusRefundLangganan::Diajukan->value)->orderBy('Id')->limit(100)->get();
        $tagihan = self::KueriTagihan()->whereIn('Id', $refund->pluck('IdTagihanLangganan')->all())->get()->keyBy('Id');
        $namaTenant = $this->AmbilNamaTenant($refund->pluck('IdTenant')->unique()->values()->all());

        return array_map(function (array $baris) use ($tagihan, $namaTenant, $refund): array {
            $model = $refund->firstWhere('Uuid', $baris['Uuid']);
            $terkait = $model === null ? null : $tagihan->get($model->IdTagihanLangganan);

            return [
                ...$baris,
                'UuidTagihan' => $terkait?->Uuid,
                'NomorTagihan' => $terkait?->Nomor,
                'NamaTenant' => $model === null ? '—' : ($namaTenant[$model->IdTenant] ?? '—'),
            ];
        }, $this->PetakanRefund(array_values($refund->all())));
    }

    /**
     * @param  list<RefundLangganan>  $refund
     * @return list<array<string, mixed>>
     */
    private function PetakanRefund(array $refund): array
    {
        $idPelaku = array_values(array_unique(array_filter(array_merge(
            array_map(fn (RefundLangganan $baris): int => $baris->IdPenggunaPengelolaPengaju, $refund),
            array_map(fn (RefundLangganan $baris): ?int => $baris->IdPenggunaPengelolaPemutus, $refund),
        ))));
        $nama = PenggunaPengelola::query()->whereKey($idPelaku)->pluck('Nama', 'Id')->all();

        return array_map(fn (RefundLangganan $baris): array => [
            'Uuid' => $baris->Uuid,
            'Status' => $baris->Status->value,
            'LabelStatus' => $baris->Status->AmbilLabel(),
            'Jumlah' => $baris->Jumlah,
            'Alasan' => $baris->Alasan,
            'CatatanPengembalian' => $baris->CatatanPengembalian,
            'Pengaju' => $nama[$baris->IdPenggunaPengelolaPengaju] ?? null,
            'Pemutus' => $baris->IdPenggunaPengelolaPemutus === null ? null : ($nama[$baris->IdPenggunaPengelolaPemutus] ?? null),
            'DiajukanPada' => $baris->DibuatPada?->toIso8601ZuluString(),
            'DiputuskanPada' => $baris->DiputuskanPada?->toIso8601ZuluString(),
            'AlasanTolak' => $baris->AlasanTolak,
            'LayananDiakhiri' => $baris->LayananDiakhiri,
        ], $refund);
    }

    /**
     * @param  array<mixed>  $idTenant
     * @return array<int, string>
     */
    public function AmbilNamaTenant(array $idTenant): array
    {
        /** @var array<int, string> $nama */
        $nama = Tenant::query()->whereKey(array_values(array_unique($idTenant)))->pluck('Nama', 'Id')->all();

        return $nama;
    }

    /**
     * @return array<string, int>
     */
    public function HitungRingkasan(): array
    {
        return [
            'MenungguVerifikasi' => self::KueriPembayaran()->where('Status', StatusPembayaranLangganan::Menunggu->value)->count(),
            'BelumDibayar' => self::KueriTagihan()->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->count(),
        ];
    }
}
