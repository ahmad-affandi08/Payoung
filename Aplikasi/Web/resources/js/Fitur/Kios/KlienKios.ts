/** Galat berformat `{"Galat": {"Kode", "Pesan"}}` dari server, atau `TanpaKoneksi` bila jaringan putus. */
export class GalatKios extends Error {
    constructor(
        public readonly kode: string,
        pesan: string,
        public readonly status: number,
    ) {
        super(pesan);
    }
}

/** GET (badan `undefined`) atau POST JSON ke rute kios. Kios tidak memakai sesi/CSRF; semua harga dari server. */
export async function KirimJsonKios<T>(url: string, badan?: unknown, sinyal?: AbortSignal): Promise<T> {
    let respons: Response;

    try {
        respons = await fetch(url, {
            method: badan === undefined ? 'GET' : 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            ...(badan === undefined ? {} : { body: JSON.stringify(badan) }),
            ...(sinyal ? { signal: sinyal } : {}),
        });
    } catch {
        throw new GalatKios('TanpaKoneksi', 'Koneksi terputus. Panggil staf atau coba lagi sebentar lagi.', 0);
    }

    const isi = (await respons.json().catch(() => null)) as { Galat?: { Kode: string; Pesan: string } } | null;

    if (!respons.ok) {
        throw new GalatKios(
            isi?.Galat?.Kode ?? 'GalatServer',
            isi?.Galat?.Pesan ?? 'Terjadi masalah. Coba lagi sebentar lagi.',
            respons.status,
        );
    }

    return isi as T;
}
