@extends('Surel.TataLetak')

@section('Judul', $Label)
@section('Pratinjau', $SelesaiPada ? 'Alert '.$Tingkat.' sudah pulih '.$SelesaiPada.'.' : 'Alert '.$Tingkat.' mulai '.$MulaiPada.'.')

@section('Isi')
    <p style="margin:0 0 20px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">{{ $SelesaiPada ? 'Halo Tim Teknis, ada insiden operasional yang sudah pulih. Pemberitahuan ini baru berhasil dikirim.' : 'Halo Tim Teknis, ada insiden operasional yang terbuka.' }}</p>

    @include('Surel.Komponen.Rincian', ['Baris' => ['Tingkat' => $Tingkat, 'Alert' => $Label, 'Mulai' => $MulaiPada] + ($SelesaiPada ? ['Selesai' => $SelesaiPada] : [])])

    @include('Surel.Komponen.Kutipan', ['Teks' => $Pesan])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Periksa dasbor operasional'])
@endsection

@section('CatatanKaki', $SelesaiPada ? 'Email ini dikirim sekali per insiden. Alert sudah tertutup karena kondisi pulih.' : 'Email ini dikirim sekali per insiden. Alert tertutup otomatis saat kondisi pulih.')
