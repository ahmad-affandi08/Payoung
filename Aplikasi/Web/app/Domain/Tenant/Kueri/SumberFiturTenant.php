<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Lisensi\Kueri\LisensiBerlaku;
use App\Domain\Tenant\Data\SumberFitur;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Carbon;

/**
 * Merakit masukan EvaluatorFitur dari data satu tenant (P-04 BR-P04.3, P-07): fitur & batas paket langganan, lalu
 * override pengelola yang masih berlaku. Override yang `BerakhirPada`-nya sudah lewat diabaikan (berakhir otomatis).
 * Bila ada lebih dari satu override aktif untuk kunci yang sama, baris terbaru yang menang.
 *
 * Modul outlet (F-01, BR-01.3) diisi bila outlet disebut: kunci `OutletFitur` aktif outlet itu, atau null (tanpa
 * batasan) bila outlet belum punya baris sama sekali. Flag fitur (P-10) dari `FlagFiturTenant`; add-on (D-49) dari
 * `LanggananAddon` yang sedang berlaku (fitur dan tambahan batasnya).
 *
 * D-35 edisi Lisensi: semua fitur di katalog aktif (termasuk fitur yang ditambahkan rilis berikutnya) dan batas
 * outlet/perangkat/pengguna diambil dari berkas lisensi yang berlaku; batas lain tak terbatas. Override pengelola
 * diabaikan (konsolnya tidak ada, dan baris yang disisipkan langsung ke basis data tidak boleh menambah batas). Tanpa lisensi sah,
 * tidak ada fitur dan semua batas nol (permintaan HTTP pun sudah ditolak `WajibLisensiSah`).
 *
 * Hasil `Ambil()` biasa (tanpa kunci, tanpa waktu khusus) diingat selama satu permintaan/job: satu halaman back-office
 * atau polling POS memeriksa fitur belasan kali (middleware, props Inertia, kuota), dan tiap perakitan memakan 6-10
 * kueri. Class ini terdaftar `scoped`, jadi ingatannya tidak melewati permintaan. Ingatan dikosongkan setiap kali data
 * yang membentuknya berubah lewat model (lihat `PenyediaAplikasi`) atau transaksi dibatalkan. Pembacaan ber-`$kunci`
 * (penegakan batas paket di dalam transaksi) tidak pernah memakai ingatan.
 */
final class SumberFiturTenant
{
    /** Cadangan tetap kecil: perintah terjadwal yang menyapu puluhan ribu tenant tidak boleh menimbun memori. */
    private const MAKS_INGATAN = 64;

    /** @var array<string, SumberFitur> */
    private array $ingatan = [];

    public function __construct(
        private readonly FiturOutlet $fiturOutlet,
        private readonly FlagFiturTenant $flagFitur,
        private readonly LisensiBerlaku $lisensiBerlaku,
    ) {}

    /**
     * @param  bool  $kunci  kunci baris langganan (FOR UPDATE) agar penambahan outlet/pengguna bersamaan dari satu
     *                       tenant diproses berurutan (F-02, BR-P04.3); hanya di dalam transaksi.
     * @param  int|null  $idOutlet  outlet yang modulnya ikut dievaluasi (F-01); null = tanpa batasan modul outlet
     */
    public function Ambil(int $idTenant, ?Carbon $pada = null, bool $kunci = false, ?int $idOutlet = null): SumberFitur
    {
        if ($kunci || $pada !== null) {
            return $this->Rakit($idTenant, $pada, $kunci, $idOutlet);
        }

        $kunciIngatan = $idTenant.':'.($idOutlet ?? '-');

        if (! isset($this->ingatan[$kunciIngatan])) {
            if (count($this->ingatan) >= self::MAKS_INGATAN) {
                array_shift($this->ingatan);
            }

            $this->ingatan[$kunciIngatan] = $this->Rakit($idTenant, null, false, $idOutlet);
        }

        return $this->ingatan[$kunciIngatan];
    }

    /** Buang ingatan (dipanggil saat langganan, add-on, override, flag, atau modul outlet berubah). */
    public function Lupakan(): void
    {
        $this->ingatan = [];
        $this->flagFitur->Lupakan();
    }

