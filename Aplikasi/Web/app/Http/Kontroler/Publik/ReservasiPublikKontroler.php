<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Kueri\JadwalStafReservasi;
use App\Domain\Katalog\Kueri\LayananReservasi;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pemenuhan\Aksi\BatalkanReservasiPelanggan;
use App\Domain\Pemenuhan\Aksi\BuatReservasi;
use App\Domain\Pemenuhan\Data\DataReservasi;
use App\Domain\Pemenuhan\Enum\SumberReservasi;
use App\Domain\Pemenuhan\Kueri\DaftarReservasi;
use App\Domain\Pemenuhan\Kueri\PengaturanReservasiTenant;
use App\Domain\Pemenuhan\Kueri\SlotReservasi;
use App\Domain\Pemenuhan\Model\Reservasi;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * F-07 mode service (SLS-07): reservasi online tanpa login di `/{slugTenant}/reservasi` bila toko mengaktifkannya.
 * Pelanggan memilih outlet, layanan (produk jasa berdurasi & tampil online), staf opsional, tanggal & jam kosong,
 * lalu mengisi nama & nomor WhatsApp. Halaman `/{slugTenant}/reservasi/{kodeAkses}` menampilkan status dan tombol
 * batal. Tenant dari slug dipasang sebagai konteks sehingga kueri tetap lewat `MilikTenant`.
 */
