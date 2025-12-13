<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kuis extends Model
{
    // Nama tabel
    protected $table = 'kuis';

    // Kolom yang dapat diisi
    protected $fillable = [
        'kelas_id',
        'judul',
        'deskripsi',
        'tanggal_mulai',
        'tanggal_selesai',
        'durasi_menit',
        'status',
    ];

    // Casting tipe data
    protected $casts = [
        'kelas_id' => 'integer',
        'durasi_menit' => 'integer',
    ];

    /**
     * Relasi: Kuis memiliki banyak soal (KuisDetailSoal).
     */
    public function detailSoal()
    {
        return $this->hasMany(KuisDetailSoal::class, 'kuis_id', 'id');
    }

    /**
     * Relasi: Kuis memiliki banyak entri NilaiKuis.
     * Digunakan untuk memicu otomatisasi dan melihat status pengerjaan.
     */
    public function nilaiKuis()
    {
        return $this->hasMany(NilaiKuis::class, 'kuis_id', 'id');
    }

    /**
     * Relasi: Kuis milik satu Kelas.
     */
    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id', 'id');
    }
}