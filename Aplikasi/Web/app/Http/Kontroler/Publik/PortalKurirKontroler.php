<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Kueri\UrlLogoPublik;
use App\Domain\Pemenuhan\Aksi\AturTautanPortalKurir;
use App\Domain\Pemenuhan\Aksi\UbahStatusPengirimanPesanan;
use App\Domain\Pemenuhan\Enum\StatusKurir;
use App\Domain\Pemenuhan\Enum\StatusPengirimanPesanan;
use App\Domain\Pemenuhan\Layanan\PenyimpanBuktiPengiriman;
use App\Domain\Pemenuhan\Model\Kurir;
use App\Domain\Pemenuhan\Model\PengirimanPesanan;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\PesananOnlineDetail;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * F-10 (v3.49) portal kurir `/{slugTenant}/kurir/{token}`: kurir tanpa akun Payoung membuka tautan rahasia dari toko,
 * melihat pengiriman yang ditugaskan kepadanya (Dikemas/Dikirim), lalu menandai berangkat, diterima (nama penerima +
 * foto bukti opsional), atau gagal (alasan). Aturan status sama dengan back-office (`UbahStatusPengirimanPesanan`).
 *
 * Data pelanggan (nomor HP & alamat) hanya tampil untuk pengiriman yang masih berjalan; yang sudah selesai dalam 24 jam
 * terakhir tampil tanpa keduanya. Tautan tak dikenal, dicabut, atau kurir diarsipkan = 404 yang sama.
 */