final class ReservasiPublikKontroler extends Kontroler
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
        private readonly PengaturanReservasiTenant $pengaturan,
    ) {}

    public function Tampilkan(string $slugTenant, PetaUuidOutlet $outlet, LayananReservasi $layanan, JadwalStafReservasi $staf): SymfonyResponse
    {
        $props = $this->DalamTenant($slugTenant, function (int $idTenant) use ($slugTenant, $outlet, $layanan, $staf): ?array {
            $atur = $this->pengaturan->Ambil();

            if (! $atur->OnlineAktif) {
                return null;
            }

            $profil = $this->profil->Ambil($idTenant);
            $hariIni = CarbonImmutable::now($profil['ZonaWaktu'])->toDateString();

            return [
                'Toko' => [
                    'Nama' => app(NamaTampilUsaha::class)->UntukTenant($idTenant),
                    'UrlLogo' => $this->UrlLogo($idTenant, $slugTenant),
                ],
                'Slug' => $slugTenant,
                'Outlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outlet->AmbilRingkas(null, hanyaAktif: true)),
                'Layanan' => array_map(fn (array $l): array => ['Uuid' => $l['Uuid'], 'Nama' => $l['Nama'], 'DurasiMenit' => $l['DurasiMenit'], 'Harga' => $l['Harga']], $layanan->Ambil(true)),
                'Staf' => $staf->AmbilOpsi(),
                'HariIni' => $hariIni,
                'TanggalTerakhir' => CarbonImmutable::parse($hariIni)->addDays($atur->BatasHariKeDepan)->toDateString(),
                'KonfirmasiOtomatis' => $atur->KonfirmasiOtomatis,
            ];
        }, fn (): null => null);

        return Inertia::render('Publik/Reservasi', ['Aktif' => $props !== null, 'Slug' => $slugTenant, ...($props ?? [])])
            ->toResponse(request())->setStatusCode($props === null ? 404 : 200);
    }

    public function Slot(string $slugTenant, Request $permintaan, SlotReservasi $slot, LayananReservasi $layanan, JadwalStafReservasi $staf, PetaUuidOutlet $outlet): JsonResponse
    {
        $valid = $permintaan->validate([
            'Outlet' => ['required', 'string', 'max:26'],
            'Layanan' => ['required', 'string', 'max:26'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Staf' => ['nullable', 'string', 'max:26'],
        ]);

        return $this->DalamTenant($slugTenant, function () use ($valid, $slot, $layanan, $staf, $outlet): JsonResponse {
            $atur = $this->pengaturan->Ambil();
            $idOutlet = $outlet->AmbilIdDariUuid([(string) $valid['Outlet']])[(string) $valid['Outlet']] ?? null;
            $l = $layanan->Cari((string) $valid['Layanan'], true);
            abort_if(! $atur->OnlineAktif || $idOutlet === null || $l === null, 404);
            $idStaf = isset($valid['Staf']) && $valid['Staf'] !== '' ? ($staf->CariStaf((string) $valid['Staf'])['Id'] ?? 0) : null;
            $palingCepat = CarbonImmutable::now()->addMinutes($atur->MinimalMenitSebelum);

            return response()->json(['Slot' => array_map(
                fn (array $s): array => ['Jam' => $s['Jam'], 'Staf' => array_map(fn (array $k): array => ['Uuid' => $k['Uuid'], 'Nama' => $k['Nama']], $s['Staf'])],
                $slot->Hitung($idOutlet, (string) $valid['Tanggal'], $l['DurasiMenit'], $idStaf, $atur, $palingCepat),
            )]);
        }, fn () => abort(404));
    }

    public function Simpan(string $slugTenant, Request $permintaan, BuatReservasi $buat, PetaUuidOutlet $outlet): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Outlet' => ['required', 'string', 'max:26'],
            'UuidLayanan' => ['required', 'string', 'max:26'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Jam' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'UuidStaf' => ['nullable', 'string', 'max:26'],
            'NamaPelanggan' => ['required', 'string', 'max:100'],
            'NoHp' => ['required', 'string', 'max:20'],
            'Catatan' => ['nullable', 'string', 'max:255'],
            'Setuju' => ['accepted'],
        ], [
            'Setuju.accepted' => 'Setujui penggunaan data untuk reservasi & pengingat.',
        ], ['UuidLayanan' => 'layanan', 'NamaPelanggan' => 'nama', 'NoHp' => 'nomor WhatsApp']);

        return $this->DalamTenant($slugTenant, function (int $idTenant) use ($slugTenant, $valid, $buat, $outlet): RedirectResponse {
            abort_unless($this->pengaturan->Ambil()->OnlineAktif, 404);
            $idOutlet = $outlet->AmbilIdDariUuid([(string) $valid['Outlet']])[(string) $valid['Outlet']] ?? null;
            abort_if($idOutlet === null, 404);
            $r = $buat->Jalankan($idTenant, new DataReservasi(
                idOutlet: $idOutlet,
                uuidLayanan: (string) $valid['UuidLayanan'],
                tanggal: (string) $valid['Tanggal'],
                jam: (string) $valid['Jam'],
                uuidStaf: isset($valid['UuidStaf']) ? (string) $valid['UuidStaf'] : null,
                namaPelanggan: (string) $valid['NamaPelanggan'],
                noHp: (string) $valid['NoHp'],
                catatan: isset($valid['Catatan']) ? (string) $valid['Catatan'] : null,
                sumber: SumberReservasi::Online,
            ));

            return redirect("/{$slugTenant}/reservasi/{$r->KodeAkses}");
        });
    }

    public function Status(string $slugTenant, string $kodeAkses, DaftarReservasi $daftar): SymfonyResponse
    {
        $props = $this->DalamTenant($slugTenant, function (int $idTenant) use ($slugTenant, $kodeAkses, $daftar): ?array {
            $r = Reservasi::query()->where('KodeAkses', strtoupper($kodeAkses))->first();

            if ($r === null) {
                return null;
            }

            $baris = $daftar->Petakan(collect([$r]))[0];

            return [
                'Toko' => [
                    'Nama' => app(NamaTampilUsaha::class)->UntukTenant($idTenant),
                    'UrlLogo' => $this->UrlLogo($idTenant, $slugTenant, $r->IdOutlet),
                ],
                'Reservasi' => [
                    'Nomor' => $baris['Nomor'],
                    'MulaiPada' => $baris['MulaiPada'],
                    'SelesaiPada' => $baris['SelesaiPada'],
                    'Layanan' => $baris['Layanan'],
                    'Staf' => $baris['Staf']['Nama'] ?? null,
                    'Outlet' => $baris['Outlet']['Nama'],
                    'NamaPelanggan' => $baris['NamaPelanggan'],
                    'Status' => $baris['Status'],
                    'LabelStatus' => $baris['LabelStatus'],
                    'BolehBatal' => in_array($baris['Status'], ['Menunggu', 'Dikonfirmasi'], true) && $r->MulaiPada->isFuture(),
                ],
            ];
        }, fn (): null => null);

        return Inertia::render('Publik/StatusReservasi', ['Ditemukan' => $props !== null, 'Slug' => $slugTenant, 'Kode' => strtoupper($kodeAkses), ...($props ?? [])])
            ->toResponse(request())->setStatusCode($props === null ? 404 : 200);
    }

    public function Batal(string $slugTenant, string $kodeAkses, BatalkanReservasiPelanggan $batal): RedirectResponse
    {
        return $this->DalamTenant($slugTenant, function () use ($kodeAkses, $batal): RedirectResponse {
            $r = Reservasi::query()->where('KodeAkses', strtoupper($kodeAkses))->first();
            abort_if($r === null, 404);
            $batal->Jalankan($r);

            return back()->with('Kilat', 'Reservasi dibatalkan.');
        });
    }

    /** Logo tenant lewat rute publik `/{slug}/logo` (D-238); null = kepala memakai inisial toko. Outlet reservasi lebih dulu. */
    private function UrlLogo(int $idTenant, string $slugTenant, ?int $idOutlet = null): ?string
    {
        $outlet = $idOutlet === null
            ? Outlet::query()->orderBy('Id')->first(['Id', 'Uuid'])
            : Outlet::query()->whereKey($idOutlet)->first(['Id', 'Uuid']);

        if ($outlet === null || app(PengaturanStrukTenant::class)->AmbilPathLogoUsaha($idTenant, $outlet->Id) === null) {
            return null;
        }

        return url("/{$slugTenant}/logo").'?outlet='.$outlet->Uuid;
    }

    private function DalamTenant(string $slugTenant, Closure $kerja, ?Closure $tidakDikenal = null): mixed
    {
        $idTenant = $this->profil->CariIdDariSlug($slugTenant);

        if ($idTenant === null) {
            return $tidakDikenal !== null ? $tidakDikenal() : throw new PelanggaranAturanBisnis('TokoTidakDitemukan', 'Toko tidak ditemukan.', 'Umum', 404);
        }

        $this->konteks->Atur($idTenant);

        try {
            return $kerja($idTenant);
        } finally {
            $this->konteks->Kosongkan();
        }
    }
}
