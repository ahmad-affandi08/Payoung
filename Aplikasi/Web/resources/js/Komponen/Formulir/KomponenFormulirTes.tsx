import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import BidangPilihan from './BidangPilihan';
import BidangTeksPanjang from './BidangTeksPanjang';
import GrupCentang from './GrupCentang';
import KotakCentang from './KotakCentang';
import Tombol from './Tombol';
import { AmbilNilaiPilihan, BukaPilihan, PilihOpsi } from '@/Pengujian/InteraksiPilihan';

describe('komponen Formulir di atas shadcn/ui (API lama tetap)', () => {
    afterEach(() => cleanup());

    it('Tombol: varian memetakan ke Button shadcn, bawaan type="button", memproses menonaktifkan', () => {
        const SaatKlik = vi.fn();
        const { rerender: RenderUlang } = render(<Tombol onClick={SaatKlik}>Simpan produk</Tombol>);

        const tombol = screen.getByRole('button', { name: 'Simpan produk' });
        expect(tombol.getAttribute('type')).toBe('button');
        expect(tombol.getAttribute('data-variant')).toBe('default');
        expect(tombol.className).toContain('bg-primary');
        fireEvent.click(tombol);
        expect(SaatKlik).toHaveBeenCalledTimes(1);

        RenderUlang(
            <Tombol varian="bahaya" type="submit">
                Hapus
            </Tombol>,
        );
        expect(screen.getByRole('button', { name: 'Hapus' }).getAttribute('data-variant')).toBe('destructive');
        expect(screen.getByRole('button', { name: 'Hapus' }).getAttribute('type')).toBe('submit');

        RenderUlang(
            <Tombol varian="sekunder" memproses>
                Kirim
            </Tombol>,
        );
        const sibuk = screen.getByRole('button', { name: 'Memproses…' });
        expect(sibuk.getAttribute('data-variant')).toBe('outline');
        expect(sibuk.getAttribute('aria-busy')).toBe('true');
        expect((sibuk as HTMLButtonElement).disabled).toBe(true);
    });

    it('KotakCentang: label bisa diklik dan meneruskan boolean', () => {
        function Uji({ saatBerubah }: { saatBerubah: (nilai: boolean) => void }) {
            const [nilai, AturNilai] = useState(false);

            return (
                <KotakCentang
                    label="Ingat saya"
                    nilai={nilai}
                    saatBerubah={(baru) => {
                        AturNilai(baru);
                        saatBerubah(baru);
                    }}
                />
            );
        }
        const SaatBerubah = vi.fn();
        render(<Uji saatBerubah={SaatBerubah} />);

        const kotak = screen.getByRole('checkbox', { name: 'Ingat saya' });
        expect(kotak.getAttribute('aria-checked')).toBe('false');

        fireEvent.click(screen.getByText('Ingat saya'));

        expect(SaatBerubah).toHaveBeenLastCalledWith(true);
        expect(kotak.getAttribute('aria-checked')).toBe('true');
    });

    it('GrupCentang: menambah/menghapus nilai terpilih. Masalah terhubung ke fieldset', () => {
        const SaatBerubah = vi.fn();
        render(
            <GrupCentang
                legenda="Peran"
                opsi={[
                    { nilai: 'kasir', label: 'Kasir' },
                    { nilai: 'admin', label: 'Admin' },
                ]}
                terpilih={['kasir']}
                saatBerubah={SaatBerubah}
                galat="Pilih minimal satu peran."
            />,
        );

        const grup = screen.getByRole('group', { name: 'Peran' });
        expect(grup.getAttribute('aria-describedby')).toBe(screen.getByText('Pilih minimal satu peran.').id);

        fireEvent.click(screen.getByRole('checkbox', { name: 'Admin' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith(['kasir', 'admin']);

        fireEvent.click(screen.getByRole('checkbox', { name: 'Kasir' }));
        expect(SaatBerubah).toHaveBeenLastCalledWith([]);
    });

    it('BidangPilihan: PilihanCari dengan kotak cari, opsi kosong, nilai, dan masalah', () => {
        const SaatBerubah = vi.fn();
        render(
            <BidangPilihan
                label="Zona waktu"
                nilai=""
                kosong="Pilih zona waktu"
                opsi={[
                    { Nilai: 'WIB', Label: 'WIB (UTC+7)' },
                    { Nilai: 'WITA', Label: 'WITA (UTC+8)' },
                    { Nilai: 'WIT', Label: 'WIT (UTC+9)' },
                ]}
                saatBerubah={SaatBerubah}
                galat="Zona waktu wajib dipilih."
            />,
        );

        const pilihan = screen.getByRole('combobox', { name: 'Zona waktu' });
        expect(pilihan.textContent).toContain('Pilih zona waktu');
        expect(pilihan.getAttribute('aria-invalid')).toBe('true');
        expect(pilihan.getAttribute('aria-describedby')).toBe(screen.getByText('Zona waktu wajib dipilih.').id);
        expect(AmbilNilaiPilihan(pilihan)).toEqual(['', 'WIB', 'WITA', 'WIT']);

        // Cari mempersempit daftar; daftar dibuka di bawah pemicu, bukan menimpanya.
        fireEvent.click(pilihan);
        fireEvent.change(screen.getByRole('textbox', { name: 'Cari Zona waktu' }), { target: { value: 'utc+8' } });
        const daftar = BukaPilihan(pilihan);
        expect(
            Array.from(daftar.querySelectorAll('[data-slot="pilihan-cari-item"]')).map((o) => o.textContent),
        ).toEqual(['WITA (UTC+8)']);
        fireEvent.change(screen.getByRole('textbox', { name: 'Cari Zona waktu' }), { target: { value: 'tidak ada' } });
        expect(screen.getByText('Tidak ada yang cocok dengan “tidak ada”.')).toBeTruthy();
        fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' });

        PilihOpsi(pilihan, 'WITA');
        expect(SaatBerubah).toHaveBeenCalledWith('WITA');
    });

    it('BidangTeksPanjang: Textarea dengan baris, batas, keterangan & masalah terhubung', () => {
        render(
            <BidangTeksPanjang
                label="Pesan"
                nilai=""
                saatBerubah={() => undefined}
                keterangan="Jelaskan masalahnya."
                galat="Pesan wajib diisi."
                baris={7}
                maksimal={2000}
            />,
        );

        const area = screen.getByLabelText<HTMLTextAreaElement>('Pesan');
        expect(area.tagName).toBe('TEXTAREA');
        expect(area.rows).toBe(7);
        expect(area.maxLength).toBe(2000);
        const dijelaskan = area.getAttribute('aria-describedby') ?? '';
        expect(dijelaskan).toContain(screen.getByText('Jelaskan masalahnya.').id);
        expect(dijelaskan).toContain(screen.getByText('Pesan wajib diisi.').id);
    });
});