final class PortalKurirKontroler extends Kontroler
{
    private const JAM_RIWAYAT = 24;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
    ) {}

    public function Tampilkan(string $slugTenant, string $token): SymfonyResponse
    {
        return $this->DenganKurir($slugTenant, $token, function (Kurir $kurir, int $idTenant) use ($slugTenant, $token): SymfonyResponse {
            $aktif = [StatusPengirimanPesanan::Dikemas->value, StatusPengirimanPesanan::Dikirim->value];
            $pengiriman = PengirimanPesanan::query()
                ->where('IdKurir', $kurir->Id)
                ->where(fn ($k) => $k->whereIn('Status', $aktif)->orWhere('DiubahPada', '>=', now()->subHours(self::JAM_RIWAYAT)))
                ->orderBy('Id')
                ->limit(100)
                ->get();
            $pesanan = PesananOnline::query()->with('Detail')->whereKey($pengiriman->pluck('IdPesananOnline')->all())->get()->keyBy('Id');

            return Inertia::render('Publik/PortalKurir', [
                'NamaToko' => app(NamaTampilUsaha::class)->UntukTenant($idTenant),
                'UrlLogo' => app(UrlLogoPublik::class)->Ambil($idTenant),
                'NamaKurir' => $kurir->Nama,
                'AlamatDasar' => url("/{$slugTenant}/kurir/{$token}"),
                'Pengiriman' => $pengiriman->map(function (PengirimanPesanan $k) use ($pesanan, $aktif): ?array {
                    $p = $pesanan->get($k->IdPesananOnline);

                    if (! $p instanceof PesananOnline) {
                        return null;
                    }

                    $berjalan = in_array($k->Status->value, $aktif, true);

                    return [
                        'Uuid' => $k->Uuid,
                        'Nomor' => $p->Nomor,
                        'Status' => $k->Status->value,
                        'LabelStatus' => $k->Status->AmbilLabel(),
                        'NamaPelanggan' => $p->NamaPelanggan,
                        'NoHp' => $berjalan ? $p->NoHp : null,
                        'Alamat' => $berjalan ? implode(', ', array_filter([$p->Alamat, $p->Kelurahan, $p->Kecamatan, $p->Kota, $p->KodePos])) : null,
                        'Catatan' => $berjalan ? $p->Catatan : null,
                        'MetodePembayaran' => $p->MetodePembayaran->value,
                        'Total' => $p->Total,
                        'SudahDibayar' => $p->IdPenjualan !== null || $p->DibayarPada !== null,
                        'Baris' => $p->Detail->map(fn (PesananOnlineDetail $d): array => ['NamaProduk' => $d->NamaProduk, 'Jumlah' => $d->Jumlah])->all(),
                        'NamaPenerima' => $k->NamaPenerima,
                        'AdaBukti' => $k->PathBukti !== null,
                        'DiubahPada' => $k->DiubahPada?->toIso8601String(),
                    ];
                })->filter()->values()->all(),
            ])->toResponse(request());
        });
    }

    public function Kirim(string $slugTenant, string $token, string $pengiriman, UbahStatusPengirimanPesanan $ubah): SymfonyResponse
    {
        return $this->DenganPengiriman($slugTenant, $token, $pengiriman, function (Kurir $kurir, PengirimanPesanan $k, PesananOnline $p) use ($ubah): RedirectResponse {
            $ubah->Jalankan($k, $p, StatusPengirimanPesanan::Dikirim, ['IdKurir' => $kurir->Id, 'Alasan' => "Berangkat (kurir {$kurir->Nama})."], null);

            return back()->with('Kilat', "Pesanan {$p->Nomor} ditandai berangkat.");
        });
    }

    public function Terima(Request $request, string $slugTenant, string $token, string $pengiriman, UbahStatusPengirimanPesanan $ubah, PenyimpanBuktiPengiriman $bukti): SymfonyResponse
    {
        $valid = $request->validate([
            'NamaPenerima' => ['required', 'string', 'max:100'],
            'Foto' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('pemenuhan.UkuranMaksimalBuktiKb')],
        ]);

        return $this->DenganPengiriman($slugTenant, $token, $pengiriman, function (Kurir $kurir, PengirimanPesanan $k, PesananOnline $p, int $idTenant) use ($ubah, $bukti, $valid, $request): RedirectResponse {
            $berkas = $request->file('Foto');
            $path = $berkas === null ? null : $bukti->Simpan($idTenant, $berkas);

            try {
                $ubah->Jalankan($k, $p, StatusPengirimanPesanan::Diterima, [
                    'NamaPenerima' => trim((string) $valid['NamaPenerima']),
                    'PathBukti' => $path,
                    'Alasan' => "Diserahkan oleh kurir {$kurir->Nama}.",
                ], null);
            } catch (Throwable $galat) {
                $bukti->Hapus($path);

                throw $galat;
            }

            return back()->with('Kilat', "Pesanan {$p->Nomor} diterima {$valid['NamaPenerima']}.");
        });
    }

    public function Gagal(Request $request, string $slugTenant, string $token, string $pengiriman, UbahStatusPengirimanPesanan $ubah): SymfonyResponse
    {
        $valid = $request->validate(['Alasan' => ['required', 'string', 'min:5', 'max:255']]);

        return $this->DenganPengiriman($slugTenant, $token, $pengiriman, function (Kurir $kurir, PengirimanPesanan $k, PesananOnline $p) use ($ubah, $valid): RedirectResponse {
            $ubah->Jalankan($k, $p, StatusPengirimanPesanan::Gagal, ['Alasan' => trim((string) $valid['Alasan'])." (kurir {$kurir->Nama})"], null);

            return back()->with('Kilat', "Pesanan {$p->Nomor} ditandai gagal dikirim.");
        });
    }

    /** @param Closure(Kurir, PengirimanPesanan, PesananOnline, int): RedirectResponse $kerja */
    private function DenganPengiriman(string $slugTenant, string $token, string $uuid, Closure $kerja): SymfonyResponse
    {
        return $this->DenganKurir($slugTenant, $token, function (Kurir $kurir, int $idTenant) use ($uuid, $kerja): SymfonyResponse {
            $k = PengirimanPesanan::query()->where('Uuid', strtoupper($uuid))->where('IdKurir', $kurir->Id)->first();
            $p = $k === null ? null : PesananOnline::query()->find($k->IdPesananOnline);

            if (! $k instanceof PengirimanPesanan || ! $p instanceof PesananOnline) {
                throw new PelanggaranAturanBisnis('PengirimanTidakDitemukan', 'Pengiriman ini tidak ditugaskan kepada Anda.', 'Umum', 404);
            }

            return $kerja($kurir, $k, $p, $idTenant);
        });
    }

    /** @param Closure(Kurir, int): SymfonyResponse $kerja */
    private function DenganKurir(string $slugTenant, string $token, Closure $kerja): SymfonyResponse
    {
        $idTenant = $this->profil->CariIdDariSlug($slugTenant);
        abort_if($idTenant === null || strlen($token) !== AturTautanPortalKurir::PANJANG_TOKEN, 404);
        $this->konteks->Atur($idTenant);

        try {
            $kurir = Kurir::query()->where('HashTokenPortal', AturTautanPortalKurir::Hash($token))->first();
            abort_if(! $kurir instanceof Kurir || $kurir->Status !== StatusKurir::Aktif, 404);

            return $kerja($kurir, $idTenant);
        } finally {
            $this->konteks->Kosongkan();
        }
    }
}
