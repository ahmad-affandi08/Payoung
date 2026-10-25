/**
 * Format tampilan Indonesia (PRD §17.6.7).
 *
 * Uang dari API selalu berupa string desimal ("1250000.00"). Fungsi ini TIDAK mengubahnya
 * ke number/float (CLAUDE.md #7): pemisah ribuan disisipkan langsung pada teks.
 */
/**
 * Format persen dari string desimal API ("12.000000" → "12", "10.500000" → "10,5"). Tanpa konversi ke number.
 */
export function FormatPersen(nilai: string): string {
    const cocok = /^(-?\d+)(?:\.(\d+))?$/.exec(nilai.trim());

    if (!cocok) {
        throw new Error(`Nilai persen tidak valid: "${nilai}"`);
    }

    const [, bulat = '0', pecahan = ''] = cocok;
    const pecahanRingkas = pecahan.replace(/0+$/, '');

    return pecahanRingkas === '' ? bulat : `${bulat},${pecahanRingkas}`;
}

export function FormatRupiah(nilai: string): string {
    const cocok = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(nilai.trim());

    if (!cocok) {
        throw new Error(`Nilai uang tidak valid: "${nilai}"`);
    }

    const [, tanda = '', bulat = '0', sen = ''] = cocok;
    const bulatBersih = bulat.replace(/^0+(?=\d)/, '');
    const denganTitik = bulatBersih.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const senDuaDigit = sen.padEnd(2, '0');
    const bagianSen = senDuaDigit === '00' || sen === '' ? '' : `,${senDuaDigit}`;
    const negatif = tanda === '-' && (bulatBersih !== '0' || bagianSen !== '');

    return `${negatif ? '−' : ''}Rp ${denganTitik}${bagianSen}`;
}

/**
 * Rupiah ringkas untuk angka besar di kartu: "Rp 249.000", "Rp 48,2 jt", "Rp 1,3 M". Hanya bilangan bulat
 * (BigInt, tanpa pecahan biner); satu desimal dibulatkan ke terdekat. Nilai di bawah satu juta ditulis utuh.
 */
export function FormatRupiahRingkas(nilai: string): string {
    const cocok = /^(-?)(\d+)(?:\.\d{1,2})?$/.exec(nilai.trim());

    if (!cocok) {
        throw new Error(`Nilai uang tidak valid: "${nilai}"`);
    }

    const negatif = cocok[1] === '-';
    const bulat = BigInt(cocok[2] ?? '0');
    const Ringkas = (pembagi: bigint, satuan: string): string => {
        const persepuluh = (bulat * 10n + pembagi / 2n) / pembagi;
        const utuh = persepuluh / 10n;
        const desimal = persepuluh % 10n;

        return `Rp ${utuh.toString()}${desimal === 0n ? '' : `,${desimal.toString()}`} ${satuan}`;
    };
    const hasil =
        bulat >= 1_000_000_000n
            ? Ringkas(1_000_000_000n, 'M')
            : bulat >= 1_000_000n
              ? Ringkas(1_000_000n, 'jt')
              : FormatRupiah(bulat.toString());

    return negatif && bulat !== 0n ? `−${hasil}` : hasil;
}
