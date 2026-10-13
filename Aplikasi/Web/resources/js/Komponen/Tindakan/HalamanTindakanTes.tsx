import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarPerangkat from '@/Halaman/Kelola/Perangkat/Daftar';
import HalamanDokumenLegal from '@/Halaman/Pengelola/Legal/Dokumen';
import HalamanDaftarTim from '@/Halaman/Kelola/Tim/Daftar';
import TampilTenant from '@/Halaman/Pengelola/Tenant/Tampil';
import { BukaMenu, PilihTab } from '@/Pengujian/InteraksiRadix';
import { RenderDenganKueri } from '@/Pengujian/RenderKueri';
import type { Tampilan360 } from '@/Tipe/TenantPengelola';

/*
 * Test perilaku halaman milik migrasi shadcn Tim 4: aksi baris lewat DropdownMenu, konfirmasi tindakan
 * berisiko lewat AlertDialog, dan tampilan 360° tenant ber-Tabs. URL & data yang dikirim tidak berubah.
 */

type Kiriman = { metode: string; url: string; data: unknown };

const uji = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    kiriman: [] as { metode: string; url: string; data: unknown }[],
    opsiTerakhir: null as null | { onSuccess?: () => void },
}));

vi.mock('@inertiajs/react', () => {
    const CatatRouter = (metode: string) => (url: string, data?: unknown, opsi?: { onSuccess?: () => void }) => {
        uji.kiriman.push({ metode, url, data: metode === 'delete' ? null : (data ?? null) });
        uji.opsiTerakhir = (metode === 'delete' ? (data as typeof opsi) : opsi) ?? null;
    };

    function useFormTiruan<T extends object>(awal: T) {
        const [data, AturData] = useState<T>(awal);
        const Kirim = (metode: string) => (url: string, opsi?: { onSuccess?: () => void }) => {
            uji.kiriman.push({ metode, url, data });
            uji.opsiTerakhir = opsi ?? null;
        };

        return {
            data,
            setData: (kunci: keyof T | T, nilai?: unknown) =>
                AturData((lama) => (typeof kunci === 'object' ? kunci : { ...lama, [kunci]: nilai })),
            errors: {},
            processing: false,
            isDirty: false,
            post: Kirim('post'),
            put: Kirim('put'),
            submit: (metode: string, url: string, opsi?: { onSuccess?: () => void }) => Kirim(metode)(url, opsi),
            reset: vi.fn(),
        };
    }

    return {
        Head: () => null,
        Link: ({ href, children, ...sisa }: { href: string; children?: ReactNode }) => (
            <a href={href} {...sisa}>
                {children}
            </a>
        ),
        router: { get: vi.fn(), post: CatatRouter('post'), put: CatatRouter('put'), delete: CatatRouter('delete') },
        usePage: () => ({ props: uji.props, url: '/' }),
        useForm: useFormTiruan,
    };
});

// Tata letak & tab bersama dibangun ulang tim lain; test ini hanya menguji isi halaman.
vi.mock('@/TataLetak/TataLetakAplikasi', () => ({
    default: ({ judul, children }: { judul: string; children: ReactNode }) => (
        <main>
            <h1>{judul}</h1>
            {children}
        </main>
    ),
}));
vi.mock('@/TataLetak/TataLetakPengelola', () => ({
    default: ({ judul, aksi, children }: { judul: string; aksi?: ReactNode; children: ReactNode }) => (
        <main>
            <h1>{judul}</h1>
            {aksi}
            {children}
        </main>
    ),
}));
vi.mock('@/Komponen/Kelola/TabPengguna', () => ({ default: () => null }));

function AturPropsAplikasi(izin: string[], pemilik = false): void {
    uji.props = {
        NamaAplikasi: 'Kasir',
        Kilat: null,
        Pengguna: { Uuid: 'U-SAYA', Nama: 'Rina Wulandari', Email: 'rina@kopinusantara.id', EmailTerverifikasi: true },
        TenantAktif: {
            Nama: 'Kopi Nusantara',
            StatusLangganan: 'Aktif',
            PeriodeSelesai: null,
            BatasTenggangPada: null,
        },
        PengumumanLegal: [],
        Akses: { Pemilik: pemilik, Izin: izin },
        errors: {},
    };
}

