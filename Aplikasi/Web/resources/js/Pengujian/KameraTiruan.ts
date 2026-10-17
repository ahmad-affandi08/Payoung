import type { Mock } from 'vitest';
import { vi } from 'vitest';

/** Pegangan tiruan mesin kamera (`vi.hoisted`) yang dipasang tes lewat `vi.mock('@/Pustaka/PemindaiKamera', …)`. */
export type TiruanMesinKamera = { mulai: Mock; daftar: Mock };

type OpsiMulai = { idPerangkat?: string | null; saatTerbaca: (barcode: string) => void };

/**
 * Kamera tiruan untuk tes komponen: `terbaca(...)` meniru barcode yang masuk ke pemindai yang terakhir dibuka;
 * `Berhenti` mencatat kamera dimatikan. Dua kamera (belakang & depan) tersedia untuk uji ganti kamera.
 */
export function SiapkanKameraTiruan(tiruan: TiruanMesinKamera) {
    const Berhenti = vi.fn();
    let opsi: OpsiMulai | null = null;

    tiruan.mulai.mockImplementation((o: OpsiMulai) => {
        opsi = o;

        return Promise.resolve({ Berhenti, IdPerangkat: o.idPerangkat ?? 'KAM-1' });
    });
    tiruan.daftar.mockResolvedValue([
        { deviceId: 'KAM-1', kind: 'videoinput', label: 'Belakang' },
        { deviceId: 'KAM-2', kind: 'videoinput', label: 'Depan' },
    ]);

    return { Berhenti, terbaca: (barcode: string) => opsi?.saatTerbaca(barcode), opsiTerakhir: () => opsi };
}
