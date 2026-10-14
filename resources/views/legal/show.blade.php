@extends('legal.layout')

@section('title', $title)

@section('content')
    @if ($draft)
        <p class="draft">Draf: teks ini belum ditinjau tim hukum {{ config('app.name') }} dan hanya tampil di lingkungan pengembangan.</p>
    @endif

    <h1>{{ $title }}</h1>
    <p class="muted">Terakhir diperbarui {{ \Illuminate\Support\Carbon::parse($updated)->locale('id')->translatedFormat('j F Y') }}</p>

    @foreach ($sections as $section)
        <h2>{{ $section['heading'] }}</h2>
        @foreach (preg_split("/\n\n/", $section['body']) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    @endforeach
@endsection
