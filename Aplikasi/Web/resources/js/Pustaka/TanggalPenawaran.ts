/**
 * Menambah hari ke tanggal `YYYY-MM-DD` dan mengembalikan `YYYY-MM-DD` (dihitung di UTC supaya zona waktu peramban tidak
 * menggeser tanggal). Dipakai formulir penawaran untuk mengisi "Berlaku sampai" dari tanggal penawaran.
 */
export function TambahHariTanggal(tanggal: string, hari: number): string {
    const [tahun, bulan, tgl] = tanggal.split('-').map(Number);

    if (tahun === undefined || bulan === undefined || tgl === undefined || Number.isNaN(tahun + bulan + tgl)) {
        return tanggal;
    }

    const hasil = new Date(Date.UTC(tahun, bulan - 1, tgl + hari));

    return hasil.toISOString().slice(0, 10);
}
