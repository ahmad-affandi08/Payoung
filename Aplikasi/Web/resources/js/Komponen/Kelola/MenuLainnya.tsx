import { Link } from '@inertiajs/react';
import { EllipsisIcon } from 'lucide-react';

import { Button } from '@/Komponen/Ui/button';
import { cn } from '@/Komponen/Ui/utils';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Komponen/Ui/dropdown-menu';

import type { ItemAksiHalaman } from './KonteksAksiHalaman';

/**
 * Menu "Lainnya" untuk aksi sekunder di HP (D-228): Impor, Ekspor, dan sejenisnya masuk sini supaya satu-satunya tombol
 * berwarna di bilah adalah aksi utama.
 */
export default function MenuLainnya({ butir, label = 'Aksi lainnya' }: { butir: ItemAksiHalaman[]; label?: string }) {
    if (butir.length === 0) {
        return null;
    }

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={label}
                    className="h-8 pointer-coarse:h-11 w-8 pointer-coarse:w-11 shrink-0"
                >
                    <EllipsisIcon aria-hidden="true" className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                {butir.map((satu) =>
                    satu.href === undefined ? (
                        <DropdownMenuItem
                            key={satu.label}
                            disabled={satu.nonaktif === true}
                            onSelect={() => satu.saatKlik?.()}
                            className={cn('min-h-10', satu.bahaya === true && 'text-bahaya')}
                        >
                            {satu.label}
                        </DropdownMenuItem>
                    ) : satu.unduh === true || satu.tabBaru === true ? (
                        <DropdownMenuItem
                            key={satu.label}
                            asChild
                            className={cn('min-h-10', satu.bahaya === true && 'text-bahaya')}
                        >
                            <a
                                href={satu.href}
                                {...(satu.tabBaru === true ? { target: '_blank', rel: 'noreferrer' } : {})}
                            >
                                {satu.label}
                            </a>
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem
                            key={satu.label}
                            asChild
                            className={cn('min-h-10', satu.bahaya === true && 'text-bahaya')}
                        >
                            <Link href={satu.href}>{satu.label}</Link>
                        </DropdownMenuItem>
                    ),
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
