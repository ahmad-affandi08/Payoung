const formatJam = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' });

export const formatJamDetik = new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZone: 'Asia/Jakarta',
});

export const formatHariPanjang = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'Asia/Jakarta',
});

export const formatHari = new Intl.DateTimeFormat('id-ID', { day: '2-digit', timeZone: 'Asia/Jakarta' });
export const formatBulan = new Intl.DateTimeFormat('id-ID', { month: 'short', timeZone: 'Asia/Jakarta' });

/** "08.30" dari waktu ISO, zona WIB. */
export function FormatJam(iso: string): string {
    return formatJam.format(new Date(iso));
}

export function SalamWaktu(sekarang: Date): string {
    const jam = Number(
        new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' }).format(sekarang),
    );

    if (jam < 11) {
        return 'Selamat pagi';
    }

    if (jam < 15) {
        return 'Selamat siang';
    }

    return jam < 18 ? 'Selamat sore' : 'Selamat malam';
}

/** Detik antara dua waktu ISO (atau sampai [sampai]); tidak pernah negatif. */
export function SelisihDetik(dari: string, sampai: Date | string): number {
    return Math.max(0, Math.floor((new Date(sampai).getTime() - new Date(dari).getTime()) / 1000));
}
