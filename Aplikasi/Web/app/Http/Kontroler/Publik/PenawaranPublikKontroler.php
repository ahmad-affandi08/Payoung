<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Penjualan\Aksi\CatatPenawaranDilihat;
use App\Domain\Penjualan\Aksi\PutuskanPenawaran;
use App\Domain\Penjualan\Enum\KeputusanPenawaran;
use App\Domain\Penjualan\Kueri\PenawaranPublik;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Halaman penawaran publik `/p/{kodePenawaran}` (F-21 bagian 3, D-168), tanpa login. Tenant diambil dari kode lalu dipasang
 * sebagai konteks (seperti halaman invoice publik); rahasia 32 karakter di kode menjadi satu-satunya kunci. Kode yang salah,
 * tenant yang ditutup, dan draf semuanya dijawab 404 yang sama. Pelanggan menerima atau menolak lewat `Putuskan`
 * (`throttle:10,1`, nama penyetuju wajib). Pembukaan oleh staf yang sedang masuk (pratinjau) tidak dihitung "dilihat".
 */
final class PenawaranPublikKontroler extends Kontroler
{
    public function Tampilkan(string $kodePenawaran, Request $permintaan, KonteksTenant $konteks, ProfilTenant $profil, PenawaranPublik $kueri, CatatPenawaranDilihat $catat): SymfonyResponse
    {
        $kode = KodeInvoicePublik::Urai($kodePenawaran);
        $dokumen = null;

        if ($kode !== null && $profil->CekAda($kode['IdTenant'])) {
            $konteks->Atur($kode['IdTenant']);

            try {
                $penawaran = $kueri->Cari($kode['KodePublik']);

                if ($penawaran !== null) {
                    $dokumen = $kueri->Ambil($penawaran);

                    if ($permintaan->user() === null) {
                        $catat->Jalankan($penawaran);
                    }
                }
            } finally {
                $konteks->Kosongkan();
            }
        }

        return Inertia::render('Publik/PenawaranDigital', ['Dokumen' => $dokumen, 'KodePenawaran' => $dokumen === null ? null : $kodePenawaran])
            ->toResponse($permintaan)
            ->setStatusCode($dokumen === null ? 404 : 200);
    }

    public function Putuskan(string $kodePenawaran, Request $permintaan, KonteksTenant $konteks, ProfilTenant $profil, PenawaranPublik $kueri, PutuskanPenawaran $putuskan, PencatatAudit $audit): RedirectResponse
    {
        // Pelaku publik tidak punya akun: audit mencatat pelaku kosong beserta IP/agen untuk menelusuri penyalahgunaan.
        $audit->AturKonteks(null, $permintaan->ip(), $permintaan->userAgent());
        $valid = $permintaan->validate([
            'Keputusan' => ['required', 'string', Rule::in(array_map(fn (KeputusanPenawaran $k): string => $k->value, KeputusanPenawaran::cases()))],
            'Nama' => ['required', 'string', 'min:2', 'max:100'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ], attributes: ['Nama' => 'nama Anda', 'Catatan' => 'catatan']);
        $kode = KodeInvoicePublik::Urai($kodePenawaran);
        abort_if($kode === null || ! $profil->CekAda($kode['IdTenant']), 404);
        $konteks->Atur($kode['IdTenant']);

        try {
            $penawaran = $kueri->Cari($kode['KodePublik']);
            abort_if($penawaran === null, 404);
            $keputusan = KeputusanPenawaran::from((string) $valid['Keputusan']);

            try {
                $putuskan->Jalankan($penawaran->Id, $keputusan, Penawaran::SUMBER_PELANGGAN, (string) $valid['Nama'], is_string($valid['Catatan'] ?? null) ? $valid['Catatan'] : null, null);
            } catch (PelanggaranAturanBisnis $galat) {
                return back()->withErrors(['Umum' => $galat->getMessage()]);
            }
        } finally {
            $konteks->Kosongkan();
        }

        return back()->with('Kilat', $keputusan === KeputusanPenawaran::Terima ? 'Terima kasih, penawaran diterima. Penjual akan menghubungi Anda.' : 'Penawaran ditolak. Terima kasih atas tanggapan Anda.');
    }
}
