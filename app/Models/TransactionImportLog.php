<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionImportLog extends Model
{
    use HasFactory;
  protected $table = 'transaction_import_logs';
    protected $fillable = [
        'transaction_id',
        'status',
        'error',
        'row_data',
        'published_at',
        'processed_at',
    ];

    protected $casts = [
        'row_data' => 'array',
        'published_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
