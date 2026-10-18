import { useEffect, useState } from 'react';

export function useStatusDaring(): boolean {
    const [daring, AturDaring] = useState(() => navigator.onLine);

    useEffect(() => {
        const Daring = () => AturDaring(true);
        const Luring = () => AturDaring(false);
        window.addEventListener('online', Daring);
        window.addEventListener('offline', Luring);

        return () => {
            window.removeEventListener('online', Daring);
            window.removeEventListener('offline', Luring);
        };
    }, []);

    return daring;
}

/** Waktu sekarang yang berdetak tiap detik (jam hidup & lama bekerja). */
export function useSekarang(): Date {
    const [sekarang, AturSekarang] = useState(() => new Date());

    useEffect(() => {
        const penghitung = setInterval(() => AturSekarang(new Date()), 1000);

        return () => clearInterval(penghitung);
    }, []);

    return sekarang;
}