    private function Rakit(int $idTenant, ?Carbon $pada, bool $kunci, ?int $idOutlet): SumberFitur
    {
        $langganan = Langganan::query()
            ->with('Paket.Fitur')
            ->where('IdTenant', $idTenant)
            ->when($kunci, fn ($kueri) => $kueri->lockForUpdate())
            ->first();
        $paket = $langganan?->Paket;

        $override = EdisiAplikasi::CekLisensi() ? [] : $this->AmbilOverrideAktif($idTenant, $pada);
        $overrideFitur = [];
        $overrideBatas = [];

        foreach ($override as $baris) {
            if ($baris->Jenis === JenisOverride::Fitur) {
                $overrideFitur[] = $baris->Kunci;
            } elseif ($baris->Jenis === JenisOverride::Batas && in_array($baris->Kunci, Paket::KOLOM_BATAS, true)) {
                $overrideBatas[$baris->Kunci] = $baris->Nilai === null ? null : (int) $baris->Nilai;
            }
        }

        [$fiturPaket, $batasPaket] = EdisiAplikasi::CekLisensi()
            ? $this->AmbilDariLisensi()
            : [$paket?->AmbilKunciFitur() ?? [], $paket?->AmbilBatas() ?? array_fill_keys(Paket::KOLOM_BATAS, 0)];

        return new SumberFitur(
            fiturPaket: $fiturPaket,
            batasPaket: $batasPaket,
            addon: EdisiAplikasi::CekLisensi() ? [] : $this->AmbilAddonAktif($idTenant, $pada),
            overrideFitur: array_values(array_unique($overrideFitur)),
            overrideBatas: $overrideBatas,
            modulOutletAktif: $idOutlet === null ? null : $this->fiturOutlet->AmbilKunciAktifAtauNull($idTenant, $idOutlet),
            flagFitur: $this->flagFitur->Ambil($idTenant, $paket?->Id),
        );
    }

    /**
     * Add-on yang sedang berlaku (D-49): `MulaiPada ≤ sekarang < SelesaiPada`. Edisi Lisensi tidak memakai add-on.
     *
     * @return list<array{KunciFitur: string|null, TambahanBatas: array<string, int>|null, Jumlah: int}>
     */
    private function AmbilAddonAktif(int $idTenant, ?Carbon $pada): array
    {
        $sekarang = $pada ?? Carbon::now();
        $hasil = [];

        foreach (LanggananAddon::query()->with('Addon')->where('IdTenant', $idTenant)->aktifPada($sekarang)->get() as $milik) {
            $hasil[] = ['KunciFitur' => $milik->Addon->KunciFitur, 'TambahanBatas' => $milik->Addon->TambahanBatas, 'Jumlah' => $milik->Jumlah];
        }

        return $hasil;
    }

    /**
     * @return array{0: list<string>, 1: array<string, int|null>}
     */
    private function AmbilDariLisensi(): array
    {
        $lisensi = $this->lisensiBerlaku->Ambil();

        if ($lisensi === null) {
            return [[], array_fill_keys(Paket::KOLOM_BATAS, 0)];
        }

        $batas = array_fill_keys(Paket::KOLOM_BATAS, null);
        $batas['BatasOutlet'] = $lisensi->batasOutlet;
        $batas['BatasPerangkatPerOutlet'] = $lisensi->batasPerangkatPerOutlet;
        $batas['BatasPengguna'] = $lisensi->batasPengguna;

        /** @var list<string> $fitur */
        $fitur = Fitur::query()->orderBy('Kunci')->pluck('Kunci')->all();

        return [$fitur, $batas];
    }

    /**
     * Override batas & fitur yang masih berlaku, terlama dulu (sehingga yang terbaru menimpa).
     *
     * @return list<OverrideTenant>
     */
    public function AmbilOverrideAktif(int $idTenant, ?Carbon $pada = null): array
    {
        return array_values(OverrideTenant::query()
            ->where('IdTenant', $idTenant)
            ->whereIn('Jenis', [JenisOverride::Batas->value, JenisOverride::Fitur->value])
            ->where('BerakhirPada', '>', $pada ?? now())
            ->orderBy('Id')
            ->get()
            ->all());
    }

    public function AmbilNamaPaket(int $idTenant): ?string
    {
        $langganan = Langganan::query()->where('IdTenant', $idTenant)->with('Paket')->first();

        return $langganan?->Paket->Nama;
    }
}
