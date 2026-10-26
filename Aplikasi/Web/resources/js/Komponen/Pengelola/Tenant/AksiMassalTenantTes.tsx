import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AturHalamanUji, RenderUji, TambahPropsHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import AksiMassalTenant, { FrasaHapusPermanen, MaksTenantMassal } from '@/Komponen/Pengelola/Tenant/AksiMassalTenant';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const KATEGORI = [
    { Nilai: 'Penipuan', Label: 'Dugaan penipuan' },
    { Nilai: 'Lainnya', Label: 'Lainnya' },
];

function Konteks<T>(terpilih: T[]) {
    return {
        terpilih,
        semuaHasil: false,
        total: terpilih.length,
        keadaan: { cari: '', urut: [], halaman: 1, perHalaman: 25, saring: {} },
        bersihkan: vi.fn(),
    };
}

function Terpilih(jumlah: number) {
    return Array.from({ length: jumlah }, (_, i) => ({ Uuid: `01J9TNT${String(i).padStart(19, '0')}` }));
}

function Masuk(izin: string[]) {
    AturHalamanUji();
    TambahPropsHalamanUji({
        Pengguna: { Uuid: '01J', Nama: 'Dewi', Email: 'dewi@contoh.id', KodePeran: [], Izin: izin },
    });
}

describe('Aksi massal tenant (D-208)', () => {
    beforeEach(() => {
        tiruanRouter.put.mockClear();
        tiruanRouter.delete.mockClear();
    });
    afterEach(() => cleanup());

    it('hanya aksi yang diizinkan yang tampil', () => {
        Masuk(['tenant.catatan.tulis']);
        RenderUji(<AksiMassalTenant konteks={Konteks(Terpilih(2))} pilihanKategori={KATEGORI} />);

        expect(screen.getByRole('button', { name: 'Beri catatan…' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Tangguhkan…' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Perpanjang uji coba…' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Aktifkan kembali…' })).toBeNull();
    });

    it('perpanjang uji coba mengirim hari dan alasan setelah alasan cukup panjang', () => {
        Masuk(['tenant.trial.perpanjang']);
        const terpilih = Terpilih(2);
        RenderUji(<AksiMassalTenant konteks={Konteks(terpilih)} pilihanKategori={KATEGORI} />);

        fireEvent.click(screen.getByRole('button', { name: 'Perpanjang uji coba…' }));
        const kirim = screen.getByRole('button', { name: 'Perpanjang uji coba' }) as HTMLButtonElement;
        expect(kirim.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'Menunggu printer struk tiba.' } });
        fireEvent.click(kirim);

        expect(tiruanRouter.put).toHaveBeenLastCalledWith(
            '/tenant/massal/trial',
            { Uuid: terpilih.map((t) => t.Uuid), Hari: 7, Alasan: 'Menunggu printer struk tiba.' },
            expect.anything(),
        );
    });

    it('tangguhkan mengirim kategori bawaan dan catatan internal', () => {
        Masuk(['tenant.tangguhkan']);
        const terpilih = Terpilih(3);
        RenderUji(<AksiMassalTenant konteks={Konteks(terpilih)} pilihanKategori={KATEGORI} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tangguhkan…' }));
        fireEvent.change(screen.getByLabelText(/Catatan internal/), {
            target: { value: 'Pola pendaftaran massal dari satu perangkat.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Tangguhkan tenant' }));

        expect(tiruanRouter.put).toHaveBeenLastCalledWith(
            '/tenant/massal/tangguhkan',
            {
                Uuid: terpilih.map((t) => t.Uuid),
                Kategori: 'Penipuan',
                Catatan: 'Pola pendaftaran massal dari satu perangkat.',
            },
            expect.anything(),
        );
    });

    it('Kirim pesan membuka formulir siaran dengan tenant terpilih (hanya bagi yang berizin komunikasi)', () => {
        Masuk(['komunikasi.kelola']);
        const terpilih = Terpilih(2);
        RenderUji(<AksiMassalTenant konteks={Konteks(terpilih)} pilihanKategori={KATEGORI} />);

        fireEvent.click(screen.getByRole('button', { name: 'Kirim pesan…' }));

        expect(tiruanRouter.get).toHaveBeenCalledWith('/komunikasi/buat', { tenant: terpilih.map((t) => t.Uuid) });
    });

    it('hapus permanen (D-213) hanya bagi yang berizin, dan baru terkirim setelah alasan cukup dan frasa persis diketik', () => {
        Masuk(['tenant.catatan.tulis']);
        RenderUji(<AksiMassalTenant konteks={Konteks(Terpilih(2))} pilihanKategori={KATEGORI} />);
        expect(screen.queryByRole('button', { name: 'Hapus permanen…' })).toBeNull();
        cleanup();

        Masuk(['tenant.hapus-permanen']);
        const terpilih = Terpilih(2);
        RenderUji(<AksiMassalTenant konteks={Konteks(terpilih)} pilihanKategori={KATEGORI} />);

        fireEvent.click(screen.getByRole('button', { name: 'Hapus permanen…' }));
        const kirim = screen.getByRole('button', { name: 'Hapus permanen' }) as HTMLButtonElement;
        expect(kirim.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Alasan/), { target: { value: 'Membersihkan tenant percobaan lama.' } });
        fireEvent.change(screen.getByLabelText(/Ketik HAPUS PERMANEN/), { target: { value: 'hapus permanen' } });
        expect(kirim.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/Ketik HAPUS PERMANEN/), { target: { value: FrasaHapusPermanen } });
        expect(kirim.disabled).toBe(false);
        fireEvent.click(kirim);

        expect(tiruanRouter.delete).toHaveBeenLastCalledWith(
            '/tenant/massal/hapus-permanen',
            expect.objectContaining({
                data: {
                    Uuid: terpilih.map((t) => t.Uuid),
                    Alasan: 'Membersihkan tenant percobaan lama.',
                    Frasa: 'HAPUS PERMANEN',
                },
            }),
        );
    });

    it('melebihi batas menonaktifkan semua aksi dan menampilkan peringatan', () => {
        Masuk(['tenant.catatan.tulis', 'tenant.tangguhkan']);
        RenderUji(<AksiMassalTenant konteks={Konteks(Terpilih(MaksTenantMassal + 1))} pilihanKategori={KATEGORI} />);

        expect(screen.getByText(new RegExp(`Maksimal ${MaksTenantMassal} tenant`))).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Beri catatan…' }) as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByRole('button', { name: 'Tangguhkan…' }) as HTMLButtonElement).disabled).toBe(true);
    });
});
