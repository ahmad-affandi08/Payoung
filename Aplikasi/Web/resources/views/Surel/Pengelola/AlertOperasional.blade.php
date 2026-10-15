Halo Tim Teknis,

Alert {!! $Tingkat !!}: {!! $Label !!}
Mulai: {!! $MulaiPada !!}
@if ($SelesaiPada)
Selesai: {!! $SelesaiPada !!} (sudah pulih; pemberitahuan ini baru berhasil dikirim sekarang)
@endif

{!! $Pesan !!}

Periksa dasbor operasional:
{!! $Tautan !!}

Email ini dikirim sekali per insiden. {!! $SelesaiPada ? 'Alert sudah tertutup karena kondisi pulih.' : 'Alert tertutup otomatis saat kondisi pulih.' !!}
