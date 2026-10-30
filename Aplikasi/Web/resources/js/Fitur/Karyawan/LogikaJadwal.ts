import type { PilihanCepatJam } from '@/Komponen/Tanggal/PemilihJam';
import type { BarisJadwal, ShiftKerja } from '@/Tipe/Karyawan';

/** Nama hari, urut Senin (indeks 0) sampai Minggu (indeks 6). */
export const NamaHari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

/** Satu baris jam di sel jadwal. `UuidShift` terisi bila jamnya berasal dari shift bernama dan belum diubah tangan. */
export type BarisIsian = { JamMulai: string; JamSelesai: string; UuidShift: string | null };

/** Isian jadwal: UUID karyawan → tanggal → daftar baris jam (selalu minimal satu baris, boleh kosong). */
export type Isian = Record<string, Record<string, BarisIsian[]>>;

export type SelBerubah = {
    UuidKaryawan: string;
    Tanggal: string;
    Shift: { JamMulai: string; JamSelesai: string; UuidShift: string | null }[];
};

export const BarisKosong = (): BarisIsian => ({ JamMulai: '', JamSelesai: '', UuidShift: null });

/** Tanggal `YYYY-MM-DD` digeser [hari] (UTC, tanpa zona). */
export function GeserTanggal(tanggal: string, hari: number): string {
    const d = new Date(`${tanggal}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + hari);

    return d.toISOString().slice(0, 10);
}

/** `08:00` valid. Kosong dinilai tidak valid, jadi pemanggil memeriksa kosong lebih dulu bila boleh. */
export function CekJam(jam: string): boolean {
    return /^([01]\d|2[0-3]):[0-5]\d$/.test(jam);
}

/** Rapikan ketikan jam: `800` → `08:00`, `0830` → `08:30`, lainnya apa adanya. */
export function RapikanJam(teks: string): string {
    const angka = teks.replace(/\D/g, '');

    if (angka.length === 3) {
        return `0${angka[0]}:${angka.slice(1)}`;
    }

    return angka.length === 4 ? `${angka.slice(0, 2)}:${angka.slice(2)}` : teks.trim();
}

/** `07 Sep` dari `2026-09-07`, untuk ringkasan tanggal yang singkat. */
export function FormatTanggalPendek(tanggal: string): string {
    const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    return `${Number(tanggal.slice(8, 10))} ${bulan[Number(tanggal.slice(5, 7)) - 1] ?? ''}`;
}

/** Nama hari dari tanggal `YYYY-MM-DD`. */
export function NamaHariDariTanggal(tanggal: string): string {
    const d = new Date(`${tanggal}T00:00:00Z`).getUTCDay();

    return NamaHari[(d + 6) % 7] ?? '';
}

/**
 * Pilihan cepat jam di panel: jam yang paling sering dipakai di jadwal yang sedang tampil, dilengkapi jam umum,
 * maksimal enam dan urut waktu. Jadi toko yang biasa buka 07:00 langsung melihat 07:00 di depan.
 */
export function SusunPilihanJam(isian: Isian, kolom: 'JamMulai' | 'JamSelesai'): PilihanCepatJam[] {
    const umum =
        kolom === 'JamMulai'
            ? ['07:00', '08:00', '09:00', '10:00', '12:00', '16:00']
            : ['15:00', '16:00', '17:00', '20:00', '21:00', '22:00'];
    const hitungan = new Map<string, number>();

    Object.values(isian).forEach((perHari) =>
        Object.values(perHari).forEach((baris) =>
            baris.forEach((sel) => {
                if (CekJam(sel[kolom])) {
                    hitungan.set(sel[kolom], (hitungan.get(sel[kolom]) ?? 0) + 1);
                }
            }),
        ),
    );

    const sering = [...hitungan.entries()].sort((a, b) => b[1] - a[1]).map(([jam]) => jam);

    return [...new Set([...sering.slice(0, 4), ...umum])]
        .slice(0, 6)
        .sort()
        .map((jam) => ({ Label: jam, Nilai: jam }));
}

/** Isian awal dari jadwal server: sel libur mendapat satu baris kosong supaya jam bisa langsung diketik. */
export function BuatIsian(baris: BarisJadwal[]): Isian {
    return Object.fromEntries(
        baris.map((b) => [
            b.Uuid,
            Object.fromEntries(
                Object.entries(b.Jadwal).map(([tanggal, shift]) => {
                    const milik = shift
                        .filter((s) => !s.OutletLain)
                        .map((s) => ({ JamMulai: s.JamMulai, JamSelesai: s.JamSelesai, UuidShift: s.UuidShift }));

                    return [tanggal, milik.length === 0 ? [BarisKosong()] : milik];
                }),
            ),
        ]),
    );
}

/** Baris yang benar-benar terisi (baris kosong sama sekali dibuang). */
export function BarisTerisi(baris: BarisIsian[]): BarisIsian[] {
    return baris.filter((b) => b.JamMulai !== '' || b.JamSelesai !== '');
}

/** Sel yang berubah dibanding jadwal awal (hanya outlet ini, sel yang berisi shift outlet lain dilewati) untuk dikirim. */
export function SusunSelBerubah(baris: BarisJadwal[], isian: Isian): SelBerubah[] {
    const sel: SelBerubah[] = [];

    for (const b of baris) {
        for (const [tanggal, awal] of Object.entries(b.Jadwal)) {
            if (awal.some((s) => s.OutletLain)) {
                continue;
            }

            const sekarang = BarisTerisi(isian[b.Uuid]?.[tanggal] ?? []);
            const KunciJam = (x: { JamMulai: string; JamSelesai: string }) => `${x.JamMulai}-${x.JamSelesai}`;
            const sama =
                awal.length === sekarang.length &&
                [...awal].map(KunciJam).sort().join('|') === [...sekarang].map(KunciJam).sort().join('|');

            if (!sama) {
                sel.push({
                    UuidKaryawan: b.Uuid,
                    Tanggal: tanggal,
                    Shift: sekarang.map((s) => ({
                        JamMulai: s.JamMulai,
                        JamSelesai: s.JamSelesai,
                        UuidShift: s.UuidShift,
                    })),
                });
            }
        }
    }

    return sel;
}

/** Ada baris yang jamnya tidak valid atau hanya terisi sebelah. */
export function AdaGalatJam(sel: SelBerubah[]): boolean {
    return sel.some((s) => s.Shift.some((x) => !CekJam(x.JamMulai) || !CekJam(x.JamSelesai)));
}

/** Teks ringkas satu shift, misal `Pagi 08:00–16:00`. */
export function LabelShift(shift: Pick<ShiftKerja, 'Nama' | 'JamMulai' | 'JamSelesai'>): string {
    return `${shift.Nama} ${shift.JamMulai}–${shift.JamSelesai}`;
}
