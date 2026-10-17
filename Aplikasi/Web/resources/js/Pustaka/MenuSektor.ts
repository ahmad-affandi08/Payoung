import { CekSesuaiSektor } from '@/Pustaka/Sektor';

/**
 * Audit kemudahan pakai #33 (D-38, D-48): ringkasan menu yang bergantung pada jenis usaha, supaya Pemilik yang
 * mengganti jenis usaha (D-165) melihat apa yang berubah di menu. Hanya butir yang punya `sektor` atau `kecualiSektor`
 * yang dihitung; butir umum sama untuk semua jenis usaha.
 */
export type ButirMenuBersektor = {
    Label: string;
    Lokasi: 'Menu samping' | 'Pengaturan';
    sektor?: string[] | undefined;
    kecualiSektor?: string[] | undefined;
};

/** Bentuk minimal menu samping (`ItemMenu`/`GrupMenu`) dan butir Pengaturan yang dibaca ringkasan ini. */
type SumberMenu = {
    label: string;
    sektor?: string[] | undefined;
    kecualiSektor?: string[] | undefined;
    gantiBilaSektor?: { sektor: string[]; kecuali: string[]; label: string } | undefined;
    sub?: SumberMenu[] | undefined;
};

export type MenuSektor = { Tampil: ButirMenuBersektor[]; Disembunyikan: ButirMenuBersektor[] };

export type PerubahanMenuSektor = { Muncul: ButirMenuBersektor[]; Hilang: ButirMenuBersektor[] };

function Datarkan(daftar: SumberMenu[]): SumberMenu[] {
    return daftar.flatMap((menu) => [menu, ...Datarkan(menu.sub ?? [])]);
}

/** Kumpulkan butir menu samping dan Pengaturan yang bergantung pada jenis usaha (tanpa duplikat). */
export function KumpulkanButirBersektor(menuSamping: SumberMenu[], pengaturan: SumberMenu[]): ButirMenuBersektor[] {
    const hasil: ButirMenuBersektor[] = [];
    const Tambah = (lokasi: ButirMenuBersektor['Lokasi'], daftar: SumberMenu[]) => {
        for (const menu of Datarkan(daftar)) {
            if (menu.sektor === undefined && menu.kecualiSektor === undefined) {
                continue;
            }

            const butir: ButirMenuBersektor = {
                Label: menu.label,
                Lokasi: lokasi,
                sektor: menu.sektor,
                kecualiSektor: menu.kecualiSektor,
            };

            // "Reservasi" (jasa) menjadi "Reservasi meja" untuk usaha FnB murni (D-145): dua nama, satu butir.
            if (menu.gantiBilaSektor !== undefined) {
                hasil.push({ ...butir, Label: menu.gantiBilaSektor.label, sektor: menu.gantiBilaSektor.sektor });
            }

            if (!hasil.some((ada) => ada.Label === butir.Label && ada.Lokasi === lokasi)) {
                hasil.push(butir);
            }
        }
    };

    Tambah('Menu samping', menuSamping);
    Tambah('Pengaturan', pengaturan);

    return hasil;
}

/**
 * Butir yang tampil dan yang disembunyikan untuk daftar sektor ini. Sektor kosong = jenis usaha belum diketahui,
 * semua tampil (sama dengan sidebar).
 */
export function TentukanMenuSektor(butir: ButirMenuBersektor[], sektorOutlet: string[]): MenuSektor {
    const tampil: ButirMenuBersektor[] = [];
    const disembunyikan: ButirMenuBersektor[] = [];

    for (const satu of butir) {
        (CekSesuaiSektor(satu, sektorOutlet) ? tampil : disembunyikan).push(satu);
    }

    return { Tampil: tampil, Disembunyikan: disembunyikan };
}

/** Selisih menu antara dua daftar sektor: apa yang kini muncul dan apa yang kini hilang. */
export function BandingkanMenuSektor(
    butir: ButirMenuBersektor[],
    sebelum: string[],
    sesudah: string[],
): PerubahanMenuSektor {
    const lama = TentukanMenuSektor(butir, sebelum);
    const baru = TentukanMenuSektor(butir, sesudah);
    const Kunci = (satu: ButirMenuBersektor) => `${satu.Lokasi}|${satu.Label}`;
    const tampilLama = new Set(lama.Tampil.map(Kunci));
    const tampilBaru = new Set(baru.Tampil.map(Kunci));

    return {
        Muncul: baru.Tampil.filter((satu) => !tampilLama.has(Kunci(satu))),
        Hilang: lama.Tampil.filter((satu) => !tampilBaru.has(Kunci(satu))),
    };
}