function AturPropsPengelola(izin: string[]): void {
    uji.props = {
        NamaAplikasi: 'Pengelola',
        Lingkungan: 'Lokal',
        Kilat: null,
        Pengguna: { Uuid: 'P-1', Nama: 'Dewi', Email: 'dewi@contoh.id', KodePeran: ['Operasional'], Izin: izin },
        PeringatanSuperAdmin: false,
        PeringatanIntegrasi: [],
        PeringatanOperasional: [],
        errors: {},
    };
}

function AmbilKiriman(): Kiriman[] {
    return uji.kiriman;
}

beforeEach(() => {
    uji.kiriman.length = 0;
    uji.opsiTerakhir = null;
});
afterEach(() => cleanup());

const batas = { Terpakai: 2, Batas: 5 };

describe('Kelola/Tim: aksi baris & konfirmasi nonaktifkan (F-02 BR-02.1, D-101)', () => {
    const izin = {
        Tambah: true,
        Undang: true,
        UbahAkses: true,
        Nonaktifkan: true,
        AturPin: true,
        KelolaKaryawan: false,
    };
    const BuatAkun = (ubah: Record<string, unknown>) => ({
        Kunci: 'U-2',
        Nama: 'Budi Santoso',
        Jabatan: null,
        Email: 'budi@kopinusantara.id',
        Pemilik: false,
        UuidPengguna: 'U-2',
        StatusAkun: 'Aktif' as const,
        UuidPeran: 'R-K',
        NamaPeran: 'Kasir',
        SemuaOutlet: false,
        UuidOutlet: ['O-1'],
        Karyawan: null,
        Status: 'Aktif' as const,
        ...ubah,
    });
    const tim = [
        BuatAkun({
            Kunci: 'U-SAYA',
            Nama: 'Rina Wulandari',
            Email: 'rina@kopinusantara.id',
            Pemilik: true,
            UuidPengguna: 'U-SAYA',
            UuidPeran: 'R-P',
            NamaPeran: 'Pemilik',
            SemuaOutlet: true,
            UuidOutlet: [],
        }),
        BuatAkun({}),
    ];
    const props = {
        Tim: tim,
        Undangan: [],
        Peran: [{ Uuid: 'R-K', Nama: 'Kasir', Pemilik: false, Cakupan: 'Terpilih' as const }],
        Outlet: [{ Uuid: 'O-1', Kode: 'JKT1', Nama: 'Kopi Nusantara Sudirman' }],
        OpsiOutlet: [{ Uuid: 'O-1', Nama: 'Kopi Nusantara Sudirman' }],
        OpsiPengguna: [],
        BatasPengguna: batas,
        UuidSaya: 'U-SAYA',
        Izin: izin,
    };

    it('akun sendiri tanpa menu aksi; nonaktifkan lewat AlertDialog lalu POST ke /kelola/tim/status', () => {
        AturPropsAplikasi([], true);
        RenderDenganKueri(<HalamanDaftarTim {...props} />);

        expect(screen.queryByRole('button', { name: 'Aksi untuk Rina Wulandari' })).toBeNull();
        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk Budi Santoso' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Nonaktifkan' }));

        const dialog = screen.getByRole('alertdialog', { name: 'Nonaktifkan Budi Santoso?' });
        expect(AmbilKiriman()).toEqual([]);
        fireEvent.click(within(dialog).getByRole('button', { name: 'Nonaktifkan' }));
        expect(AmbilKiriman()).toEqual([
            {
                metode: 'post',
                url: '/kelola/tim/status',
                data: { Aksi: 'Nonaktifkan', UuidPengguna: 'U-2', UuidKaryawan: null },
            },
        ]);

        // Dialog tertutup setelah berhasil.
        act(() => uji.opsiTerakhir?.onSuccess?.());
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('ubah peran & outlet membuka panel samping dan mengirim PUT dengan nama field yang sama', () => {
        AturPropsAplikasi([], true);
        RenderDenganKueri(<HalamanDaftarTim {...props} />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk Budi Santoso' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Ubah peran & outlet' }));
        const panel = screen.getByRole('dialog', { name: 'Peran & outlet Budi Santoso' });
        fireEvent.click(within(panel).getByRole('button', { name: 'Simpan akses' }));

        expect(AmbilKiriman()).toEqual([
            {
                metode: 'put',
                url: '/kelola/pengguna/U-2/akses',
                data: { Email: 'budi@kopinusantara.id', Peran: 'R-K', SemuaOutlet: false, Outlet: ['O-1'] },
            },
        ]);
    });

    it('atur ulang PIN kasir langsung dari menu baris: dialog 6 angka lalu PUT ke /pengguna/{uuid}/pin', () => {
        AturPropsAplikasi([], true);
        RenderDenganKueri(<HalamanDaftarTim {...props} />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk Budi Santoso' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Atur ulang PIN' }));
        const dialog = screen.getByRole('dialog', { name: 'Atur ulang PIN Budi Santoso' });
        fireEvent.change(within(dialog).getByLabelText(/PIN baru/, { selector: 'input' }), {
            target: { value: '482915' },
        });
        fireEvent.change(within(dialog).getByLabelText(/Ulangi PIN/, { selector: 'input' }), {
            target: { value: '482915' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan PIN baru' }));

        expect(AmbilKiriman()).toEqual([
            { metode: 'put', url: '/kelola/pengguna/U-2/pin', data: { Pin: '482915', KonfirmasiPin: '482915' } },
        ]);
    });

    it('tanpa izin atur PIN, menu baris tidak menawarkan "Atur ulang PIN"', () => {
        AturPropsAplikasi(['pengguna.ubah'], false);
        RenderDenganKueri(<HalamanDaftarTim {...props} Izin={{ ...izin, AturPin: false }} />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk Budi Santoso' }));
        expect(screen.getByRole('menuitem', { name: 'Ubah peran & outlet' })).toBeTruthy();
        expect(screen.queryByRole('menuitem', { name: 'Atur ulang PIN' })).toBeNull();
    });

    it('D-46: "Catat sebagai karyawan" hanya untuk akun tanpa karyawan dan hanya bagi pemegang karyawan.kelola', () => {
        AturPropsAplikasi([], true);
        const karyawan = {
            Uuid: 'K-3',
            Nama: 'Sari Dewi',
            Jabatan: null,
            LevelStaf: null,
            GajiPokok: null,
            TarifLemburPerJam: null,
            PotonganTerlambatPerMenit: null,
            PotonganTidakMasukPerHari: null,
            UuidPengguna: 'U-3',
            NamaPengguna: 'Sari Dewi',
            UuidOutlet: null,
            NamaOutlet: null,
            Status: 'Aktif' as const,
            LabelStatus: 'Aktif',
            TautanAbsen: false,
            StatusWajah: null,
        };
        const dengan = [...tim, BuatAkun({ Kunci: 'U-3', Nama: 'Sari Dewi', UuidPengguna: 'U-3', Karyawan: karyawan })];
        RenderDenganKueri(<HalamanDaftarTim {...props} Tim={dengan} Izin={{ ...izin, KelolaKaryawan: true }} />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk Sari Dewi' }));
        expect(screen.queryByRole('menuitem', { name: 'Catat sebagai karyawan' })).toBeNull();
        expect(screen.getByRole('menuitem', { name: 'Ubah data & gaji' })).toBeTruthy();
    });

    it('D-46: "Catat sebagai karyawan" mengirim POST ke URL akun', () => {
        AturPropsAplikasi([], true);
        RenderDenganKueri(<HalamanDaftarTim {...props} Izin={{ ...izin, KelolaKaryawan: true }} />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk Budi Santoso' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Catat sebagai karyawan' }));
        expect(AmbilKiriman()).toEqual([{ metode: 'post', url: '/kelola/pengguna/U-2/karyawan', data: {} }]);
    });

    it('tanpa izin ubah/nonaktifkan: tidak ada menu aksi, tetapi tombol tambah tetap ada untuk pemegang izin undang', () => {
        AturPropsAplikasi(['pengguna.undang']);
        RenderDenganKueri(
            <HalamanDaftarTim
                {...props}
                Izin={{
                    Tambah: true,
                    Undang: true,
                    UbahAkses: false,
                    Nonaktifkan: false,
                    AturPin: false,
                    KelolaKaryawan: false,
                }}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Aksi untuk Budi Santoso' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Tambah anggota tim' }).getAttribute('href')).toBe('/kelola/tim/buat');
    });
});

