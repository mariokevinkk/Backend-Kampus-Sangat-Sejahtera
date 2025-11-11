<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Matakuliah extends Model
{
    // Specify the table name if it's not following Laravel's default naming convention
    protected $table = 'matakuliah';  // Adjust table name if necessary

    // Define the fields that can be mass-assigned
    protected $fillable = [
        'kode_matakuliah', 'nama_matakuliah', 'sks', 'fakultas_id', 'created_at', 'updated_at'
    ];

    // Define the relationship with Fakultas (one Matakuliah belongs to one Fakultas)
    public function fakultas()
    {
        return $this->belongsTo(Fakultas::class, 'fakultas_id'); // A matakuliah belongs to a Fakultas
    }

    // If a Matakuliah has many Kelas (classes), you can define it like so
    public function kelas()
    {
        return $this->hasMany(Kelas::class, 'matakuliah_id'); // A matakuliah has many Kelas
    }
}
