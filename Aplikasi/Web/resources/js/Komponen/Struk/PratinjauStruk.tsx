import type { PengaturanStruk, ProfilPratinjauStruk } from '@/Tipe/Kasir';

/** Lebar kertas thermal dalam karakter font printer (58 mm = 32, 80 mm = 48); sama dengan aplikasi kasir. */
export const KOLOM_KERTAS = { '58': 32, '80': 48 } as const;

export type LebarKertas = keyof typeof KOLOM_KERTAS;

/** Kalimat penutup bawaan bila pengaturan kosong (sama dengan aplikasi kasir). */
export const PENUTUP_BAWAAN = 'Terima kasih atas kunjungan Anda';

export type BarisPratinjau = { teks: string; rata: 'kiri' | 'tengah'; tebal?: boolean };

/** Memotong teks menjadi baris selebar `kolom` karakter, memecah di spasi bila bisa. */
export function PecahBaris(teks: string, kolom: number): string[] {
    const hasil: string[] = [];
    let sisa = teks.trim();

    while (sisa.length > kolom) {
        const potong = sisa.lastIndexOf(' ', kolom);
        const titik = potong > 0 ? potong : kolom;
        hasil.push(sisa.slice(0, titik).trimEnd());
        sisa = sisa.slice(titik).trimStart();
    }

    if (sisa !== '') {
        hasil.push(sisa);
    }

    return hasil;
}

/** Dua kolom: kiri rata kiri, kanan rata kanan, dalam satu baris selebar `kolom` (kiri dipotong bila perlu). */
export function DuaKolom(kiri: string, kanan: string, kolom: number): string {
    const ruang = Math.max(kolom - kanan.length - 1, 1);
    const potongan = kiri.length > ruang ? kiri.slice(0, ruang) : kiri;

    return `${potongan}${' '.repeat(kolom - potongan.length - kanan.length)}${kanan}`;
}

/**
 * Susunan struk contoh (transaksi rekaan) sesuai pengaturan, untuk pratinjau di back-office. Tata letak mengikuti
 * `PenyusunStrukPenjualan` di aplikasi kasir; nominal hanya contoh.
 */
export function SusunPratinjauStruk(
    pengaturan: PengaturanStruk,
    profil: ProfilPratinjauStruk,
    lebar: LebarKertas,
): BarisPratinjau[] {
    const kolom = KOLOM_KERTAS[lebar];
    const garis: BarisPratinjau = { teks: '-'.repeat(kolom), rata: 'kiri' };
    const Tengah = (teks: string, tebal = false): BarisPratinjau[] =>
        PecahBaris(teks, kolom).map((t) => ({ teks: t, rata: 'tengah', tebal }));
    const Kiri = (teks: string, tebal = false): BarisPratinjau => ({ teks, rata: 'kiri', tebal });
    const baris: BarisPratinjau[] = [];

    // D-75: judul = nama outlet (yang dilihat pelanggan); nama usaha/akun pemilik tidak pernah dicetak.
    const judul = pengaturan.NamaDicetak ?? profil.NamaOutlet ?? profil.NamaUsaha;
    baris.push(...Tengah(judul, true));
    for (const teks of pengaturan.TeksKepala) {
        baris.push(...Tengah(teks));
    }
    if (profil.NamaOutlet && profil.NamaOutlet !== judul) {
        baris.push(...Tengah(profil.NamaOutlet));
    }
    // D-177: alamat & telepon asli outlet (sama dengan yang dicetak kasir); belum diisi = tidak tercetak.
    if (pengaturan.TampilkanAlamat && profil.AlamatOutlet) {
        baris.push(...Tengah(profil.AlamatOutlet));
    }
    if (pengaturan.TampilkanTelepon && profil.TeleponOutlet) {
        baris.push(...Tengah(`Telp. ${profil.TeleponOutlet}`));
    }
    if (pengaturan.TampilkanNpwp && profil.Npwp) {
        baris.push(...Tengah(`NPWP ${profil.Npwp}`));
    }
    baris.push(garis);
    baris.push(Kiri('INV/SLO/260925/K01-0042'));
    baris.push(Kiri(DuaKolom('25 Sep 2026', '14.32 WIB', kolom)));
    if (pengaturan.TampilkanKasir) {
        baris.push(Kiri('Kasir: Rina Wulandari'));
    }
    if (pengaturan.TampilkanPelanggan) {
        baris.push(Kiri('Pelanggan: Budi Santoso'));
    }
    baris.push(garis);
    baris.push(Kiri('Kopi Susu Gula Aren'));
    baris.push(Kiri(DuaKolom('  2 x 18.000', '36.000', kolom)));
    baris.push(Kiri('Roti Bakar Cokelat'));
    baris.push(Kiri(DuaKolom('  1 x 22.000', '22.000', kolom)));
    baris.push(Kiri(DuaKolom('  Diskon', '-2.000', kolom)));
    baris.push(garis);
    baris.push(Kiri(DuaKolom('Subtotal', '56.000', kolom)));
    baris.push(Kiri(DuaKolom('TOTAL', 'Rp 56.000', kolom), true));
    baris.push(Kiri(DuaKolom('Tunai', '100.000', kolom)));
    baris.push(Kiri(DuaKolom('Kembalian', '44.000', kolom)));
    if (pengaturan.TampilkanHemat) {
        baris.push(...Tengah('Anda hemat Rp 2.000'));
    }
    baris.push(garis);
    if (pengaturan.CatatanKaki) {
        baris.push(...Tengah(pengaturan.CatatanKaki));
    }
    if (pengaturan.TampilkanStrukDigital) {
        baris.push(...Tengah('[QR] Struk digital: payoung.id/s/…'));
    }
    baris.push(...Tengah(pengaturan.TeksPenutup ?? PENUTUP_BAWAAN));
    if (profil.TandaAir) {
        baris.push(...Tengah('Dibuat dengan Payoung'));
    }

    return baris;
}

type PropsPratinjauStruk = {
    pengaturan: PengaturanStruk;
    profil: ProfilPratinjauStruk;
    lebar: LebarKertas;
};

/** Kertas struk thermal rekaan: font Mono, lebar sesuai jumlah kolom kertas. */
export default function PratinjauStruk({ pengaturan, profil, lebar }: PropsPratinjauStruk) {
    const baris = SusunPratinjauStruk(pengaturan, profil, lebar);

    return (
        <figure
            aria-label={`Pratinjau struk ${lebar} mm`}
            className="mx-auto w-fit max-w-full overflow-x-auto rounded-kontrol border border-garis bg-permukaan px-3 py-4"
        >
            {pengaturan.TampilkanLogo && profil.TautanLogo ? (
                <img
                    src={profil.TautanLogo}
                    alt="Logo usaha"
                    className="mx-auto mb-2 max-h-16 w-auto object-contain grayscale"
                />
            ) : null}
            <pre className="font-mono text-keterangan text-teks-utama" style={{ width: `${KOLOM_KERTAS[lebar]}ch` }}>
                {baris.map((b, i) => (
                    <div key={i} className={b.rata === 'tengah' ? 'text-center' : undefined}>
                        {b.tebal ? <strong>{b.teks}</strong> : b.teks}
                    </div>
                ))}
            </pre>
        </figure>
    );
}