describe('Kelola/Perangkat: cabut lewat AlertDialog (F-02 langkah 5, BR-02.3)', () => {
    const perangkat = {
        Uuid: 'D-1',
        Kode: 'KSR01',
        Nama: 'Kasir Depan',
        Jenis: 'Kasir',
        LabelJenis: 'Kasir',
        UuidOutlet: 'O-1',
        NamaOutlet: 'Sudirman',
        Status: 'Aktif' as const,
        Platform: 'Android',
        VersiAplikasi: '1.2.0',
        DiaktifkanPada: '2026-09-01T02:00:00Z',
        TerakhirAktifPada: '2026-09-20T02:00:00Z',
        DicabutPada: null,
    };
    const props = {
        Perangkat: [
            perangkat,
            { ...perangkat, Uuid: 'D-2', Kode: 'KSR02', Nama: 'Kasir Lama', Status: 'Dicabut' as const },
        ],
        Outlet: [{ Uuid: 'O-1', Kode: 'JKT1', Nama: 'Sudirman', BatasPerangkat: batas }],
        JenisPerangkat: [{ Nilai: 'Kasir', Label: 'Kasir' }],
        KodeAktivasiBaru: null,
    };

    it('Batal tidak mengirim apa pun; Cabut perangkat mengirim POST cabut', () => {
        AturPropsAplikasi(['perangkat.kelola']);
        RenderDenganKueri(<HalamanDaftarPerangkat {...props} />);

        // Perangkat yang sudah dicabut tidak punya aksi.
        expect(screen.queryByRole('button', { name: 'Aksi perangkat Kasir Lama (KSR02)' })).toBeNull();

        BukaMenu(screen.getByRole('button', { name: 'Aksi perangkat Kasir Depan (KSR01)' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Cabut' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog', { name: 'Cabut Kasir Depan (KSR01)?' })).getByRole('button', {
                name: 'Batal',
            }),
        );
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(AmbilKiriman()).toEqual([]);

        BukaMenu(screen.getByRole('button', { name: 'Aksi perangkat Kasir Depan (KSR01)' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Cabut' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cabut perangkat' }));
        expect(AmbilKiriman()).toEqual([{ metode: 'post', url: '/kelola/perangkat/D-1/cabut', data: {} }]);
    });

    it('menu aksi perangkat aktif menawarkan pindah HP; tambah perangkat lewat dialog', () => {
        AturPropsAplikasi(['perangkat.kelola']);
        RenderDenganKueri(<HalamanDaftarPerangkat {...props} />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi perangkat Kasir Depan (KSR01)' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Pindahkan ke HP lain' }));
        expect(AmbilKiriman()).toEqual([{ metode: 'post', url: '/kelola/perangkat/D-1/kode-aktivasi', data: {} }]);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah perangkat' }));
        const dialog = screen.getByRole('dialog', { name: 'Tambah perangkat' });
        fireEvent.change(within(dialog).getByLabelText('Nama perangkat'), { target: { value: 'Tablet Dapur' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tambah & buat kode aktivasi' }));
        expect(AmbilKiriman()[1]).toEqual({
            metode: 'post',
            url: '/kelola/perangkat',
            data: { Nama: 'Tablet Dapur', Outlet: 'O-1', Jenis: 'Kasir' },
        });
    });

    it('kosong: pesan ajakan menambah perangkat', () => {
        AturPropsAplikasi([]);
        RenderDenganKueri(<HalamanDaftarPerangkat {...props} Perangkat={[]} />);

        expect(
            screen.getByText('Belum ada perangkat. Tambahkan perangkat kasir pertama untuk mulai berjualan.'),
        ).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Tambah perangkat' })).toBeNull();
    });
});

function BuatTenant(): Tampilan360 {
    return {
        AksesDukungan: { Aktif: null, Izin: [], Sesi: [], Alat: [] },
        MitraPerujuk: null,
        Kesehatan: { Terbaru: null, Riwayat: [], Tugas: [] },
        // Peran tanpa izin tagihan/tiket: tab disembunyikan; perangkat kosong.
        Tagihan: null,
        Tiket: null,
        Perangkat: { Data: [], JumlahAktif: 0, JumlahOffline: 0, TotalOutboxTertunda: 0 },
        PendaftaranMerchant: { Pendaftaran: null, PartnerAktif: false },
        Profil: {
            Uuid: 'T-1',
            Nama: 'Kopi Nusantara',
            Slug: 'kopi-nusantara',
            Npwp: null,
            Pkp: false,
            ZonaWaktu: 'Asia/Jakarta',
            Status: 'Aktif',
            Penanda: null,
            DibuatPada: '2026-08-01T02:00:00Z',
            TemplateSektor: ['Kafe'],
        },
        Langganan: {
            Status: 'Aktif',
            StatusSebelumDitangguhkan: null,
            StatusSetelahDiaktifkan: null,
            BisaDiaktifkan: false,
            KodePaket: 'TMB',
            NamaPaket: 'Tumbuh',
            TrialBerakhirPada: null,
            PeriodeMulai: '2026-09-01T00:00:00Z',
            PeriodeSelesai: '2026-10-01T00:00:00Z',
            SiklusTagihan: 'Bulanan',
            PerpanjanganTrial: 0,
            SisaPerpanjanganTrial: 2,
        },
        Addon: [],
        Pemakaian: [{ Label: 'Outlet', Pakai: 4, Batas: 3 }],
        Organisasi: { Outlet: [], JumlahGudang: 0, JumlahMerek: 1 },
        Anggota: [],
        PersetujuanLegal: [],
        Override: [
            {
                Uuid: 'OV-1',
                Jenis: 'Batas',
                Kunci: 'BatasOutlet',
                Nilai: '5',
                BerakhirPada: '2026-10-01T00:00:00Z',
                Aktif: true,
                Alasan: 'Pembukaan cabang ke-3',
                DibuatOleh: 'Dewi',
                DibuatPada: '2026-09-10T02:00:00Z',
            },
        ],
        Catatan: [],
        PenghapusanData: [],
        Riwayat: [],
    };
}

describe('Pengelola/Tenant 360°: Tabs & tindakan berisiko (P-07, BR-P07.3)', () => {
    const pilihan = {
        KategoriPenangguhan: [{ Nilai: 'Penipuan', Label: 'Dugaan penipuan' }],
        Penanda: [{ Nilai: 'Uji', Label: 'Uji' }],
        KolomBatas: ['BatasOutlet'],
        Fitur: [],
        PaketNegosiasi: [{ Nilai: 'ENTERPRISE', Label: 'Enterprise' }],
    };
    const aturan = { MaksHariTrial: 14, MaksKaliTrial: 2, MaksHariOverride: 30 };

    it('ringkasan tampil di tab pertama; tab override memuat tabel override', () => {
        AturPropsPengelola(['tenant.lihat']);
        RenderDenganKueri(<TampilTenant Tenant={BuatTenant()} Pilihan={pilihan} Aturan={aturan} />);

        expect(screen.getByRole('tab', { name: 'Ringkasan', selected: true })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Profil usaha' })).toBeTruthy();
        expect(screen.getByText('Melebihi batas')).toBeTruthy();
        expect(screen.queryByRole('table', { name: 'Override tenant, terbaru di atas' })).toBeNull();

        PilihTab(screen.getByRole('tab', { name: 'Override & trial (1)' }));
        expect(screen.getByRole('table', { name: 'Override tenant, terbaru di atas' })).toBeTruthy();
        // Tanpa izin override: tidak ada tombol cabut.
        expect(screen.queryByRole('button', { name: 'Cabut' })).toBeNull();
    });

    it('tangguhkan memakai AlertDialog dan mengirim POST dengan field yang sama', () => {
        AturPropsPengelola(['tenant.lihat', 'tenant.tangguhkan']);
        RenderDenganKueri(<TampilTenant Tenant={BuatTenant()} Pilihan={pilihan} Aturan={aturan} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tangguhkan' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Tangguhkan tenant' });
        fireEvent.change(within(dialog).getByLabelText('Catatan internal'), {
            target: { value: 'Surat permintaan hukum 12/IX' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tangguhkan tenant' }));

        expect(AmbilKiriman()).toEqual([
            {
                metode: 'post',
                url: '/tenant/T-1/tangguhkan',
                data: { Kategori: 'Penipuan', Catatan: 'Surat permintaan hukum 12/IX' },
            },
        ]);
        act(() => uji.opsiTerakhir?.onSuccess?.());
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('D-112: tagihan manual untuk paket harga negosiasi hanya bagi pemegang izin tagihan.terbitkan', () => {
        AturPropsPengelola(['tenant.lihat']);
        const { unmount: Lepas } = RenderDenganKueri(
            <TampilTenant Tenant={BuatTenant()} Pilihan={pilihan} Aturan={aturan} />,
        );
        expect(screen.queryByRole('button', { name: 'Terbitkan tagihan manual' })).toBeNull();
        Lepas();

        AturPropsPengelola(['tenant.lihat', 'tagihan.terbitkan']);
        RenderDenganKueri(<TampilTenant Tenant={BuatTenant()} Pilihan={pilihan} Aturan={aturan} />);
        fireEvent.click(screen.getByRole('button', { name: 'Terbitkan tagihan manual' }));
        const dialog = screen.getByRole('dialog', { name: 'Terbitkan tagihan manual' });
        fireEvent.change(within(dialog).getByLabelText(/Harga kesepakatan/), { target: { value: '12000000' } });
        fireEvent.change(within(dialog).getByLabelText(/Keterangan kontrak/), {
            target: { value: 'Kontrak Enterprise 12 bulan' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Terbitkan tagihan' }));

        expect(AmbilKiriman()).toEqual([
            {
                metode: 'post',
                url: '/tenant/T-1/tagihan-manual',
                data: {
                    KodePaket: 'ENTERPRISE',
                    Siklus: 'Tahunan',
                    Subtotal: '12000000',
                    Keterangan: 'Kontrak Enterprise 12 bulan',
                },
            },
        ]);
    });

    it('cabut override dari tab override memakai AlertDialog', () => {
        AturPropsPengelola(['tenant.lihat', 'tenant.override.kelola']);
        RenderDenganKueri(<TampilTenant Tenant={BuatTenant()} Pilihan={pilihan} Aturan={aturan} />);

        PilihTab(screen.getByRole('tab', { name: 'Override & trial (1)' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cabut' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Cabut override BatasOutlet' });
        fireEvent.change(within(dialog).getByLabelText('Alasan'), { target: { value: 'Sudah upgrade paket' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cabut override' }));

        expect(AmbilKiriman()).toEqual([
            { metode: 'post', url: '/tenant/T-1/override/OV-1/cabut', data: { Alasan: 'Sudah upgrade paket' } },
        ]);
    });
});

describe('Pengelola/Legal: hapus & terbitkan draf lewat AlertDialog, bukan window.confirm (P-06)', () => {
    const dokumen = {
        Uuid: 'L-1',
        Jenis: 'SyaratLayanan',
        Label: 'Syarat Layanan',
        Versi: 2,
        Judul: 'Syarat Layanan',
        Isi: '# Syarat',
        RingkasanPerubahan: null,
        Materiil: false,
        BerlakuMulai: '2026-10-01',
        Status: 'Draf' as const,
        DiterbitkanPada: null,
    };

    it('hapus draf: konfirmasi dulu, lalu DELETE ke URL dokumen', () => {
        const KonfirmasiBawaan = vi.spyOn(window, 'confirm');
        AturPropsPengelola(['legal.kelola']);
        render(<HalamanDokumenLegal Dokumen={dokumen} />);

        fireEvent.click(screen.getByRole('button', { name: 'Hapus draf' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Hapus draf Syarat Layanan versi 2?' });
        expect(AmbilKiriman()).toEqual([]);
        fireEvent.click(within(dialog).getByRole('button', { name: 'Hapus draf' }));

        expect(AmbilKiriman()).toEqual([{ metode: 'delete', url: '/legal/L-1', data: null }]);
        expect(KonfirmasiBawaan).not.toHaveBeenCalled();
        KonfirmasiBawaan.mockRestore();
    });

    it('terbitkan: konfirmasi dulu, lalu POST terbitkan', () => {
        AturPropsPengelola(['legal.kelola']);
        render(<HalamanDokumenLegal Dokumen={dokumen} />);

        fireEvent.click(screen.getByRole('button', { name: 'Terbitkan' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Terbitkan Syarat Layanan versi 2?' });
        expect(within(dialog).getByText('Versi terbit tidak bisa diubah lagi.')).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Terbitkan' }));

        expect(AmbilKiriman()).toEqual([{ metode: 'post', url: '/legal/L-1/terbitkan', data: {} }]);
    });
});
