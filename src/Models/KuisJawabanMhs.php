<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KuisJawabanMhs extends Model
{
    protected $table = 'kuis_jawaban_mhs';

    protected $fillable = [
        'nilai_kuis_id',
        'soal_id',
        'jawaban_mhs',
        'is_correct',
    ];

    protected $casts = [
        'nilai_kuis_id' => 'integer',
        'soal_id' => 'integer',
        'is_correct' => 'boolean',
    ];

    /**
     * Relasi: Jawaban milik satu entri NilaiKuis.
     */
    public function nilaiKuis()
    {
        return $this->belongsTo(NilaiKuis::class, 'nilai_kuis_id', 'id');
    }

    /**
     * Relasi: Jawaban merujuk ke satu Soal Kuis.
     */
    public function soal()
    {
        return $this->belongsTo(KuisDetailSoal::class, 'soal_id', 'id');
    }
}