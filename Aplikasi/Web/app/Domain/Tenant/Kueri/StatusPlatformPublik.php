<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Enum\KomponenLayanan;
use App\Domain\Tenant\Enum\KondisiKomponen;
use App\Domain\Tenant\Enum\StatusInsiden;
use App\Domain\Tenant\Model\InsidenPlatform;
use App\Domain\Tenant\Model\PembaruanInsiden;
use Illuminate\Support\Facades\Cache;

/**
 * Isi halaman status publik (P-11 PGL-21): kondisi tiap komponen, insiden yang sedang berjalan, dan riwayat insiden
 * selesai `HARI_RIWAYAT` hari terakhir. Hanya insiden yang sudah diumumkan; data internal (siapa yang mencatat, persen
 * tenant terdampak, audit) tidak ikut. Post-mortem hanya bila diterbitkan. Ditembolok `DETIK_TEMBOLOK` detik dan
 * dilupakan setiap kali insiden berubah. Bentuk yang sama menjadi `status.json` untuk hosting statis terpisah.
 */
final class StatusPlatformPublik
{
    public const HARI_RIWAYAT = 30;

    public const DETIK_TEMBOLOK = 30;

    private const KUNCI_TEMBOLOK = 'status-platform-publik';

    /**
     * @return array{DiperbaruiPada: string, Keseluruhan: string, LabelKeseluruhan: string, Komponen: list<array{Kode: string, Nama: string, Kondisi: string, LabelKondisi: string}>, Aktif: list<array<string, mixed>>, Riwayat: list<array<string, mixed>>}
     */
    public function Ambil(): array
    {
        /** @var array{DiperbaruiPada: string, Keseluruhan: string, LabelKeseluruhan: string, Komponen: list<array{Kode: string, Nama: string, Kondisi: string, LabelKondisi: string}>, Aktif: list<array<string, mixed>>, Riwayat: list<array<string, mixed>>} $hasil */
        $hasil = Cache::remember(self::KUNCI_TEMBOLOK, self::DETIK_TEMBOLOK, fn (): array => $this->Susun());

        return $hasil;
    }

    public static function LupakanTembolok(): void
    {
        Cache::forget(self::KUNCI_TEMBOLOK);
    }

    /**
     * @return array{DiperbaruiPada: string, Keseluruhan: string, LabelKeseluruhan: string, Komponen: list<array{Kode: string, Nama: string, Kondisi: string, LabelKondisi: string}>, Aktif: list<array<string, mixed>>, Riwayat: list<array<string, mixed>>}
     */
    private function Susun(): array
    {
        $aktif = InsidenPlatform::query()->whereNotNull('DiumumkanPada')->where('Status', '<>', StatusInsiden::Selesai->value)->orderByDesc('MulaiPada')->get();
        $riwayat = InsidenPlatform::query()
            ->whereNotNull('DiumumkanPada')
            ->where('Status', StatusInsiden::Selesai->value)
            ->where('SelesaiPada', '>=', now()->subDays(self::HARI_RIWAYAT))
            ->orderByDesc('SelesaiPada')
            ->limit(50)
            ->get();
        $pembaruan = PembaruanInsiden::query()->whereIn('IdInsiden', $aktif->pluck('Id')->merge($riwayat->pluck('Id'))->all())->orderBy('Id')->get()->groupBy('IdInsiden');

        $kondisi = [];

        foreach ($aktif as $i) {
            foreach ($i->Komponen as $kode) {
                $baru = $i->Tingkat->AmbilKondisiKomponen();
                $kondisi[$kode] = isset($kondisi[$kode]) && $kondisi[$kode]->Bobot() >= $baru->Bobot() ? $kondisi[$kode] : $baru;
            }
        }

        $komponen = array_map(function (KomponenLayanan $k) use ($kondisi): array {
            $kondisiKomponen = $kondisi[$k->value] ?? KondisiKomponen::Normal;

            return ['Kode' => $k->value, 'Nama' => $k->AmbilLabel(), 'Kondisi' => $kondisiKomponen->value, 'LabelKondisi' => $kondisiKomponen->AmbilLabel()];
        }, KomponenLayanan::cases());

        $terburuk = KondisiKomponen::Normal;

        foreach ($kondisi as $k) {
            $terburuk = $k->Bobot() > $terburuk->Bobot() ? $k : $terburuk;
        }

        return [
            'DiperbaruiPada' => now()->toIso8601ZuluString(),
            'Keseluruhan' => $terburuk->value,
            'LabelKeseluruhan' => match ($terburuk) {
                KondisiKomponen::Normal => 'Semua layanan berjalan normal',
                KondisiKomponen::Sebagian => 'Sebagian layanan mengalami gangguan',
                KondisiKomponen::Besar => 'Ada gangguan besar pada layanan',
            },
            'Komponen' => $komponen,
            'Aktif' => array_values($aktif->map(fn (InsidenPlatform $i): array => $this->Petakan($i, array_values($pembaruan->get($i->Id)?->all() ?? [])))->all()),
            'Riwayat' => array_values($riwayat->map(fn (InsidenPlatform $i): array => $this->Petakan($i, array_values($pembaruan->get($i->Id)?->all() ?? [])))->all()),
        ];
    }

    /**
     * @param  list<PembaruanInsiden>  $pembaruan
     * @return array<string, mixed>
     */
    private function Petakan(InsidenPlatform $i, array $pembaruan): array
    {
        return [
            'Uuid' => $i->Uuid,
            'Judul' => $i->Judul,
            'Tingkat' => $i->Tingkat->value,
            'Status' => $i->Status->value,
            'LabelStatus' => $i->Status->AmbilLabel(),
            'Dampak' => $i->Dampak,
            'Komponen' => array_values(array_map(fn (string $kode): string => KomponenLayanan::tryFrom($kode)?->AmbilLabel() ?? $kode, $i->Komponen)),
            'MulaiPada' => $i->MulaiPada->toIso8601ZuluString(),
            'SelesaiPada' => $i->SelesaiPada?->toIso8601ZuluString(),
            'PostMortem' => $i->PostMortemDiterbitkanPada === null ? null : $i->PostMortem,
            'Pembaruan' => array_values(array_map(fn (PembaruanInsiden $p): array => [
                'Status' => $p->Status->value,
                'LabelStatus' => $p->Status->AmbilLabel(),
                'Isi' => $p->Isi,
                'Waktu' => $p->DibuatPada?->toIso8601ZuluString(),
            ], array_reverse($pembaruan))),
        ];
    }
}
